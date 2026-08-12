<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// «كل مرحلة يدخلها المرشح يسجّل حضوره الذي يستقبله»
// المقيّم/المساعد: جلساتهم وحدها. الاستقبال/مشرف القياس: أي جلسة.
class AttendanceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function scheduleFor(?User $evaluator = null, string $activity = 'interview', ?User $assistant = null): Schedule
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        return Schedule::create([
            'candidate_id' => $c->id,
            'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(),
            'schedule_time' => '10:00:00',
            'activity' => $activity,
            'evaluator_id' => $evaluator?->id,
            'assistant_id' => $assistant?->id,
            'location' => 'قاعة 1',
        ]);
    }

    // ── الجلسات المُسنَدة ──

    public function test_evaluator_records_attendance_for_their_own_session(): void
    {
        $ev = $this->actingAsRole('EVALUATOR');
        $s = $this->scheduleFor($ev);

        $this->postJson("/api/attendance/{$s->id}/checkin")->assertOk();
        $this->assertSame('present', Attendance::where('schedule_id', $s->id)->value('status'));
    }

    public function test_evaluator_cannot_record_someone_elses_session(): void
    {
        $other = $this->actingAsRole('EVALUATOR');
        $s = $this->scheduleFor($other);

        $intruder = $this->actingAsRole('EVALUATOR'); // مقيّم آخر
        $this->postJson("/api/attendance/{$s->id}/checkin")->assertStatus(403);
        $this->postJson("/api/attendance/{$s->id}/absence", ['excused' => true])->assertStatus(403);

        // لا تسجيل لمرشّح لم يره (المعامل الثالث لـassertDatabaseCount اتصالٌ لا رسالة)
        $this->assertDatabaseCount('attendance', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DENIED_ATTENDANCE_NOT_ASSIGNED', 'user_id' => $intruder->id,
        ]);
    }

    public function test_assistant_records_the_session_assigned_to_them(): void
    {
        $as = $this->actingAsRole('ASSISTANT');
        $s = $this->scheduleFor(null, 'interview', $as);

        $this->postJson("/api/attendance/{$s->id}/checkin")->assertOk();
    }

    public function test_discussion_evaluator_records_their_own_circle(): void
    {
        $de = $this->actingAsRole('DISCUSSION_EVAL');
        $s = $this->scheduleFor($de, 'discussion');

        $this->postJson("/api/attendance/{$s->id}/checkin")->assertOk();
    }

    // ── من يستقبل الجميع ──

    public function test_receptionist_records_any_session(): void
    {
        $ev = $this->actingAsRole('EVALUATOR');
        $s = $this->scheduleFor($ev); // مُسنَدة لغيره

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/attendance/{$s->id}/checkin")->assertOk();
    }

    public function test_measurement_supervisor_records_any_measurement_session(): void
    {
        $s = $this->scheduleFor(null, 'measurement');

        $this->actingAsRole('MEASURE_SUPER');
        $this->postJson("/api/attendance/{$s->id}/checkin")->assertOk();
    }

    public function test_unassigned_session_is_recordable_only_by_record_any_holders(): void
    {
        $s = $this->scheduleFor(null); // لا مقيّم ولا مساعد

        $this->actingAsRole('EVALUATOR');
        $this->postJson("/api/attendance/{$s->id}/checkin")->assertStatus(403);

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/attendance/{$s->id}/checkin")->assertOk();
    }

    // ── ما زال يحتاج الصلاحية أصلاً ──

    public function test_role_without_attendance_record_is_refused_even_for_its_own_session(): void
    {
        $sch = $this->actingAsRole('SCHEDULER'); // attendance.view فقط
        $s = $this->scheduleFor($sch);

        $this->postJson("/api/attendance/{$s->id}/checkin")->assertStatus(403);
    }

    // ── ما تراه الواجهة ──

    public function test_today_flags_which_rows_the_viewer_may_record(): void
    {
        $ev = $this->actingAsRole('EVALUATOR');
        $mine = $this->scheduleFor($ev);
        $theirs = $this->scheduleFor($this->actingAsRole('EVALUATOR'));

        \Laravel\Sanctum\Sanctum::actingAs($ev);
        $rows = collect($this->getJson('/api/attendance/today')->assertOk()->json('attendance'))
            ->keyBy('id');

        $this->assertTrue($rows[$mine->id]['canRecord'], 'جلستي');
        $this->assertFalse($rows[$theirs->id]['canRecord'], 'جلسة غيري');
    }

    public function test_receptionist_may_record_every_row(): void
    {
        $this->scheduleFor($this->actingAsRole('EVALUATOR'));
        $this->scheduleFor(null, 'measurement');

        $this->actingAsRole('RECEPTIONIST');
        $rows = $this->getJson('/api/attendance/today')->assertOk()->json('attendance');

        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertTrue($r['canRecord']);
        }
    }

    // ── الحرّاس السابقة لم تنكسر ──

    public function test_classification_gate_still_precedes_the_assignment_gate(): void
    {
        $ev = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'classification' => 'secret']);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
            'activity' => 'interview', 'evaluator_id' => $ev->id, 'location' => 'قاعة 1',
        ]);

        // جلسته هو، لكن المرشح مصنّف — «غير موجود» لا «ليست لك»
        $this->postJson("/api/attendance/{$s->id}/checkin")->assertStatus(404);
    }

    public function test_own_session_on_another_day_is_still_refused(): void
    {
        $ev = $this->actingAsRole('EVALUATOR');
        $s = $this->scheduleFor($ev);
        DB::table('schedules')->where('id', $s->id)
            ->update(['schedule_date' => now()->addDay()->toDateString()]);

        $this->postJson("/api/attendance/{$s->id}/checkin")->assertStatus(422);
    }
}
