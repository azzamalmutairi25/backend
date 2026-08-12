<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// الغياب ← إعادة الجدولة بتاريخ جديد + علم الغياب في قائمة المرشحين.
class RescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function sessionWith(?string $attStatus = null): Schedule
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
            'activity' => 'interview', 'evaluator_id' => null, 'location' => 'قاعة 1',
        ]);
        if ($attStatus) {
            Attendance::create(['schedule_id' => $s->id, 'status' => $attStatus,
                'absence_reason' => 'ظرف طارئ', 'recorded_by' => null]);
        }
        return $s;
    }

    // ── علم الغياب في القائمة ──

    public function test_candidate_list_flags_those_with_a_recorded_absence(): void
    {
        $absent = $this->sessionWith('absent_unexcused');
        $present = $this->sessionWith('present');
        $none = $this->sessionWith();

        $this->actingAsRole('SCHEDULER');
        $rows = collect($this->getJson('/api/candidates')->assertOk()->json('candidates'))->keyBy('id');

        $this->assertTrue($rows[$absent->candidate_id]['hasAbsence'], 'الغائب مُعلَّم');
        $this->assertFalse($rows[$present->candidate_id]['hasAbsence'], 'الحاضر ليس مُعلَّماً');
        $this->assertFalse($rows[$none->candidate_id]['hasAbsence'], 'بلا حضور مسجّل');
    }

    public function test_both_absence_kinds_set_the_flag(): void
    {
        foreach (['absent_excused', 'absent_unexcused'] as $st) {
            $s = $this->sessionWith($st);
            $this->actingAsRole('SCHEDULER');
            $rows = collect($this->getJson('/api/candidates')->json('candidates'))->keyBy('id');
            $this->assertTrue($rows[$s->candidate_id]['hasAbsence'], $st);
        }
    }

    // ── إعادة الجدولة ──

    public function test_candidate_manager_reschedules_an_absent_session(): void
    {
        $s = $this->sessionWith('absent_excused');
        $this->actingAsRole('SCHEDULER');

        $date = now()->addDays(3)->toDateString();
        $res = $this->postJson("/api/schedules/{$s->id}/reschedule", ['date' => $date])
            ->assertCreated();

        $new = Schedule::find($res->json('scheduleId'));
        $this->assertStringStartsWith($date, (string) $new->schedule_date);
        $this->assertSame('interview', $new->activity, 'نفس النشاط الذي تغيّب عنه');
        $this->assertSame($s->candidate_id, $new->candidate_id);
        // الجلسة القديمة تبقى للتدقيق
        $this->assertNotNull(Schedule::find($s->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'RESCHEDULE_SESSION']);
    }

    public function test_reschedule_requires_candidate_edit(): void
    {
        $s = $this->sessionWith('absent_unexcused');
        // مشرف القياس يجدول لكن لا يدير المرشحين
        $this->actingAsRole('MEASURE_SUPER');
        $this->postJson("/api/schedules/{$s->id}/reschedule", ['date' => now()->addDay()->toDateString()])
            ->assertStatus(403);
    }

    public function test_cannot_reschedule_a_session_without_an_absence(): void
    {
        $present = $this->sessionWith('present');
        $none = $this->sessionWith();
        $this->actingAsRole('SCHEDULER');

        $d = now()->addDay()->toDateString();
        $this->postJson("/api/schedules/{$present->id}/reschedule", ['date' => $d])->assertStatus(422);
        $this->postJson("/api/schedules/{$none->id}/reschedule", ['date' => $d])->assertStatus(422);
    }

    public function test_reschedule_rejects_a_past_date(): void
    {
        $s = $this->sessionWith('absent_excused');
        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/schedules/{$s->id}/reschedule", ['date' => now()->subDay()->toDateString()])
            ->assertStatus(422);
    }

    public function test_reschedule_is_404_for_out_of_scope_candidate(): void
    {
        $s = $this->sessionWith('absent_unexcused'); // قطاع ED
        $this->actingAsRole('SCHEDULER');
        // بمرشّح مصنّف خارج الصلاحية
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'classification' => 'secret']);
        $classified = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
            'activity' => 'interview', 'location' => 'x',
        ]);
        Attendance::create(['schedule_id' => $classified->id, 'status' => 'absent_excused', 'recorded_by' => null]);

        $this->postJson("/api/schedules/{$classified->id}/reschedule", ['date' => now()->addDay()->toDateString()])
            ->assertStatus(404);
    }
}
