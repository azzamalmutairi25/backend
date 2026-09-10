<?php

namespace Tests\Feature;

use App\Models\AssessorAbsence;
use App\Models\Role;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// الخدمة الرابعة: الفترة تعرف أيام عملها وطاقتها، والمستشار تُسجَّل إجازته
// مدىً وسبباً — ولا جلسةَ بلا فترة.
class PeriodCapacityAndLeaveTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function person(string $roleCode = 'EVALUATOR', string $sectorCode = 'DW'): User
    {
        $bound = in_array($roleCode, User::SECTOR_BOUND_ROLES, true);

        return User::create([
            'username' => 'u_'.substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستشار '.$roleCode,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'sector_id' => $bound ? Sector::where('code', $sectorCode)->value('id') : null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    // ═══ أيام العمل والطاقة ═══

    public function test_work_days_default_to_sunday_through_thursday(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->postJson('/api/scheduling-periods', [
            'name' => 'فترة الافتراض',
            'startDate' => now()->addDay()->toDateString(),
            'endDate' => now()->addDays(13)->toDateString(),
        ])->assertStatus(201);

        $this->assertSame([0, 1, 2, 3, 4], $res->json('period.workDays'));
    }

    public function test_working_days_exclude_the_weekend_and_the_excluded_dates(): void
    {
        // أسبوعان كاملان: أربعة عشر يوماً، منها عشرة أيام عمل
        $start = now()->startOfWeek(Carbon::SUNDAY)->addWeek();
        $period = SchedulingPeriod::create([
            'name' => 'فترة '.uniqid(),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(13)->toDateString(),
            'status' => 'draft',
            'work_days' => '0,1,2,3,4',
        ]);

        $this->assertSame(14, $period->dayCount(), 'المدى كلّه');
        $this->assertSame(10, $period->workingDayCount(), 'الجمعة والسبت خارج العمل');

        // عطلةٌ رسمية داخل المدى تُسقِط يومَ عملٍ آخر
        $period->update(['excluded_dates' => $start->copy()->addDay()->toDateString()]);
        $this->assertSame(9, $period->fresh()->workingDayCount());
    }

    public function test_the_target_total_is_days_times_capacity(): void
    {
        $start = now()->startOfWeek(Carbon::SUNDAY)->addWeek();
        $period = SchedulingPeriod::create([
            'name' => 'فترة '.uniqid(),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(13)->toDateString(),
            'status' => 'draft',
            'work_days' => '0,1,2,3,4',
            'daily_capacity' => 12,
        ]);

        $this->assertSame(120, $period->targetTotal(), 'عشرة أيام عمل × اثني عشر');

        // بلا طاقةٍ معلَنة لا هدف — ولا يُخترع رقم
        $period->update(['daily_capacity' => null]);
        $this->assertNull($period->fresh()->targetTotal());
    }

    public function test_capacity_and_excluded_dates_are_saved_and_returned(): void
    {
        $this->actingAsRole('SCHEDULER');
        $start = now()->addDay()->toDateString();

        $res = $this->postJson('/api/scheduling-periods', [
            'name' => 'فترة الطاقة',
            'startDate' => $start,
            'endDate' => now()->addDays(13)->toDateString(),
            'workDays' => [0, 1, 2],
            'dailyCapacity' => 8,
            'excludedDates' => [$start],
        ])->assertStatus(201);

        $this->assertSame([0, 1, 2], $res->json('period.workDays'));
        $this->assertSame(8, $res->json('period.dailyCapacity'));
        $this->assertSame([$start], $res->json('period.excludedDates'));
    }

    // ═══ لا جلسةَ بلا فترة ═══

    public function test_a_session_takes_the_narrowest_covering_period(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $date = now()->addDays(3)->toDateString();

        // فترةٌ ضيّقة تشمل اليوم — وأخرى واسعة (التحتيّة) تشمله أيضاً
        $narrow = SchedulingPeriod::create([
            'name' => 'الضيّقة',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'draft',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview', 'date' => $date, 'time' => '10:15',
        ])->assertStatus(201);

        $this->assertDatabaseHas('schedules', ['candidate_id' => $c->id, 'period_id' => $narrow->id]);
    }

    // ═══ إجازات المستشارين ═══

    public function test_an_absence_is_recorded_with_a_range_and_a_reason(): void
    {
        $ev = $this->person();
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/assessor-absences', [
            'userId' => $ev->id,
            'fromDate' => now()->addDays(2)->toDateString(),
            'toDate' => now()->addDays(5)->toDateString(),
            'reason' => 'leave',
        ])->assertStatus(201);

        $row = AssessorAbsence::firstOrFail();
        $this->assertSame($ev->id, $row->user_id);
        $this->assertSame('leave', $row->reason);
        $this->assertSame('إجازة', AssessorAbsence::reasonLabel($row->reason));
    }

    public function test_an_overlapping_absence_is_refused(): void
    {
        $ev = $this->person();
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/assessor-absences', [
            'userId' => $ev->id,
            'fromDate' => now()->addDays(2)->toDateString(),
            'toDate' => now()->addDays(5)->toDateString(),
            'reason' => 'leave',
        ])->assertStatus(201);

        // مدىً يغطّي مدىً يجعل الشبكة تقول شيئين عن اليوم نفسه
        $this->postJson('/api/assessor-absences', [
            'userId' => $ev->id,
            'fromDate' => now()->addDays(4)->toDateString(),
            'toDate' => now()->addDays(7)->toDateString(),
            'reason' => 'training',
        ])->assertStatus(422);

        $this->assertSame(1, AssessorAbsence::count());
    }

    public function test_a_backwards_range_is_refused(): void
    {
        $ev = $this->person();
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/assessor-absences', [
            'userId' => $ev->id,
            'fromDate' => now()->addDays(5)->toDateString(),
            'toDate' => now()->addDays(2)->toDateString(),
            'reason' => 'leave',
        ])->assertStatus(422);
    }

    // ═══ الغياب يسقط الاسم من ذلك اليوم وحده ═══

    public function test_an_absent_consultant_is_unavailable_on_those_days_only(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->person('EVALUATOR', 'DW');

        $away = now()->addDays(3);
        AssessorAbsence::create([
            'user_id' => $ev->id,
            'from_date' => $away->toDateString(),
            'to_date' => $away->copy()->addDay()->toDateString(),
            'reason' => 'leave',
        ]);

        $this->actingAsRole('SCHEDULER');

        $onLeave = collect($this->getJson(
            "/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator&date=".$away->toDateString()
        )->assertOk()->json('assessors'))->firstWhere('id', $ev->id);

        $this->assertTrue($onLeave['absentToday']);
        $this->assertFalse($onLeave['available'], 'الغائب غير متاح في يوم غيابه');

        // واليوم الذي بعده — عاد متاحاً، ولم يُشطب من الفترة كلّها
        $back = collect($this->getJson(
            "/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator&date=".$away->copy()->addDays(5)->toDateString()
        )->assertOk()->json('assessors'))->firstWhere('id', $ev->id);

        $this->assertFalse($back['absentToday']);
        $this->assertTrue($back['available']);
    }

    public function test_recording_an_absence_needs_the_schedule_permission(): void
    {
        $ev = $this->person();
        $this->actingAsRole('RECEPTIONIST');

        $this->postJson('/api/assessor-absences', [
            'userId' => $ev->id,
            'fromDate' => now()->addDay()->toDateString(),
            'toDate' => now()->addDays(2)->toDateString(),
            'reason' => 'leave',
        ])->assertStatus(403);
    }
}
