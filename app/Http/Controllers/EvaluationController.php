<?php

namespace App\Http\Controllers;

use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\Competency;
use App\Models\Candidate;
use App\Models\Assessment;
use App\Models\CandidateCv;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Services\CvGuard;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EvaluationController extends Controller
{
    public function __construct(private NotificationService $notify) {}


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

        $query = Evaluation::with(['candidate', 'assessment'])
            ->withCount('scores')
            ->where('evaluator_id', $request->user()->id);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $evaluations = $query->latest('updated_at')->get()
            ->filter(fn ($e) => in_array($e->candidate->classification, $allowed))
            ->map(fn ($e) => [
                'id' => $e->id,
                // رمز دورة التقييم (مجمّد) لا رمز المرشح الحالي — وإلا ظهر رمز دورة أحدث بعد reassess
                'candidateCode' => optional($e->assessment)->participant_code ?? $e->candidate->participant_code,
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
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        // حدّ القطاع: المقيّم لا يُقيّم إلا مرشحي قطاعه. يُفرض هنا لا عند التوزيع
        // وحده — وإلا بدأ مقيّمٌ تقييماً لمرشّح من قطاع آخر بلا جدولة أصلاً.
        if (!$request->user()->coversSector($candidate->sector_id)) {
            $this->log($request, 'DENIED_EVAL_CROSS_SECTOR', $candidate->id, [
                'candidateSector' => $candidate->sector_id,
            ]);
            // 404 لا 403: لا يفرّق الردّ بين «غير موجود» و«خارج قطاعك» فلا يكون عرّاف قطاع
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        if (!in_array($candidate->status, ['scheduled', 'assessed'])) {
            return response()->json(['error' => 'لا يمكن تقييم مرشح غير معتمد للتقييم'], 422);
        }

        // الدورة الحالية — التكرار يُمنع داخل الدورة نفسها (يُسمح بتقييم النشاط في دورة جديدة)
        $assessmentId = $candidate->assessments()->latest('id')->value('id');
        $existing = Evaluation::where('assessment_id', $assessmentId)
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

        // الفحص أعلاه غير ذرّي — الفهرس الفريد (assessment_id, activity) يحسم السباق المتزامن كـ 422 لا 500
        try {
            $evaluation = DB::transaction(function () use ($validated, $assessmentId, $request) {
                // قفل صفّ الدورة نقطة تسلسل مقابل saveCv عبر البوّابة، فتُلتقَط لقطة سيرة مكتملة
                if ($assessmentId) {
                    Assessment::whereKey($assessmentId)->lockForUpdate()->first();
                }
                $e = Evaluation::create([
                    'candidate_id' => $validated['candidateId'],
                    'assessment_id' => $assessmentId,
                    'evaluator_id' => $request->user()->id,
                    'activity' => $validated['activity'],
                    'status' => 'draft',
                ]);
                // جمّد لقطة السيرة عند أول تقييم — فقط إن كانت غير فارغة كي لا
                // يُحبَس المرشح بلقطة فارغة لو بدأ المقيّم قبل أن يملأ سيرته
                if ($assessmentId) {
                    Assessment::with('candidate.cv')->find($assessmentId)?->freezeCvSnapshot(true);
                }
                return $e;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'error' => 'تم بدء تقييم لهذا المرشح في هذا النشاط للتوّ',
            ], 422);
        }

        $this->log($request, 'START_EVALUATION', $evaluation->id, [
            'candidate' => $candidate->participant_code,
            'activity' => $validated['activity'],
        ]);

        return response()->json([
            'message' => 'بدأت الجلسة',
            'evaluation' => ['id' => $evaluation->id],
        ], 201);
    }

    // GET /evaluations/{id}/cv — سيرة المرشح للمقيّم بلا اسم (الميزة ٧)
    // يقرأ لقطة الدورة المجمَّدة لا السيرة الحيّة، ويطمس أي معرّف لمن لا يرى الأسماء.
    public function cv(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::EVALUATION_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقييم'], 403);
        }

        // find لا findOrFail: رد 404 موحّد لغير الموجود ولغير المصرَّح، فلا يفرّق
        // المعرّف بينهما (لا يكون عرّاف وجود). العلاقات تُحمَّل مع السيرة الحيّة احتياطاً.
        $evaluation = Evaluation::with('candidate.cv', 'assessment')->find($id);
        if (!$evaluation) {
            return response()->json(['error' => 'التقييم غير موجود'], 404);
        }

        // النطاق: تصنيف المرشح ثم ملكية الجلسة — 404 لا 403 كي لا يكون المعرّف عرّافاً
        if (!in_array($evaluation->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_EVAL_CLASSIFIED', $id);
            return response()->json(['error' => 'التقييم غير موجود'], 404);
        }
        if ($evaluation->evaluator_id !== $user->id && !$user->hasPermission(Permissions::EVALUATION_APPROVE)) {
            return response()->json(['error' => 'التقييم غير موجود'], 404);
        }

        $assessment = $evaluation->assessment;
        if (!$assessment) { // لا نرجع رمز المرشح المتغيّر بديلاً
            return response()->json(['error' => 'السيرة غير متوفرة لهذا التقييم'], 404);
        }

        // اللقطة المجمَّدة إن وُجدت، وإلا الحيّة (تقييم مسودّة قبل التجميد) كي يرى المقيّم السيرة
        $doc = $assessment->cv_snapshot
            ?? $evaluation->candidate->cv?->data
            ?? CandidateCv::emptyDoc();
        $canSeeNames = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        if (!$canSeeNames) {
            $doc = CvGuard::scrub($doc, $evaluation->candidate);
        }

        $this->log($request, 'VIEW_CV', $id, ['candidate' => $assessment->participant_code]);

        return response()->json(['cv' => [
            'candidateCode' => $assessment->participant_code, // الرمز المجمَّد للدورة فقط
            'name' => $canSeeNames ? $evaluation->candidate->full_name : null,
            'hasCv' => !CandidateCv::isEmptyDoc($doc),
            'document' => $doc,
            // لا يُرسَل أبداً: candidate_id، updated_by، version، الطوابع، الهوية، الجوال، البريد
        ]]);
    }

    public function show(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::EVALUATION_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقييم'], 403);
        }

        $evaluation = Evaluation::with(['scores.competency', 'candidate', 'assessment'])->findOrFail($id);

        if (!in_array($evaluation->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_EVAL_CLASSIFIED', $id);
            return response()->json(['error' => 'التقييم غير موجود'], 404);
        }
        // سرّية الدرجات: المستشار يرى تقييماته فقط؛ المراجعون (اعتماد التقييم) يرون الكل
        if ($evaluation->evaluator_id !== $request->user()->id
            && !$request->user()->hasPermission(Permissions::EVALUATION_APPROVE)) {
            return response()->json(['error' => 'التقييم غير موجود'], 404);
        }

        $this->log($request, 'VIEW_EVALUATION', $id, [
            'candidate' => $evaluation->candidate->participant_code,
        ]);

        return response()->json(['evaluation' => [
            'id' => $evaluation->id,
            'candidateCode' => optional($evaluation->assessment)->participant_code ?? $evaluation->candidate->participant_code,
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
        // الصلاحية قبل الملكية: الملكية وحدها تُبقي الجلسة مفتوحةً لمن سُحبت
        // منه EVALUATION_INPUT بعد بدئها — وقد صار السحب الفردي ممكناً.
        if (!$request->user()->hasPermission(Permissions::EVALUATION_INPUT)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدخال التقييم'], 403);
        }
        // find لا findOrFail: 404 موحّد لغير الموجود/غير المملوك/خارج التصنيف — لا عرّاف وجود
        $evaluation = Evaluation::with('candidate')->find($id);
        if (!$evaluation || $evaluation->evaluator_id !== $request->user()->id
            || !in_array($evaluation->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_EVAL_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'التقييم غير موجود'], 404);
        }

        if ($evaluation->status !== 'draft') {
            return response()->json(['error' => 'لا يمكن تعديل تقييم تم إرساله أو اعتماده'], 422);
        }

        $validated = $request->validate([
            'scores' => 'required|array',
            'scores.*.competencyId' => 'required|exists:competencies,id|distinct',
            'scores.*.score' => 'required|integer|min:1',
            'scores.*.note' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $activityCompetencyIds = Competency::idsForActivity($evaluation->activity);
        // تحميل الكفاءات المطلوبة دفعة واحدة (تفادي N+1 داخل الحلقة)
        $competencies = Competency::whereIn('id', collect($validated['scores'])->pluck('competencyId'))
            ->get()->keyBy('id');

        foreach ($validated['scores'] as $s) {
            if (!in_array($s['competencyId'], $activityCompetencyIds)) {
                return response()->json([
                    'error' => 'إحدى الكفاءات لا تخص هذا النشاط',
                ], 422);
            }

            $competency = $competencies->get($s['competencyId']);
            if ($competency && $s['score'] > $competency->max_level) {
                return response()->json([
                    'error' => "الدرجة تتجاوز الحد الأقصى للكفاءة ({$competency->name_ar}: {$competency->max_level})",
                ], 422);
            }
        }

        $oldScores = EvaluationScore::where('evaluation_id', $id)->get()
            ->map(fn ($s) => ['c' => $s->competency_id, 'v' => $s->score])->toArray();

        // استبدال ذرّي — إمّا كل الدرجات الجديدة أو لا شيء (لا فقدان درجات عند فشل جزئي)
        DB::transaction(function () use ($id, $validated, $evaluation) {
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
        });

        $this->log($request, 'SAVE_SCORES', $id, [
            'count' => count($validated['scores']),
            'previous' => $oldScores ?: 'none',
        ]);

        return response()->json(['message' => 'تم حفظ الدرجات']);
    }

    public function submit(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::EVALUATION_INPUT)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدخال التقييم'], 403);
        }
        // find لا findOrFail: 404 موحّد لغير الموجود/غير المملوك/خارج التصنيف — لا عرّاف وجود
        $evaluation = Evaluation::with('candidate')->find($id);
        if (!$evaluation || $evaluation->evaluator_id !== $request->user()->id
            || !in_array($evaluation->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_EVAL_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'التقييم غير موجود'], 404);
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
            ->distinct('competency_id')
            ->count('competency_id');

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

        // ضمان تجميد لقطة السيرة لو لم تُلتقَط عند البدء (فكرة idempotent)
        if ($evaluation->assessment_id) {
            Assessment::with('candidate.cv')->find($evaluation->assessment_id)?->freezeCvSnapshot();
        }

        // المرشح: scheduled -> assessed (تمّ تقييمه)
        if ($evaluation->candidate->status === 'scheduled') {
            $evaluation->candidate->setStatus('assessed');
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

        // نطاق التصنيف والقطاع قبل الطفرة (الصلاحية قد تُفوَّض لمن هو محصور/بلا تصريح)
        $evaluation = Evaluation::with('candidate')->find($id);
        if (!$evaluation
            || !in_array($evaluation->candidate->classification, $this->allowedClassifications($request))
            || !$request->user()->coversSector($evaluation->candidate->sector_id)) {
            $this->log($request, 'DENIED_EVAL_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'التقييم غير موجود'], 404);
        }

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

        // نطاق التصنيف والقطاع قبل الطفرة (كـapprove — الصلاحية قابلة للتفويض)
        $evaluation = Evaluation::with('candidate')->find($id);
        if (!$evaluation
            || !in_array($evaluation->candidate->classification, $this->allowedClassifications($request))
            || !$request->user()->coversSector($evaluation->candidate->sector_id)) {
            $this->log($request, 'DENIED_EVAL_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'التقييم غير موجود'], 404);
        }

        if ($evaluation->status !== 'submitted') {
            return response()->json(['error' => 'لا يمكن إرجاع تقييم غير مُرسل'], 422);
        }

        $evaluation->update([
            'status' => 'draft',
            'submitted_at' => null,
        ]);

        // إرجاع التقييم يُبطل حالة «assessed» — لكن فقط على الدورة «الحالية» (الأحدث) للمرشح.
        // إن كان التقييم من دورة أقدم/منتهية فإنّ setStatus يزامن الأحدث، فيُفسد دورةً حيّة صالحة — لذا لا نمسّ الحالة.
        $candidate = $evaluation->candidate;
        $assessment = $evaluation->assessment;
        $isCurrentCycle = $candidate && $assessment
            && $assessment->id === $candidate->assessments()->max('id');
        if ($isCurrentCycle && $assessment->status === 'assessed' && $candidate->status === 'assessed') {
            $stillAssessed = Evaluation::where('assessment_id', $evaluation->assessment_id)
                ->whereIn('status', ['submitted', 'approved'])
                ->where('id', '!=', $evaluation->id)
                ->exists();
            if (!$stillAssessed) {
                $candidate->setStatus('scheduled'); // يزامن الدورة الحالية للخلف
            }
        }

        $this->notify->notify($evaluation->evaluator_id, 'info',
            'تقييم مُرجع للتعديل',
            'أُرجع تقييمك: ' . $validated['reason'],
            'evaluation', (string) $id, $request->user()->id);

        $this->log($request, 'RETURN_EVALUATION', $id, ['reason' => $validated['reason']]);

        return response()->json(['message' => 'تم إرجاع التقييم للمقيّم']);
    }
}
