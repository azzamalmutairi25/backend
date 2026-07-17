<?php

namespace App\Http\Controllers;

use App\Models\FinalReport;
use App\Models\Candidate;
use App\Models\MeasurementResult;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Security\Permissions;
use App\Services\NotificationService;
use App\Services\ScoringService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    // ════════════════════════════════════════════════════════
    //  سلسلة اعتماد التقرير — تُقرأ من workflow_stages لا من الكود،
    //  فيعيد المشرف ترتيبها ويُفعّل/يُعطّل مراحلها من الشاشة.
    //
    //  المرحلة تُشتقّ من حالة التقرير لا من دور المستخدم — فالدور لا
    //  يحدّد ماذا يعتمد، بل الحالة تحدّد من يملك اعتمادها.
    // ════════════════════════════════════════════════════════

    // كل حالة «قيد الاعتماد» — تشمل مراحل مُعطّلة فيها تقارير عالقة،
    // وإلا اختفت تلك التقارير من الإحصاء والفلاتر وهي قائمة.
    // دالّة لا ثابت: السلسلة بيانات تتغيّر في الطلب نفسه.
    public static function pendingStatuses(): array
    {
        return WorkflowStage::pendingStatuses();
    }

    private function firstStage(): ?WorkflowStage
    {
        return WorkflowStage::firstStage();
    }

    // ── التجاوز: صاحب المرحلة التالية مباشرةً يعتمد من المرحلة الحالية ──
    // كان محفوراً كـ«مدير التقييم يتجاوز المقيّم»؛ صار مشتقّاً من الترتيب فيصمد
    // مهما أُعيدت السلسلة من الشاشة.
    //
    // «التالية مباشرةً» لا «أي لاحقة»: التعميم الأوسع يجعل صاحب الاعتماد النهائي
    // يقفز السلسلة كلها من أولها — وهو نقضٌ لمعنى السلسلة. يُتخطّى طرفٌ واحد،
    // ومن يليه لا يزال يعتمد.
    private function maySkipTo($user, WorkflowStage $current): ?WorkflowStage
    {
        $chain = WorkflowStage::chain();
        $i = $chain->search(fn ($s) => $s->status_key === $current->status_key);
        if ($i === false) {
            return null;
        }
        $next = $chain->get($i + 1);

        return $next && $user->hasPermission($next->permission) ? $next : null;
    }

    // ── قواعد المرحلة على كاتب التقرير ──
    // إعدادان على workflow_stages لا شرطان محفوران، فيُبدَّلان من الشاشة:
    //   blocks_self_authored     — الكاتب لا يعتمد مرحلته («من يكتب لا يعتمد»)
    //   requires_team_authorship — الكاتب يجب أن يكون من فريق المعتمِد
    // يرجع رسالة المنع أو null.
    private function stageRuleError(WorkflowStage $stage, FinalReport $report, $user): ?string
    {
        if ($stage->blocks_self_authored && $report->created_by === $user->id) {
            return 'لا يمكنك اعتماد تقرير كتبته بنفسك — يعتمده صاحب صلاحية أخرى';
        }

        if ($stage->requires_team_authorship) {
            $author = $report->created_by ? User::with('role')->find($report->created_by) : null;
            if (!$author) {
                // تقرير بلا كاتب معروف لا يُنسب لفريق — لا يُعتمد بقاعدة الفريق
                return 'تعذّر تحديد كاتب التقرير — لا يمكن اعتماده بقاعدة الفريق';
            }
            if ($author->manager_id !== $user->id) {
                return 'هذا التقرير كتبه من ليس ضمن فريقك';
            }
        }

        return null;
    }

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
        // النطاق كاملاً — لا يُكتب/يُقرأ تقرير لمرشّح خارج قطاع المستخدم.
        // eligibleCandidates محصور، فكان يُخفي المرشّح ثم يقبله بمعرّفه.
        $candidate = $this->resolveCandidateInScope($request, $validated['candidateId']);
        if (!$candidate || $this->evaluatorNarrowedOut($request, $candidate)) {
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
        // النطاق كاملاً — لا يُكتب/يُقرأ تقرير لمرشّح خارج قطاع المستخدم.
        // eligibleCandidates محصور، فكان يُخفي المرشّح ثم يقبله بمعرّفه.
        $candidate = $this->resolveCandidateInScope($request, $validated['candidateId']);
        if (!$candidate || $this->evaluatorNarrowedOut($request, $candidate)) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $assessment = $candidate->assessments()->orderByDesc('id')->first();
        if (!$assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم لهذا المرشح'], 422);
        }
        return response()->json($this->scoring->computeGap($assessment, $candidate->tier ?? 'middle'));
    }



    // ── حلّ تقرير ضمن نطاق المستخدم ──
    // مسارات الكتابة (approve/return/resubmit) كانت تستعمل findOrFail ثم تفحص
    // التصنيف وحده، بينما القراءة محصورة بالقطاع والملكية — فكان مقيّم قطاعٍ
    // يعتمد ويُرجع تقارير قطاعٍ آخر لا تظهر له في القائمة أصلاً.
    private function resolveReportInScope(Request $request, int $id, array $with = ['candidate']): ?FinalReport
    {
        $q = FinalReport::with($with);
        $this->scopeReports($request, $q); // يشمل التصنيف والقطاع والملكية

        return $q->find($id);
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
            'sectorId' => 'nullable|integer',
            'tier' => 'nullable|in:upper,middle',
            'recommendation' => 'nullable|string|max:120',
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date',
        ]);

        $query = FinalReport::with('candidate.sector');
        $this->scopeReports($request, $query);

        $this->applyReportFilters($request, $query);

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

        $base = FinalReport::query();
        // نفس نطاق index — وإلا عدّ المؤشّر تقارير لا تظهر في القائمة
        $this->scopeReports($request, $base);

        return response()->json(['stats' => [
            'approved' => (clone $base)->where('status', 'approved')->count(),
            // «معلّق» = السلسلة كاملة لا مرحلتها الأخيرة — وإلا أخفى المؤشّر تقارير عالقة
            'pending' => (clone $base)->whereIn('status', self::pendingStatuses())->count(),
            'draft' => (clone $base)->where('status', 'draft')->count(),
            'returned' => (clone $base)->where('status', 'returned')->count(),
            // تفصيل المراحل — يُظهر أين تتكدّس التقارير فعلاً
            'pendingEvaluator' => (clone $base)->where('status', 'pending_evaluator')->count(),
            'pendingManager' => (clone $base)->where('status', 'pending_manager')->count(),
            'pendingDev' => (clone $base)->where('status', 'pending_dev_approval')->count(),
        ]]);
    }

    // فلاتر مشتركة بين القائمة والتحليلات — كي تطابق الرسومُ القائمةَ المفلترة
    private function applyReportFilters(Request $request, $query): void
    {
        if ($request->filled('status')) {
            // «pending» = السلسلة كاملة (يطابق مؤشّر «معلّق»)
            $request->status === 'pending'
                ? $query->whereIn('status', self::pendingStatuses())
                : $query->where('status', $request->status);
        }
        if ($request->filled('nationalId')) {
            $hash = hash('sha256', $request->nationalId);
            $query->whereHas('candidate', fn ($q) => $q->where('national_id_hash', $hash));
        }
        // فوق النطاق المفروض — لا توسّعه (القطاع المحصور يبقى محصوراً)
        if ($request->filled('sectorId')) {
            $query->whereHas('candidate', fn ($q) => $q->where('sector_id', $request->sectorId));
        }
        if ($request->filled('tier')) {
            $query->whereHas('candidate', fn ($q) => $q->where('tier', $request->tier));
        }
        if ($request->filled('recommendation')) {
            $query->where('recommendation', $request->recommendation);
        }
        if ($request->filled('dateFrom')) {
            $query->whereDate('created_at', '>=', $request->dateFrom);
        }
        if ($request->filled('dateTo')) {
            $query->whereDate('created_at', '<=', $request->dateTo);
        }
    }

    // GET /reports/analytics — تجميعات مشهد التقارير للرسوم البيانية (بنفس النطاق والفلاتر)
    public function analytics(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقارير'], 403);
        }

        $base = FinalReport::query();
        $this->scopeReports($request, $base);
        $this->applyReportFilters($request, $base); // تطابق فلاتر القائمة
        $reports = (clone $base)->with('candidate.sector')->get();

        // توزيع التوصية
        $byRecommendation = $reports->groupBy('recommendation')
            ->map(fn ($g, $k) => ['label' => $k ?: 'بلا توصية', 'value' => $g->count()])
            ->values();

        // توزيع الفئة القيادية
        $byTier = collect(['upper' => 'قيادة عليا', 'middle' => 'قيادة وسطى'])->map(fn ($label, $t) => [
            'label' => $label,
            'value' => $reports->filter(fn ($r) => optional($r->candidate)->tier === $t)->count(),
        ])->values();

        // متوسط قيمة قد تكون null (لا تقييم) — نحفظ null بدل قسرها إلى 0.0 المضلِّل
        $avg = fn ($col, $set) => ($v = $set->avg($col)) === null ? null : round((float) $v, 1);

        // متوسط التوافق حسب القطاع (للتقارير المعتمدة)
        $approved = $reports->where('status', 'approved');
        $bySector = $approved->groupBy(fn ($r) => optional($r->candidate->sector)->name_ar ?? '—')
            ->map(fn ($g, $name) => [
                'label' => $name,
                'behavioral' => $avg('behavioral_fit', $g),
                'technical' => $avg('technical_fit', $g),
                'count' => $g->count(),
            ])->values();

        return response()->json(['analytics' => [
            'total' => $reports->count(),
            'approved' => $approved->count(),
            'avgBehavioral' => $avg('behavioral_fit', $approved),
            'avgTechnical' => $avg('technical_fit', $approved),
            'byRecommendation' => $byRecommendation,
            'byTier' => $byTier,
            'bySector' => $bySector,
        ]]);
    }

    // مرشحون جاهزون لكتابة تقرير: انتهى تقييمهم ولا تقرير لدورتهم الحالية
    public function eligibleCandidates(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إنشاء تقرير'], 403);
        }
        $allowed = $this->allowedClassifications($request);
        $user = $request->user();
        // تحميل مُسبق للدورات (مرتّبة) وتقاريرها — يتفادى N+1 (استعلامان بدل استعلامين لكل مرشح)
        $candidates = Candidate::with(['sector', 'assessments' => fn ($q) => $q->orderByDesc('id'), 'assessments.report'])
            ->whereIn('classification', $allowed)
            // المحصور بقطاع لا يكتب تقريراً لمرشّح من قطاع آخر — القائمة تطابق ما يُسمح به
            ->when($user->isSectorBound(), fn ($q) => $q->where('sector_id', $user->sector_id))
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
        // النطاق داخل الاستعلام لا بعده: تقرير خارج نطاق المستخدم لا يُحلّ أصلاً،
        // فلا يفرّق الردّ بين «غير موجود» و«موجود وليس لك» — ولا يصير المعرّف كاشفاً
        $q = FinalReport::with('candidate.sector');
        $this->scopeReports($request, $q);

        $r = $q->find($id);
        if (!$r) {
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
            'executiveSummary' => $r->executive_summary,
            'canEditExecSummary' => $request->user()->hasPermission(Permissions::REPORT_EXEC_SUMMARY),
            'strengths' => $this->toList($r->strengths),
            'developmentAreas' => $this->toList($r->development_areas),
            'returnReason' => $r->return_reason,
        ]]);
    }

    // POST /reports/{id}/executive-summary — الملخّص التنفيذي (مدير المركز، قابل للتفويض)
    public function saveExecutiveSummary(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_EXEC_SUMMARY)) {
            return response()->json(['error' => 'الملخّص التنفيذي بصلاحية مدير المركز فقط'], 403);
        }
        $r = $this->resolveReportInScope($request, $id);
        if (!$r) {
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }

        $validated = $request->validate(['executiveSummary' => 'required|string|max:5000']);

        $r->update([
            'executive_summary' => $validated['executiveSummary'],
            'exec_summary_by' => $request->user()->id,
            'exec_summary_at' => now(),
        ]);

        $this->log($request, 'SAVE_EXEC_SUMMARY', $id, ['code' => $r->candidate->participant_code]);

        return response()->json(['message' => 'تم حفظ الملخّص التنفيذي']);
    }

    // GET /reports/{id}/brief — المستند المختصر (الملخّص التنفيذي + النتيجة)، للطباعة
    public function briefDocument(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقرير'], 403);
        }
        $q = FinalReport::with(['candidate.sector', 'assessment']);
        $this->scopeReports($request, $q);
        $r = $q->find($id);
        if (!$r) {
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }
        $canSeeNames = $request->user()->hasPermission(Permissions::REPORT_VIEW_NAMES);
        $this->log($request, 'EXPORT_REPORT_BRIEF', $id, ['code' => $r->candidate->participant_code, 'named' => $canSeeNames]);

        return response($this->renderBrief($r, $canSeeNames), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
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
        // النطاق كاملاً — لا يُكتب/يُقرأ تقرير لمرشّح خارج قطاع المستخدم.
        // eligibleCandidates محصور، فكان يُخفي المرشّح ثم يقبله بمعرّفه.
        $candidate = $this->resolveCandidateInScope($request, $validated['candidateId']);
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
                'status' => $submit ? $this->firstStage()?->status_key : 'draft',
                'created_by' => $request->user()->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // الفهرس الفريد حسم سباقاً متزامناً: أُنشئ تقرير للتوّ لهذه الدورة
            return response()->json(['error' => 'يوجد تقرير لهذه الدورة بالفعل'], 422);
        }

        if ($submit) {
            // إشعار دور المرحلة الأولى لا DEV_MANAGER (المرحلة الأخيرة): وإلا لا يُشعَر
            // صاحب أول اعتماد فتتجمّد السلسلة، ويصل DEV_MANAGER إشعاراً كاذباً عن تقرير
            // لم يبلغ مرحلته ولا يستطيع اعتماده. نفس نمط resubmit.
            $first = $this->firstStage();
            if ($first) {
                $this->notify->notifyRole($first->role_code, 'approval',
                    'تقرير جديد بانتظار اعتمادك',
                    "تقرير المرشح {$assessment->participant_code} وصل مرحلة اعتمادك",
                    'report', (string) $report->id, $request->user()->id);
            }
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
        // النطاق كاملاً كبقية مسارات الكتابة (التصنيف + القطاع + ملكية المقيّم) — 404 لا 403.
        // كان findOrFail + فحص تصنيف فقط، فيصل الكاتب لتقرير خارج قطاعه/ملكيّته
        $report = $this->resolveReportInScope($request, $id, ['candidate', 'assessment']);
        if (!$report) {
            $this->log($request, 'DENIED_REPORT_OUT_OF_SCOPE', $id);
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
            'status' => $submit ? $this->firstStage()?->status_key : $report->status,
        ]);

        if ($submit) {
            // دور المرحلة الأولى لا DEV_MANAGER (انظر التعليق في store) — وإلا تتجمّد السلسلة
            $first = $this->firstStage();
            if ($first) {
                $this->notify->notifyRole($first->role_code, 'approval',
                    'تقرير معدّل بانتظار اعتمادك',
                    "تقرير المرشح " . optional($report->assessment)->participant_code . " وصل مرحلة اعتمادك",
                    'report', (string) $report->id, $request->user()->id);
            }
        }
        $this->log($request, $submit ? 'UPDATE_SUBMIT_REPORT' : 'UPDATE_REPORT', $report->id);

        return response()->json([
            'message' => $submit ? 'تم حفظ التعديلات وإرسال التقرير للاعتماد' : 'تم حفظ التعديلات',
        ]);
    }

    public function approve(Request $request, int $id)
    {
        // النطاق كاملاً قبل أي إفصاح: التصنيف والقطاع والملكية معاً.
        // 404 لا 403 — لا يفرّق الردّ بين «غير موجود» و«ليس لك».
        $report = $this->resolveReportInScope($request, $id);
        if (!$report) {
            $this->log($request, 'DENIED_REPORT_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }

        $stage = WorkflowStage::forStatus($report->status);
        if (!$stage) {
            return response()->json(['error' => 'لا يمكن اعتماد تقرير في هذه الحالة'], 422);
        }

        $user = $request->user();
        $skipped = false;
        $from = $report->status;

        if ($user->hasPermission($stage->permission)) {
            // قواعد المرحلة على الكاتب — تُفحص بعد الصلاحية: الرسالة تشرح المنع
            // لمن يملك المرحلة، ولا تُفصح بشيء لمن لا يملكها أصلاً
            if ($err = $this->stageRuleError($stage, $report, $user)) {
                $this->log($request, 'DENIED_APPROVE_STAGE_RULE', $id, ['stage' => $stage->status_key]);
                return response()->json(['error' => $err], 403);
            }
            $next = WorkflowStage::nextAfter($from);
        } elseif ($skipTo = $this->maySkipTo($user, $stage)) {
            // تجاوز مقصود: من يملك مرحلةً لاحقة يعتمد مباشرة دون انتظار من قبله.
            // قواعد المرحلة التي يقفز إليها تُفحص أيضاً — وإلا صار التجاوز
            // باباً خلفياً لاعتماد ما كتبه بنفسه.
            if ($err = $this->stageRuleError($skipTo, $report, $user)) {
                $this->log($request, 'DENIED_APPROVE_STAGE_RULE', $id, ['stage' => $skipTo->status_key]);
                return response()->json(['error' => $err], 403);
            }
            // يقفز إلى ما بعد مرحلته هو (لا إليها) — وإلا اعتمد مرحلته مرتين.
            $next = WorkflowStage::nextAfter($skipTo->status_key);
            $skipped = true;
        } else {
            return response()->json(['error' => 'ليس لديك صلاحية اعتماد هذه المرحلة'], 403);
        }

        // كتابة مشروطة بالحالة المقروءة — يمنع سباق TOCTOU: اعتمادٌ بحالة قديمة
        // لا يطمس إرجاعاً/إلغاءً متزامناً، ولا يتقدّم مرتين. صفر صفوف = تغيّرت الحالة.
        $affected = FinalReport::where('id', $report->id)->where('status', $from)
            ->update(['status' => $next, 'escalated_at' => null]);
        if ($affected === 0) {
            return response()->json(['error' => 'تغيّرت حالة التقرير — أعد التحميل'], 409);
        }
        $report->status = $next; // مزامنة الذاكرة للآثار الجانبية أدناه

        $final = $next === WorkflowStage::FINAL_STATUS;
        if ($final) {
            // يزامن حالة الدورة → تُتاح إعادة التقييم بدورة جديدة
            $report->candidate->setStatus('completed');
        }

        $this->notifyStage($report, $final ? null : WorkflowStage::forStatus($next)?->role_code, $final, $user->id);

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
            // draft = يعود لكاتبه للتعديل، previous = خطوة واحدة للوراء في السلسلة
            'target' => 'nullable|in:draft,previous',
        ], [
            'reason.required' => 'يجب ذكر سبب الإرجاع',
            'reason.min' => 'سبب الإرجاع قصير جداً',
        ]);

        // نفس نطاق approve — من لا يرى التقرير لا يُرجعه
        $report = $this->resolveReportInScope($request, $id);
        if (!$report) {
            $this->log($request, 'DENIED_REPORT_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }

        // لا يُرجَع إلا تقرير في إحدى مراحل الاعتماد (منع إرجاع معتمد/مسودة → إفساد حالة المرشح)
        if (!in_array($report->status, self::pendingStatuses(), true)) {
            return response()->json(['error' => 'لا يمكن إرجاع تقرير غير مُرسل للاعتماد'], 422);
        }

        // «للمرحلة السابقة» ترجع خطوة واحدة؛ وعند أول مرحلة لا سابق لها فتؤول
        // للمسودة — البديل ردٌّ بلا أثر يُوهم المستخدم أن شيئاً حدث
        $from = $report->status; // للكتابة المشروطة ضد السباق
        $target = $validated['target'] ?? 'draft';
        $newStatus = 'returned';
        if ($target === 'previous') {
            $prev = WorkflowStage::previousBefore($report->status);
            $newStatus = $prev?->status_key ?? 'returned';
        }

        $affected = FinalReport::where('id', $report->id)->where('status', $from)->update([
            'status' => $newStatus,
            'return_reason' => $validated['reason'],
            'return_count' => $report->return_count + 1,
            'last_returned_by' => $request->user()->id,
            'last_returned_at' => now(),
            // الرجوع للوراء حالة تأخّر جديدة — يُصعَّد من جديد إن تأخّر
            'escalated_at' => null,
        ]);
        if ($affected === 0) { // تغيّرت الحالة متزامنةً — لا نطمس تحوّلاً آخر
            return response()->json(['error' => 'تغيّرت حالة التقرير — أعد التحميل'], 409);
        }
        $report->return_count += 1; // مزامنة الذاكرة للرد/السجل

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

        $this->log($request, 'RETURN_REPORT', $id, [
            'reason' => $validated['reason'],
            'count' => $report->return_count,
            'target' => $target,
            'to' => $newStatus,
        ]);

        return response()->json([
            'message' => $newStatus === 'returned'
                ? 'تم إرجاع التقرير للتعديل'
                : 'تم إرجاع التقرير للمرحلة السابقة',
            'status' => $newStatus,
            'returnCount' => $report->return_count,
        ]);
    }

    // POST /reports/{id}/cancel — إيقاف التقرير نهائياً (مدير المركز وحده)
    public function cancel(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CANCEL)) {
            return response()->json(['error' => 'ليس لديك صلاحية إلغاء التقرير'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'يجب ذكر سبب الإلغاء',
            'reason.min' => 'سبب الإلغاء قصير جداً',
        ]);

        $report = $this->resolveReportInScope($request, $id);
        if (!$report) {
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }

        // المعتمَد لا يُلغى: وثيقة نافذة قد تكون طُبعت ووُقّعت — سحبها يحتاج
        // إجراءً موثّقاً لا زرّاً. والملغى لا يُلغى مرتين.
        if (in_array($report->status, ['approved', 'cancelled'], true)) {
            return response()->json(['error' => 'لا يمكن إلغاء تقرير معتمد أو ملغى مسبقاً'], 422);
        }
        $from = $report->status;

        // كتابة مشروطة بالحالة المقروءة — لا يُلغي تقريراً اعتُمد/تغيّر متزامناً
        $affected = FinalReport::where('id', $report->id)->where('status', $from)->update([
            'status' => 'cancelled',
            'return_reason' => $validated['reason'],
            'escalated_at' => null,
        ]);
        if ($affected === 0) {
            return response()->json(['error' => 'تغيّرت حالة التقرير — أعد التحميل'], 409);
        }

        // المرشّح يعود «مُقيَّم» فيُكتب له تقرير جديد — الإلغاء يفتح الباب لا يغلقه
        $report->candidate->setStatus('assessed');

        if ($report->created_by) {
            $this->notify->notify($report->created_by, 'return',
                'أُلغي التقرير',
                'سبب الإلغاء: ' . $validated['reason'],
                'report', (string) $id, $request->user()->id);
        }

        $thread = ChatThread::firstOrCreate(
            ['entity_type' => 'report', 'entity_id' => $id],
            ['title' => 'محادثة التقرير']
        );
        ChatMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $request->user()->id,
            'message' => 'أُلغي التقرير. السبب: ' . $validated['reason'],
            'message_type' => 'action',
            'action_type' => 'return',
        ]);

        $this->log($request, 'CANCEL_REPORT', $id, [
            'reason' => $validated['reason'],
            'candidate' => $report->candidate->participant_code,
        ]);

        return response()->json(['message' => 'تم إلغاء التقرير', 'status' => 'cancelled']);
    }

    public function resubmit(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إعادة الإرسال'], 403);
        }

        // نفس النطاق — التصنيف والقطاع والملكية معاً
        $report = $this->resolveReportInScope($request, $id);
        if (!$report) {
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
        $first = $this->firstStage();
        if (!$first) {
            return response()->json(['error' => 'لا توجد مراحل اعتماد مفعّلة — راجع إعدادات سير العمل'], 422);
        }
        // مشروطة بـ«returned» — لا تتصادم مع إلغاء/تعديل متزامن
        $affected = FinalReport::where('id', $report->id)->where('status', 'returned')
            ->update(['status' => $first->status_key, 'escalated_at' => null]);
        if ($affected === 0) {
            return response()->json(['error' => 'تغيّرت حالة التقرير — أعد التحميل'], 409);
        }

        $this->notify->notifyRole($first->role_code, 'approval',
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
        // نفس نطاق show — المستند وثيقة كاملة، فحدّه لا يكون أوسع من القائمة
        $q = FinalReport::with(['candidate.sector', 'assessment']);
        $this->scopeReports($request, $q);

        $r = $q->find($id);
        if (!$r) {
            return response()->json(['error' => 'التقرير غير موجود'], 404);
        }
        // المستند المطبوع يحمل الاسم لحاملي REPORT_VIEW_NAMES وحدهم (مدير النظام
        // ومدير المركز) — لا لكل من يملك رؤية الأسماء في الشاشات. المستند يُطبع
        // ويخرج من النظام، فحدّه أضيق.
        $canSeeNames = $request->user()->hasPermission(Permissions::REPORT_VIEW_NAMES);
        $fit = $this->scoring->computeFit($r->assessment);
        $measurement = MeasurementResult::where('assessment_id', $r->assessment_id)->first();
        $devPlan = \App\Models\DevelopmentPlanItem::where('assessment_id', $r->assessment_id)
            ->orderBy('id')->get();
        $this->log($request, 'EXPORT_REPORT', $id, [
            'code' => $r->candidate->participant_code,
            'named' => $canSeeNames, // يُدوَّن من أخرج مستنداً يحمل الاسم
        ]);

        return response($this->renderDocument($r, $fit, $canSeeNames, $measurement, $devPlan), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // GET /reports/export — تصدير CSV (يفتحه Excel عربياً عبر BOM)
    public function exportCsv(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_EXPORT)) {
            return response()->json(['error' => 'ليس لديك صلاحية تصدير التقارير'], 403);
        }
        $query = FinalReport::with('candidate.sector')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status));
        // التصدير لا يكون ثغرةً لما تُخفيه الشاشة
        $this->scopeReports($request, $query);

        $reports = $query->orderByDesc('created_at')->get();

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

    // يعرض كفاءات مجمّعة حسب مفتاح (group للسلوكية، domain للفنية) كجداول فرعية
    private function renderCompGroups(array $comps, string $key, string $fallback): string
    {
        if (empty($comps)) {
            return '<p class="muted">لا توجد كفاءات مُحتسَبة في هذا القسم</p>';
        }
        $groups = [];
        foreach ($comps as $c) {
            $g = $c[$key] ?? null;
            $g = ($g === null || trim((string) $g) === '') ? $fallback : $g;
            $groups[$g][] = $c;
        }
        $html = '';
        foreach ($groups as $gName => $rows) {
            $body = '';
            foreach ($rows as $b) {
                $body .= '<tr><td>' . e($b['name']) . '</td><td class="num">' . $b['avgScore'] . ' / ' . $b['maxLevel']
                    . '</td><td class="num">' . $b['pct'] . '%</td></tr>';
            }
            $html .= '<h3>' . e($gName) . '</h3>'
                . '<table><thead><tr><th>الكفاءة</th><th>المتوسط</th><th>النسبة</th></tr></thead><tbody>'
                . $body . '</tbody></table>';
        }
        return $html;
    }

    private function renderDevPlan($items): string
    {
        if (!$items || $items->isEmpty()) {
            return '<p class="muted">لا توجد خطة تطوير مسجّلة</p>';
        }
        $st = ['pending' => 'قيد الانتظار', 'in_progress' => 'قيد التنفيذ', 'done' => 'منجز'];
        $body = '';
        foreach ($items as $it) {
            $body .= '<tr><td>' . e($it->area) . '</td><td>' . e($it->action ?? '—') . '</td>'
                . '<td class="num">' . e($it->target_date ? (string) $it->target_date : '—') . '</td>'
                . '<td>' . ($st[$it->status] ?? e($it->status)) . '</td></tr>';
        }
        return '<table><thead><tr><th>مجال التطوير</th><th>الإجراء</th><th>المستهدف</th><th>الحالة</th></tr></thead><tbody>'
            . $body . '</tbody></table>';
    }

    // المستند المختصر — الملخّص التنفيذي + النتيجة النهائية فقط
    private function renderBrief(FinalReport $r, bool $canSeeNames): string
    {
        $name = e($canSeeNames ? ($r->candidate->full_name ?: $r->candidate->participant_code) : $r->candidate->participant_code);
        $code = e($r->candidate->participant_code);
        $sector = e(optional($r->candidate->sector)->name_ar ?? '—');
        $tier = $r->candidate->tier === 'upper' ? 'قيادة عليا' : 'قيادة وسطى';
        $rec = e($r->recommendation ?? '—');
        $beh = $r->behavioral_fit !== null ? (float) $r->behavioral_fit . '%' : '—';
        $tech = $r->technical_fit !== null ? (float) $r->technical_fit . '%' : '—';
        $summary = trim((string) $r->executive_summary) !== ''
            ? nl2br(e($r->executive_summary))
            : '<span class="muted">لم يُكتب الملخّص التنفيذي بعد (بصلاحية مدير المركز).</span>';
        $date = now()->format('Y-m-d');

        return <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>الملخّص التنفيذي — {$code}</title>
<style>
  * { box-sizing:border-box; }
  body { font-family:"Segoe UI","Noto Naskh Arabic",Tahoma,sans-serif; color:#1a2420; margin:0; background:#f0f2ef; }
  .sheet { max-width:720px; margin:24px auto; background:#fff; padding:40px 46px; box-shadow:0 2px 20px rgba(0,0,0,.08); }
  .print-bar { max-width:720px; margin:16px auto 0; text-align:left; }
  .print-bar button { font:inherit; padding:8px 18px; border:0; border-radius:8px; background:#1f6b4a; color:#fff; cursor:pointer; }
  .hd { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #1f6b4a; padding-bottom:16px; }
  .hd .org { font-weight:800; font-size:19px; color:#1f6b4a; }
  .hd .sub { color:#5b6a62; font-size:13px; margin-top:4px; }
  .hd .meta { text-align:left; font-size:13px; color:#5b6a62; }
  h1 { font-size:21px; margin:24px 0 10px; }
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:8px 28px; margin:14px 0; font-size:14px; }
  .grid .k { color:#5b6a62; } .grid .v { font-weight:700; }
  .res { display:flex; gap:14px; margin:16px 0; }
  .res div { flex:1; background:#f6f8f6; border-radius:10px; padding:12px 14px; font-size:13px; }
  .res b { display:block; font-size:18px; color:#1f6b4a; margin-top:4px; }
  h2 { font-size:15px; margin:22px 0 8px; color:#1f6b4a; border-right:4px solid #1f6b4a; padding-right:10px; }
  .summary { font-size:14.5px; line-height:1.9; background:#f6f8f6; border-radius:10px; padding:16px 18px; }
  .muted { color:#8a978f; }
  .sign { margin-top:44px; text-align:center; font-size:13px; color:#5b6a62; }
  .sign .line { display:inline-block; margin-top:44px; border-top:1px solid #b9c4bd; padding-top:6px; min-width:220px; }
  @media print { body{ background:#fff; } .sheet{ box-shadow:none; margin:0; max-width:none; } .print-bar{ display:none; } @page{ margin:14mm; } }
</style></head>
<body>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
<div class="sheet">
  <div class="hd">
    <div><div class="org">مركز تمكين الكفاءات لتقييم القيادات</div><div class="sub">الملخّص التنفيذي</div></div>
    <div class="meta">رمز المشارك: <b>{$code}</b><br>تاريخ الإصدار: {$date}</div>
  </div>
  <h1>{$name}</h1>
  <div class="grid">
    <div><span class="k">القطاع:</span> <span class="v">{$sector}</span></div>
    <div><span class="k">الفئة القيادية:</span> <span class="v">{$tier}</span></div>
  </div>
  <div class="res">
    <div>التوافق السلوكي/القيادي <b>{$beh}</b></div>
    <div>التوافق الفني <b>{$tech}</b></div>
    <div>التوصية <b>{$rec}</b></div>
  </div>
  <h2>الملخّص التنفيذي</h2>
  <div class="summary">{$summary}</div>
  <div class="sign"><div class="line">مدير المركز</div></div>
</div>
</body></html>
HTML;
    }

    private function renderDocument(FinalReport $r, array $fit, bool $canSeeNames, ?MeasurementResult $measurement = null, $devPlan = null): string
    {
        $name = e($canSeeNames ? ($r->candidate->full_name ?: $r->candidate->participant_code) : $r->candidate->participant_code);
        $code = e($r->candidate->participant_code);
        $sector = e(optional($r->candidate->sector)->name_ar ?? '—');
        $rank = e($r->candidate->rank_label ?? '—');
        $tier = $r->candidate->tier === 'upper' ? 'قيادة عليا' : 'قيادة وسطى';
        $status = $this->statusLabel($r->status);
        $beh = $r->behavioral_fit !== null ? (float) $r->behavioral_fit : null;
        $tech = $r->technical_fit !== null ? (float) $r->technical_fit : null;
        $rec = e($r->recommendation);
        $overview = nl2br(e($r->overview_text ?? '')); // يحافظ على الأسطر كنظيره في المختصر
        $date = now()->format('Y-m-d');

        // الكفاءات مجمّعة: السلوكية/القيادية حسب «المجموعة»، الفنية حسب «المجال»
        $breakdown = $fit['breakdown'];
        $behComps = array_values(array_filter($breakdown, fn ($b) => in_array($b['type'], ['behavioral', 'leadership'], true)));
        $techComps = array_values(array_filter($breakdown, fn ($b) => $b['type'] === 'technical'));
        $behHtml = $this->renderCompGroups($behComps, 'group', 'كفاءات عامة');
        $techHtml = $this->renderCompGroups($techComps, 'domain', 'مجال عام');
        $devPlanHtml = $this->renderDevPlan($devPlan);

        $li = fn ($items) => count($items)
            ? '<ul>' . implode('', array_map(fn ($x) => '<li>' . e($x) . '</li>', $items)) . '</ul>'
            : '<p class="muted">—</p>';
        $strengths = $li($r->strengths ?? []);
        $devAreas = $li($r->development_areas ?? []);

        $engRow = $measurement && $measurement->english_score !== null
            ? '<div class="rec">درجة اللغة الإنجليزية: <b>' . $measurement->english_score . ' / 100</b></div>'
            : '<p class="muted">لم تُسجَّل درجة اللغة الإنجليزية</p>';

        $measHtml = '';
        if ($measurement && ($measurement->personality_score !== null
            || $measurement->analytical_score !== null || $measurement->english_score !== null)) {
            $mrow = fn ($label, $v) => '<tr><td>' . $label . '</td><td class="num">' . ($v === null ? '—' : $v) . '</td></tr>';
            $measHtml = '<table><tbody>'
                . $mrow('المقياس الشخصي', $measurement->personality_score)
                . $mrow('القدرات التحليلية', $measurement->analytical_score)
                . $mrow('اللغة الإنجليزية', $measurement->english_score)
                . '</tbody></table>';
        } else {
            $measHtml = '<p class="muted">لم تُسجَّل أدوات القياس</p>';
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
  h2 .n { color:#8aa99a; font-weight:800; margin-inline-end:6px; }
  h3 { font-size:13.5px; margin:14px 0 6px; color:#33473e; }
  table { width:100%; border-collapse:collapse; font-size:13.5px; margin-bottom:6px; }
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

  <h2><span class="n">١</span>البيانات الشخصية والوظيفية</h2>
  <div class="grid">
    <div><span class="k">رمز المشارك:</span> <span class="v">{$code}</span></div>
    <div><span class="k">القطاع:</span> <span class="v">{$sector}</span></div>
    <div><span class="k">الرتبة / المسمّى:</span> <span class="v">{$rank}</span></div>
    <div><span class="k">الفئة القيادية:</span> <span class="v">{$tier}</span></div>
  </div>

  <h2><span class="n">٢</span>نتائج التقييم النهائية</h2>
  <div class="fits">
    {$behBox}
    {$techBox}
  </div>
  <h3>التوصية النهائية</h3>
  <div class="rec">{$rec}</div>

  <h2><span class="n">٣</span>الكفاءات السلوكية (المجموعات: سلوكية / تميّز / إحساس)</h2>
  {$behHtml}

  <h2><span class="n">٤</span>الكفاءات الفنية حسب مجالات التقييم</h2>
  {$techHtml}

  <h2><span class="n">٥</span>تقييم اللغة الإنجليزية</h2>
  {$engRow}

  <h2><span class="n">٦</span>المرئيات والتوصيات</h2>
  <p>{$overview}</p>
  <h3>مواطن القوة</h3>
  {$strengths}
  <h3>مجالات التطوير</h3>
  {$devAreas}
  <h3>أدوات القياس</h3>
  {$measHtml}

  <h2><span class="n">٧</span>خطة التطوير الفردية</h2>
  {$devPlanHtml}

  <div class="sign">
    <div><div class="line">المُقيّم</div></div>
    <div><div class="line">مدير إدارة التقييم</div></div>
    <div><div class="line">إدارة تطوير الكفاءات</div></div>
    <div><div class="line">مدير المركز</div></div>
  </div>
</div>
</body></html>
HTML;
    }
}
