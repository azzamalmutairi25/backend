<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// إصلاحات مراجعة الحضور/القياس/الجدولة:
//  - حصر القطاع في مسارات الجدولة/الحضور بالمعرّف (كان التصنيف وحده).
//  - تعديل جلسة لا يحذف حضوراً سليماً إن لم يتغيّر الموعد.
//  - إعادة الجدولة مرّة واحدة لكل غياب، وضمن دورة نشطة.
class AssessmentDataFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // جلسة جاهزة لمرشّح في قطاع محدّد
    private function scheduleFor(string $sectorCode, string $date, string $candidateStatus = 'assessed'): Schedule
    {
        [$c, $a] = $this->makeCandidate([
            'sectorCode' => $sectorCode,
            'status' => $candidateStatus,
            'assessmentStatus' => $candidateStatus === 'completed' ? 'completed' : 'scheduled',
        ]);
        return Schedule::create([
            'candidate_id' => $c->id,
            'assessment_id' => $a->id,
            'schedule_date' => $date,
            'activity' => 'interview',
        ]);
    }

    // ── Fix A: مقيّم (ED) مُنِح schedule.manage لا يجدول مرشّح قطاع آخر (HI) ──
    public function test_schedule_store_is_sector_scoped(): void
    {
        $actor = $this->actingAsRole('EVALUATOR', 'ED');
        $actor->permissionOverrides()->create(['permission' => 'schedule.manage', 'granted' => true]);

        [$otherSector] = $this->makeCandidate(['sectorCode' => 'HI', 'status' => 'assessed']);
        $this->postJson('/api/schedules', [
            'candidateId' => $otherSector->id, 'activity' => 'interview',
            'date' => now()->addDay()->toDateString(),
        ])->assertStatus(404);
    }

    // ── Fix A: تعديل/حذف جلسة قطاع آخر = «غير موجودة» (404) ──
    public function test_schedule_update_and_destroy_are_sector_scoped(): void
    {
        $actor = $this->actingAsRole('EVALUATOR', 'ED');
        $actor->permissionOverrides()->create(['permission' => 'schedule.manage', 'granted' => true]);
        $actor->permissionOverrides()->create(['permission' => 'candidate.edit', 'granted' => true]);

        $sch = $this->scheduleFor('HI', now()->addDay()->toDateString());

        $this->putJson("/api/schedules/{$sch->id}", ['date' => now()->addDays(2)->toDateString()])
            ->assertStatus(404);
        $this->deleteJson("/api/schedules/{$sch->id}")->assertStatus(404);
        $this->postJson("/api/schedules/{$sch->id}/reschedule", ['date' => now()->addDays(3)->toDateString()])
            ->assertStatus(404);
    }

    // ── Fix B: الحضور خارج القطاع = 404 لا 403 (لا مِكشاف وجود) ──
    public function test_attendance_out_of_sector_returns_404_not_403(): void
    {
        $this->actingAsRole('EVALUATOR', 'ED'); // يملك attendance.record بالدور
        $sch = $this->scheduleFor('HI', now()->toDateString());

        $this->postJson("/api/attendance/{$sch->id}/checkin")->assertStatus(404);
        $this->postJson("/api/attendance/{$sch->id}/absence", ['excused' => true])->assertStatus(404);
    }

    // ── Fix C: تعديل المكان بنفس التاريخ لا يحذف الحضور المسجّل ──
    public function test_updating_location_same_date_preserves_attendance(): void
    {
        $actor = $this->actingAsRole('SCHEDULER'); // SCHEDULE_MANAGE + CANDIDATE_EDIT
        $today = now()->toDateString();
        $sch = $this->scheduleFor('ED', $today);
        Attendance::create(['schedule_id' => $sch->id, 'status' => 'present', 'check_in_time' => now(), 'recorded_by' => $actor->id]);

        $res = $this->putJson("/api/schedules/{$sch->id}", ['location' => 'قاعة ب', 'date' => $today])->assertOk();
        $this->assertFalse($res->json('attendanceCleared'));
        $this->assertSame(1, Attendance::where('schedule_id', $sch->id)->count());

        // تغيير التاريخ فعلاً يُبطل الحضور (السلوك المقصود)
        $res2 = $this->putJson("/api/schedules/{$sch->id}", ['date' => now()->addDay()->toDateString()])->assertOk();
        $this->assertTrue($res2->json('attendanceCleared'));
        $this->assertSame(0, Attendance::where('schedule_id', $sch->id)->count());
    }

    // ── Fix D: إعادة جدولة الغياب مرّة واحدة — الثانية 409 وجلسة واحدة تُنشأ ──
    public function test_reschedule_is_one_shot(): void
    {
        $actor = $this->actingAsRole('SCHEDULER'); // CANDIDATE_EDIT
        $sch = $this->scheduleFor('ED', now()->subDay()->toDateString());
        Attendance::create(['schedule_id' => $sch->id, 'status' => 'absent_unexcused', 'recorded_by' => $actor->id]);

        $date = now()->addDays(3)->toDateString();
        $this->postJson("/api/schedules/{$sch->id}/reschedule", ['date' => $date])->assertStatus(201);
        $this->postJson("/api/schedules/{$sch->id}/reschedule", ['date' => $date])->assertStatus(409);

        // الأصلية + واحدة جديدة فقط
        $this->assertSame(2, Schedule::where('candidate_id', $sch->candidate_id)->count());
    }

    // ── Fix D: لا تُعاد جدولة مرشّح أنهى دورته (لا دورة نشطة) ──
    public function test_reschedule_rejects_completed_cycle(): void
    {
        $actor = $this->actingAsRole('SCHEDULER');
        $sch = $this->scheduleFor('ED', now()->subDay()->toDateString(), 'completed');
        Attendance::create(['schedule_id' => $sch->id, 'status' => 'absent_unexcused', 'recorded_by' => $actor->id]);

        $this->postJson("/api/schedules/{$sch->id}/reschedule", ['date' => now()->addDays(3)->toDateString()])
            ->assertStatus(422);
    }

    // ── Fix F: بلا فلتر، جلسات أقدم من ٦٠ يوماً لا تظهر في القائمة ──
    public function test_index_excludes_old_schedules_when_unfiltered(): void
    {
        $this->actingAsRole('SCHEDULER'); // SCHEDULE_VIEW
        $old = $this->scheduleFor('ED', now()->subDays(90)->toDateString());
        $recent = $this->scheduleFor('ED', now()->addDay()->toDateString());

        $ids = collect($this->getJson('/api/schedules')->assertOk()->json('schedules'))->pluck('id');
        $this->assertFalse($ids->contains($old->id));
        $this->assertTrue($ids->contains($recent->id));
    }
}
