<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// مرحلة «الاعتماد النهائي — مدير المركز» مفعّلةٌ في سلسلة التقرير، وكانت غائبة
// عن كل قائمةٍ تعدّ المراحل بيدها: لوحة القيادة تُسقطها من خطّ الاعتماد،
// والتحليلات لا تعدّها، والتصعيد لا يعرف صاحبها، وشاشة التقارير بلا زرّ لها.
// ومعها الجاهزية: الدرجة الناقصة كانت تُحسب صفراً.
class CenterStageVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function reportAt(string $status, array $extra = []): FinalReport
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'assessed', 'assessmentStatus' => 'assessed']);

        return FinalReport::create(array_merge([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مشارك',
            'status' => $status, 'created_by' => null,
        ], $extra));
    }

    // ── مرحلة مدير المركز ──

    public function test_reports_stats_count_the_center_stage_and_return_the_active_chain(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $this->reportAt('pending_center');

        $res = $this->getJson('/api/reports/stats')->assertOk();
        $this->assertSame(1, $res->json('stats.pendingCenter'));
        $this->assertSame(1, $res->json('stats.pending'));
        // آخر المراحل المفعّلة — منها تعرف الشاشة زرّ «اعتماد نهائي»
        $chain = $res->json('stats.chain');
        $this->assertSame('pending_center', end($chain));
    }

    public function test_executive_board_keeps_the_center_stage_in_the_chain(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $r = $this->reportAt('pending_center');
        DB::table('final_reports')->where('id', $r->id)->update(['updated_at' => now()->subDays(4)]);

        $board = $this->getJson('/api/analytics/executive/reports')->assertOk();
        $this->assertSame(1, $board->json('kpis.inChain'));

        $stage = collect($board->json('pipeline'))->firstWhere('status', 'pending_center');
        $this->assertNotNull($stage, 'مرحلة مدير المركز غائبة عن خطّ الاعتماد');
        $this->assertSame(1, $stage['count']);
        $this->assertSame('بانتظار مدير المركز', $stage['label']);

        $aging = collect($board->json('aging'))->firstWhere('status', 'pending_center');
        $this->assertNotNull($aging, 'مرحلة مدير المركز غائبة عن «أطول انتظار»');
        $this->assertSame(4, $aging['oldestDays']);

        $this->assertSame('بانتظار مدير المركز', $board->json('recent.0.statusLabel'));

        $sections = collect($this->getJson('/api/analytics/executive/overview')->assertOk()->json('sections'))->keyBy('key');
        $inChain = collect($sections['reports']['metrics'])->firstWhere('label', 'في سلسلة الاعتماد')['value'];
        $this->assertSame(1, $inChain);
    }

    public function test_analytics_dashboard_counts_the_center_stage(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $this->reportAt('pending_center');

        $this->getJson('/api/analytics/dashboard')->assertOk()
            ->assertJsonPath('reports.byStatus.pending_center', 1);
    }

    public function test_a_late_report_at_the_center_stage_is_escalated_to_the_center_manager(): void
    {
        $center = User::create([
            'username' => 'u_'.substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مدير مركز', 'role_id' => Role::where('code', 'CENTER_MANAGER')->value('id'), 'is_active' => true,
            'must_change_password' => false, 'user_type' => 'external', 'password' => 'Kafaat@2026',
        ]);
        $r = $this->reportAt('pending_center');
        DB::table('final_reports')->where('id', $r->id)->update(['updated_at' => now()->subDays(5)]);

        Artisan::call('kafaat:daily', ['--days' => 3]);

        $this->assertTrue(
            Notification::where('recipient_id', $center->id)
                ->where('entity_type', 'report')->where('type', 'approval')->exists(),
            'التقرير العالق عند مدير المركز لم يُصعَّد إليه'
        );
    }

    // ── الجاهزية ──

    public function test_readiness_takes_the_available_score_instead_of_zero(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        // تقريرٌ فنّيه لم يُرصد — كان يدخل المتوسط بـ٤٠ ويقع في «يحتاج تطويراً»
        $this->reportAt('approved', ['behavioral_fit' => 80, 'technical_fit' => null]);

        $exec = $this->getJson('/api/analytics/executive')->assertOk();
        $this->assertEquals(80, $exec->json('kpis.avgReadiness'));
        $dist = collect($exec->json('readinessDistribution'))->keyBy('tone');
        $this->assertSame(1, $dist['good']['count']);
        $this->assertSame(0, $dist['weak']['count']);

        $board = $this->getJson('/api/analytics/executive/reports')->assertOk();
        $this->assertEquals(80, $board->json('kpis.avgReadiness'));
        $this->assertEquals(80, $board->json('recent.0.readiness'));
    }

    // لوحة التحكم تقرأ الجاهزية نفسها — رقمان مختلفان للمفهوم نفسه في شاشتين خطأٌ ثانٍ
    public function test_dashboard_readiness_uses_the_same_rule(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $this->reportAt('approved', ['behavioral_fit' => 80, 'technical_fit' => null]);

        $this->assertEquals(80, $this->getJson('/api/dashboard/overview')->assertOk()->json('readiness.value'));
    }

    public function test_a_report_without_any_score_is_left_out_of_readiness(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $this->reportAt('approved', ['behavioral_fit' => 90, 'technical_fit' => 70]);
        $this->reportAt('approved', ['behavioral_fit' => null, 'technical_fit' => null]);

        // (٩٠+٧٠)/٢ = ٨٠ — لا متوسطَ ٨٠ وصفرٍ = ٤٠
        $this->assertEquals(80, $this->getJson('/api/analytics/executive')->assertOk()->json('kpis.avgReadiness'));
    }

    // ── موجات الجدولة ──

    public function test_waves_card_names_the_scheduler_as_the_approver(): void
    {
        $this->actingAsRole('CENTER_MANAGER');

        $sections = collect($this->getJson('/api/analytics/executive/overview')->assertOk()->json('sections'))->keyBy('key');
        $labels = array_column($sections['waves']['metrics'], 'label');

        $this->assertContains('بانتظار اعتماد مسؤول الجدولة', $labels);
        $this->assertNotContains('بانتظار اعتمادك', $labels);
    }
}
