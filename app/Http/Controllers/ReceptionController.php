<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\CandidateCv;
use App\Models\ReceptionAssignment;
use App\Models\ReceptionKiosk;
use App\Models\ReceptionVisit;
use App\Models\Schedule;
use App\Models\User;
use App\Security\Permissions;
use App\Services\CvGuard;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function __construct(private NotificationService $notify)
    {
    }

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
                    $q->where('sector_id', $user->sector_id);
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
        if (!$user->hasPermission(Permissions::RECEPTION_VIEW)) {
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
                'candidate.sector', 'candidate.cv', 'assessment',
                'assignments.evaluator',
            ])
                ->whereDate('visit_date', $date)
                ->whereHas('candidate', function ($c) use ($request, $user) {
                    $c->whereIn('classification', $this->allowedClassifications($request));
                    if ($user->isSectorBound()) $c->where('sector_id', $user->sector_id);
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
            'signed' => $v->isSigned(),
            'attested' => $v->attested,
            'status' => $v->status,
            // من سجّل نفسه على الكشك مقابل من سجّله موظّف — يظهر في الكشف
            // كي يعرف الاستقبال من مرّ عليه فعلاً ومن دخل من الجهاز اللوحي
            'viaKiosk' => $v->kiosk_id !== null,
            'badgePrinted' => $v->badge_printed_at !== null,
            'badgePending' => $v->badgePending(),
            'hasCv' => $doc !== null && !CandidateCv::isEmptyDoc($doc),
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

    // الدورات المنتظَرة اليوم — لم تصل بعد
    private function expectedList(Request $request, string $date, string $q, array $can): array
    {
        $user = $request->user();

        $arrived = ReceptionVisit::whereDate('visit_date', $date)->pluck('assessment_id');

        $query = Assessment::with('candidate.sector')
            ->whereNotIn('id', $arrived)
            // المنتهية لا تُستقبل — دورة اكتملت ليست موعداً قادماً
            ->whereNotIn('status', ['completed'])
            ->whereHas('candidate', function ($c) use ($request, $user) {
                $c->whereIn('classification', $this->allowedClassifications($request));
                if ($user->isSectorBound()) $c->where('sector_id', $user->sector_id);
            });

        // البحث بالرمز على الخادم (الاسم مشفَّر فلا يُبحث فيه بـSQL)
        if ($q !== '') {
            $query->where('participant_code', 'ilike', '%' . $q . '%');
        }

        // حدٌّ صريح: الكشف أداة استقبالٍ لا تصفّحٌ لقاعدة المشاركين كاملة.
        // العدد الكلّي يُرسَل مع المقتطَع — قائمةٌ مقصوصة صامتة تُقرأ «هذا كل
        // من ينتظر»، فيُصرَف مشاركٌ حاضرٌ لأنه لم يظهر في الشاشة.
        $total = (clone $query)->count();
        $rows = $query->orderBy('participant_code')->limit(self::EXPECTED_LIMIT)->get();

        return [
            'total' => $total,
            'shown' => $rows->count(),
            'rows' => $rows->map(fn (Assessment $a) => [
                'assessmentId' => $a->id,
                'participantCode' => $a->participant_code,
                'name' => $can['viewNames'] ? $a->candidate?->full_name : null,
                'sector' => $a->candidate?->sector?->name_ar,
                'rank' => $a->candidate?->rank_label,
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
        if (!$user->hasPermission(Permissions::RECEPTION_ASSIGN)) {
            return $this->deny('ليس لديك صلاحية توزيع المشاركين');
        }

        $validated = $request->validate([
            'activity' => 'required|in:' . implode(',', ReceptionAssignment::ACTIVITIES),
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
            ->filter(fn (User $u) => !isset($validated['sectorId'])
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
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تسجيل وصول المشاركين');
        }

        $validated = $request->validate([
            'assessmentId' => 'required|integer',
            'date' => 'nullable|date_format:Y-m-d',
        ]);
        $date = $validated['date'] ?? now()->toDateString();

        $assessment = Assessment::with('candidate')->find($validated['assessmentId']);
        if (!$assessment || !$this->resolveCandidateInScope($request, $assessment->candidate_id)) {
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
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تعديل وقت الوصول');
        }

        $validated = $request->validate(['arrivedAt' => 'required|date_format:H:i']);

        $visit = $this->findVisit($request, $id);
        if (!$visit) {
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
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
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
        if (!$visit) {
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
        if (!$this->canReadVisitCv($user)) {
            return $this->deny('ليس لديك صلاحية عرض السيرة الذاتية');
        }

        $visit = $this->findVisit($request, $id, ['candidate.cv', 'assessment']);
        if (!$visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }

        $doc = $visit->assessment?->cv_snapshot ?? $visit->candidate->cv?->data ?? CandidateCv::emptyDoc();
        $canSeeNames = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        if (!$canSeeNames) {
            $doc = CvGuard::scrub($doc, $visit->candidate);
        }

        $this->log($request, 'RECEPTION_VIEW_CV', $visit->id);

        return response()->json(['cv' => [
            'participantCode' => $visit->assessment?->participant_code,
            'name' => $canSeeNames ? $visit->candidate->full_name : null,
            'rank' => $visit->candidate->rank_label,
            'sector' => $visit->candidate->sector?->name_ar,
            'hasCv' => !CandidateCv::isEmptyDoc($doc),
            'document' => $doc,
        ]]);
    }

    // ═══════════════════════════════════════════════════════
    //  ٣) التوزيع على نشاط ومقيّم
    // ═══════════════════════════════════════════════════════
    public function assign(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::RECEPTION_ASSIGN)) {
            return $this->deny('ليس لديك صلاحية توزيع المشاركين');
        }

        $validated = $request->validate([
            'activity' => 'required|in:' . implode(',', ReceptionAssignment::ACTIVITIES),
            'evaluatorId' => 'required|integer',
        ]);

        $visit = $this->findVisit($request, $id, ['assignments', 'assessment', 'candidate']);
        if (!$visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        if ($visit->status === ReceptionVisit::APPROVED) {
            return response()->json(['error' => 'الزيارة معتمدة — أعد فتحها قبل التوزيع'], 422);
        }
        // التوزيع بعد الإقرار لا قبله: توزيعُ مشاركٍ لم يُقرّ بصحّة بياناته
        // يُدخِل المقيّم على بياناتٍ لم يُصادَق عليها
        if (!$visit->isSigned()) {
            return response()->json(['error' => 'لم يوقّع المشارك ولم يُقرّ بصحّة بياناته بعد'], 422);
        }
        if ($visit->activeAssignment($validated['activity'])) {
            return response()->json(['error' => 'هذا النشاط مُسنَد بالفعل — اسحب الإسناد القائم أولاً'], 422);
        }

        $evaluator = User::with('role')->where('is_active', true)->find($validated['evaluatorId']);
        if (!$evaluator) {
            return response()->json(['error' => 'المقيّم غير موجود أو غير مفعّل'], 404);
        }
        $roles = ReceptionAssignment::ACTIVITY_ROLES[$validated['activity']];
        if (!$evaluator->role || !in_array($evaluator->role->code, $roles, true)) {
            return response()->json([
                'error' => 'المقيّم المختار لا يمارس ' . ReceptionAssignment::label($validated['activity']),
            ], 422);
        }
        if (!$evaluator->hasPermission(Permissions::RECEPTION_DECIDE)) {
            return response()->json(['error' => 'المقيّم المختار لا يملك صلاحية استلام المشاركين'], 422);
        }
        // حدّ القطاع: مقيّم محصور لا يُسنَد إليه مشارك من قطاع آخر إلا بصلاحية
        // التجاوز الصريحة — نفس قاعدة الجدولة، لا قاعدة جديدة
        if (!$evaluator->coversSector($visit->candidate->sector_id)
            && !$user->hasPermission(Permissions::CROSS_SECTOR_ASSIGN)) {
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
            'مشارك مُسنَد إليك: ' . $code,
            'أُسنِد إليك ' . ReceptionAssignment::label($validated['activity'])
                . ' للمشارك ' . $code . '. افتح شاشة استقبال الموظفين للاستلام أو الردّ.',
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
    public function withdraw(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::RECEPTION_ASSIGN)) {
            return $this->deny('ليس لديك صلاحية سحب الإسناد');
        }

        $assignment = $this->findAssignment($request, $id);
        if (!$assignment) {
            return response()->json(['error' => 'الإسناد غير موجود'], 404);
        }
        // المستلَم لا يُسحب من تحت المقيّم — يُردّ منه أو يُعتمد
        if ($assignment->status !== ReceptionAssignment::PENDING) {
            return response()->json(['error' => 'لا يُسحب إسنادٌ بُتّ فيه'], 422);
        }

        $assignment->delete();
        $this->log($request, 'RECEPTION_WITHDRAW', $id, ['visit' => $assignment->visit_id]);

        return response()->json(['withdrawn' => true]);
    }

    private function findAssignment(Request $request, int $id, array $with = []): ?ReceptionAssignment
    {
        $user = $request->user();

        return ReceptionAssignment::with($with)
            ->whereHas('visit.candidate', function ($c) use ($request, $user) {
                $c->whereIn('classification', $this->allowedClassifications($request));
                if ($user->isSectorBound()) $c->where('sector_id', $user->sector_id);
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
        if (!$request->user()->hasPermission(Permissions::RECEPTION_DECIDE)) {
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
        if (!$user->hasPermission(Permissions::RECEPTION_DECIDE)) {
            return $this->deny('ليس لديك صلاحية البتّ في الإسناد');
        }

        $assignment = $this->findAssignment($request, $id, ['visit.assessment']);
        // صاحب الإسناد وحده يبتّ فيه — لا أحد يقبل نيابةً عن غيره.
        // 404 لا 403: إسناد غيره ليس شأنه فلا يُعلَم بوجوده.
        if (!$assignment || $assignment->evaluator_id !== $user->id) {
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
                'ردّ إسناد: ' . $code,
                'ردّ ' . $user->full_name . ' ' . $label . ' للمشارك ' . $code
                    . '. السبب: ' . $reason . ' — أعد إسناده لمقيّم آخر أو لنشاط آخر.',
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
        if (!$user->hasPermission(Permissions::RECEPTION_DECIDE)) {
            return $this->deny('ليس لديك صلاحية عرض سيرة المُسنَد إليك');
        }

        $assignment = $this->findAssignment($request, $id, ['visit.candidate.cv', 'visit.assessment']);
        if (!$assignment || $assignment->evaluator_id !== $user->id) {
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
            'hasCv' => !CandidateCv::isEmptyDoc($doc),
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
        if (!$user->hasPermission(Permissions::RECEPTION_APPROVE)) {
            return $this->deny('ليس لديك صلاحية اعتماد بيانات الاستقبال');
        }

        $visit = $this->findVisit($request, $id, ['assignments', 'assessment']);
        if (!$visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
        }
        if ($visit->status === ReceptionVisit::APPROVED) {
            return response()->json(['error' => 'الزيارة معتمدة من قبل'], 422);
        }
        if (!$visit->isSigned()) {
            return response()->json(['error' => 'لم يوقّع المشارك ولم يُقرّ بصحّة بياناته'], 422);
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

        $created = DB::transaction(function () use ($visit, $accepted, $user) {
            $n = 0;
            foreach ($accepted as $a) {
                if ($a->schedule_id) continue;   // مُرحَّل من قبل — لا تكرار
                $schedule = Schedule::create([
                    'candidate_id' => $visit->candidate_id,
                    'assessment_id' => $visit->assessment_id,
                    'schedule_date' => $visit->visit_date,
                    'activity' => $a->activity,
                    'evaluator_id' => $a->evaluator_id,
                ]);
                $a->update(['schedule_id' => $schedule->id]);
                \App\Models\Assessment::refreshDatesFor($visit->assessment_id);
                $n++;
            }
            $visit->update([
                'status' => ReceptionVisit::APPROVED,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);
            return $n;
        });

        $code = $visit->assessment?->participant_code ?? '—';
        foreach ($accepted as $a) {
            if (!$a->evaluator_id) continue;
            $this->notify->notify(
                $a->evaluator_id,
                'info',
                'اعتُمد ورُحّل: ' . $code,
                'اعتُمدت بيانات المشارك ' . $code . ' ورُحّلت ' . ReceptionAssignment::label($a->activity)
                    . ' إلى جدول اليوم.',
                'reception_visit',
                (string) $visit->id,
                $user->id,
            );
        }

        $this->log($request, 'RECEPTION_APPROVE', $visit->id, [
            'code' => $code, 'schedules' => $created,
        ]);

        return response()->json(['approved' => true, 'schedulesCreated' => $created]);
    }

    // ═══════════════════════════════════════════════════════
    //  كشك الجهاز اللوحي — الرابط الذي يفتحه مسؤول المشاركين
    // ═══════════════════════════════════════════════════════

    // إنشاء رمز اليوم يفرض RECEPTION_RECORD لا RECEPTION_VIEW: الرمز يُنتج
    // بابَ تسجيلِ وصولٍ وتوقيع، فلا يصدره من لا يملك أن يفعلهما بيده.
    public function createKiosk(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تشغيل كشك الاستقبال');
        }
        if (!config('features.reception_kiosk')) {
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
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
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
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية تشغيل كشك الاستقبال');
        }

        $kiosk = ReceptionKiosk::find($id);
        if (!$kiosk) {
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
            'url' => rtrim(config('app.frontend_url'), '/') . '/kiosk/' . $k->token,
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
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
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
                if ($user->isSectorBound()) $c->where('sector_id', $user->sector_id);
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
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية طباعة بطاقات المشاركين');
        }

        $visit = $this->findVisit($request, $id, ['assessment']);
        if (!$visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
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
        if (!$user->hasPermission(Permissions::RECEPTION_RECORD)) {
            return $this->deny('ليس لديك صلاحية طباعة بطاقات المشاركين');
        }

        $visit = $this->findVisit($request, $id, ['assessment']);
        if (!$visit) {
            return response()->json(['error' => 'الزيارة غير موجودة'], 404);
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
            'schedules' => collect($a?->schedules ?? [])
                ->sortBy(fn ($s) => substr((string) $s->schedule_date, 0, 10) . ' ' . $s->schedule_time)
                ->values()
                ->map(fn ($s) => [
                    'time' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null,
                    'activity' => ReceptionAssignment::label($s->activity),
                    'location' => $s->location,
                ])->all(),
        ];
    }
}
