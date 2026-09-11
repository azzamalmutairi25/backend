<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// لوحة التقارير التنفيذية — معدّل الإعادة، والمعتمَد بلا ملخّص تنفيذي، وزمن الدورة
class ExecutiveReportsInsightTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function report(string $status, array $extra = [], array $candidate = []): FinalReport
    {
        [$c, $a] = $this->makeCandidate(array_merge(
            ['sectorCode' => 'DW', 'status' => 'assessed', 'assessmentStatus' => 'assessed'], $candidate));

        return FinalReport::create(array_merge([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مشارك',
            'status' => $status, 'created_by' => null,
        ], $extra));
    }

    public function test_rework_rate_counts_reports_that_came_back_at_least_once(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $this->report('approved', ['return_count' => 2]);
        $this->report('approved');
        $this->report('pending_manager', ['return_count' => 1]);
        $this->report('pending_evaluator');
        $this->report('draft');   // لم يدخل السلسلة — خارج المقام

        $kpis = $this->getJson('/api/analytics/executive/reports')->assertOk()->json('kpis');

        $this->assertSame(4, $kpis['reworkBase']);
        $this->assertSame(2, $kpis['reworked']);
        $this->assertEquals(50, $kpis['reworkRate']);
    }

    public function test_pending_exec_summary_lists_codes_oldest_first_without_names(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $old = $this->report('approved', [], ['code' => 'EXS001', 'fullName' => 'اسمٌ لا يجوز أن يظهر']);
        DB::table('final_reports')->where('id', $old->id)->update(['updated_at' => now()->subDays(12)]);
        $this->report('approved', [], ['code' => 'EXS002']);
        $this->report('approved', ['executive_summary' => 'مكتوب'], ['code' => 'EXS003']);

        $res = $this->getJson('/api/analytics/executive/reports')->assertOk();

        $res->assertDontSee('اسمٌ لا يجوز أن يظهر');
        $this->assertSame(2, $res->json('pendingExecSummary.count'));
        $this->assertSame('EXS001', $res->json('pendingExecSummary.rows.0.code'));
        $this->assertSame(12, $res->json('pendingExecSummary.rows.0.days'));
    }

    public function test_cycle_time_measures_each_hop_from_recorded_dates(): void
    {
        $user = $this->actingAsRole('CENTER_MANAGER');
        $r = $this->report('approved');
        DB::table('assessments')->where('id', $r->assessment_id)->update([
            'created_at' => now()->subDays(30),
            'first_session_date' => now()->subDays(20)->toDateString(),
        ]);
        DB::table('final_reports')->where('id', $r->id)->update(['created_at' => now()->subDays(10), 'updated_at' => now()]);
        AuditLog::create([
            'user_id' => $user->id, 'action' => 'APPROVE_REPORT', 'entity_type' => 'report',
            'entity_id' => (string) $r->id, 'details' => ['from' => 'pending_center', 'to' => 'approved'],
            'created_at' => now()->subDays(2),
        ]);

        $cycle = $this->getJson('/api/analytics/executive/reports')->assertOk()->json('cycleTime');
        $hops = collect($cycle['hops'])->keyBy('key');

        $this->assertEquals(10, $hops['to_session']['medianDays']);
        $this->assertEquals(10, $hops['to_report']['medianDays']);
        // من سجل التدقيق لا من updated_at (الذي يقول «اليوم»)
        $this->assertEquals(8, $hops['to_approval']['medianDays']);
        $this->assertEquals(28, $cycle['total']['medianDays']);
        $this->assertSame(1, $cycle['total']['n']);
    }
}
