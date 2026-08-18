<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// خدمة الجدولة: إنشاء/تعديل/حذف الجلسات، بوابة التصنيف، وحظر التعديل بعد الحضور
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function tomorrow(int $days = 1): string
    {
        return now()->addDays($days)->toDateString();
    }

    public function test_list_requires_schedule_view(): void
    {
        $this->actingAsRole('EXTERNAL_ADD'); // لا SCHEDULE_VIEW
        $this->getJson('/api/schedules')->assertStatus(403);
    }

    public function test_create_requires_schedule_manage(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('OPERATIONS'); // SCHEDULE_VIEW فقط، لا MANAGE
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(), 'time' => '09:30',
        ])->assertStatus(403);
    }

    public function test_scheduler_creates_session_bound_to_current_cycle(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER'); // SCHEDULE_MANAGE

        $id = $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(),
            'time' => '09:30', 'location' => 'قاعة 1',
        ])->assertCreated()->json('scheduleId');

        $s = Schedule::find($id);
        $this->assertNotNull($s);
        $this->assertSame($a->id, $s->assessment_id);   // مربوطة بالدورة الحالية
        $this->assertSame($c->id, $s->candidate_id);
        $this->assertSame('interview', $s->activity);
    }

    public function test_cannot_schedule_draft_candidate(): void
    {
        [$c] = $this->makeCandidate(['status' => 'draft']);
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(), 'time' => '09:30',
        ])->assertStatus(422);
    }

    public function test_past_date_is_rejected(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_classified_candidate_is_404_without_clearance(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'classification' => 'secret']);
        $this->actingAsRole('SCHEDULER'); // لا VIEW_CLASSIFIED
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(), 'time' => '09:30',
        ])->assertStatus(404);
    }

    // القفل بعد الحضور يبقى لمن لا يملك إدارة المشاركين (CANDIDATE_EDIT).
    // MEASURE_SUPER يجدول ويسجّل حضوراً لكنه لا يدير المشاركين.
    public function test_update_blocked_after_attendance_for_non_candidate_managers(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $sch = \App\Models\Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => $this->tomorrow(), 'schedule_time' => '10:00:00',
            'activity' => 'interview', 'evaluator_id' => $ev->id, 'location' => 'قاعة',
        ]);
        \App\Models\Attendance::create(['schedule_id' => $sch->id, 'status' => 'present', 'recorded_by' => null]);

        // مشرف القياس يجدول لكن لا CANDIDATE_EDIT — القفل قائم عليه
        $this->actingAsRole('MEASURE_SUPER');
        $this->putJson("/api/schedules/{$sch->id}", ['location' => 'قاعة أخرى'])->assertStatus(403);
    }

    // إدارة المشاركين تتجاوز القفل — وتغيير الموعد يُلغي الحضور المسجّل
    public function test_candidate_manager_overrides_the_lock_and_clears_stale_attendance(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER'); // يملك CANDIDATE_EDIT + SCHEDULE_MANAGE
        $id = $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(), 'time' => '09:30',
        ])->assertCreated()->json('scheduleId');
        \App\Models\Attendance::create(['schedule_id' => $id, 'status' => 'present', 'recorded_by' => null]);

        // تغيير المكان فقط: الحضور يبقى — لم يتبدّل الموعد
        $this->putJson("/api/schedules/{$id}", ['location' => 'قاعة ٣'])
            ->assertOk()->assertJsonPath('attendanceCleared', false);
        $this->assertDatabaseCount('attendance', 1);

        // تغيير التاريخ: الحضور يُلغى — حضورٌ لجلسة تبدّل موعدها
        $this->putJson("/api/schedules/{$id}", ['date' => $this->tomorrow(2)])
            ->assertOk()->assertJsonPath('attendanceCleared', true);
        $this->assertDatabaseCount('attendance', 0);

        $this->assertDatabaseHas('audit_logs', ['action' => 'UPDATE_SCHEDULE_OVERRIDE']);
    }

    public function test_delete_removes_a_clean_session(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER');
        $id = $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'measurement', 'date' => $this->tomorrow(), 'time' => '09:30',
        ])->assertCreated()->json('scheduleId');

        $this->deleteJson("/api/schedules/{$id}")->assertOk();
        $this->assertNull(Schedule::find($id));
    }

    public function test_list_returns_created_sessions(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'integration', 'date' => $this->tomorrow(), 'time' => '09:30',
        ])->assertCreated();

        $res = $this->getJson('/api/schedules')->assertOk();
        $this->assertCount(1, $res->json('schedules'));
        $this->assertSame('integration', $res->json('schedules.0.activity'));
    }

    public function test_partial_update_changes_only_location(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER');
        $id = $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(),
            'time' => '09:30', 'location' => 'قاعة 1',
        ])->assertCreated()->json('scheduleId');

        // تعديل الموقع فقط دون إرسال activity ولا time — يجب أن ينجح (تعديل جزئي)
        $this->putJson("/api/schedules/{$id}", ['location' => 'قاعة 2'])->assertOk();
        $this->assertSame('قاعة 2', Schedule::find($id)->location);
        $this->assertSame('interview', Schedule::find($id)->activity); // لم يتغيّر
    }
}
