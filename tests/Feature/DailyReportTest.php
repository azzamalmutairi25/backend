<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Services\DailyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// التقرير اليومي لمدير المركز — عرض وطباعة.
class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function sessionToday(string $attStatus, ?string $reason = null): Schedule
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
            'activity' => 'interview', 'location' => 'قاعة',
        ]);
        Attendance::create([
            'schedule_id' => $s->id, 'status' => $attStatus,
            'absence_reason' => $reason, 'check_in_time' => $attStatus === 'present' ? now() : null,
            'recorded_by' => null,
        ]);
        return $s;
    }

    public function test_requires_analytics_view(): void
    {
        $this->actingAsRole('EVALUATOR');
        $this->getJson('/api/daily-report')->assertStatus(403);
        $this->get('/api/daily-report/document')->assertStatus(403);
    }

    public function test_center_manager_sees_the_report(): void
    {
        $this->sessionToday('present');
        $this->sessionToday('absent_unexcused', 'ظرف طارئ');

        $this->actingAsRole('CENTER_MANAGER');
        $res = $this->getJson('/api/daily-report')->assertOk();

        $res->assertJsonPath('totals.present', 1);
        $res->assertJsonPath('totals.absent', 1);
        $this->assertSame('ظرف طارئ', $res->json('absences.0.reason'));
    }

    public function test_totals_count_each_attendance_state(): void
    {
        $this->sessionToday('present');
        $this->sessionToday('present');
        $this->sessionToday('absent_excused', 'سفر');
        $this->sessionToday('pending');

        $this->actingAsRole('ADMIN');
        $res = $this->getJson('/api/daily-report')->assertOk();

        $res->assertJsonPath('totals.sessions', 4);
        $res->assertJsonPath('totals.present', 2);
        $res->assertJsonPath('totals.absent', 1);
        $res->assertJsonPath('totals.pending', 1);
    }

    public function test_document_renders_printable_html(): void
    {
        $this->sessionToday('absent_unexcused', 'لم يحضر');
        $this->actingAsRole('CENTER_MANAGER');

        $res = $this->get('/api/daily-report/document')->assertOk();
        $res->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $html = $res->getContent();
        $this->assertStringContainsString('التقرير اليومي', $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString('لم يحضر', $html);
    }

    public function test_report_reflects_a_specific_date(): void
    {
        // جلسة أمس بغياب — لا تظهر في تقرير اليوم
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->subDay()->toDateString(), 'schedule_time' => '10:00:00',
            'activity' => 'interview', 'location' => 'x',
        ]);
        Attendance::create(['schedule_id' => $s->id, 'status' => 'present', 'check_in_time' => now()->subDay(), 'recorded_by' => null]);

        $this->actingAsRole('ADMIN');
        $today = $this->getJson('/api/daily-report')->assertOk();
        $this->assertSame(0, $today->json('totals.sessions'), 'جلسة أمس ليست في تقرير اليوم');

        $yesterday = $this->getJson('/api/daily-report?date=' . now()->subDay()->toDateString())->assertOk();
        $this->assertSame(1, $yesterday->json('totals.present'));
    }

    public function test_empty_day_renders_without_error(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $res = $this->getJson('/api/daily-report')->assertOk();
        $this->assertSame(0, $res->json('totals.sessions'));

        $html = $this->get('/api/daily-report/document')->assertOk()->getContent();
        $this->assertStringContainsString('لا حضور مسجّل', $html);
    }

    public function test_service_gather_shape(): void
    {
        $this->sessionToday('present');
        $data = app(DailyReportService::class)->gather();

        foreach (['date', 'totals', 'absences', 'presence', 'scores', 'reports'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }
}
