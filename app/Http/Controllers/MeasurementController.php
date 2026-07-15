<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\MeasurementResult;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  وحدة أدوات القياس — رفع/عرض نتائج القياس لدورة المرشّح الحالية
// ════════════════════════════════════════════════════════════

class MeasurementController extends Controller
{

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'measurement',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function present(?MeasurementResult $m): ?array
    {
        if (!$m) return null;
        return [
            'personalityScore' => $m->personality_score,
            'analyticalScore' => $m->analytical_score,
            'englishScore' => $m->english_score,
            'updatedAt' => $m->updated_at,
        ];
    }

    // GET /measurements/{candidateId} — نتيجة القياس للدورة الحالية
    public function show(Request $request, int $candidateId)
    {
        if (!$request->user()->hasPermission(Permissions::MEASUREMENT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض القياس'], 403);
        }
        // النطاق كاملاً — كان التصنيف وحده، فكانت نتائج القياس مفتوحة لكل قطاع
        $candidate = $this->resolveCandidateInScope($request, $candidateId);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $assessment = $candidate->assessments()->orderByDesc('id')->first();
        $m = $assessment
            ? MeasurementResult::where('assessment_id', $assessment->id)->first()
            : null;

        return response()->json(['measurement' => $this->present($m)]);
    }

    // POST /measurements — رفع/تحديث نتيجة القياس للدورة النشطة (upsert لكل دورة)
    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::MEASUREMENT_UPLOAD)) {
            return response()->json(['error' => 'ليس لديك صلاحية رفع القياس'], 403);
        }

        $validated = $request->validate([
            'candidateId' => 'required|integer',
            'personalityScore' => 'nullable|numeric|min:0|max:100',
            'analyticalScore' => 'nullable|numeric|min:0|max:100',
            'englishScore' => 'nullable|numeric|min:0|max:100',
        ]);

        // النطاق كاملاً — كان التصنيف وحده، فكانت نتائج القياس مفتوحة لكل قطاع
        $candidate = $this->resolveCandidateInScope($request, $validated['candidateId']);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        // القياس يُرصد ضمن الدورة النشطة (غير المكتملة) — لا نكتب على دورة منتهية
        $assessment = $candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
        if (!$assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم نشطة للمرشّح'], 422);
        }

        $m = MeasurementResult::updateOrCreate(
            ['assessment_id' => $assessment->id],
            [
                'candidate_id' => $candidate->id,
                'personality_score' => $validated['personalityScore'] ?? null,
                'analytical_score' => $validated['analyticalScore'] ?? null,
                'english_score' => $validated['englishScore'] ?? null,
                'uploaded_by' => $request->user()->id,
            ]
        );

        $this->log($request, 'UPLOAD_MEASUREMENT', $m->id, ['candidate' => $candidate->participant_code]);

        return response()->json(['message' => 'تم حفظ نتيجة القياس', 'measurement' => $this->present($m->fresh())]);
    }
}
