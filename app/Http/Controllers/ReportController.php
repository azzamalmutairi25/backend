<?php

namespace App\Http\Controllers;

use App\Models\FinalReport;
use App\Models\Candidate;
use App\Models\MeasurementResult;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Services\NotificationService;
use App\Services\ScoringService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    // ════════════════════════════════════════════════════════
    //  سلسلة اعتماد التقرير
    //    مسودة → المقيّم → مدير التقييم → تطوير الكفاءات → معتمد
    //
    //  المرحلة تُشتقّ من حالة التقرير لا من دور المستخدم — فالدور لا
    //  يحدّد ماذا يعتمد، بل الحالة تحدّد من يملك اعتمادها.
    // ════════════════════════════════════════════════════════
    private const STAGES = [
        'pending_evaluator' => [
            'perm' => Permissions::REPORT_APPROVE_EVALUATOR,
            'owner' => 'EVALUATOR',          // من يعتمد هذه المرحلة (لإشعاره عند وصولها)
            'next' => 'pending_manager',
            'label' => 'اعتماد المقيّم',
        ],
        'pending_manager' => [
            'perm' => Permissions::REPORT_APPROVE_MANAGER,
            'owner' => 'ASSESS_MANAGER',
            'next' => 'pending_dev_approval',
            'label' => 'اعتماد مدير إدارة التقييم',
        ],
        'pending_dev_approval' => [
            'perm' => Permissions::REPORT_APPROVE,
            'owner' => 'DEV_MANAGER',
            'next' => 'approved',            // نهاية السلسلة — يُبلَّغ كاتب التقرير لا مرحلة تالية
            'label' => 'الاعتماد النهائي',
        ],
    ];

    public const PENDING_STATUSES = ['pending_evaluator', 'pending_manager', 'pending_dev_approval'];

    private const FIRST_STAGE = 'pending_evaluator';

    public function __construct(
        private NotificationService $notify,
        private ScoringService $scoring,
    ) {}

    // GET /reports/score-preview?candidateId= — توافق مُحتسَب آلياً + تفصيل الكفاءات (لتعبئة التقرير)
    public function scorePreview(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إنشاء تقرير'], 403);
        }
        $validated = $request->validate(['candidateId' => 'required|integer']);
        $candidate = Candidate::whereIn('classification', $this->allowedClassifications($request))
            ->find($validated['candidateId']);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $assessment = $candidate->assessments()->orderByDesc('id')->first();
        if (!$assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم لهذا المرشح'], 422);
        }
        return response()->json($this->scoring->computeFit($assessment));
    }

    // GET /reports/competency-gap?candidateId= — الفجوة مقابل المستوى المطلوب لفئة المرشّح
    public function competencyGap(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقارير'], 403);
        }
        $validated = $request->validate(['candidateId' => 'required|integer']);
        $candidate = Candidate::whereIn('classification', $this->allowedClassifications($request))
            ->find($validated['candidateId']);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $assessment = $candidate->assessments()->orderByDesc('id')->first();
        if (!$assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم لهذا المرشح'], 422);
        }
        return response()->json($this->scoring->computeGap($assessment, $candidate->tier ?? 'middle'));
    }

    private function allowedClassifications(Request $request): array
    {
        $canSeeClassified = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED);
        return $canSeeClassified ? ['normal', 'secret', 'top_secret'] : ['normal'];
    }

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'report',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقارير'], 403);
        }

        $request->validate([
            'status' => 'nullable|string',
            'nationalId' => 'nullable|string|regex:/^\d{10}$/',
        ]);

        $query = FinalReport::with('candidate.sector')
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $this->allowedClassifications($request)));

        if ($request->filled('status')) {
            // «pending» تعني السلسلة كاملة — تطابق ما يعدّه مؤشّر «معلّق» في اللوحة،
            // وإلا فتح الرقمُ قائمةً أصغر منه
            $request->status === 'pending'
                ? $query->whereIn('status', self::PENDING_STATUSES)
                : $query->where('status', $request->status);
        }
        // البحث بالهوية عبر الهاش المشفّر (لا نكشف رقم الهوية)
        if ($request->filled('nationalId')) {
            $hash = hash('sha256', $request->nationalId);
            $query->whereHas('candidate', fn ($q) => $q->where('national_id_hash', $hash));
        }

        $userId = $request->user()->id;
        $canEditAny = $request->user()->hasPermission(Permissions::REPORT_EDIT_ANY);

        $reports = $query->orderByDesc('created_at')->get()->map(fn ($r) => [
            'id' => $r->id,
            'candidateId' => $r->candidate_id,
            'participantCode' => $r->candidate->participant_code,
            'sectorName' => $r->candidate->sector->name_ar,
            'tier' => $r->candidate->tier,
            'behavioralFit' => $r->behavioral_fit,
            'technicalFit' => $r->technical_fit,
            'recommendation' => $r->recommendation,
            'status' => $r->status,
            'returnCount' => $r->return_count,
            'createdAt' => $r->created_at,
            'canEdit' => $canEditAny || $r->created_by === $userId,
        ]);

        $this->log($request, 'VIEW_REPORTS', 0, ['count' => $reports->count()]);

        return response()->json(['reports' => $reports]);
    }

    public function stats(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقارير'], 403);
        }

        $allowed = $this->allowedClassifications($request);
        $base = FinalReport::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));

        return response()->json(['stats' => [
            'approved' => (clone $base)->where('status', 'approved')->count(),
            // «معلّق» = السلسلة كاملة لا مرحلتها الأخيرة — وإلا أخفى المؤشّر تقارير عالقة
            'pending' => (clone $base)->whereIn('status', self::PENDING_STATUSES)->count(),
            'draft' => (clone $base)->where('status', 'draft')->count(),
            'returned' => (clone $base)->where('status', 'returned')->count(),
            // تفصيل المراحل — يُظهر أين تتكدّس التقارير فعلاً
            'pendingEvaluator' => (clone $base)->where('status', 'pending_evaluator')->count(),
            'pendingManager' => (clone $base)->where('status', 'pending_manager')->count(),
            'pendingDev' => (clone $base)->where('status', 'pending_dev_approval')->count(),
        ]]);
    }

    // مرشحون جاهزون لكتابة تقرير: انتهى تقييمهم ولا تقرير لدورتهم الحالية
    public function eligibleCandidates(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إنشاء تقرير'], 403);
        }
        $allowed = $this->allowedClassifications($request);
        // تحميل مُسبق للدورات (مرتّبة) وتقاريرها — يتفادى N+1 (استعلامان بدل استعلامين لكل مرشح)
        $candidates = Candidate::with(['sector', 'assessments' => fn ($q) => $q->orderByDesc('id'), 'assessments.report'])
            ->whereIn('classification', $allowed)
            ->where('status', 'assessed')
            ->get()
            ->filter(function ($c) {
                $a = $c->assessments->first(); // الأحدث (مرتّبة تنازلياً)
                return $a && !$a->report;
            })
            ->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->participant_code,
                'sectorName' => optional($c->sector)->name_ar,
                'tier' => $c->tier,
            ])->values();

        return response()->json(['candidates' => $candidates]);
    }

    // بيانات تقرير كاملة للتحرير
    public function show(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقرير'], 403);
        }
        $r = FinalReport::with('candidate.sector')->findOrFail($id);
        // مصنّف خارج صلاحية المستخدم يُعامَل كـ«غير موجود» (لا نكشف وجوده)
        if (!in_array($r->candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }
        return response()->json(['report' => [
            'id' => $r->id,
            'participantCode' => $r->candidate->participant_code,
            'sectorName' => optional($r->candidate->sector)->name_ar,
            'tier' => $r->candidate->tier,
            'status' => $r->status,
            'behavioralFit' => $r->behavioral_fit !== null ? (float) $r->behavioral_fit : null,
            'technicalFit' => $r->technical_fit !== null ? (float) $r->technical_fit : null,
            'recommendation' => $r->recommendation,
            'overviewText' => $r->overview_text,
            'strengths' => $this->toList($r->strengths),
            'developmentAreas' => $this->toList($r->development_areas),
            'returnReason' => $r->return_reason,
        ]]);
    }

    // قواعد التحقق المشتركة للإنشاء/التعديل
    private function reportRules(): array
    {
        return [
            'behavioralFit' => 'nullable|numeric|min:0|max:100',
            'technicalFit' => 'nullable|numeric|min:0|max:100',
            'recommendation' => 'required|string|max:120',
            'overviewText' => 'nullable|string|max:5000',
            'strengths' => 'nullable|array|max:20',
            'strengths.*' => 'string|max:500',
            'developmentAreas' => 'nullable|array|max:20',
            'developmentAreas.*' => 'string|max:500',
            'submit' => 'boolean',
        ];
    }

    private function toList($v): array
    {
        if (is_array($v)) return array_values(array_filter($v, fn ($x) => trim((string) $x) !== ''));
        if (is_string($v) && $v !== '') return [$v];
        return [];
    }

    // من يعدّل التقرير: مؤلّفه، أو من يملك تعديل تقارير الغير (مدير التقييم)
    private function canEditReport(Request $request, FinalReport $report): bool
    {
        return $report->created_by === $request->user()->id
            || $request->user()->hasPermission(Permissions::REPORT_EDIT_ANY);
    }

    // إنشاء تقرير لدورة المرشح الحالية
    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إنشاء تقرير'], 403);
        }
        $validated = $request->validate($this->reportRules() + [
            'candidateId' => 'required|integer',
        ]);

        // حلّ المرشح ضمن صلاحية التصنيف فقط — نفس ردّ «غير موجود» للمصنّف وغير الموجود (لا كشف وجود)
        $candidate = Candidate::whereIn('classification', $this->allowedClassifications($request))
            ->find($validated['candidateId']);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $assessment = $candidate->assessments()->orderByDesc('id')->first();
        if (!$assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم لهذا المرشح'], 422);
        }
        // لا يُكتب تقرير قبل انتهاء التقييم فعلاً — منع تجاوز مسار التقييم بأكمله
        if ($candidate->status !== 'assessed' || $assessment->status !== 'assessed') {
            return response()->json(['error' => 'لا يمكن إنشاء تقرير قبل انتهاء تقييم المرشح'], 422);
        }
        if (FinalReport::where('assessment_id', $assessment->id)->exists()) {
            return response()->json(['error' => 'يوجد تقرير لهذه الدورة — استخدم التعديل'], 422);
        }

        $submit = (bool) ($validated['submit'] ?? false);
        // احتساب آلي من درجات الكفاءات؛ يُقبل التجاوز اليدوي إن أُرسلت القيمة صراحةً
        $computed = $this->scoring->computeFit($assessment);
        $behavioralFit = $validated['behavioralFit'] ?? $computed['behavioralFit'];
        $technicalFit = $validated['technicalFit'] ?? $computed['technicalFit'];
        try {
            $report = FinalReport::create([
                'candidate_id' => $candidate->id,
                'assessment_id' => $assessment->id,
                'behavioral_fit' => $behavioralFit,
                'technical_fit' => $technicalFit,
                'recommendation' => $validated['recommendation'],
                'overview_text' => $validated['overviewText'] ?? null,
                'strengths' => $this->toList($validated['strengths'] ?? []),
                'development_areas' => $this->toList($validated['developmentAreas'] ?? []),
                'status' => $submit ? self::FIRST_STAGE : 'draft',
                'created_by' => $request->user()->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // الفهرس الفريد حسم سباقاً متزامناً: أُنشئ تقرير للتوّ لهذه الدورة
            return response()->json(['error' => 'يوجد تقرير لهذه الدورة بالفعل'], 422);
        }

        if ($submit) {
            $this->notify->notifyRole('DEV_MANAGER', 'approval',
                'تقرير جديد بانتظار الاعتماد',
                "تقرير المرشح {$assessment->participant_code} بانتظار الاعتماد النهائي",
                'report', (string) $report->id, $request->user()->id);
        }
        $this->log($request, $submit ? 'CREATE_SUBMIT_REPORT' : 'CREATE_REPORT', $report->id,
            ['code' => $assessment->participant_code]);

        return response()->json([
            'message' => $submit ? 'تم إنشاء التقرير وإرساله للاعتماد' : 'تم حفظ التقرير كمسودة',
            'id' => $report->id,
        ], 201);
    }

    // تعديل تقرير (مسودة أو مُعاد) — مع إمكانية الإرسال للاعتماد
    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية تعديل التقرير'], 403);
        }
        $report = FinalReport::with('candidate', 'assessment')->findOrFail($id);
        if (!in_array($report->candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }
        if (!$this->canEditReport($request, $report)) {
            return response()->json(['error' => 'لا تملك صلاحية تعديل تقرير أنشأه غيرك'], 403);
        }
        if (!in_array($report->status, ['draft', 'returned'])) {
            return response()->json(['error' => 'لا يمكن تعديل تقرير في هذه الحالة'], 422);
        }
        $validated = $request->validate($this->reportRules());
        $submit = (bool) ($validated['submit'] ?? false);

        $report->update([
            'behavioral_fit' => $validated['behavioralFit'] ?? null,
            'technical_fit' => $validated['technicalFit'] ?? null,
            'recommendation' => $validated['recommendation'],
            'overview_text' => $validated['overviewText'] ?? null,
            'strengths' => $this->toList($validated['strengths'] ?? []),
            'development_areas' => $this->toList($validated['developmentAreas'] ?? []),
            'status' => $submit ? self::FIRST_STAGE : $report->status,
        ]);

        if ($submit) {
            $this->notify->notifyRole('DEV_MANAGER', 'approval',
                'تقرير معدّل بانتظار الاعتماد',
                "تقرير المرشح " . optional($report->assessment)->participant_code . " بانتظار الاعتماد",
                'report', (string) $report->id, $request->user()->id);
        }
        $this->log($request, $submit ? 'UPDATE_SUBMIT_REPORT' : 'UPDATE_REPORT', $report->id);

        return response()->json([
            'message' => $submit ? 'تم حفظ التعديلات وإرسال التقرير للاعتماد' : 'تم حفظ التعديلات',
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $report = FinalReport::with('candidate')->findOrFail($id);

        // بوابة التصنيف قبل أي إفصاح عن حالة التقرير
        if (!in_array($report->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_REPORT_CLASSIFIED', $id);
            return response()->json(['error' => 'هذا التقرير لمرشح مصنّف'], 403);
        }

        $stage = self::STAGES[$report->status] ?? null;
        if (!$stage) {
            return response()->json(['error' => 'لا يمكن اعتماد تقرير في هذه الحالة'], 422);
        }

        $user = $request->user();
        $skipped = false;

        $from = $report->status;

        if ($user->hasPermission($stage['perm'])) {
            $next = $stage['next'];
        } elseif ($from === self::FIRST_STAGE && $user->hasPermission(Permissions::REPORT_APPROVE_MANAGER)) {
            // تجاوز مقصود: مدير التقييم يعتمد مباشرة دون انتظار المقيّم.
            // يقفز إلى ما بعد مرحلته هو (لا إليها) — وإلا اعتمد المدير مرحلته مرتين.
            $next = self::STAGES['pending_manager']['next'];
            $skipped = true;
        } else {
            return response()->json(['error' => 'ليس لديك صلاحية اعتماد هذه المرحلة'], 403);
        }

        $report->update(['status' => $next, 'escalated_at' => null]);

        $final = $next === 'approved';
        if ($final) {
            // يزامن حالة الدورة → تُتاح إعادة التقييم بدورة جديدة
            $report->candidate->setStatus('completed');
        }

        $this->notifyStage($report, $final ? null : self::STAGES[$next]['owner'], $final, $user->id);

        $this->log($request, $skipped ? 'APPROVE_REPORT_SKIPPED_EVALUATOR' : 'APPROVE_REPORT', $id, [
            'candidate' => $report->candidate->participant_code,
            'stage' => $stage['label'],
            'from' => $from,
            'to' => $next,
        ]);

        return response()->json([
            'message' => $final ? 'تم اعتماد التقرير نهائياً' : 'تم الاعتماد — أُحيل للمرحلة التالية',
            'status' => $next,
            'skippedEvaluator' => $skipped,
        ]);
    }

    // إشعار المرحلة التالية، أو كاتب التقرير عند نهاية السلسلة
    private function notifyStage(FinalReport $report, ?string $roleCode, bool $final, int $actorId): void
    {
        $code = $report->candidate->participant_code;

        if ($final) {
            if ($report->created_by) {
                $this->notify->notify($report->created_by, 'report',
                    'اعتُمد التقرير نهائياً',
                    "اكتمل اعتماد تقرير المشارك {$code}",
                    'report', (string) $report->id, $actorId);
            }
            return;
        }

        if ($roleCode) {
            $this->notify->notifyRole($roleCode, 'approval',
                'تقرير بانتظار اعتمادك',
                "تقرير المشارك {$code} وصل مرحلتك",
                'report', (string) $report->id, $actorId);
        }
    }

    public function returnReport(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_RETURN)) {
            return response()->json(['error' => 'ليس لديك صلاحية الإرجاع'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'يجب ذكر سبب الإرجاع',
            'reason.min' => 'سبب الإرجاع قصير جداً',
        ]);

        $report = FinalReport::with('candidate')->findOrFail($id);

        if (!in_array($report->candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'هذا التقرير لمرشح مصنّف'], 403);
        }

        // لا يُرجَع إلا تقرير في إحدى مراحل الاعتماد (منع إرجاع معتمد/مسودة → إفساد حالة المرشح).
        // أي مرحلة تُرجع: من يستطيع حجب التقرير يستطيع ردّه لصاحبه.
        if (!in_array($report->status, self::PENDING_STATUSES, true)) {
            return response()->json(['error' => 'لا يمكن إرجاع تقرير غير مُرسل للاعتماد'], 422);
        }

        $report->update([
            'status' => 'returned',
            'return_reason' => $validated['reason'],
            'return_count' => $report->return_count + 1,
            'last_returned_by' => $request->user()->id,
            'last_returned_at' => now(),
        ]);

        if ($report->created_by) {
            $this->notify->notify($report->created_by, 'return',
                'أُعيد تقرير للتعديل',
                'سبب الإرجاع: ' . $validated['reason'],
                'report', (string) $id, $request->user()->id);
        }

        $thread = ChatThread::firstOrCreate(
            ['entity_type' => 'report', 'entity_id' => $id],
            ['title' => 'محادثة التقرير']
        );
        ChatMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $request->user()->id,
            'message' => 'أُعيد التقرير للتعديل. السبب: ' . $validated['reason'],
            'message_type' => 'action',
            'action_type' => 'return',
        ]);

        $this->log($request, 'RETURN_REPORT', $id, ['reason' => $validated['reason'], 'count' => $report->return_count]);

        return response()->json([
            'message' => 'تم إرجاع التقرير للتعديل',
            'returnCount' => $report->return_count,
        ]);
    }

    public function resubmit(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إعادة الإرسال'], 403);
        }

        $report = FinalReport::with('candidate')->findOrFail($id);
        // نفس بوابة التصنيف في بقية الإجراءات — كانت مفقودة هنا (مصنّف → «غير موجود»)
        if (!in_array($report->candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }
        if (!$this->canEditReport($request, $report)) {
            return response()->json(['error' => 'لا تملك صلاحية إرسال تقرير أنشأه غيرك'], 403);
        }
        if ($report->status !== 'returned') {
            // 422 كبقية حرّاس حالة هذا المتحكّم (store/update/approve/return) بدل 400 الشاذّ
            return response()->json(['error' => 'التقرير ليس في حالة إرجاع'], 422);
        }

        // يعود لأول السلسلة لا لآخرها: التعديل بعد الإرجاع يستحق مراجعة المراحل كلها
        // من جديد، وإلا مرّ تغييرٌ جوهري باعتماد مرحلة واحدة.
        // تصفير التصعيد: حالة تأخّر جديدة قد تتطلّب تصعيداً لاحقاً
        $report->update(['status' => self::FIRST_STAGE, 'escalated_at' => null]);

        $this->notify->notifyRole(self::STAGES[self::FIRST_STAGE]['owner'], 'approval',
            'تقرير معدّل بانتظار الاعتماد',
            'أُعيد إرسال تقرير بعد تعديله',
            'report', (string) $id, $request->user()->id);

        $this->log($request, 'RESUBMIT_REPORT', $id);

        return response()->json(['message' => 'تم إعادة إرسال التقرير']);
    }

    // GET /reports/{id}/document — مستند رسمي جاهز للطباعة (المتصفّح → PDF)
    public function document(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقرير'], 403);
        }
        $r = FinalReport::with(['candidate.sector', 'assessment'])->findOrFail($id);
        if (!in_array($r->candidate->classification, $this->allowedClassifications($request), true)) {
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }
        $canSeeNames = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        $fit = $this->scoring->computeFit($r->assessment);
        $measurement = MeasurementResult::where('assessment_id', $r->assessment_id)->first();
        $this->log($request, 'EXPORT_REPORT', $id, ['code' => $r->candidate->participant_code]);

        return response($this->renderDocument($r, $fit, $canSeeNames, $measurement), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // GET /reports/export — تصدير CSV (يفتحه Excel عربياً عبر BOM)
    public function exportCsv(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_EXPORT)) {
            return response()->json(['error' => 'ليس لديك صلاحية تصدير التقارير'], 403);
        }
        $allowed = $this->allowedClassifications($request);
        $reports = FinalReport::with('candidate.sector')
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')->get();

        $this->log($request, 'EXPORT_REPORTS', 0, ['count' => $reports->count()]);

        $out = "\xEF\xBB\xBF"; // BOM ليعرض Excel العربية صحيحةً
        $out .= "الرمز,القطاع,الفئة,الحالة,التوافق السلوكي,التوافق الفني,التوصية\n";
        foreach ($reports as $r) {
            $out .= implode(',', [
                $this->csv($r->candidate->participant_code),
                $this->csv(optional($r->candidate->sector)->name_ar),
                $this->csv($r->candidate->tier === 'upper' ? 'قيادة عليا' : 'قيادة وسطى'),
                $this->csv($this->statusLabel($r->status)),
                $r->behavioral_fit ?? '',
                $r->technical_fit ?? '',
                $this->csv($r->recommendation),
            ]) . "\n";
        }

        return response($out, 200)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="reports.csv"');
    }

    private function csv($v): string
    {
        $v = (string) $v;
        // تحييد حقن صيغ الجداول (CSV formula injection): قيمة تبدأ بمُشغّل صيغة تُسبَق بفاصلة عليا
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'" . $v;
        }
        if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    private function statusLabel(string $s): string
    {
        return [
            'draft' => 'مسودة',
            'pending_evaluator' => 'بانتظار اعتماد المقيّم',
            'pending_manager' => 'بانتظار اعتماد مدير التقييم',
            'pending_dev_approval' => 'بانتظار الاعتماد النهائي',
            'returned' => 'مُعاد للتعديل',
            'approved' => 'معتمد',
        ][$s] ?? $s;
    }

    private function typeLabel(string $t): string
    {
        return ['behavioral' => 'سلوكية', 'leadership' => 'قيادية', 'technical' => 'فنية'][$t] ?? $t;
    }

    private function renderDocument(FinalReport $r, array $fit, bool $canSeeNames, ?MeasurementResult $measurement = null): string
    {
        $name = e($canSeeNames ? ($r->candidate->full_name ?: $r->candidate->participant_code) : $r->candidate->participant_code);
        $code = e($r->candidate->participant_code);
        $sector = e(optional($r->candidate->sector)->name_ar ?? '—');
        $tier = $r->candidate->tier === 'upper' ? 'قيادة عليا' : 'قيادة وسطى';
        $status = $this->statusLabel($r->status);
        $beh = $r->behavioral_fit !== null ? (float) $r->behavioral_fit : null;
        $tech = $r->technical_fit !== null ? (float) $r->technical_fit : null;
        $rec = e($r->recommendation);
        $overview = e($r->overview_text ?? '');
        $date = now()->format('Y-m-d');

        $rows = '';
        foreach ($fit['breakdown'] as $b) {
            $rows .= '<tr><td>' . e($b['name']) . '</td><td>' . $this->typeLabel($b['type'])
                . '</td><td class="num">' . $b['avgScore'] . ' / ' . $b['maxLevel']
                . '</td><td class="num">' . $b['pct'] . '%</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">لا توجد درجات مُحتسَبة</td></tr>';
        }

        $li = fn ($items) => count($items)
            ? '<ul>' . implode('', array_map(fn ($x) => '<li>' . e($x) . '</li>', $items)) . '</ul>'
            : '<p class="muted">—</p>';
        $strengths = $li($r->strengths ?? []);
        $devAreas = $li($r->development_areas ?? []);

        $measHtml = '';
        if ($measurement && ($measurement->personality_score !== null
            || $measurement->analytical_score !== null || $measurement->english_score !== null)) {
            $mrow = fn ($label, $v) => '<tr><td>' . $label . '</td><td class="num">' . ($v === null ? '—' : $v) . '</td></tr>';
            $measHtml = '<h2>أدوات القياس</h2><table><tbody>'
                . $mrow('المقياس الشخصي', $measurement->personality_score)
                . $mrow('القدرات التحليلية', $measurement->analytical_score)
                . $mrow('اللغة الإنجليزية', $measurement->english_score)
                . '</tbody></table>';
        }

        $fitBox = function ($label, $val) {
            $v = $val === null ? '—' : $val . '%';
            $w = $val === null ? 0 : max(0, min(100, $val));
            return '<div class="fit"><div class="fit-h"><span>' . $label . '</span><b>' . $v . '</b></div>'
                . '<div class="bar"><div class="bar-f" style="width:' . $w . '%"></div></div></div>';
        };
        $behBox = $fitBox('التوافق السلوكي/القيادي', $beh);
        $techBox = $fitBox('التوافق الفني', $tech);

        return <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>التقرير النهائي — {$code}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI","Noto Naskh Arabic",Tahoma,sans-serif; color:#1a2420; margin:0; background:#f0f2ef; }
  .sheet { max-width: 820px; margin: 24px auto; background:#fff; padding: 40px 46px; box-shadow: 0 2px 20px rgba(0,0,0,.08); }
  .print-bar { max-width:820px; margin: 16px auto 0; text-align:left; }
  .print-bar button { font: inherit; padding: 8px 18px; border:0; border-radius:8px; background:#1f6b4a; color:#fff; cursor:pointer; }
  .hd { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #1f6b4a; padding-bottom:16px; }
  .hd .org { font-weight:800; font-size:20px; color:#1f6b4a; }
  .hd .sub { color:#5b6a62; font-size:13px; margin-top:4px; }
  .hd .meta { text-align:left; font-size:13px; color:#5b6a62; }
  h1 { font-size:22px; margin:26px 0 4px; }
  .status { display:inline-block; font-size:12px; padding:3px 12px; border-radius:999px; background:#e5f0ea; color:#1f6b4a; font-weight:700; }
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:8px 28px; margin:18px 0; font-size:14px; }
  .grid .k { color:#5b6a62; } .grid .v { font-weight:700; }
  .fits { display:flex; gap:20px; margin:22px 0; }
  .fit { flex:1; } .fit-h { display:flex; justify-content:space-between; font-size:14px; margin-bottom:6px; } .fit-h b{ color:#1f6b4a; }
  .bar { height:10px; background:#e8ece9; border-radius:6px; overflow:hidden; } .bar-f { height:100%; background:#1f6b4a; }
  h2 { font-size:15px; margin:24px 0 8px; color:#1f6b4a; border-right:4px solid #1f6b4a; padding-right:10px; }
  table { width:100%; border-collapse:collapse; font-size:13.5px; }
  th,td { text-align:right; padding:8px 10px; border-bottom:1px solid #e8ece9; }
  th { color:#5b6a62; font-size:12px; background:#f6f8f6; } td.num { text-align:left; font-variant-numeric:tabular-nums; }
  .rec { background:#f6f8f6; border-radius:10px; padding:14px 16px; font-weight:700; font-size:15px; }
  ul { margin:6px 0; padding-inline-start:20px; } li { margin:3px 0; font-size:14px; }
  .muted { color:#8a978f; }
  .sign { display:flex; justify-content:space-between; margin-top:44px; gap:24px; }
  .sign div { flex:1; text-align:center; font-size:13px; color:#5b6a62; }
  .sign .line { margin-top:44px; border-top:1px solid #b9c4bd; padding-top:6px; }
  @media print { body{ background:#fff; } .sheet{ box-shadow:none; margin:0; max-width:none; } .print-bar{ display:none; } @page{ margin:14mm; } }
</style></head>
<body>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
<div class="sheet">
  <div class="hd">
    <div><div class="org">مركز تمكين الكفاءات لتقييم القيادات</div><div class="sub">التقرير النهائي لتقييم الكفاءات</div></div>
    <div class="meta">رمز المشارك: <b>{$code}</b><br>تاريخ الإصدار: {$date}</div>
  </div>

  <h1>{$name}</h1>
  <span class="status">{$status}</span>

  <div class="grid">
    <div><span class="k">القطاع:</span> <span class="v">{$sector}</span></div>
    <div><span class="k">الفئة القيادية:</span> <span class="v">{$tier}</span></div>
  </div>

  <div class="fits">
    {$behBox}
    {$techBox}
  </div>

  <h2>التوصية</h2>
  <div class="rec">{$rec}</div>

  <h2>نظرة عامة</h2>
  <p>{$overview}</p>

  <h2>تفصيل الكفاءات</h2>
  <table><thead><tr><th>الكفاءة</th><th>النوع</th><th>المتوسط</th><th>النسبة</th></tr></thead>
  <tbody>{$rows}</tbody></table>

  {$measHtml}

  <h2>مواطن القوة</h2>
  {$strengths}
  <h2>مجالات التطوير</h2>
  {$devAreas}

  <div class="sign">
    <div><div class="line">المُقيّم</div></div>
    <div><div class="line">مدير إدارة التقييم</div></div>
    <div><div class="line">إدارة تطوير الكفاءات</div></div>
  </div>
</div>
</body></html>
HTML;
    }
}
