<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// التحليلات: لوحة تنفيذية، حسب القطاع، فجوات الكفاءات، الاتجاهات
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function approvedReport(int $candidateId, int $assessmentId, float $beh, float $tech): void
    {
        FinalReport::create([
            'candidate_id' => $candidateId, 'assessment_id' => $assessmentId, 'recommendation' => 'مرشّح',
            'behavioral_fit' => $beh, 'technical_fit' => $tech, 'status' => 'approved', 'created_by' => null,
        ]);
    }

    public function test_dashboard_requires_analytics_view(): void
    {
        $this->actingAsRole('EVALUATOR'); // لا ANALYTICS_VIEW
        $this->getJson('/api/analytics/dashboard')->assertStatus(403);
    }

    public function test_dashboard_aggregates_candidates_and_reports(): void
    {
        [$c1, $a1] = $this->makeCandidate(['status' => 'completed', 'assessmentStatus' => 'completed']);
        $this->makeCandidate(['status' => 'draft']);
        $this->approvedReport($c1->id, $a1->id, 80, 60);

        $this->actingAsRole('ASSESS_MANAGER'); // ANALYTICS_VIEW
        $res = $this->getJson('/api/analytics/dashboard')->assertOk();

        $this->assertGreaterThanOrEqual(2, $res->json('candidates.total'));
        $this->assertGreaterThanOrEqual(1, $res->json('candidates.byStatus.completed'));
        $this->assertGreaterThanOrEqual(1, $res->json('candidates.byStatus.draft'));
        $this->assertEquals(80, $res->json('reports.avgBehavioralFit'));
        $this->assertEquals(60, $res->json('reports.avgTechnicalFit'));
        $this->assertGreaterThanOrEqual(1, $res->json('reports.byStatus.approved'));
    }

    public function test_by_sector_lists_sectors_with_totals(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'completed', 'assessmentStatus' => 'completed']);
        $this->approvedReport($c->id, $a->id, 90, 70);

        $this->actingAsRole('ASSESS_MANAGER');
        $sectors = $this->getJson('/api/analytics/by-sector')->assertOk()->json('sectors');
        $mine = collect($sectors)->firstWhere('sectorId', $c->sector_id);
        $this->assertNotNull($mine);
        $this->assertGreaterThanOrEqual(1, $mine['total']);
        $this->assertGreaterThanOrEqual(1, $mine['completed']);
        $this->assertEquals(90, $mine['avgBehavioralFit']);
    }

    public function test_competency_gaps_sorted_weakest_first(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $weak = Competency::create(['name_ar' => 'ضعيفة', 'type' => 'behavioral', 'max_level' => 5, 'weight' => 1, 'sort_order' => 1]);
        $strong = Competency::create(['name_ar' => 'قوية', 'type' => 'technical', 'max_level' => 5, 'weight' => 1, 'sort_order' => 2]);
        $e = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $evaluator->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $weak->id, 'score' => 2]);   // 40%
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $strong->id, 'score' => 5]); // 100%

        $this->actingAsRole('ASSESS_MANAGER');
        $gaps = $this->getJson('/api/analytics/competency-gaps')->assertOk()->json('gaps');
        $this->assertCount(2, $gaps);
        $this->assertSame('ضعيفة', $gaps[0]['competency']); // الأضعف أولاً
        $this->assertEquals(40, $gaps[0]['avgPct']);
        $this->assertEquals(100, $gaps[1]['avgPct']);
    }

    public function test_trends_reports_approved_by_month(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'completed', 'assessmentStatus' => 'completed']);
        $this->approvedReport($c->id, $a->id, 75, 65);

        $this->actingAsRole('ASSESS_MANAGER');
        $trends = $this->getJson('/api/analytics/trends')->assertOk()->json('trends');
        $this->assertNotEmpty($trends);
        $this->assertGreaterThanOrEqual(1, collect($trends)->sum('approvedReports'));
    }
}
