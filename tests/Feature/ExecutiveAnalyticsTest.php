<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalReport;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// لوحة التحليلات التنفيذية: البوابة، البنية، الرؤى، وحصر التصنيف.
class ExecutiveAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(): User
    {
        return User::create([
            'username' => 'ev_' . substr(md5(uniqid('', true)), 0, 6), 'full_name' => 'مقيّم',
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', 'ED')->value('id'), 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    // مرشّح كامل: تقييم معتمد + درجة كفاءة + تقرير (بحالة معطاة)
    private function scored(string $sectorCode, string $classification, int $score, int $evaluatorId, Competency $comp, string $reportStatus = 'approved'): void
    {
        [$c, $a] = $this->makeCandidate([
            'sectorCode' => $sectorCode, 'classification' => $classification,
            'status' => $reportStatus === 'approved' ? 'completed' : 'assessed',
        ]);
        $ev = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $evaluatorId,
            'activity' => 'interview', 'status' => 'approved',
        ]);
        EvaluationScore::create(['evaluation_id' => $ev->id, 'competency_id' => $comp->id, 'score' => $score]);
        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => $reportStatus,
            'behavioral_fit' => 80, 'technical_fit' => 70, 'created_by' => null,
        ]);
    }

    public function test_executive_requires_analytics_permission(): void
    {
        $this->actingAsRole('EVALUATOR', 'ED'); // لا يملك analytics.view
        $this->getJson('/api/analytics/executive')->assertStatus(403);
    }

    public function test_executive_returns_full_structure_and_kpis(): void
    {
        $this->actingAsRole('CENTER_MANAGER'); // analytics.view + view_classified
        $ev = $this->evaluator();
        $comp = Competency::first();

        $this->scored('ED', 'normal', 4, $ev->id, $comp, 'approved');
        $this->scored('ED', 'normal', 5, $ev->id, $comp, 'approved');
        $this->scored('HI', 'normal', 3, $ev->id, $comp, 'approved');

        $res = $this->getJson('/api/analytics/executive')->assertOk();
        $res->assertJsonStructure([
            'kpis' => ['totalCandidates', 'activeAssessments', 'approvedReports', 'avgReadiness',
                'deltas' => ['newCandidates', 'approvedReports', 'readiness']],
            'heatmap' => ['competencies', 'sectors', 'cells'],
            'sectorComparison', 'tierComparison', 'readinessDistribution', 'trends', 'insights',
        ]);
        $this->assertSame(3, $res->json('kpis.approvedReports'));
        // متوسط الجاهزية = (80+70)/2 = 75
        $this->assertEquals(75, $res->json('kpis.avgReadiness'));
        // الفئتان القياديتان، وأربع شرائح جاهزية
        $this->assertCount(2, $res->json('tierComparison'));
        $this->assertCount(4, $res->json('readinessDistribution'));
        // ٣ تقارير بجاهزية ٧٥ → كلها في شريحة «جيّد (٧٠–٨٥)»
        $good = collect($res->json('readinessDistribution'))->firstWhere('tone', 'good');
        $this->assertSame(3, $good['count']);
    }

    public function test_heatmap_and_sector_comparison_populated(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $ev = $this->evaluator();
        $comp = Competency::first();
        $this->scored('ED', 'normal', 4, $ev->id, $comp, 'approved');
        $this->scored('HI', 'normal', 2, $ev->id, $comp, 'approved');

        $res = $this->getJson('/api/analytics/executive')->assertOk();

        // الخريطة تحوي الكفاءة وقطاعين وخليّة لكل (كفاءة×قطاع)
        $compIds = collect($res->json('heatmap.competencies'))->pluck('id');
        $this->assertTrue($compIds->contains($comp->id));
        $this->assertGreaterThanOrEqual(2, count($res->json('heatmap.sectors')));
        $this->assertNotEmpty($res->json('heatmap.cells'));

        // مقارنة القطاعات: مرتّبة بالجاهزية ولكل قطاع رتبة
        $sectors = collect($res->json('sectorComparison'));
        $this->assertTrue($sectors->every(fn ($s) => isset($s['rank']) && array_key_exists('avgReadiness', $s)));
    }

    public function test_insights_surface_report_bottleneck(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $ev = $this->evaluator();
        $comp = Competency::first();
        // تقريران عالقان في مرحلة المقيّم → اختناق
        $this->scored('ED', 'normal', 4, $ev->id, $comp, 'pending_evaluator');
        $this->scored('ED', 'normal', 3, $ev->id, $comp, 'pending_evaluator');

        $insights = $this->getJson('/api/analytics/executive')->assertOk()->json('insights');
        $titles = collect($insights)->pluck('title');
        $this->assertTrue($titles->contains('اختناق الاعتماد'));
    }

    public function test_executive_respects_classification_scope(): void
    {
        // مدير جدولة مُنِح analytics.view لكنه بلا رؤية للمصنّفين
        $u = $this->actingAsRole('SCHEDULER');
        $u->permissionOverrides()->create(['permission' => 'analytics.view', 'granted' => true]);
        $ev = $this->evaluator();
        $comp = Competency::first();

        $this->scored('ED', 'normal', 4, $ev->id, $comp, 'approved');
        $this->scored('ED', 'secret', 5, $ev->id, $comp, 'approved'); // مصنّف — يُحجب

        $res = $this->getJson('/api/analytics/executive')->assertOk();
        // العادي فقط يُحتسب (المصنّف خارج نطاقه)
        $this->assertSame(1, $res->json('kpis.approvedReports'));
    }
}
