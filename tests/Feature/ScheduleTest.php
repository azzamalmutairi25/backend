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

    private function tomorrow(): string
    {
        return now()->addDay()->toDateString();
    }

    public function test_list_requires_schedule_view(): void
    {
        $this->actingAsRole('EXTERNAL_ADD'); // لا SCHEDULE_VIEW
        $this->getJson('/api/schedules')->assertStatus(403);
    }

    public function test_create_requires_schedule_manage(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('CENTER_MANAGER'); // SCHEDULE_VIEW فقط، لا MANAGE
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(),
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
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(),
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
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(),
        ])->assertStatus(404);
    }

    public function test_update_and_delete_blocked_after_attendance(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER');
        $id = $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $this->tomorrow(),
        ])->assertCreated()->json('scheduleId');

        Attendance::create(['schedule_id' => $id, 'status' => 'present', 'recorded_by' => null]);

        $this->putJson("/api/schedules/{$id}", ['activity' => 'discussion', 'date' => $this->tomorrow()])
            ->assertStatus(422);
        $this->deleteJson("/api/schedules/{$id}")->assertStatus(422);
    }

    public function test_delete_removes_a_clean_session(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER');
        $id = $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'measurement', 'date' => $this->tomorrow(),
        ])->assertCreated()->json('scheduleId');

        $this->deleteJson("/api/schedules/{$id}")->assertOk();
        $this->assertNull(Schedule::find($id));
    }

    public function test_list_returns_created_sessions(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'integration', 'date' => $this->tomorrow(),
        ])->assertCreated();

        $res = $this->getJson('/api/schedules')->assertOk();
        $this->assertCount(1, $res->json('schedules'));
        $this->assertSame('integration', $res->json('schedules.0.activity'));
    }
}
