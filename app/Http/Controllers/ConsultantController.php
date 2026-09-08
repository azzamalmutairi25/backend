<?php

namespace App\Http\Controllers;

use App\Models\AssessorAbsence;
use App\Models\AuditLog;
use App\Models\PeriodAssessor;
use App\Models\Sector;
use App\Models\TechnicalArea;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  إعدادات المستشارين — الرمز والقطاعات والمجالات الفنية.
//
//  ── لماذا بابٌ مستقلٌّ عن `/users` ──
//  شاشة المستخدمين محجوزةٌ لـ`user.manage`: تُنشئ الحسابات وتغيّر الأدوار
//  وتُصفّر كلمات المرور. ومسؤول الجدولة يحتاج ثلاثة حقولٍ منها فقط، ولا
//  يُعطى الباقي لأجلها. فهذا الباب يفتح الثلاثة وحدها لـ`schedule.manage`،
//  ويبقى إنشاءُ الحساب وإسناد الدور حيث كانا.
//
//  ── وما لا يُمَسّ هنا ──
//  القطاع الأساسي عمودٌ في الحساب لا في التغطية: هو ما يُعرض في البطاقة وما
//  تسقط إليه الصلاحيات، ويُسنَد يوم يُنشأ الحساب. والمسؤول هنا يزيد ما
//  **يُغطّى فوقه** ولا يُبدّله — وتبديلُه يُبطل الجلسات ويُعيد حساب ما يراه،
//  وذاك قرارُ إدارة مستخدمين لا قرارُ جدولة.
// ════════════════════════════════════════════════════════════

class ConsultantController extends Controller
{
    private function denyView(Request $request): ?JsonResponse
    {
        $u = $request->user();

        return $u->hasPermission(Permissions::SCHEDULE_MANAGE) || $u->hasPermission(Permissions::USER_MANAGE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية إدارة المستشارين'], 403);
    }

    // GET /consultants
    public function index(Request $request)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $today = now()->toDateString();

        $users = User::with(['role:id,code,name_ar', 'sector:id,name_ar', 'sectors:id,name_ar',
            'technicalAreas:id,label_ar', 'manager:id,full_name,code'])
            ->whereHas('role', fn ($q) => $q->whereIn('code', User::SECTOR_BOUND_ROLES))
            ->orderByRaw('code IS NULL, code')
            ->orderBy('full_name')
            ->get();

        // عدد الفترات التي هو في لوحتها — يُقرأ دفعةً لا مرّةً لكل اسم
        $panels = PeriodAssessor::whereIn('user_id', $users->pluck('id'))
            ->select('user_id', DB::raw('count(distinct period_id) c'))
            ->groupBy('user_id')->pluck('c', 'user_id');

        // أقربُ غيابٍ قادمٍ أو جارٍ — المسؤول يريد أن يعرف من هو خارجٌ الآن
        $absences = AssessorAbsence::with('host:id,code')
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('to_date', '>=', $today)
            ->orderBy('from_date')
            ->get()
            ->groupBy('user_id');

        $rows = $users->map(function (User $u) use ($panels, $absences, $today) {
            $next = $absences->get($u->id)?->first();

            return [
                'id' => $u->id,
                'code' => $u->code,
                'fullName' => $u->full_name,
                'roleCode' => $u->role->code,
                'roleName' => $u->role->name_ar,
                'isActive' => $u->is_active,
                // الأساسي مقفلٌ في الشاشة، والبقية تُزاد وتُنقَص
                'primarySectorId' => $u->sector_id,
                'primarySectorName' => $u->sector?->name_ar,
                'sectorIds' => $u->sectorIds(),
                'areaIds' => $u->technicalAreas->pluck('id')->all(),
                'areaLabels' => $u->technicalAreas->pluck('label_ar')->all(),
                'managerId' => $u->manager_id,
                'managerName' => $u->manager?->full_name,
                // الهوية تُعرَض وجوداً لا قيمةً: مسؤول الجدولة يحتاج أن يعرف
                // أنّها مسجَّلة كي يُطالِب بها، ولا يحتاج أن يقرأها
                'hasNationalId' => $u->national_id_hash !== null,
                'periodCount' => (int) ($panels[$u->id] ?? 0),
                'absence' => $next ? [
                    'fromDate' => $next->from_date->toDateString(),
                    'toDate' => $next->to_date->toDateString(),
                    'reasonLabel' => AssessorAbsence::reasonLabel($next->reason),
                    'hostCode' => $next->host?->code,
                    'current' => $next->from_date->toDateString() <= $today,
                ] : null,
            ];
        });

        return response()->json([
            'consultants' => $rows->values(),
            // المراجع في الطلب نفسه: الشاشة لا تُفتح على ثلاث رحلات
            'sectors' => Sector::orderBy('name_ar')->get(['id', 'code', 'name_ar'])
                ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name_ar]),
            'areas' => TechnicalArea::active()->ordered()->with('sectors:id')->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'label' => $a->label_ar,
                    'sectorIds' => $a->sectors->pluck('id')->all(),
                ]),
            // ما يُعطّل الشبكة والتوزيع — يُعَدّ هنا كي يظهر تنبيهاً لا يُكتشف لاحقاً
            'gaps' => [
                'noCode' => $rows->whereNull('code')->count(),
                'noAreas' => $rows->filter(fn ($r) => count($r['areaIds']) === 0)->count(),
            ],
            'canManage' => $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE),
        ]);
    }

    // PUT /consultants/{id} — الرمز والقطاعات والمجالات، لا غير
    public function update(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $user = User::with('role')->find($id);
        if (! $user) {
            return response()->json(['error' => 'المستخدم غير موجود'], 404);
        }
        // من ليس مستشاراً لا يُحرَّر من هنا: البابُ ضيّقٌ بحقوله **وبمن يمرّ منه**
        if (! in_array($user->role->code, User::SECTOR_BOUND_ROLES, true)) {
            return response()->json(['error' => 'هذا الحساب ليس مستشاراً'], 422);
        }

        $validated = $request->validate([
            'code' => ['present', 'nullable', 'string', 'regex:/^[A-Z]{1,2}$/', 'unique:users,code,'.$id],
            'sectorIds' => 'present|array|max:25',
            'sectorIds.*' => 'integer|distinct|exists:sectors,id',
            'areaIds' => 'present|array|max:60',
            'areaIds.*' => 'integer|distinct|exists:technical_areas,id',
        ], [
            'code.regex' => 'الرمز حرفٌ أو حرفان لاتينيّان كبيران',
            'code.unique' => 'الرمز مستخدَمٌ لمستشارٍ آخر',
        ]);

        // القطاع الأساسي داخلٌ دائماً — فلا يبقى مستشارٌ بلا قطاعٍ واحد
        $sectorIds = array_values(array_unique(array_merge(
            array_map('intval', $validated['sectorIds']),
            $user->sector_id ? [$user->sector_id] : []
        )));
        if (! $sectorIds) {
            return response()->json([
                'errors' => ['sectorIds' => ['لكل مستشارٍ قطاعٌ واحد على الأقلّ']],
            ], 422);
        }

        // ── المجال خارج قطاعات المستشار لا يُطابِق أحداً ──
        // المطابقة تقاطعُ مجالات، والقطاع حارسٌ فوقها: مجالٌ لقطاعٍ لا يغطّيه
        // لا يصل به إلى مشاركٍ أبداً. حفظُه بلا اعتراض يترك المسؤول يظنّ أنه
        // وسَمَه بشيء، والشاشة تعرضه، والتوزيع لا يراه.
        $areaIds = array_map('intval', $validated['areaIds']);
        if ($areaIds) {
            $stray = TechnicalArea::whereIn('technical_areas.id', $areaIds)
                ->whereDoesntHave('sectors', fn ($q) => $q->whereIn('sectors.id', $sectorIds))
                ->pluck('label_ar');
            if ($stray->isNotEmpty()) {
                return response()->json([
                    'errors' => ['areaIds' => ['مجالاتٌ خارج قطاعات المستشار: '.$stray->take(3)->implode('، ')]],
                ], 422);
            }
        }

        $before = ['code' => $user->code, 'sectors' => count($user->sectorIds()),
            'areas' => $user->technicalAreas()->count()];

        $user->code = $validated['code'] ?: null;
        $user->save();
        $user->sectors()->sync($sectorIds);
        $user->technicalAreas()->sync($areaIds);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'UPDATE_CONSULTANT_SETUP',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'details' => ['name' => $user->full_name, 'before' => $before,
                'after' => ['code' => $user->code, 'sectors' => count($sectorIds), 'areas' => count($areaIds)]],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'حُفظت إعدادات المستشار',
            'code' => $user->code,
            'sectorIds' => $sectorIds,
            'areaIds' => $areaIds,
        ]);
    }
}
