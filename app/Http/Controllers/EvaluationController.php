<?php

namespace App\Http\Controllers;

use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\Competency;
use App\Models\Candidate;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class EvaluationController extends Controller
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
            'entity_type' => 'evaluation',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    public function competencies(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::EVALUATION_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقييم'], 403);
        }

        $validated = $request->validate([
            'activity' => 'nullable|in:interview,discussion',
        ]);

        $query = Competency::orderBy('sort_order');

        // لو حُدّد نشاط، رجّع كفاءات هذا النشاط فقط
        if (!empty($validated['activity'])) {
            $ids = Competency::idsForActivity($validated['activity']);
            $query->whereIn('id', $ids);
        }

        $list = $query->get()->map(fn ($c) => [
            'id' => $c->id,
            'nameAr' => $c->name_ar,
            'type' => $c->type,
            'maxLevel' => $c->max_level,
        ]);

        return response()->json(['competencies' => $list]);
    }

    // قائمة تقييمات المُقيّم الحالي (تُستخدم لاستكمال المسودات) — تدعم ?status=draft
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::EVALUATION_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقييمات'], 403);
        }

        $validated = $request->validate([
            'status' => 'nullable|in:draft,submitted,approved',
        ]);

        $allowed = $this->allowedClassifications($request);

        $query = Evaluation::with('candidate')
            ->withCount('scores')
            ->where('evaluator_id', $request->user()->id);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $evaluations = $query->latest('updated_at')->get()
            ->filter(fn ($e) => in_array($e->candidate->classification, $allowed))
            ->map(fn ($e) => [
                'id' => $e->id,
                'candidateCode' => $e->candidate->participant_code,
                'activity' => $e->activity,
                'status' => $e->status,
                'scoredCount' => $e->scores_count,
                'updatedAt' => $e->updated_at,
            ])->values();

        return response()->json(['evaluations' => $evaluations]);
    }

    public function start(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::EVALUATION_INPUT)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدخال التقييم'], 403);
        }

        $validated = $request->validate([
            'candidateId' => 'required|exists:candidates,id',
            'activity' => 'required|in:interview,discussion',
        ]);

        $candidate = Candidate::findOrFail($validated['candidateId']);

        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_EVAL_CLASSIFIED', $candidate->id);
            return response()->json(['error' => 'هذا المرشح مصنّف، وليس لديك صلاحية تقييمه'], 403);
        }

        if (!in_array($candidate->status, ['scheduled', 'assessed'])) {
            return response()->json(['error' => 'لا يمكن تقييم مرشح غير معتمد للتقييم'], 422);
        }

        // منع تكرار التقييم لنفس (المرشح + النشاط)
        $existing = Evaluation::where('candidate_id', $validated['candidateId'])
            ->where('activity', $validated['activity'])
            ->first();
        if ($existing) {
            $msg = $existing->status === 'draft'
                ? 'توجد مسودة تقييم لهذا المرشح في هذا النشاط — استكملها بدل بدء جلسة جديدة'
                : 'تم تقييم هذا المرشح في هذا النشاط مسبقاً';
            return response()->json([
                'error' => $msg,
                'existingEvaluationId' => $existing->id,
                'existingStatus' => $existing->status,
            ], 422);
        }

        $evaluation = Evaluation::create([
            'candidate_id' => $validated['candidateId'],
            'evaluator_id' => $request->user()->id,
            'activity' => $validated['activity'],
            'status' => 'draft',
        ]);

        $this->log($request, 'START_EVALUATION', $evaluation->id, [
            'candidate' => $candidate->participant_code,
            'activity' => $validated['activity'],
        ]);

        return response()->json([
            'message' => 'بدأت الجلسة',
            'evaluation' => ['id' => $evaluation->id],
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::EVALUATION_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقييم'], 403);
        }

        $evaluation = Evaluation::with(['scores.competency', 'candidate'])->findOrFail($id);

        if (!in_array($evaluation->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_EVAL_CLASSIFIED', $id);
            return response()->json(['error' => 'هذا التقييم لمرشح مصنّف'], 403);
        }

        $this->log($request, 'VIEW_EVALUATION', $id, [
            'candidate' => $evaluation->candidate->participant_code,
        ]);

        return response()->json(['evaluation' => [
            'id' => $evaluation->id,
            'candidateCode' => $evaluation->candidate->participant_code,
            'activity' => $evaluation->activity,
            'status' => $evaluation->status,
            'notes' => $evaluation->notes,
            'evaluatorId' => $evaluation->evaluator_id,
            'scores' => $evaluation->scores->map(fn ($s) => [
                'competencyId' => $s->competency_id,
                'competencyName' => $s->competency->name_ar,
                'score' => $s->score,
                'note' => $s->note,
            ]),
        ]]);
    }

    public function saveScores(Request $request, int $id)
    {
        $evaluation = Evaluation::with('candidate')->findOrFail($id);

        if ($evaluation->evaluator_id !== $request->user()->id) {
            return response()->json(['error' => 'هذه ليست جلستك'], 403);
        }

        if (!in_array($evaluation->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_EVAL_CLASSIFIED', $id);
            return response()->json(['error' => 'هذا التقييم لمرشح مصنّف'], 403);
        }

        if ($evaluation->status !== 'draft') {
            return response()->json(['error' => 'لا يمكن تعديل تقييم تم إرساله أو اعتماده'], 422);
        }

        $validated = $request->validate([
            'scores' => 'required|array',
            'scores.*.competencyId' => 'required|exists:competencies,id',
            'scores.*.score' => 'required|integer|min:1',
            'scores.*.note' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $activityCompetencyIds = Competency::idsForActivity($evaluation->activity);

        foreach ($validated['scores'] as $s) {
            if (!in_array($s['competencyId'], $activityCompetencyIds)) {
                return response()->json([
                    'error' => 'إحدى الكفاءات لا تخص هذا النشاط',
                ], 422);
            }

            $competency = Competency::find($s['competencyId']);
            if ($competency && $s['score'] > $competency->max_level) {
                return response()->json([
                    'error' => "الدرجة تتجاوز الحد الأقصى للكفاءة ({$competency->name_ar}: {$competency->max_level})",
                ], 422);
            }
        }

        $oldScores = EvaluationScore::where('evaluation_id', $id)->get()
            ->map(fn ($s) => ['c' => $s->competency_id, 'v' => $s->score])->toArray();

        EvaluationScore::where('evaluation_id', $id)->delete();

        foreach ($validated['scores'] as $s) {
            EvaluationScore::create([
                'evaluation_id' => $id,
                'competency_id' => $s['competencyId'],
                'score' => $s['score'],
                'note' => $s['note'] ?? null,
            ]);
        }

        if (isset($validated['notes'])) {
            $evaluation->update(['notes' => $validated['notes']]);
        }

        $this->log($request, 'SAVE_SCORES', $id, [
            'count' => count($validated['scores']),
            'previous' => $oldScores ?: 'none',
        ]);

        return response()->json(['message' => 'تم حفظ الدرجات']);
    }

    public function submit(Request $request, int $id)
    {
        $evaluation = Evaluation::with('candidate')->findOrFail($id);

        if ($evaluation->evaluator_id !== $request->user()->id) {
            return response()->json(['error' => 'هذه ليست جلستك'], 403);
        }

        if (!in_array($evaluation->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_EVAL_CLASSIFIED', $id);
            return response()->json(['error' => 'هذا التقييم لمرشح مصنّف'], 403);
        }

        if ($evaluation->status !== 'draft') {
            return response()->json(['error' => 'التقييم مُرسل مسبقاً'], 422);
        }

        $requiredCompetencyIds = Competency::idsForActivity($evaluation->activity);
        $requiredCount = count($requiredCompetencyIds);

        if ($requiredCount === 0) {
            return response()->json([
                'error' => 'لا توجد كفاءات مرتبطة بهذا النشاط',
            ], 422);
        }

        $scoredCount = EvaluationScore::where('evaluation_id', $id)
            ->whereIn('competency_id', $requiredCompetencyIds)
            ->count();

        if ($scoredCount === 0) {
            return response()->json(['error' => 'لا يمكن إرسال تقييم بدون درجات'], 422);
        }

        if ($scoredCount < $requiredCount) {
            return response()->json([
                'error' => "التقييم ناقص: تم تقييم {$scoredCount} من {$requiredCount} كفاءة لهذا النشاط",
            ], 422);
        }

        $evaluation->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        // المرشح: scheduled -> assessed (تمّ تقييمه)
        if ($evaluation->candidate->status === 'scheduled') {
            $evaluation->candidate->update(['status' => 'assessed']);
        }

        $this->notify->notifyRole('ASSESS_MANAGER', 'approval',
            'تقييم بانتظار الاعتماد',
            'وصل تقييم جديد للمراجعة',
            'evaluation', (string) $id, $request->user()->id);

        $this->log($request, 'SUBMIT_EVALUATION', $id);

        return response()->json(['message' => 'تم إرسال التقييم للاعتماد']);
    }

    public function approve(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::EVALUATION_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاعتماد'], 403);
        }

        $evaluation = Evaluation::findOrFail($id);

        if ($evaluation->status !== 'submitted') {
            return response()->json(['error' => 'لا يمكن اعتماد تقييم غير مُرسل'], 422);
        }

        $evaluation->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        $this->log($request, 'APPROVE_EVALUATION', $id);

        return response()->json(['message' => 'تم اعتماد التقييم']);
    }

    public function returnEvaluation(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::EVALUATION_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إرجاع التقييم'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'يجب ذكر سبب الإرجاع',
            'reason.min' => 'سبب الإرجاع قصير جداً',
        ]);

        $evaluation = Evaluation::findOrFail($id);

        if ($evaluation->status !== 'submitted') {
            return response()->json(['error' => 'لا يمكن إرجاع تقييم غير مُرسل'], 422);
        }

        $evaluation->update([
            'status' => 'draft',
            'submitted_at' => null,
        ]);

        $this->notify->notify($evaluation->evaluator_id, 'info',
            'تقييم مُرجع للتعديل',
            'أُرجع تقييمك: ' . $validated['reason'],
            'evaluation', (string) $id, $request->user()->id);

        $this->log($request, 'RETURN_EVALUATION', $id, ['reason' => $validated['reason']]);

        return response()->json(['message' => 'تم إرجاع التقييم للمقيّم']);
    }
}
