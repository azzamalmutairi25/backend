<?php

namespace App\Http\Controllers;

use App\Models\FinalReport;
use App\Models\Candidate;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private NotificationService $notify) {}

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
            $query->where('status', $request->status);
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
            'pending' => (clone $base)->where('status', 'pending_dev_approval')->count(),
            'draft' => (clone $base)->where('status', 'draft')->count(),
            'returned' => (clone $base)->where('status', 'returned')->count(),
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
        try {
            $report = FinalReport::create([
                'candidate_id' => $candidate->id,
                'assessment_id' => $assessment->id,
                'behavioral_fit' => $validated['behavioralFit'] ?? null,
                'technical_fit' => $validated['technicalFit'] ?? null,
                'recommendation' => $validated['recommendation'],
                'overview_text' => $validated['overviewText'] ?? null,
                'strengths' => $this->toList($validated['strengths'] ?? []),
                'development_areas' => $this->toList($validated['developmentAreas'] ?? []),
                'status' => $submit ? 'pending_dev_approval' : 'draft',
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
            'status' => $submit ? 'pending_dev_approval' : $report->status,
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
        if (!$request->user()->hasPermission(Permissions::REPORT_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاعتماد النهائي'], 403);
        }

        $report = FinalReport::with('candidate')->findOrFail($id);

        if (!in_array($report->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_REPORT_CLASSIFIED', $id);
            return response()->json(['error' => 'هذا التقرير لمرشح مصنّف'], 403);
        }

        if ($report->status !== 'pending_dev_approval') {
            return response()->json(['error' => 'لا يمكن اعتماد تقرير غير مُرسل للاعتماد'], 422);
        }

        $report->update(['status' => 'approved']);
        $report->candidate->setStatus('completed'); // يزامن حالة الدورة → تُتاح إعادة التقييم بدورة جديدة

        $this->log($request, 'APPROVE_REPORT', $id, ['candidate' => $report->candidate->participant_code]);

        return response()->json(['message' => 'تم اعتماد التقرير نهائياً']);
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

        // لا يُرجَع إلا تقرير مُرسل للاعتماد (منع إرجاع معتمد/مسودة → إفساد حالة المرشح)
        if ($report->status !== 'pending_dev_approval') {
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

        $report->update(['status' => 'pending_dev_approval']);

        $this->notify->notifyRole('DEV_MANAGER', 'approval',
            'تقرير معدّل بانتظار الاعتماد',
            'أُعيد إرسال تقرير بعد تعديله',
            'report', (string) $id, $request->user()->id);

        $this->log($request, 'RESUBMIT_REPORT', $id);

        return response()->json(['message' => 'تم إعادة إرسال التقرير']);
    }
}
