<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TechnicalArea;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// المجالات الفنية — مرجعٌ يُدار من الإعدادات، يُوسَم به المشارك ويُرشَّح عليه.
//
// نظيرُ ExpertiseAreaController شكلاً، ويفترق عنه في القراءة: مجالات الخبرة
// لا يقرؤها إلا من يحرّرها أو يوسم بها حساباً، أمّا هذه فيقرؤها **كل من يضيف
// مشاركاً** (النموذج يعرضها) و**كل من يرشّح** (الشاشة تفلتر بها). فحصرُها في
// الإعدادات يُفرِغ نموذج الإضافة من حقلٍ إلزامي.
class TechnicalAreaController extends Controller
{
    private function audit(Request $request, string $action, int $id, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'technical_area',
            'entity_id' => (string) $id,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function denyManage(Request $request): ?JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SETTINGS_MANAGE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
    }

    // GET /technical-areas — المُدار يرى المعطَّلة أيضاً ليعيد تفعيلها
    public function index(Request $request)
    {
        $user = $request->user();
        $canManage = $user->hasPermission(Permissions::SETTINGS_MANAGE);

        if (! $canManage
            && ! $user->hasPermission(Permissions::CANDIDATE_VIEW)
            && ! $user->hasPermission(Permissions::CANDIDATE_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المجالات الفنية'], 403);
        }

        $validated = $request->validate(['sectorId' => 'nullable|integer']);

        $q = TechnicalArea::ordered()->with('sectors:id,name_ar');
        if (! $canManage) {
            $q->active();
        }
        // نموذج المشارك يطلب مجالات قطاعه وحدها: عرضُ مجالات كل الجهات في
        // كل نموذج يجعل القائمة بلا معنى — والمجال يُنسَب لقطاعه لهذا.
        if (! empty($validated['sectorId'])) {
            $q->whereHas('sectors', fn ($s) => $s->where('sectors.id', $validated['sectorId']));
        }

        return response()->json([
            'areas' => $q->get()->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label_ar,
                'sortOrder' => $a->sort_order,
                'isActive' => $a->is_active,
                'sectorIds' => $a->sectors->pluck('id')->all(),
                'sectorNames' => $a->sectors->pluck('name_ar')->all(),
                'participantCount' => $a->candidates()->count(),
            ]),
            'canManage' => $canManage,
        ]);
    }

    // POST /technical-areas
    public function store(Request $request)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'label' => 'required|string|max:120',
            'sortOrder' => 'nullable|integer|min:0|max:999',
            // قطاعٌ واحد على الأقلّ — مجالٌ بلا قطاع لا يظهر في أي نموذج،
            // فيصير سجلاًّ ميتاً لا يُوسَم به مشاركٌ ولا مستشار
            'sectorIds' => 'required|array|min:1|max:50',
            'sectorIds.*' => 'required|integer|distinct|exists:sectors,id',
        ]);

        if (TechnicalArea::where('label_ar', $validated['label'])->exists()) {
            return response()->json(['errors' => ['label' => ['المجال مسجّل مسبقاً']]], 422);
        }

        $area = TechnicalArea::create([
            'label_ar' => $validated['label'],
            'sort_order' => $validated['sortOrder'] ?? 0,
            'is_active' => true,
        ]);
        $area->sectors()->sync($validated['sectorIds']);
        $this->audit($request, 'CREATE_TECHNICAL_AREA', $area->id, [
            'label' => $area->label_ar,
            'sectors' => count($validated['sectorIds']),
        ]);

        return response()->json(['message' => 'أُضيف المجال الفني', 'areaId' => $area->id], 201);
    }

    // PUT /technical-areas/{id}
    public function update(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $area = TechnicalArea::find($id);
        if (! $area) {
            return response()->json(['error' => 'المجال غير موجود'], 404);
        }

        $validated = $request->validate([
            'label' => 'required|string|max:120',
            'sortOrder' => 'nullable|integer|min:0|max:999',
            'isActive' => 'boolean',
            'sectorIds' => 'required|array|min:1|max:50',
            'sectorIds.*' => 'required|integer|distinct|exists:sectors,id',
        ]);

        if (TechnicalArea::where('label_ar', $validated['label'])->where('id', '!=', $id)->exists()) {
            return response()->json(['errors' => ['label' => ['المجال مسجّل مسبقاً']]], 422);
        }

        $area->update([
            'label_ar' => $validated['label'],
            'sort_order' => $validated['sortOrder'] ?? $area->sort_order,
            'is_active' => $request->boolean('isActive', $area->is_active),
        ]);
        $area->sectors()->sync($validated['sectorIds']);
        $this->audit($request, 'UPDATE_TECHNICAL_AREA', $area->id, [
            'label' => $area->label_ar,
            'sectors' => count($validated['sectorIds']),
        ]);

        return response()->json(['message' => 'حُدّث المجال الفني']);
    }

    // PUT /users/{id}/technical-areas — مجالات المستشار الفنية
    //
    // انتقلت من «مجالات الخبرة»: كان المقيّم يُوسَم من مرجعٍ والمشارك من آخر،
    // والمطابقة بينهما بحثاً نصّياً في نثر السيرة. صار الوسمان من مرجعٍ واحد،
    // فالمطابقة تقاطعٌ يُعدّ ويُعرَض ويُراجَع سببه.
    public function setUserAreas(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)
            && ! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة المستشارين'], 403);
        }

        $user = User::find($id);
        if (! $user) {
            return response()->json(['error' => 'المستخدم غير موجود'], 404);
        }

        $validated = $request->validate([
            'areaIds' => 'present|array|max:60',
            'areaIds.*' => 'required|integer|distinct|exists:technical_areas,id',
        ]);

        $user->technicalAreas()->sync($validated['areaIds']);
        $this->audit($request, 'SET_USER_TECHNICAL_AREAS', $user->id, [
            'count' => count($validated['areaIds']),
        ]);

        return response()->json([
            'message' => 'حُفظت المجالات الفنية للمستشار',
            'areaIds' => $user->technicalAreas()->pluck('technical_areas.id'),
        ]);
    }

    // DELETE /technical-areas/{id}
    //
    // مجالٌ يوصف به مشاركون لا يُحذف — الحذف يُسقط الوسم عنهم بلا أثرٍ ظاهر،
    // فيخرجون من كل قائمة ترشيح تفلتر به ولا يفهم أحدٌ لماذا. التعطيل يخفيه
    // عن النماذج الجديدة ويُبقي الوسم القائم مقروءاً.
    public function destroy(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $area = TechnicalArea::find($id);
        if (! $area) {
            return response()->json(['error' => 'المجال غير موجود'], 404);
        }

        $tagged = $area->candidates()->count();
        if ($tagged > 0) {
            return response()->json([
                'error' => "المجال موصوفٌ به {$tagged} مشاركاً — عطّله بدل حذفه ليبقى وسمهم مقروءاً",
            ], 422);
        }

        $label = $area->label_ar;
        $area->delete();
        $this->audit($request, 'DELETE_TECHNICAL_AREA', $id, ['label' => $label]);

        return response()->json(['message' => 'حُذف المجال الفني']);
    }
}
