<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// الاحتساب الآلي: تجميع درجات الكفاءات → توافق سلوكي/فني/عام (متوسط موزون)
class ScoringTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function comp(string $type, int $max = 5, float $weight = 1, int $sort = 1): Competency
    {
        return Competency::create([
            'name_ar' => "كفاءة {$type} {$sort}", 'type' => $type,
            'max_level' => $max, 'weight' => $weight, 'sort_order' => $sort,
        ]);
    }

    private function submittedEval(int $candidateId, int $assessmentId, int $evaluatorId, string $status = 'submitted', string $activity = 'interview'): Evaluation
    {
        return Evaluation::create([
            'candidate_id' => $candidateId, 'assessment_id' => $assessmentId, 'evaluator_id' => $evaluatorId,
            'activity' => $activity, 'status' => $status, 'submitted_at' => now(),
        ]);
    }

    public function test_score_preview_computes_fit_from_competency_scores(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $beh = $this->comp('behavioral', 5, 1, 1);
        $tech = $this->comp('technical', 5, 1, 2);
        $e = $this->submittedEval($c->id, $a->id, $evaluator->id);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $beh->id, 'score' => 4]); // 80%
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $tech->id, 'score' => 3]); // 60%

        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_CREATE
        $res = $this->getJson("/api/reports/score-preview?candidateId={$c->id}")->assertOk();
        $this->assertEquals(80, $res->json('behavioralFit'));
        $this->assertEquals(60, $res->json('technicalFit'));
        $this->assertEquals(70, $res->json('overallFit'));   // (80+60)/2
        $this->assertEquals(2, $res->json('competenciesScored'));
    }

    public function test_weighted_average_within_a_type(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $b1 = $this->comp('behavioral', 5, 3, 1); // وزن 3، درجة 5 → 100%
        $b2 = $this->comp('behavioral', 5, 1, 2); // وزن 1، درجة 1 → 20%
        $e = $this->submittedEval($c->id, $a->id, $evaluator->id);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $b1->id, 'score' => 5]);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $b2->id, 'score' => 1]);

        $this->actingAsRole('ASSESS_MANAGER');
        $res = $this->getJson("/api/reports/score-preview?candidateId={$c->id}")->assertOk();
        // (100×3 + 20×1) / 4 = 80
        $this->assertEquals(80, $res->json('behavioralFit'));
    }

    public function test_only_submitted_or_approved_evaluations_count(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $beh = $this->comp('behavioral', 5, 1, 1);
        $tech = $this->comp('technical', 5, 1, 2);
        $submitted = $this->submittedEval($c->id, $a->id, $evaluator->id, 'submitted');
        EvaluationScore::create(['evaluation_id' => $submitted->id, 'competency_id' => $beh->id, 'score' => 4]);
        // مسودة تُهمَل: درجتها الفنية يجب ألا تُحتسب (نشاط مختلف لتفادي فهرس التفرّد)
        $draft = $this->submittedEval($c->id, $a->id, $evaluator->id, 'draft', 'discussion');
        EvaluationScore::create(['evaluation_id' => $draft->id, 'competency_id' => $tech->id, 'score' => 5]);

        $this->actingAsRole('ASSESS_MANAGER');
        $res = $this->getJson("/api/reports/score-preview?candidateId={$c->id}")->assertOk();
        $this->assertEquals(80, $res->json('behavioralFit'));
        $this->assertNull($res->json('technicalFit')); // فنية فقط في مسودة → لا تُحتسب
    }

    public function test_store_auto_fills_fit_when_not_provided(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $beh = $this->comp('behavioral', 5, 1, 1);
        $tech = $this->comp('technical', 5, 1, 2);
        $e = $this->submittedEval($c->id, $a->id, $evaluator->id);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $beh->id, 'score' => 4]);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $tech->id, 'score' => 3]);

        $this->actingAsRole('ASSESS_MANAGER');
        $this->postJson('/api/reports', [
            'candidateId' => $c->id, 'recommendation' => 'مرشّح قوي',
        ])->assertCreated();

        $report = FinalReport::where('assessment_id', $a->id)->first();
        $this->assertEquals(80, $report->behavioral_fit);  // مُحتسَب آلياً
        $this->assertEquals(60, $report->technical_fit);
    }

    public function test_store_respects_manual_override(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $beh = $this->comp('behavioral', 5, 1, 1);
        $e = $this->submittedEval($c->id, $a->id, $evaluator->id);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $beh->id, 'score' => 4]); // 80%

        $this->actingAsRole('ASSESS_MANAGER');
        $this->postJson('/api/reports', [
            'candidateId' => $c->id, 'recommendation' => 'مرشّح', 'behavioralFit' => 95,
        ])->assertCreated();

        $report = FinalReport::where('assessment_id', $a->id)->first();
        $this->assertEquals(95, $report->behavioral_fit); // التجاوز اليدوي يُحترَم
    }
}
