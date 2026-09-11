<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\GateManifest;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// «بانتظار قرارك» — الطابور بصلاحيات القارئ، وما توقّف عند غيره بعمره وصاحبه،
// ويوم المركز. البوابة نفسها: analytics.executive.
class ExecutiveDecisionDeskTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(): User
    {
        return User::create([
            'username' => 'ev_'.substr(md5(uniqid('', true)), 0, 6), 'full_name' => 'مقيّم',
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', 'DW')->value('id'), 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    private function report(string $status, array $extra = []): FinalReport
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'assessed', 'assessmentStatus' => 'assessed']);

        return FinalReport::create(array_merge([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مشارك',
            'status' => $status, 'created_by' => null,
        ], $extra));
    }

    private function row(array $rows, string $key): ?array
    {
        return collect($rows)->firstWhere('key', $key);
    }

    public function test_desk_requires_the_executive_permission(): void
    {
        $this->actingAsRole('EVALUATOR', 'DW');
        $this->getJson('/api/analytics/executive/today')->assertStatus(403);
    }

    public function test_center_manager_queue_counts_what_waits_for_his_signature(): void
    {
        $this->actingAsRole('CENTER_MANAGER');

        $atCenter = $this->report('pending_center');
        DB::table('final_reports')->where('id', $atCenter->id)->update(['updated_at' => now()->subDays(5)]);
        $this->report('approved');                                   // بلا ملخّص
        $this->report('approved', ['executive_summary' => 'ملخّص']); // مكتوبٌ — لا يُعدّ
        $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'draft']);
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled']);
        Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $this->evaluator()->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now()->subDays(2),
        ]);
        GateManifest::create([
            'manifest_date' => now()->addDay()->toDateString(), 'gate_time' => '07:00',
            'location' => 'البوّابة الرئيسية', 'status' => GateManifest::PENDING,
        ]);

        $queue = $this->getJson('/api/analytics/executive/today')->assertOk()->json('queue');

        $center = $this->row($queue, 'reports_center');
        $this->assertSame(1, $center['count']);
        $this->assertSame(5, $center['oldestDays']);
        $this->assertSame(['status' => 'pending_center'], $center['query']);

        $this->assertSame(1, $this->row($queue, 'exec_summary')['count']);
        $this->assertGreaterThanOrEqual(1, $this->row($queue, 'candidates_draft')['count']);
        $this->assertSame(1, $this->row($queue, 'evaluations_submitted')['count']);
        $this->assertSame(2, $this->row($queue, 'evaluations_submitted')['oldestDays']);
        $this->assertSame(1, $this->row($queue, 'gate_manifest')['count']);
        $this->assertNotNull($this->row($queue, 'gate_manifest')['hint']);
    }

    // الصفّ بصلاحية البتّ لا بالدور: من فُتحت له الشاشة بلا صلاحيات الاعتماد
    // لا يُعرض عليه قرارٌ سيرفضه الخادم
    public function test_queue_rows_follow_decision_permissions_not_the_role(): void
    {
        $user = $this->actingAsRole('ASSESS_MANAGER');
        UserPermissionOverride::create([
            'user_id' => $user->id, 'permission' => Permissions::ANALYTICS_EXECUTIVE, 'granted' => true,
        ]);
        $user->refresh();

        $keys = array_column($this->getJson('/api/analytics/executive/today')->assertOk()->json('queue'), 'key');

        $this->assertNotContains('reports_center', $keys);
        $this->assertNotContains('gate_manifest', $keys);
        $this->assertNotContains('exec_summary', $keys);
    }

    public function test_blockers_carry_the_owner_and_the_age(): void
    {
        $this->actingAsRole('CENTER_MANAGER');

        SchedulingPeriod::create([
            'name' => 'موجة الاختبار', 'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(), 'status' => 'pending_center',
            'submitted_at' => now()->subDays(9),
        ]);
        $stalled = $this->report('pending_manager');
        DB::table('final_reports')->where('id', $stalled->id)->update(['updated_at' => now()->subDays(10)]);
        $this->report('pending_manager');   // حديثٌ — لا يُعدّ متوقّفاً
        $this->report('returned', ['last_returned_at' => now()->subDays(6)]);

        $blockers = $this->getJson('/api/analytics/executive/today')->assertOk()->json('blockers');

        $waves = $this->row($blockers, 'waves_pending');
        $this->assertSame(1, $waves['count']);
        $this->assertSame(9, $waves['oldestDays']);
        $this->assertSame('مسؤول الجدولة', $waves['owner']);

        $this->assertSame(1, $this->row($blockers, 'stalled_reports')['count']);
        $this->assertSame(10, $this->row($blockers, 'stalled_reports')['oldestDays']);
        $this->assertSame(1, $this->row($blockers, 'returned_reports')['count']);
        $this->assertSame(6, $this->row($blockers, 'returned_reports')['oldestDays']);
    }

    public function test_today_counts_sessions_attendance_and_the_gate_manifest(): void
    {
        $this->actingAsRole('CENTER_MANAGER');

        foreach (['present', 'absent_unexcused', null] as $status) {
            [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled']);
            $s = Schedule::create([
                'candidate_id' => $c->id, 'assessment_id' => $a->id,
                'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
                'activity' => 'interview', 'location' => 'قاعة',
            ]);
            if ($status) {
                Attendance::create([
                    'schedule_id' => $s->id, 'status' => $status,
                    'check_in_time' => $status === 'present' ? now() : null, 'recorded_by' => null,
                ]);
            }
        }
        GateManifest::create([
            'manifest_date' => now()->toDateString(), 'gate_time' => '07:00',
            'location' => 'البوّابة الرئيسية', 'status' => GateManifest::DRAFT,
        ]);

        $today = $this->getJson('/api/analytics/executive/today')->assertOk()->json('today');

        $this->assertSame(3, $today['sessions']);
        $this->assertSame(1, $today['present']);
        $this->assertSame(1, $today['absent']);
        $this->assertSame(1, $today['notRecorded']);
        $this->assertSame(GateManifest::DRAFT, $today['gateManifest']['status']);
        $this->assertSame(0, $today['gateManifest']['names']);
    }
}
