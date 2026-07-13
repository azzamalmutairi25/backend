<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\FinalReport;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// الأتمتة اليومية: كشف الغياب التلقائي، تذكير جلسات الغد، تصعيد التقارير المتأخرة
class DailyMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function userWithRole(string $roleCode): User
    {
        $role = Role::where('code', $roleCode)->firstOrFail();
        return User::create([
            'username' => 'u_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستخدم', 'role_id' => $role->id, 'is_active' => true,
            'must_change_password' => false, 'user_type' => 'external', 'password' => 'Kafaat@2026',
        ]);
    }

    public function test_past_session_without_attendance_becomes_absent(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $past = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->subDay()->toDateString(), 'activity' => 'interview',
        ]);

        Artisan::call('kafaat:daily');

        $att = Attendance::where('schedule_id', $past->id)->first();
        $this->assertNotNull($att);
        $this->assertSame('absent_unexcused', $att->status);
    }

    public function test_does_not_touch_future_or_recorded_sessions(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        // جلسة مستقبلية — لا تُمَسّ
        $future = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->addDay()->toDateString(), 'activity' => 'discussion',
        ]);
        // جلسة ماضية سُجّل حضورها — لا تُستبدل
        $recorded = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->subDay()->toDateString(), 'activity' => 'measurement',
        ]);
        Attendance::create(['schedule_id' => $recorded->id, 'status' => 'present', 'recorded_by' => null]);

        Artisan::call('kafaat:daily');

        $this->assertSame(0, Attendance::where('schedule_id', $future->id)->count());
        $this->assertSame('present', Attendance::where('schedule_id', $recorded->id)->first()->status);
    }

    public function test_tomorrow_session_reminds_the_evaluator(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $evaluator = $this->userWithRole('EVALUATOR');
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->addDay()->toDateString(), 'activity' => 'interview',
            'evaluator_id' => $evaluator->id,
        ]);

        Artisan::call('kafaat:daily');

        $this->assertTrue(
            Notification::where('recipient_id', $evaluator->id)->where('entity_type', 'schedule')->exists()
        );
    }

    public function test_overdue_pending_report_is_escalated_to_dev_manager(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $devManager = $this->userWithRole('DEV_MANAGER');
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'status' => 'pending_dev_approval', 'created_by' => null,
        ]);
        // اجعله متأخّراً 5 أيام (يتجاوز المهلة الافتراضية 3)
        DB::table('final_reports')->where('id', $report->id)->update(['updated_at' => now()->subDays(5)]);

        Artisan::call('kafaat:daily', ['--days' => 3]);

        $this->assertTrue(
            Notification::where('recipient_id', $devManager->id)
                ->where('entity_type', 'report')->where('type', 'approval')->exists()
        );
    }

    public function test_recent_pending_report_is_not_escalated(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $devManager = $this->userWithRole('DEV_MANAGER');
        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'status' => 'pending_dev_approval', 'created_by' => null,
        ]); // updated_at = now → ضمن المهلة

        Artisan::call('kafaat:daily', ['--days' => 3]);

        $this->assertFalse(
            Notification::where('recipient_id', $devManager->id)->where('entity_type', 'report')->exists()
        );
    }

    public function test_overdue_report_escalates_only_once_across_runs(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $devManager = $this->userWithRole('DEV_MANAGER');
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'status' => 'pending_dev_approval', 'created_by' => null,
        ]);
        DB::table('final_reports')->where('id', $report->id)->update(['updated_at' => now()->subDays(5)]);

        Artisan::call('kafaat:daily', ['--days' => 3]);
        Artisan::call('kafaat:daily', ['--days' => 3]); // تشغيل ثانٍ — يجب ألا يُعيد التصعيد

        $this->assertSame(1, Notification::where('recipient_id', $devManager->id)
            ->where('entity_type', 'report')->count());
    }

    public function test_no_show_ignores_sessions_outside_window(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $old = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->subDays(20)->toDateString(), 'activity' => 'interview',
        ]);
        Artisan::call('kafaat:daily'); // النافذة الافتراضية 7 أيام

        // جلسة قديمة خارج النافذة لا تُوسَم غياباً (تفادي وسم كل التاريخ عند أول تشغيل)
        $this->assertSame(0, Attendance::where('schedule_id', $old->id)->count());
    }
}
