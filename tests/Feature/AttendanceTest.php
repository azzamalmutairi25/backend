<?php

namespace Tests\Feature;

use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function todaySchedule(array $candAttrs = []): Schedule
    {
        [$c, $a] = $this->makeCandidate($candAttrs + ['status' => 'scheduled']);
        return Schedule::create([
            'candidate_id' => $c->id,
            'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(),
            'schedule_time' => '10:00:00',
            'activity' => 'interview',
            'location' => 'قاعة 1',
        ]);
    }

    public function test_check_in_is_one_time(): void
    {
        $s = $this->todaySchedule();
        $this->actingAsRole('RECEPTIONIST'); // ATTENDANCE_RECORD

        $this->postJson("/api/attendance/{$s->id}/checkin")->assertOk();
        $this->assertDatabaseHas('attendance', ['schedule_id' => $s->id, 'status' => 'present']);

        // a second recording is refused
        $this->postJson("/api/attendance/{$s->id}/checkin")->assertStatus(422);
        $this->assertDatabaseCount('attendance', 1);
    }

    public function test_classified_schedule_reads_as_404_for_uncleared_recorder(): void
    {
        $s = $this->todaySchedule(['classification' => 'secret']);
        $this->actingAsRole('RECEPTIONIST'); // no view_classified

        $this->postJson("/api/attendance/{$s->id}/checkin")->assertStatus(404);
        $this->assertDatabaseCount('attendance', 0);
    }
}
