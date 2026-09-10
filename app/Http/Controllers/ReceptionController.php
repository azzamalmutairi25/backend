<?php

namespace App\Http\Controllers;

use App\Exceptions\CvTooLargeException;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateCv;
use App\Models\CandidateCvRevision;
use App\Models\ReceptionAssignment;
use App\Models\ReceptionKiosk;
use App\Models\ReceptionVisit;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\User;
use App\Security\Permissions;
use App\Services\CvGuard;
use App\Services\CvValidator;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// ════════════════════════════════════════════════════════════
//  استقبال الموظفين — مسار المشارك من باب المركز إلى جدول المقابلات.
//
//  ١) الاستقبال يسجّل الوصول (الوقت تلقائي وقابل للتعديل)
//  ٢) المشارك يوقّع ويقرّ بصحّة بياناته
//  ٣) الاستقبال يوزّعه على مقابلة / حلقة نقاش / أدوات قياس ويختار المقيّم
//  ٤) المقيّم يُشعَر، فيستلمه أو يردّه بسبب
//  ٥) المردود يعود للعمليات لإعادة إسناده لمقيّم آخر أو لنشاط آخر
//  ٦) العمليات تعتمد فتُرحَّل المستلَمة إلى جدول الجلسات كلٌّ حسب اختصاصه
//
//  لكل مرحلة صلاحيتها المستقلّة (reception.*): من يسجّل ليس من يوزّع، ومن
//  يوزّع ليس من يقرّر، ومن يقرّر ليس من يعتمد.
//
//  السرّية: المقيّم لا يرى اسم المشارك ولا رقم هويته في أي مخرَج من هذا
//  المتحكّم — لا بشرط صلاحية ولا بدونه. حدُّه رمز المشارك والسيرة مطموسة.
// ════════════════════════════════════════════════════════════

class ReceptionController extends Controller
{
    // سقف قائمة المنتظَرين المعروضة دفعةً واحدة — البحث بالرمز هو طريق من بعده
    private const EXPECTED_LIMIT = 40;

    public function __construct(private NotificationService $notify) {}

    private function log(Request $request, string $action, $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'reception',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function deny(string $message)
    {
        return response()->json(['error' => $message], 403);
    }

    // ── حلّ زيارة ضمن نطاق المستخدم (تصنيف + قطاع) ──
    // 404 موحّد لغير الموجود ولغير المصرَّح: المعرّف لا يكون عرّافاً بوجود مشارك.
    private function findVisit(Request $request, int $id, array $with = []): ?ReceptionVisit
    {
        $user = $request->user();

        return ReceptionVisit::with($with)
            ->whereHas('candidate', function ($q) use ($request, $user) {
                $q->whereIn('classification', $this->allowedClassifications($request));
                if ($user->isSectorBound()) {
                    $q->whereIn('sector_id', $user->sectorIds());
                }
            })
            ->find($id);
    }

    // ═══════════════════════════════════════════════════════
    //  كشف اليوم
    // ═══════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_VIEW)) {
            return $this->deny('ليس لديك صلاحية عرض شاشة استقبال الموظفين');
        }

        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'q' => 'nullable|string|max:80',
        ]);
        $date = $validated['date'] ?? now()->toDateString();
        $q = trim($validated['q'] ?? '');

        $can = [
            'record' => $user->hasPermission(Permissions::RECEPTION_RECORD),
            'assign' => $user->hasPermission(Permissions::RECEPTION_ASSIGN),
            'decide' => $user->hasPermission(Permissions::RECEPTION_DECIDE),
            'approve' => $user->hasPermission(Permissions::RECEPTION_APPROVE),
            'viewNames' => $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES),
            'viewCv' => $this->canReadVisitCv($user),
        ];

        // كشف اليوم كاملاً لمن يديره؛ أمّا من لا يملك إلا القرار (المقيّم) فلا
        // يُعرَض عليه الكشف أصلاً — يرى المُسنَد إليه وحده في «مهامّي».
        $manages = $can['record'] || $can['assign'] || $can['approve'];

        $visits = [];
        $expected = ['total' => 0, 'shown' => 0, 'rows' => []];

        if ($manages) {
            $rows = ReceptionVisit::with([
                'candidate.sector', 'candidate.cv', 'assessment.stations',
                'assignments.evaluator',
            ])
                ->whereDate('visit_date', $date)
                ->whereHas('candidate', function ($c) use ($request, $user) {
                    $c->whereIn('classification', $this->allowedClassifications($request));
                    if ($user->isSectorBound()) {
                        $c->whereIn('sector_id', $user->sectorIds());
                    }
                })
                ->orderBy('arrived_at')
                ->get();

            $visits = $rows->map(fn (ReceptionVisit $v) => $this->visitPayload($v, $can))->values();

            // المنتظَرون: دورات لم تُسجَّل لها زيارة اليوم ولم تُنجَز بعد.
            // مقصورة على من يسجّل الوصول — غيره لا يحتاج قائمة الغائبين.
            if ($can['record']) {
                $expected = $this->expectedList($request, $date, $q, $can);
            }
        }

        // مهامّ المقيّم — تظهر لكل من يملك القرار، أياً كان دوره
        $mine = $can['decide'] ? $this->myAssignments($user, $date) : [];

        return response()->json([
            'date' => $date,
            // يومٌ مضى يُقرأ ولا يُكتب — الواجهة تُخفي أزرار التسجيل عليه
            'isToday' => $date === now()->toDateString(),
            'can' => $can,
            'activities' => collect(ReceptionAssignment::ACTIVITIES)
                ->map(fn ($a) => ['key' => $a, 'label' => ReceptionAssignment::label($a)])->values(),
            'visits' => $visits,
            'expected' => $expected,
            'mine' => $mine,
            'totals' => [
                'arrived' => count($visits),
                'signed' => collect($visits)->where('signed', true)->count(),
                'approved' => collect($visits)->where('status', ReceptionVisit::APPROVED)->count(),
                'pendingDecision' => collect($visits)->sum(
                    fn ($v) => collect($v['assignments'])->where('status', ReceptionAssignment::PENDING)->count()
                ),
            ],
        ]);
    }

    private function visitPayload(ReceptionVisit $v, array $can): array
    {
        $c = $v->candidate;
        $doc = $c->cv?->data;

        return [
            'id' => $v->id,
            'assessmentId' => $v->assessment_id,
            'candidateId' => $c->id,
            'participantCode' => $v->assessment?->participant_code ?? $c->participant_code,
            // الاسم لحامل صلاحيته وحده — والاستقبال يحملها ليطابق الحاضر ببطاقته
            'name' => $can['viewNames'] ? $c->full_name : null,
            'sector' => $c->sector?->name_ar,
            // يُرسَل ليُصفّى به قائمة المقيّمين المؤهّلين قبل الإسناد
            'sectorId' => $c->sector_id,
            'rank' => $c->rank_label,
            'tier' => $c->tier,
            'arrivedAt' => $v->arrived_at?->format('H:i'),
            // ── مدّة الانتظار بالدقائق ──
            // «وصل ٠٩:١٥» تُقرأ ويُطرَح منها الوقتُ الحاليّ في الرأس، وذاك
            // حسابٌ لا يقع في زحمة الردهة. والرقم يُقاس من الوصول إلى الإرسال،
            // فمن أُرسِل توقّف عدّاده — انتظارُه انتهى.
            'waitedMinutes' => $v->arrived_at
                ? (int) $v->arrived_at->diffInMinutes($v->sent_at ?? now())
                : null,
            'signed' => $v->isSigned(),
            'attested' => $v->attested,
            'status' => $v->status,
            // من سجّل نفسه على الكشك مقابل من سجّله موظّف — يظهر في الكشف
            // كي يعرف الاستقبال من مرّ عليه فعلاً ومن دخل من الجهاز اللوحي
            'viaKiosk' => $v->kiosk_id !== null,
            'badgePrinted' => $v->badge_printed_at !== null,
            'badgePending' => $v->badgePending(),
            'hasCv' => $doc !== null && ! CandidateCv::isEmptyDoc($doc),
            // ── بوّابة البطاقة والإرسال ──
            // اعتماد السيرة فعلُ يومٍ بعينه: هذا ما رآه الموظّف وأقرّه اليوم.
            // بلا اعتماد لا تُطبع بطاقة ولا يصل المستشار شيء.
            'cvApproved' => $v->cv_approved_at !== null,
            'cvApprovedAt' => $v->cv_approved_at?->format('H:i'),
            'cvVersion' => $c->cv?->version,
            'sentAt' => $v->sent_at?->format('H:i'),
            // ── التقدّم على محطّاته المختارة ──
            // موقعٌ لا نتيجة: الاستقبال يعرف أين وصل ولا يرى درجةً ولا رأياً.
            // والناقص يُسمّى — «بقيت حلقة النقاش» تُقرأ، و«٢ من ٣» لا تُقرأ.
            'stations' => $v->assessment ? collect($v->assessment->chosenStations())
                ->map(fn ($k) => [
                    'key' => $k,
                    'label' => Assessment::stationLabel($k),
                    'done' => in_array($k, $v->assessment->completedStations(), true),
                ])->values()->all() : [],
            'missingStations' => $v->assessment
                ? array_map([Assessment::class, 'stationLabel'], $v->assessment->missingStations())
                : [],
            'assignments' => $v->assignments->map(fn (ReceptionAssignment $a) => [
                'id' => $a->id,
                'activity' => $a->activity,
                'activityLabel' => ReceptionAssignment::label($a->activity),
                'evaluatorId' => $a->evaluator_id,
                'evaluatorName' => $a->evaluator?->full_name,
                'status' => $a->status,
                'rejectReason' => $a->reject_reason,
                'decidedAt' => $a->decided_at?->format('H:i'),
            ])->values()->all(),
        ];
    }

    /**
     * كشف اليوم — **من جلسات ذلك اليوم**، لا من قاعدة المشاركين كلّها.
     *
     * ── ما كان يقع ──
     * كانت القائمة تستعلم الدورات بشرطين: ألّا تكون له زيارةٌ اليوم، وألّا
     * تكون دورتُه منتهية. **بلا أيّ ربطٍ بتاريخ ولا بجلسة.** فمن موعده بعد
     * شهرين، ومن لم يُجدوَل قطّ، يظهران في «منتظَري اليوم» بالتساوي مع من
     * موعده اليوم — مقصوصةً عند الأربعين وبترتيب الرمز. أي أنّ الموظّف كان
     * يستقبل من قائمةٍ لا تعني اليوم في شيء.
     *
     * ── ولماذا لا تُشترَط فترةٌ معتمَدة ──
     * المواصفة تبني الكشف على «الجدولة بعد اعتمادها». وفي القاعدة اليوم
     * **صفرُ جلسةٍ في فترةٍ معتمَدة**: ٣٤٣ من ٣٤٤ بلا فترة أصلاً، والفترات
     * الأربع مسوّدات. فاشتراطُ الاعتماد يُفرِغ الشاشة على مركزٍ يعمل. الشرط
     * هو **الجلسة في ذلك اليوم**، وحالةُ الفترة تُرسَل مع الصفّ ليُرى النقص
     * لا ليُمنع به العمل.
     *
     * ── والبحث يبقى منفذاً للاستثناء ──
     * حصرُ الشاشة في المجدولين يُعمي الموظّف عمّن حضر بلا جلسة مسجَّلة. فمتى
     * بحث برمزٍ بعينه تُوسَّع القائمة، ويُوسَم الصفُّ `offRoster` — يُستقبَل
     * ويُعرَف أنه خارج كشف اليوم.
     */
    private function expectedList(Request $request, string $date, string $q, array $can): array
    {
        $user = $request->user();

        $arrived = ReceptionVisit::whereDate('visit_date', $date)->pluck('assessment_id');

        // من له جلسةٌ في هذا اليوم — هذا هو كشف اليوم
        $onRoster = Schedule::whereDate('schedule_date', $date)
            ->whereNotNull('assessment_id')
            ->pluck('assessment_id')->unique()->values();

        $query = Assessment::with('candidate.sector')
            ->whereNotIn('id', $arrived)
            // المنتهية لا تُستقبل — دورة اكتملت ليست موعداً قادماً
            ->whereNotIn('status', ['completed'])
            ->whereHas('candidate', function ($c) use ($request, $user) {
                $c->whereIn('classification', $this->allowedClassifications($request));
                if ($user->isSectorBound()) {
                    $c->whereIn('sector_id', $user->sectorIds());
                }
            });

        // البحث بالرمز على الخادم (الاسم مشفَّر فلا يُبحث فيه بـSQL)
        if ($q !== '') {
            $query->where('participant_code', 'ilike', '%'.$q.'%');
        } else {
            $query->whereIn('id', $onRoster);
        }

        // حدٌّ صريح: الكشف أداة استقبالٍ لا تصفّحٌ لقاعدة المشاركين كاملة.
        // العدد الكلّي يُرسَل مع المقتطَع — قائمةٌ مقصوصة صامتة تُقرأ «هذا كل
        // من ينتظر»، فيُصرَف مشاركٌ حاضرٌ لأنه لم يظهر في الشاشة.
        $total = (clone $query)->count();
        $rows = $query->orderBy('participant_code')->limit(self::EXPECTED_LIMIT)->get();

        // بعد القصّ لا قبله: استعلامٌ واحد لأربعين صفّاً، لا أربعون استعلاماً
        $sessions = Schedule::whereDate('schedule_date', $date)
            ->whereIn('assessment_id', $rows->pluck('id'))
            ->orderBy('schedule_time')
            ->get(['assessment_id', 'schedule_time', 'activity'])
            ->groupBy('assessment_id');

        return [
            'total' => $total,
            'shown' => $rows->count(),
            'rows' => $rows->map(fn (Assessment $a) => [
                'assessmentId' => $a->id,
                'participantCode' => $a->participant_code,
                'name' => $can['viewNames'] ? $a->candidate?->full_name : null,
                'sector' => $a->candidate?->sector?->name_ar,
                'rank' => $a->candidate?->rank_label,
                // مواعيد اليوم — الموظّف يرى متى ينتظره ولمَ حضر
                'sessions' => ($sessions[$a->id] ?? collect())->map(fn ($x) => [
                    'time' => $x->schedule_time ? substr((string) $x->schedule_time, 0, 5) : null,
                    'activity' => ReceptionAssignment::label($x->activity),
                ])->values()->all(),
                // خارج كشف اليوم: ظهر بالبحث لا بجلسة. يُستقبَل ويُعرَف حالُه
                'offRoster' => ! $onRoster->contains($a->id),
            ])->values()->all(),
        ];
    }

    // مهامّ المقيّم — بلا اسم ولا هوية، أياً كانت صلاحياته
    private function myAssignments(User $user, string $date): array
    {
        return ReceptionAssignment::with('visit.assessment')
            ->where('evaluator_id', $user->id)
            ->whereIn('status', [ReceptionAssignment::PENDING, ReceptionAssignment::ACCEPTED])
            ->whereHas('visit', fn ($v) => $v->whereDate('visit_date', $date))
            ->get()
            ->map(fn (ReceptionAssignment $a) => [
                'id' => $a->id,
                'activity' => $a->activity,
                'activityLabel' => ReceptionAssignment::label($a->activity),
                'status' => $a->status,
                // الرمز هو هوية المشارك عند المقيّم — لا اسم ولا رقم هوية
                'participantCode' => $a->visit?->assessment?->participant_code,
                'arrivedAt' => $a->visit?->arrived_at?->format('H:i'),
            ])->values()->all();
    }

    // ── المقيّمون المؤهّلون لنشاط ──
    public function evaluators(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_ASSIGN)) {
            return $this->deny('ليس لديك صلاحية توزيع المشاركين');
        }

        $validated = $request->validate([
            'activity' => 'required|in:'.implode(',', ReceptionAssignment::ACTIVITIES),
            'sectorId' => 'nullable|integer',
        ]);

        $roles = ReceptionAssignment::ACTIVITY_ROLES[$validated['activity']];

        // permissionOverrides محمَّلة مسبقاً: hasPermission تستعلم لكل مستخدم بدونها
        $rows = User::with('role', 'sector', 'permissionOverrides')
            ->where('is_active', true)
            ->whereHas('role', fn ($r) => $r->whereIn('code', $roles))
            ->orderBy('full_name')
            ->get()
            // القائمة تعرض من يستطيع الاستلام فعلاً. الدورُ وحده لا يكفي: صلاحيةٌ
            // مسحوبة باستثناء فردي تجعل الإسناد يُرفض بعد اختياره.
            ->filter(fn (User $u) => $u->hasPermission(Permissions::RECEPTION_DECIDE))
            // المحصور بقطاع لا يُقترَح لمشارك خارج قطاعه — الإسناد سيُرفض على
            // أي حال في assign()، وعرضه في القائمة يجعل الرفض مفاجأة
            ->filter(fn (User $u) => ! isset($validated['sectorId'])
                || $u->coversSector((int) $validated['sectorId']))
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'role' => $u->role?->name_ar,
                'sector' => $u->sector?->name_ar,
            ])->values();

        return response()->json(['evaluators' => $rows]);
    }

    // ═══════════════════════════════════════════════════════
    //  ١) تسجيل الوصول
    // ═══════════════════════════════════════════════════════
    public function arrive(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تسجيل وصول المشاركين');
        }

        $validated = $request->validate([
            'assessmentId' => 'required|integer',
            'date' => 'nullable|date_format:Y-m-d',
        ]);
        $date = $validated['date'] ?? now()->toDateString();

        // ── الوصول يُسجَّل في يومه ──
        // «يوماً بيوم كي لا يختلط»: تسجيلُ وصولٍ بتاريخٍ آخر يضع مشاركاً في
        // كشف يومٍ لم يحضر فيه، ويُبنى عليه إسنادٌ وجلسةٌ وبطاقة. وقراءةُ يومٍ
        // مضى تبقى مفتوحة — المراجعة لا تُفسد شيئاً، والكتابة تُفسد.
        if ($date !== now()->toDateString()) {
            return response()->json([
                'error' => 'الوصول يُسجَّل في يومه — كشفُ اليوم لا يقبل تاريخاً آخر',
            ], 422);
        }

        $assessment = Assessment::with('candidate')->find($validated['assessmentId']);
        if (! $assessment || ! $this->resolveCandidateInScope($request, $assessment->candidate_id)) {
            return response()->json(['error' => 'الدورة غير موجودة'], 404);
        }

        // firstOrCreate على القيد الفريد (assessment_id, visit_date): نقرتان
        // متتاليتان تُنتجان زيارةً واحدة لا صفَّين متنافسين
        $visit = ReceptionVisit::firstOrCreate(
            ['assessment_id' => $assessment->id, 'visit_date' => $date],
            [
                'candidate_id' => $assessment->candidate_id,
                'arrived_at' => now(),
                'received_by' => $user->id,
                'status' => ReceptionVisit::ARRIVED,
            ]
        );

        if ($visit->wasRecentlyCreated) {
            // assessments.arrived_at قائمة من قبل (بوّابة المشارك) — نُبقيها
            // متّسقة كي لا يختلف مصدران عن وقتٍ واحد
            if ($assessment->arrived_at === null) {
                $assessment->update(['arrived_at' => $visit->arrived_at]);
            }
            $this->log($request, 'RECEPTION_ARRIVE', $visit->id, ['code' => $assessment->participant_code]);
        }

        return response()->json([
            'visitId' => $visit->id,
            'arrivedAt' => $visit->arrived_at?->format('H:i'),
            'created' => $visit->wasRecentlyCreated,
        ], $visit->wasRecentlyCreated ? 201 : 200);
    }

    // ── تعديل وقت الوصول ──
    public function updateArrival(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تعديل وقت الوصول');
        }

        $validated = $request->validate(['arrivedAt' => 'required|date_format:H:i']);

        $visit = $this->findVisit($request, $id);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        if ($visit->status === ReceptionVisit::APPROVED) {
            return response()->json(['error' => 'الزيارة معتمدة — لا يُعدَّل وقتها'], 422);
        }

        $before = $visit->arrived_at?->format('H:i');
        // الوقت يُركَّب على تاريخ الزيارة لا على اليوم الحالي: تعديل زيارة أمس
        // بوقتٍ فقط كان ينقلها إلى اليوم فتختفي من كشف يومها
        $visit->arrived_at = $visit->visit_date->copy()
            ->setTimeFromTimeString($validated['arrivedAt']);
        $visit->save();

        $this->log($request, 'RECEPTION_ARRIVAL_EDIT', $visit->id, [
            'from' => $before, 'to' => $validated['arrivedAt'],
        ]);

        return response()->json(['arrivedAt' => $visit->arrived_at->format('H:i')]);
    }

    // ═══════════════════════════════════════════════════════
    //  ٢) توقيع المشارك وإقراره بصحّة بياناته
    // ═══════════════════════════════════════════════════════
    public function sign(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية أخذ توقيع المشارك');
        }

        $validated = $request->validate([
            // صورة PNG بترميز data URL يرسمها المشارك على الشاشة.
            // الحدّ 400 ألف محرف (~٣٠٠ك بايت) — كافٍ لتوقيعٍ عالي الدقّة،
            // ومانعٌ لرفع ملفٍ كبير عبر الحقل.
            'signature' => 'required|string|max:400000|starts_with:data:image/png;base64,',
            'attested' => 'required|accepted',
        ], [
            'signature.starts_with' => 'صيغة التوقيع غير صالحة',
            'attested.accepted' => 'لا بدّ من إقرار المشارك بصحّة بياناته',
        ]);

        $visit = $this->findVisit($request, $id, ['assessment']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        // التوقيع إقرارٌ لا يُعاد: استبداله بعد الاعتماد يجعل الوثيقة الموقَّعة
        // غير التي اعتُمدت
        if ($visit->status === ReceptionVisit::APPROVED) {
            return response()->json(['error' => 'الزيارة معتمدة — لا يُعدَّل توقيعها'], 422);
        }

        $visit->signature = $validated['signature'];
        $visit->attested = true;
        $visit->signed_at = now();
        $visit->save();

        // لا يُسجَّل التوقيع نفسه في التدقيق — بيانات شخصية، ووجودها في السجلّ
        // يجعل نسخةً منها خارج التشفير
        $this->log($request, 'RECEPTION_SIGN', $visit->id, [
            'code' => $visit->assessment?->participant_code,
        ]);

        return response()->json(['signed' => true, 'signedAt' => $visit->signed_at->format('H:i')]);
    }

    // ── من يقرأ سيرة زائرٍ في شاشة الاستقبال ──
    //
    // مَن يستقبله فعلاً (RECEPTION_RECORD) — سيرته أمامه ويطابق بها بياناته —
    // أو حاملُ CANDIDATE_CV_VIEW أصلاً (مدير التقييم ومن في مرتبته).
    // الفرق عن /api/candidates/{id}/cv جوهري: ذاك يفتح أي سيرة بمعرّفها،
    // وهذا محصور بزيارةٍ قائمة في يومها ضمن نطاق القارئ.
    private function canReadVisitCv(User $user): bool
    {
        return $user->hasPermission(Permissions::RECEPTION_VIEW)
            && ($user->hasPermission(Permissions::RECEPTION_RECORD)
                || $user->hasPermission(Permissions::CANDIDATE_CV_VIEW));
    }

    // ── السيرة الذاتية في شاشة الاستقبال (بالاسم لمن يملك صلاحيته) ──
    public function visitCv(Request $request, int $id)
    {
        $user = $request->user();
        if (! $this->canReadVisitCv($user)) {
            return $this->deny('ليس لديك صلاحية عرض السيرة الذاتية');
        }

        $visit = $this->findVisit($request, $id, ['candidate.cv', 'assessment']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }

        $doc = $visit->assessment?->cv_snapshot ?? $visit->candidate->cv?->data ?? CandidateCv::emptyDoc();
        $canSeeNames = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        if (! $canSeeNames) {
            $doc = CvGuard::scrub($doc, $visit->candidate);
        }

        $this->log($request, 'RECEPTION_VIEW_CV', $visit->id);

        return response()->json(['cv' => [
            'participantCode' => $visit->assessment?->participant_code,
            'name' => $canSeeNames ? $visit->candidate->full_name : null,
            'rank' => $visit->candidate->rank_label,
            'sector' => $visit->candidate->sector?->name_ar,
            'hasCv' => ! CandidateCv::isEmptyDoc($doc),
            'document' => $doc,
        ]]);
    }

    // ═══════════════════════════════════════════════════════
    //  ٣) التوزيع على نشاط ومقيّم
    // ═══════════════════════════════════════════════════════
    // ═══════════════════════════════════════════════════════
    //  السيرة عند المكتب — تُصحَّح، ثم تُعتمد، ثم تُرسَل
    // ═══════════════════════════════════════════════════════

    // الحقول السبعة التي يصحّحها موظّف الاستقبال — ولا شيء غيرها.
    //
    // ما خرج منها خرج بسبب: تاريخ الميلاد والتعيين والرتبة تقود تصنيف الفئة
    // القيادية وتُقرأ من سجلّات الجهة، فتصحيحُها عند مكتب الاستقبال يُغيّر
    // تصنيفاً بُني عليه ترشيحٌ واعتماد. وهذه السبعة وصفُ عملٍ يُراجَع بالنظر
    // مع صاحبه في دقيقة.
    private const RECEPTION_CV_FIELDS = [
        'currentPosition', 'department', 'generalDepartment',
        'totalYearsExperience', 'qualifications', 'experiences', 'certifications',
    ];

    // PUT /reception/visits/{id}/cv — تصحيحٌ مباشر عند المكتب
    public function updateCv(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_CV_EDIT)) {
            return $this->deny('ليس لديك صلاحية تصحيح السيرة عند الاستقبال');
        }

        $visit = $this->findVisit($request, $id, ['candidate.cv', 'assessment']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        // بعد الإرسال جُمّدت الصورة التي يقرؤها المستشار: تصحيحٌ بعدها يغيّر
        // الملفّ الحيّ ولا يغيّر ما يُقيَّم عليه — فيظنّ الموظّف أنه صحّح شيئاً
        if ($visit->sent_at !== null) {
            return response()->json([
                'error' => 'أُرسِلت القوائم وجُمّدت السيرة — التصحيح بعدها لا يصل المستشار',
            ], 422);
        }

        $input = $request->input('cv');
        if (! is_array($input) || $input === []) {
            return response()->json(['error' => 'بيانات غير صحيحة'], 422);
        }
        // ما لا يُصحَّح من هنا لا يُقبل صامتاً: تجاهلُه يجعل الموظّف يظنّ أنه
        // غيّر تاريخ ميلادٍ وقد سقط في الطريق
        $extra = array_diff(array_keys($input), self::RECEPTION_CV_FIELDS);
        if ($extra) {
            return response()->json([
                'error' => 'حقولٌ لا تُصحَّح من الاستقبال: '.implode('، ', array_slice($extra, 0, 4)),
            ], 422);
        }

        $candidate = $visit->candidate;

        $result = DB::transaction(function () use ($candidate, $input, $request, $user) {
            Candidate::whereKey($candidate->id)->lockForUpdate()->first();
            $cv = CandidateCv::firstOrNew(['candidate_id' => $candidate->id]);
            $before = $cv->exists ? $cv->data : CandidateCv::emptyDoc();

            // الوثيقة تُغطّى لا تُستبدل: الاستقبال يصحّح سبعة حقول، والباقي
            // يبقى كما أدخلته الجهة — وإرسالُ وثيقةٍ كاملة من نموذجٍ جزئي
            // كان يمحو ما لم يُعرَض على الشاشة أصلاً
            $merged = array_merge($before, $input);

            try {
                $clean = app(CvValidator::class)->clean($merged);
            } catch (CvTooLargeException $e) {
                return 'too_large';
            } catch (ValidationException $e) {
                return ['invalid' => $e->errors()];
            }

            // الاستقبال ليس معفىً من فحص التسرّب: المستشار يقرأ هذه الوثيقة
            // بلا اسم، واسمٌ يتسلّل في «المنصب» يهدم إخفاء الهوية كلَّه
            if ($hit = CvGuard::directIdentifierHit($clean, $candidate)) {
                return ['leak' => $hit];
            }

            // ── الفرق يُقاس بين مُطبَّعَين ──
            // `clean` تُسوّي الوثيقة: تُكمل المفاتيح الغائبة، وتُجرّد التشكيل،
            // وتُرتّب عناصر المصفوفات. فمقارنة المحفوظ الخام بالمُنظَّف تُظهر
            // التطبيع تغييراً، فيُقيَّد إصدارٌ لحفظٍ لم يمسّه أحد — و**يُنقَض
            // اعتمادٌ قائم** بلا سبب. والمقصود ما غيّره الموظّف لا ما سوّاه
            // المدقّق. ووثيقةٌ قديمة تأبى التنظيف تسقط على مفاتيح الوثيقة
            // الفارغة — تطبيعٌ أخفّ خيرٌ من مقارنةٍ كاذبة.
            try {
                $baseline = app(CvValidator::class)->clean($before);
            } catch (\Throwable $e) {
                $baseline = array_merge(CandidateCv::emptyDoc(), $before);
            }

            $version = ($cv->version ?? 0) + 1;
            $cv->data = $clean;
            $cv->version = $version;
            $cv->source = 'reception';
            $cv->updated_by = $user->id;
            $cv->save();

            CandidateCvRevision::record(
                $candidate, $baseline, $clean, $version, 'reception', $user->id,
                $request->input('note')
            );

            return ['version' => $version, 'changed' => CandidateCvRevision::diffKeys($baseline, $clean)];
        });

        if ($result === 'too_large') {
            return response()->json(['error' => 'عناصر أكثر من المسموح'], 413);
        }
        if (isset($result['invalid'])) {
            return response()->json(['error' => 'بيانات غير صحيحة', 'fields' => $result['invalid']], 422);
        }
        if (isset($result['leak'])) {
            return response()->json([
                'error' => 'السيرة تحوي اسم المشارك أو معرّفاً — أزِله',
                'field' => $result['leak'],
            ], 422);
        }

        // ── والتصحيح ينقض الاعتماد ──
        // اعتمادٌ يبقى بعد تغيير ما اعتُمد يشهد على نصٍّ لم يُقرأ.
        $wasApproved = $visit->cv_approved_at !== null;
        if ($wasApproved && $result['changed']) {
            $visit->update(['cv_approved_at' => null, 'cv_approved_by' => null]);
        }

        $this->log($request, 'RECEPTION_CV_UPDATE', $visit->id, [
            'code' => $visit->assessment?->participant_code,
            'version' => $result['version'],
            'changed' => $result['changed'],
            'unapproved' => $wasApproved && (bool) $result['changed'],
        ]);

        return response()->json([
            'message' => $result['changed'] ? 'حُفظ التصحيح' : 'لا تغيير',
            'version' => $result['version'],
            'changed' => $result['changed'],
            'cvApproved' => $visit->fresh()->cv_approved_at !== null,
        ]);
    }

    // POST /reception/visits/{id}/cv/approve — البوّابة
    public function approveCv(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_CV_APPROVE)) {
            return $this->deny('ليس لديك صلاحية اعتماد السيرة عند الاستقبال');
        }

        $visit = $this->findVisit($request, $id, ['candidate.cv', 'assessment']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        if ($visit->cv_approved_at !== null) {
            return response()->json(['error' => 'السيرة معتمدة من قبل'], 422);
        }

        // ── سيرةٌ فارغة لا تُعتمَد ──
        // الاعتماد شهادةٌ أنّ ما فيها صحيح، ولا شهادة على فراغ. والمستشار
        // يستقبل ورقةً بيضاء ولا يعرف أهي كذلك أم انقطع شيء في الطريق.
        $doc = $visit->candidate->cv?->data;
        if ($doc === null || CandidateCv::isEmptyDoc($doc)) {
            return response()->json([
                'error' => 'السيرة فارغة — صحّحها مع المشارك قبل اعتمادها',
            ], 422);
        }

        $visit->update([
            'cv_approved_at' => now(),
            'cv_approved_by' => $user->id,
        ]);

        $this->log($request, 'RECEPTION_CV_APPROVE', $visit->id, [
            'code' => $visit->assessment?->participant_code,
            'version' => $visit->candidate->cv?->version,
        ]);

        return response()->json([
            'approved' => true,
            'approvedAt' => $visit->cv_approved_at->format('H:i'),
        ]);
    }

    // GET /reception/visits/{id}/cv/revisions — ما تغيّر ومن غيّره
    public function cvRevisions(Request $request, int $id)
    {
        if (! $this->canReadVisitCv($request->user())) {
            return $this->deny('ليس لديك صلاحية قراءة السيرة');
        }

        $visit = $this->findVisit($request, $id, ['candidate']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }

        // الوثائق نفسها لا تُرسَل — السطر يقول ماذا تغيّر ومن ومتى، والوثيقة
        // الحيّة تُقرأ من مسارها. إرسال كل إصدارٍ كاملاً يضاعف سطح التسرّب
        // بلا حاجةٍ يعرفها أحد.
        $rows = $visit->candidate->cvRevisions()->with('createdBy:id,full_name')->limit(50)->get()
            ->map(fn (CandidateCvRevision $r) => [
                'version' => $r->version,
                'changed' => $r->changed_fields ?? [],
                'changedLabels' => $r->changedLabels(),
                'source' => $r->source,
                'note' => $r->note,
                'by' => $r->createdBy?->full_name,
                'at' => $r->created_at?->format('Y-m-d H:i'),
            ]);

        return response()->json(['revisions' => $rows]);
    }

    public function assign(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_ASSIGN)) {
            return $this->deny('ليس لديك صلاحية توزيع المشاركين');
        }

        $validated = $request->validate([
            'activity' => 'required|in:'.implode(',', ReceptionAssignment::ACTIVITIES),
            'evaluatorId' => 'required|integer',
        ]);

        $visit = $this->findVisit($request, $id, ['assignments', 'assessment', 'candidate']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        if ($visit->status === ReceptionVisit::APPROVED) {
            return response()->json(['error' => 'الزيارة معتمدة — أعد فتحها قبل التوزيع'], 422);
        }
        // التوزيع بعد الإقرار لا قبله: توزيعُ مشاركٍ لم يُقرّ بصحّة بياناته
        // يُدخِل المقيّم على بياناتٍ لم يُصادَق عليها
        if (! $visit->isSigned()) {
            return response()->json(['error' => 'لم يوقّع المشارك ولم يُقرّ بصحّة بياناته بعد'], 422);
        }
        if ($visit->activeAssignment($validated['activity'])) {
            return response()->json(['error' => 'هذا النشاط مُسنَد بالفعل — اسحب الإسناد القائم أولاً'], 422);
        }

        $evaluator = User::with('role')->where('is_active', true)->find($validated['evaluatorId']);
        if (! $evaluator) {
            return response()->json(['error' => 'المقيّم غير موجود أو غير مفعّل'], 404);
        }
        $roles = ReceptionAssignment::ACTIVITY_ROLES[$validated['activity']];
        if (! $evaluator->role || ! in_array($evaluator->role->code, $roles, true)) {
            return response()->json([
                'error' => 'المقيّم المختار لا يمارس '.ReceptionAssignment::label($validated['activity']),
            ], 422);
        }
        if (! $evaluator->hasPermission(Permissions::RECEPTION_DECIDE)) {
            return response()->json(['error' => 'المقيّم المختار لا يملك صلاحية استلام المشاركين'], 422);
        }
        // حدّ القطاع: مقيّم محصور لا يُسنَد إليه مشارك من قطاع آخر إلا بصلاحية
        // التجاوز الصريحة — نفس قاعدة الجدولة، لا قاعدة جديدة
        if (! $evaluator->coversSector($visit->candidate->sector_id)
            && ! $user->hasPermission(Permissions::CROSS_SECTOR_ASSIGN)) {
            return response()->json(['error' => 'المقيّم من قطاع آخر — يلزم صلاحية الإسناد عبر القطاعات'], 422);
        }

        $assignment = DB::transaction(function () use ($visit, $validated, $user) {
            $a = ReceptionAssignment::create([
                'visit_id' => $visit->id,
                'activity' => $validated['activity'],
                'evaluator_id' => $validated['evaluatorId'],
                'status' => ReceptionAssignment::PENDING,
                'assigned_by' => $user->id,
            ]);
            if ($visit->status === ReceptionVisit::ARRIVED) {
                $visit->update(['status' => ReceptionVisit::DISTRIBUTED]);
            }

            return $a;
        });

        // الإشعار خارج المعاملة: عنوانه يحمل الرمز لا الاسم
        $code = $visit->assessment?->participant_code ?? '—';
        $this->notify->notify(
            $evaluator->id,
            'action',
            'مشارك مُسنَد إليك: '.$code,
            'أُسنِد إليك '.ReceptionAssignment::label($validated['activity'])
                .' للمشارك '.$code.'. افتح شاشة استقبال الموظفين للاستلام أو الردّ.',
            'reception_assignment',
            (string) $assignment->id,
            $user->id,
        );

        $this->log($request, 'RECEPTION_ASSIGN', $assignment->id, [
            'visit' => $visit->id,
            'activity' => $validated['activity'],
            'evaluator' => $evaluator->id,
        ]);

        return response()->json(['assignmentId' => $assignment->id], 201);
    }

    // ── سحب إسناد لم يُبتّ فيه ──
    /**
     * سحب الإسناد — **بسببٍ مكتوب دائماً**.
     *
     * ── لماذا صار السبب إلزامياً ──
     * التبديل يقع بلا موافقة أحد: «الاستقبال يبدّل بلا موافقة، ويُدوَّن
     * السبب». فالسببُ هو كلُّ ما يبقى من القرار. وسحبٌ صامت يجعل مسؤول
     * الجدولة يرى مستشاراً تغيّر ولا يعرف أمريضٌ كان أم ردّ المشارك أم
     * انشغل — وهي ثلاثةُ أحوالٍ يُبنى على كلٍّ منها إجراءٌ مختلف.
     *
     * ── ولماذا يُسحب المستلَم أيضاً ──
     * كان السحب مقصوراً على المعلّق، والبتُّ مقصوراً على المعلّق كذلك. فمتى
     * استلم المستشارُ المشاركَ ثم غاب أو انشغل، لم يكن للحال مخرجٌ البتّة:
     * لا يُسحب منه ولا يردّه — والمشارك واقفٌ في الردهة. وهي أكثر حالات
     * التبديل وقوعاً يوم التنفيذ.
     *
     * ── والجلسة المُرحَّلة تتبع التبديل ──
     * مسار الاستقبال كان موازياً لجدول الجلسات لا محرِّراً له: إسنادٌ رُحّل
     * ثم سُحب كان يترك جلسته باسم المستشار القديم. فالجدول يقول شيئاً
     * والاستقبال يقول غيره، ويُبنى على المتناقضين حضورٌ وتقييم.
     */
    public function withdraw(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_ASSIGN)) {
            return $this->deny('ليس لديك صلاحية سحب الإسناد');
        }

        $assignment = $this->findAssignment($request, $id, ['visit.assessment', 'evaluator']);
        if (! $assignment) {
            return response()->json(['error' => 'الإسناد غير موجود'], 404);
        }
        // المردود انتهى أمرُه — سحبُه لا يعني شيئاً، وسببُه مكتوبٌ أصلاً
        if ($assignment->status === ReceptionAssignment::REJECTED) {
            return response()->json(['error' => 'الإسناد مردودٌ أصلاً — أسنِده لغيره'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:300',
        ], [
            'reason.required' => 'اكتب سبب السحب — التبديل يقع بلا موافقة، فالسببُ كلُّ ما يبقى منه',
        ]);

        $wasAccepted = $assignment->status === ReceptionAssignment::ACCEPTED;
        $scheduleId = $assignment->schedule_id;

        DB::transaction(function () use ($assignment, $scheduleId) {
            // جلسةٌ رُحّلت باسم هذا المستشار تُخلى منه: تركُها تجعل الجدول
            // يقول شيئاً والاستقبال يقول غيره
            if ($scheduleId) {
                Schedule::whereKey($scheduleId)->update(['evaluator_id' => null]);
            }
            $assignment->delete();
        });

        $this->log($request, 'RECEPTION_WITHDRAW', $id, [
            'visit' => $assignment->visit_id,
            'code' => $assignment->visit?->assessment?->participant_code,
            'activity' => $assignment->activity,
            'from' => $assignment->evaluator?->full_name,
            'wasAccepted' => $wasAccepted,
            'scheduleCleared' => $scheduleId,
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'withdrawn' => true,
            'wasAccepted' => $wasAccepted,
            'scheduleCleared' => $scheduleId !== null,
        ]);
    }

    private function findAssignment(Request $request, int $id, array $with = []): ?ReceptionAssignment
    {
        $user = $request->user();

        return ReceptionAssignment::with($with)
            ->whereHas('visit.candidate', function ($c) use ($request, $user) {
                $c->whereIn('classification', $this->allowedClassifications($request));
                if ($user->isSectorBound()) {
                    $c->whereIn('sector_id', $user->sectorIds());
                }
            })
            ->find($id);
    }

    // ═══════════════════════════════════════════════════════
    //  ٤) قرار المقيّم: استلام أو ردّ
    // ═══════════════════════════════════════════════════════
    public function accept(Request $request, int $id)
    {
        return $this->decide($request, $id, ReceptionAssignment::ACCEPTED, null);
    }

    public function reject(Request $request, int $id)
    {
        // الصلاحية قبل التحقّق من المدخلات: التحقّق أولاً يردّ على غير المُصرَّح
        // له بقواعد الحقول (٤٢٢ مفصَّلة) فيتعلّم شكل المسار قبل أن يُمنع منه
        if (! $request->user()->hasPermission(Permissions::RECEPTION_DECIDE)) {
            return $this->deny('ليس لديك صلاحية البتّ في الإسناد');
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ], [
            'reason.required' => 'اذكر سبب الردّ — العمليات تبني عليه إعادة الإسناد',
        ]);

        return $this->decide($request, $id, ReceptionAssignment::REJECTED, $validated['reason']);
    }

    private function decide(Request $request, int $id, string $status, ?string $reason)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_DECIDE)) {
            return $this->deny('ليس لديك صلاحية البتّ في الإسناد');
        }

        $assignment = $this->findAssignment($request, $id, ['visit.assessment']);
        // صاحب الإسناد وحده يبتّ فيه — لا أحد يقبل نيابةً عن غيره.
        // 404 لا 403: إسناد غيره ليس شأنه فلا يُعلَم بوجوده.
        if (! $assignment || $assignment->evaluator_id !== $user->id) {
            return response()->json(['error' => 'الإسناد غير موجود'], 404);
        }
        if ($assignment->status !== ReceptionAssignment::PENDING) {
            return response()->json(['error' => 'بُتّ في هذا الإسناد من قبل'], 422);
        }

        $assignment->update([
            'status' => $status,
            'reject_reason' => $reason,
            'decided_at' => now(),
        ]);

        $code = $assignment->visit?->assessment?->participant_code ?? '—';
        $label = ReceptionAssignment::label($assignment->activity);

        if ($status === ReceptionAssignment::REJECTED) {
            // المردود يعود إلى **من يستطيع إعادة إسناده** لا إلى رمز دورٍ بعينه:
            // مركزٌ لم يُنشئ دور «مسؤول العمليات» كان الإشعار يذهب فيه إلى لا
            // أحد، فيقف المشارك في منتصف المسار بلا أن يعلم به أحد.
            $reached = $this->notify->notifyPermission(
                Permissions::RECEPTION_ASSIGN,
                'return',
                'ردّ إسناد: '.$code,
                'ردّ '.$user->full_name.' '.$label.' للمشارك '.$code
                    .'. السبب: '.$reason.' — أعد إسناده لمقيّم آخر أو لنشاط آخر.',
                'reception_visit',
                (string) $assignment->visit_id,
                $user->id,
                $user->id,
            );
            // صفرُ متلقّين حدثٌ يستحقّ أثراً: المشارك مردود ولا أحد يعلم
            if ($reached === 0) {
                $this->log($request, 'RECEPTION_REJECT_UNROUTED', $id, [
                    'visit' => $assignment->visit_id,
                    'note' => 'لا مستخدم نشط يملك صلاحية إعادة الإسناد',
                ]);
            }
        }

        $this->log($request, $status === ReceptionAssignment::ACCEPTED
            ? 'RECEPTION_ACCEPT' : 'RECEPTION_REJECT', $id, ['visit' => $assignment->visit_id]);

        return response()->json(['status' => $status]);
    }

    // ── السيرة كما يراها المقيّم بعد الاستلام ──
    //
    // لا اسم ولا رقم هوية هنا مهما كانت صلاحية القارئ — بخلاف مسار الإدارة.
    // القاعدة في هذا المسار قاعدة إجراء لا قاعدة صلاحية: من استلم مشاركاً
    // يقيّمه برمزه، وإتاحة الاسم لمدير التقييم (وهو مؤهَّل للمقابلة) كانت
    // ستفتح باباً لمعرفة من يقابل قبل أن يقابله.
    public function assignmentCv(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_DECIDE)) {
            return $this->deny('ليس لديك صلاحية عرض سيرة المُسنَد إليك');
        }

        $assignment = $this->findAssignment($request, $id, ['visit.candidate.cv', 'visit.assessment']);
        if (! $assignment || $assignment->evaluator_id !== $user->id) {
            return response()->json(['error' => 'الإسناد غير موجود'], 404);
        }
        // السيرة تُفتح بعد الاستلام لا قبله: القرار على الرمز والنشاط، لا على
        // محتوى سيرةٍ يُطّلَع عليها ثم تُردّ
        if ($assignment->status !== ReceptionAssignment::ACCEPTED) {
            return response()->json(['error' => 'استلم المشارك أولاً لعرض سيرته'], 422);
        }

        $visit = $assignment->visit;
        $doc = $visit->assessment?->cv_snapshot ?? $visit->candidate->cv?->data ?? CandidateCv::emptyDoc();
        $doc = CvGuard::scrub($doc, $visit->candidate);   // دائماً، بلا شرط

        $this->log($request, 'RECEPTION_EVAL_VIEW_CV', $id, [
            'code' => $visit->assessment?->participant_code,
        ]);

        return response()->json(['cv' => [
            'participantCode' => $visit->assessment?->participant_code,
            'activityLabel' => ReceptionAssignment::label($assignment->activity),
            'hasCv' => ! CandidateCv::isEmptyDoc($doc),
            'document' => $doc,
            // لا يُرسَل أبداً: الاسم، رقم الهوية، الجوال، البريد، معرّف المشارك
        ]]);
    }

    // ═══════════════════════════════════════════════════════
    //  ٦) اعتماد العمليات — ترحيل المستلَم إلى جدول الجلسات
    // ═══════════════════════════════════════════════════════
    public function approve(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_APPROVE)) {
            return $this->deny('ليس لديك صلاحية اعتماد بيانات الاستقبال');
        }

        $visit = $this->findVisit($request, $id, ['assignments', 'assessment']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        if ($visit->status === ReceptionVisit::APPROVED) {
            return response()->json(['error' => 'الزيارة معتمدة من قبل'], 422);
        }
        if (! $visit->isSigned()) {
            return response()->json(['error' => 'لم يوقّع المشارك ولم يُقرّ بصحّة بياناته'], 422);
        }
        // ── وسيرةٌ لم تُعتمَد لا تُرسَل للمستشار ──
        // الاعتماد هو البوّابة: ما يصل المستشار قرأه موظّفٌ وأقرّه مع صاحبه.
        if ($visit->cv_approved_at === null) {
            return response()->json([
                'error' => 'لم تُعتمَد السيرة بعد — راجِعها مع المشارك ثم اعتمِدها',
            ], 422);
        }

        $accepted = $visit->assignments->where('status', ReceptionAssignment::ACCEPTED);
        if ($accepted->isEmpty()) {
            return response()->json(['error' => 'لا إسناد مستلَم بعد — لا شيء يُرحَّل'], 422);
        }
        // إسنادٌ معلّق يعني قراراً لم يُتّخذ: الاعتماد الآن يُسقطه صامتاً
        $pending = $visit->assignments->where('status', ReceptionAssignment::PENDING);
        if ($pending->isNotEmpty()) {
            return response()->json([
                'error' => 'بقي إسناد بانتظار قرار المقيّم — انتظر البتّ أو اسحب الإسناد',
            ], 422);
        }

        $created = $this->routeVisit($visit, $user);
        $code = $visit->assessment?->participant_code ?? '—';

        $this->log($request, 'RECEPTION_APPROVE', $visit->id, [
            'code' => $code, 'schedules' => $created,
        ]);

        return response()->json(['approved' => true, 'schedulesCreated' => $created]);
    }

    // ═══════════════════════════════════════════════════════
    //  إرسال قوائم اليوم للمستشارين — دفعةً واحدة
    // ═══════════════════════════════════════════════════════
    //
    //  الموظّف يسجّل الكلّ أوّلاً، ثم يُرسل مرّةً واحدة. والزرّ لا يُخفي ما
    //  عجز عنه: يعود بأسماء من لم يُرسَل وبسبب كلٍّ منهم، فيُعالَج ما بقي
    //  ويُعاد الضغط. إرسالٌ صامت يترك مشاركاً واقفاً بلا مستشارٍ ينتظره.

    // POST /reception/send — يعتمد ويُرحّل ويُجمّد كلَّ من اكتمل
    public function send(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_APPROVE)) {
            return $this->deny('ليس لديك صلاحية اعتماد بيانات الاستقبال');
        }

        $validated = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $validated['date'] ?? now()->toDateString();

        $visits = ReceptionVisit::with(['assignments', 'assessment', 'candidate.cv'])
            ->whereDate('visit_date', $date)
            ->where('status', '!=', ReceptionVisit::APPROVED)
            ->whereHas('candidate', function ($c) use ($request, $user) {
                $c->whereIn('classification', $this->allowedClassifications($request));
                if ($user->isSectorBound()) {
                    $c->whereIn('sector_id', $user->sectorIds());
                }
            })
            ->get();

        $sent = 0;
        $created = 0;
        $blocked = [];

        foreach ($visits as $visit) {
            if ($reason = $this->sendBlocker($visit)) {
                $blocked[] = [
                    'visitId' => $visit->id,
                    'code' => $visit->assessment?->participant_code ?? '—',
                    'reason' => $reason,
                ];

                continue;
            }

            $created += $this->routeVisit($visit, $user);
            $sent++;
        }

        $this->log($request, 'RECEPTION_SEND_BATCH', $date, [
            'sent' => $sent, 'schedules' => $created, 'blocked' => count($blocked),
        ]);

        return response()->json([
            'sent' => $sent,
            'schedulesCreated' => $created,
            'blocked' => $blocked,
            'message' => $sent
                ? "أُرسِل {$sent} مشاركاً للمستشارين"
                : 'لا مشارك جاهزاً للإرسال',
        ]);
    }

    // ما يمنع إرسال هذه الزيارة — أو null إن كانت جاهزة
    private function sendBlocker(ReceptionVisit $visit): ?string
    {
        if (! $visit->isSigned()) {
            return 'لم يوقّع ولم يُقرّ';
        }
        if ($visit->cv_approved_at === null) {
            return 'لم تُعتمَد سيرته';
        }
        if ($visit->assignments->where('status', ReceptionAssignment::PENDING)->isNotEmpty()) {
            return 'إسنادٌ بانتظار قرار المستشار';
        }
        if ($visit->assignments->where('status', ReceptionAssignment::ACCEPTED)->isEmpty()) {
            return 'لا إسناد مستلَم';
        }

        return null;
    }

    /**
     * ترحيل زيارةٍ واحدة: جلساتٌ للمقبول، وتجميدٌ للسيرة، وختمُ إرسال.
     *
     * مشتركةٌ بين `approve` (زيارةً زيارة) و`send` (دفعةً واحدة) — نسختان من
     * الترحيل تتفرّعان عند أوّل تعديل، فتُرسِل إحداهما ما لا تُرسله الأخرى.
     */
    private function routeVisit(ReceptionVisit $visit, User $user): int
    {
        $accepted = $visit->assignments->where('status', ReceptionAssignment::ACCEPTED);

        $created = DB::transaction(function () use ($visit, $accepted, $user) {
            $n = 0;
            foreach ($accepted as $a) {
                if ($a->schedule_id) {
                    continue;
                }
                $schedule = Schedule::create([
                    'candidate_id' => $visit->candidate_id,
                    'assessment_id' => $visit->assessment_id,
                    'period_id' => SchedulingPeriod::coveringDate(
                        $visit->visit_date instanceof \DateTimeInterface
                            ? $visit->visit_date->format('Y-m-d')
                            : (string) $visit->visit_date
                    )?->id,
                    'schedule_date' => $visit->visit_date,
                    'activity' => $a->activity,
                    'evaluator_id' => $a->evaluator_id,
                ]);
                $a->update(['schedule_id' => $schedule->id]);
                Assessment::refreshDatesFor($visit->assessment_id);
                $n++;
            }

            $visit->assessment?->loadMissing('candidate.cv');
            $visit->assessment?->freezeCvSnapshot();

            $visit->update([
                'status' => ReceptionVisit::APPROVED,
                'approved_at' => now(),
                'approved_by' => $user->id,
                'sent_at' => now(),
                'sent_by' => $user->id,
            ]);

            return $n;
        });

        $code = $visit->assessment?->participant_code ?? '—';
        foreach ($accepted as $a) {
            if (! $a->evaluator_id) {
                continue;
            }
            $this->notify->notify(
                $a->evaluator_id,
                'info',
                'اعتُمد ورُحّل: '.$code,
                'اعتُمدت بيانات المشارك '.$code.' ورُحّلت '.ReceptionAssignment::label($a->activity)
                    .' إلى جدول اليوم.',
                'reception_visit',
                (string) $visit->id,
                $user->id,
            );
        }

        return $created;
    }

    // ═══════════════════════════════════════════════════════
    //  كشك الجهاز اللوحي — الرابط الذي يفتحه مسؤول المشاركين
    // ═══════════════════════════════════════════════════════

    // إنشاء رمز اليوم يفرض RECEPTION_RECORD لا RECEPTION_VIEW: الرمز يُنتج
    // بابَ تسجيلِ وصولٍ وتوقيع، فلا يصدره من لا يملك أن يفعلهما بيده.
    public function createKiosk(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تشغيل كشك الاستقبال');
        }
        if (! config('features.reception_kiosk')) {
            return response()->json(['error' => 'كشك الاستقبال غير مُفعَّل'], 422);
        }

        $validated = $request->validate(['label' => 'nullable|string|max:60']);

        $kiosk = ReceptionKiosk::create([
            'token' => ReceptionKiosk::generateToken(),
            'kiosk_date' => now()->toDateString(),
            'label' => $validated['label'] ?? null,
            'created_by' => $user->id,
        ]);

        // الرمز يُسجَّل في التدقيق بمعرّفه لا بقيمته: قيمةُ رمزٍ حيّ في سجلٍّ
        // يقرؤه غيرُ مُصدره تجعل السجلَّ نسخةً ثانية من المفتاح
        $this->log($request, 'KIOSK_CREATE', $kiosk->id, ['label' => $kiosk->label]);

        return response()->json(['kiosk' => $this->kioskPayload($kiosk)], 201);
    }

    // كشوك اليوم الفعّالة — لعرض الرابط ثانيةً دون إصدار رمزٍ جديد.
    // إصدارُ رمزٍ في كل مرة يُبطل الجهاز العامل في البهو بلا سبب.
    public function kiosks(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تشغيل كشك الاستقبال');
        }

        $rows = ReceptionKiosk::with('creator')
            ->whereDate('kiosk_date', now()->toDateString())
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ReceptionKiosk $k) => $this->kioskPayload($k));

        return response()->json([
            'enabled' => (bool) config('features.reception_kiosk'),
            'kiosks' => $rows,
        ]);
    }

    public function revokeKiosk(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تشغيل كشك الاستقبال');
        }

        $kiosk = ReceptionKiosk::find($id);
        if (! $kiosk) {
            return response()->json(['error' => 'الكشك غير موجود'], 404);
        }
        if ($kiosk->revoked_at === null) {
            $kiosk->update(['revoked_at' => now()]);
            $this->log($request, 'KIOSK_REVOKE', $kiosk->id);
        }

        return response()->json(['revoked' => true]);
    }

    private function kioskPayload(ReceptionKiosk $k): array
    {
        return [
            'id' => $k->id,
            'label' => $k->label,
            'date' => $k->kiosk_date->toDateString(),
            // الرابط كاملاً: يُنسخ أو يُقرأ رمزاً مربّعاً على الجهاز اللوحي
            'url' => rtrim(config('app.frontend_url'), '/').'/kiosk/'.$k->token,
            'createdBy' => $k->creator?->full_name,
            'lastUsedAt' => $k->last_used_at?->format('H:i'),
        ];
    }

    // ═══════════════════════════════════════════════════════
    //  طابور طباعة البطاقات — ما طلبه الكشك ولم يُطبع بعد
    // ═══════════════════════════════════════════════════════
    public function printQueue(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية طباعة بطاقات المشاركين');
        }

        $validated = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $validated['date'] ?? now()->toDateString();

        $rows = ReceptionVisit::with(['candidate.sector', 'assessment.schedules'])
            ->whereDate('visit_date', $date)
            ->whereNotNull('badge_requested_at')
            ->whereNull('badge_printed_at')
            ->whereHas('candidate', function ($c) use ($request, $user) {
                $c->whereIn('classification', $this->allowedClassifications($request));
                if ($user->isSectorBound()) {
                    $c->whereIn('sector_id', $user->sectorIds());
                }
            })
            ->orderBy('badge_requested_at')   // ترتيب الطابور هو ترتيب الوصول
            ->get();

        return response()->json([
            'date' => $date,
            'queue' => $rows->map(fn (ReceptionVisit $v) => $this->badgePayload($v))->values(),
        ]);
    }

    // تعليم البطاقة مطبوعة — يُنادى بعد فتح نافذة الطباعة على جهاز المسؤول.
    // ليس دليلاً على خروج الورقة من الطابعة، بل على أن المسؤول تولّاها:
    // البطاقة التي لم تُطبع فعلاً تُعاد من زرّ إعادة الطباعة في الكشف.
    public function markBadgePrinted(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية طباعة بطاقات المشاركين');
        }

        $visit = $this->findVisit($request, $id, ['assessment']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        // ── والاعتماد يسبق البطاقة ──
        // البطاقة تُخرِج المشارك من المكتب إلى المحطّات، وبها يُعرَف عند
        // المستشار. طبعُها قبل مراجعة سيرته يُرسله وقد بقي في ورقته خطأ.
        if ($visit->cv_approved_at === null) {
            return response()->json([
                'error' => 'اعتمِد السيرة أوّلاً — البطاقة تُطبع بعد المراجعة',
            ], 422);
        }

        $visit->update(['badge_printed_at' => now(), 'badge_printed_by' => $user->id]);
        $this->log($request, 'RECEPTION_BADGE_PRINTED', $visit->id, [
            'code' => $visit->assessment?->participant_code,
        ]);

        return response()->json(['printed' => true]);
    }

    // إعادة الطباعة: تُعيد الزيارة إلى الطابور. بابها جهاز المسؤول وحده —
    // لو فُتح للكشك لأمكن لمشاركٍ أن يُخرج بطاقاتٍ بلا حدّ.
    public function reprintBadge(Request $request, int $id)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية طباعة بطاقات المشاركين');
        }

        $visit = $this->findVisit($request, $id, ['assessment']);
        if (! $visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }

        // ── وإعادة الطباعة تحترم ما تحترمه الطباعة الأولى ──
        // كانت تُدخل الزيارة الطابورَ بلا فحص، فيخرج طريقٌ ثانٍ إلى بطاقةٍ
        // لمن لم يوقّع ولم تُعتمَد سيرتُه — والباب الأمامي مقفلٌ عليهما.
        if (! $visit->isSigned()) {
            return response()->json(['error' => 'لم يوقّع المشارك ولم يُقرّ بصحّة بياناته'], 422);
        }
        if ($visit->cv_approved_at === null) {
            return response()->json([
                'error' => 'اعتمِد السيرة أوّلاً — البطاقة تُطبع بعد المراجعة',
            ], 422);
        }

        $visit->update([
            'badge_requested_at' => now(),
            'badge_printed_at' => null,
            'badge_printed_by' => null,
        ]);
        $this->log($request, 'RECEPTION_BADGE_REPRINT', $visit->id, [
            'code' => $visit->assessment?->participant_code,
        ]);

        return response()->json(['queued' => true]);
    }

    // محتوى البطاقة — بلا اسم عمداً: تُبرَز في القاعة أمام المقيّمين،
    // والتقييم يجري دون معرفة الاسم. رمز المشارك هو هويتها.
    private function badgePayload(ReceptionVisit $v): array
    {
        $a = $v->assessment;

        return [
            'visitId' => $v->id,
            'participantCode' => $a?->participant_code,
            'sector' => $v->candidate?->sector?->name_ar,
            'assessmentType' => Assessment::typeLabel($a?->assessment_type),
            'requestedAt' => $v->badge_requested_at?->format('H:i'),
            // الزرّ يُخفى قبل الاعتماد بدل أن يُضغط فيُردّ — الردّ عند
            // الطابعة والمشارك واقفٌ ليس مكان اكتشاف القاعدة
            'cvApproved' => $v->cv_approved_at !== null,
            'schedules' => collect($a?->schedules ?? [])
                ->sortBy(fn ($s) => substr((string) $s->schedule_date, 0, 10).' '.$s->schedule_time)
                ->values()
                ->map(fn ($s) => [
                    'time' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null,
                    'activity' => ReceptionAssignment::label($s->activity),
                    'location' => $s->location,
                ])->all(),
        ];
    }
}
