<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// فلاتر قائمة التقارير (قطاع/فئة/توصية/تاريخ) + تجميعات الرسوم البيانية.
class ReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function reportFor(string $sectorCode, string $tier, string $rec, string $status = 'approved', $behavioral = 80): FinalReport
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed', 'sectorCode' => $sectorCode, 'tier' => $tier]);
        return FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => $status,
            'recommendation' => $rec, 'behavioral_fit' => $behavioral, 'technical_fit' => 70, 'created_by' => null,
        ]);
    }

    public function test_filter_by_sector_tier_and_recommendation(): void
    {
        $this->reportFor('ED', 'upper', 'يوصى به');
        $this->reportFor('HI', 'middle', 'لا يوصى به');
        $this->reportFor('ED', 'middle', 'يوصى به');

        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_VIEW, غير محصور بقطاع
        $edId = Sector::where('code', 'ED')->value('id');

        $bySector = $this->getJson("/api/reports?sectorId={$edId}")->assertOk()->json('reports');
        $this->assertCount(2, $bySector);

        $byTier = $this->getJson('/api/reports?tier=upper')->assertOk()->json('reports');
        $this->assertCount(1, $byTier);

        $byRec = $this->getJson('/api/reports?' . http_build_query(['recommendation' => 'يوصى به']))->assertOk()->json('reports');
        $this->assertCount(2, $byRec);
    }

    public function test_filter_by_date_range(): void
    {
        $r = $this->reportFor('ED', 'upper', 'يوصى به');
        // created_at ليس ضمن fillable — نحدّثه مباشرة عبر الاستعلام
        FinalReport::where('id', $r->id)->update(['created_at' => now()->subDays(10)]);
        $this->reportFor('ED', 'upper', 'يوصى به'); // اليوم

        $this->actingAsRole('ASSESS_MANAGER');
        $recent = $this->getJson('/api/reports?dateFrom=' . now()->subDays(2)->toDateString())->json('reports');
        $this->assertCount(1, $recent);
    }

    public function test_analytics_aggregates_within_scope(): void
    {
        $this->reportFor('ED', 'upper', 'يوصى به', 'approved', 90);
        $this->reportFor('ED', 'middle', 'لا يوصى به', 'approved', 60);
        $this->reportFor('HI', 'upper', 'يوصى به', 'draft'); // ليس معتمداً

        $this->actingAsRole('ASSESS_MANAGER');
        $a = $this->getJson('/api/reports/analytics')->assertOk()->json('analytics');

        $this->assertSame(3, $a['total']);
        $this->assertSame(2, $a['approved']);
        $this->assertEquals(75, $a['avgBehavioral']); // (90+60)/2
        $recLabels = collect($a['byRecommendation'])->pluck('label')->all();
        $this->assertContains('يوصى به', $recLabels);
        $tierUpper = collect($a['byTier'])->firstWhere('label', 'قيادة عليا');
        $this->assertSame(2, $tierUpper['value']);
    }

    public function test_analytics_requires_report_view(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');
        $this->getJson('/api/reports/analytics')->assertStatus(403);
    }

    public function test_sector_bound_evaluator_scope_not_widened_by_filter(): void
    {
        // مقيّم قطاع ED يفلتر بقطاع HI → لا يرى شيئاً (النطاق يبقى محصوراً)
        $this->reportFor('HI', 'upper', 'يوصى به');
        $this->actingAsRole('EVALUATOR', 'ED');
        $hiId = Sector::where('code', 'HI')->value('id');
        $rows = $this->getJson("/api/reports?sectorId={$hiId}")->assertOk()->json('reports');
        $this->assertCount(0, $rows);
    }
}
