<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExpertiseArea;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// مجالات الخبرة — مرجعٌ يُدار من الإعدادات كالرتب، تُوسَم به حسابات المقيّمين.
class ExpertiseAreaController extends Controller
{
    private function audit(Request $request, string $action, int $id, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'expertise_area',
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

    // GET /expertise-areas — القائمة.
    //
    // **ليست مرجعاً عاماً كالرتب والقطاعات.** تلك يفتحها نموذج الترشيح للجهة
    // الخارجية، أمّا مجالات الخبرة فلا يقرؤها إلا من يحرّرها (الإعدادات) أو من
    // يوسم بها حساباً (المستخدمون). وشاشة الجدولة لا تطلبها أصلاً: المطابقة
    // تُحسب في الخادم وتعود ضمن `/candidates/{id}/assessors`.
    //
    // فتحُها لكل مصادَقٍ كان يكشف بنية اهتمامات المركز لدورٍ لا شأن له بها.
    public function index(Request $request)
    {
        $user = $request->user();
        $canManage = $user->hasPermission(Permissions::SETTINGS_MANAGE);
        if (! $canManage && ! $user->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض مجالات الخبرة'], 403);
        }
        $q = ExpertiseArea::ordered();
        if (! $canManage) {
            $q->active();
        }

        return response()->json([
            'areas' => $q->get()->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label_ar,
                'sortOrder' => $a->sort_order,
                'isActive' => $a->is_active,
                'userCount' => $a->users()->count(),
            ]),
            'canManage' => $canManage,
        ]);
    }

    // POST /expertise-areas
    public function store(Request $request)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'sortOrder' => 'nullable|integer|min:0|max:999',
        ]);

        if (ExpertiseArea::where('label_ar', $validated['label'])->exists()) {
            return response()->json(['errors' => ['label' => ['المجال مسجّل مسبقاً']]], 422);
        }

        $area = ExpertiseArea::create([
            'label_ar' => $validated['label'],
            'sort_order' => $validated['sortOrder'] ?? 0,
            'is_active' => true,
        ]);
        $this->audit($request, 'CREATE_EXPERTISE_AREA', $area->id, ['label' => $area->label_ar]);

        return response()->json(['message' => 'أُضيف مجال الخبرة', 'areaId' => $area->id], 201);
    }

    // PUT /expertise-areas/{id}
    public function update(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $area = ExpertiseArea::find($id);
        if (! $area) {
            return response()->json(['error' => 'المجال غير موجود'], 404);
        }

        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'sortOrder' => 'nullable|integer|min:0|max:999',
            'isActive' => 'boolean',
        ]);

        if (ExpertiseArea::where('label_ar', $validated['label'])->where('id', '!=', $id)->exists()) {
            return response()->json(['errors' => ['label' => ['المجال مسجّل مسبقاً']]], 422);
        }

        $area->update([
            'label_ar' => $validated['label'],
            'sort_order' => $validated['sortOrder'] ?? $area->sort_order,
            'is_active' => $request->boolean('isActive', $area->is_active),
        ]);
        $this->audit($request, 'UPDATE_EXPERTISE_AREA', $area->id, ['label' => $area->label_ar]);

        return response()->json(['message' => 'حُدّث مجال الخبرة']);
    }

    // DELETE /expertise-areas/{id} — يسقط معه وسمُه عن كل مستخدم
    public function destroy(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $area = ExpertiseArea::find($id);
        if (! $area) {
            return response()->json(['error' => 'المجال غير موجود'], 404);
        }

        $label = $area->label_ar;
        $tagged = $area->users()->count();
        $area->delete();
        $this->audit($request, 'DELETE_EXPERTISE_AREA', $id, ['label' => $label, 'taggedUsers' => $tagged]);

        return response()->json([
            'message' => $tagged > 0
                ? 'حُذف المجال، وسقط وسمه عن '.$tagged.' مستخدماً'
                : 'حُذف المجال',
        ]);
    }

    // PUT /users/{id}/expertise — وسم حساب مقيّم بمجالاته
    //
    // مسارٌ مستقل لا حقلٌ في تعديل المستخدم: وسمُ الخبرة قرارٌ يتكرّر ويُراجَع
    // وحده، ولا يستدعي فتح نموذج المستخدم كاملاً بكلمة مروره وأدواره.
    public function setUserExpertise(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::USER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة المستخدمين'], 403);
        }

        $user = User::find($id);
        if (! $user) {
            return response()->json(['error' => 'المستخدم غير موجود'], 404);
        }

        $validated = $request->validate([
            'areaIds' => 'present|array|max:30',
            'areaIds.*' => 'required|integer|distinct|exists:expertise_areas,id',
        ]);

        $user->expertiseAreas()->sync($validated['areaIds']);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'SET_USER_EXPERTISE',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'details' => ['count' => count($validated['areaIds'])],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'حُفظت مجالات الخبرة',
            'areaIds' => $user->expertiseAreas()->pluck('expertise_areas.id'),
        ]);
    }
}
