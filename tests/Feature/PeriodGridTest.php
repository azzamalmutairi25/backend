<?php

namespace Tests\Feature;

use App\Models\AssessorAbsence;
use App\Models\PeriodAssessor;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// شبكة جدولة المستشارين — أيام العمل × المستشارين، والخلية عددٌ مخطَّط.
class PeriodGridTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function consultant(string $code): User
    {
        return User::create([
            'username' => 'u_'.substr(md5(uniqid('', true)), 0, 8),
            'code' => $code,
            'full_name' => 'مستشار '.$code,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', 'DW')->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    /** فترةُ أسبوعين تبدأ أحداً — عشرة أيام عمل */
    private function period(int $capacity = 12): SchedulingPeriod
    {
        $start = now()->startOfWeek(Carbon::SUNDAY)->addWeek();

        return SchedulingPeriod::create([
            'name' => 'فترة '.uniqid(),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(13)->toDateString(),
            'status' => 'draft',
            'work_days' => '0,1,2,3,4',
            'daily_capacity' => $capacity,
        ]);
    }

    private function onPanel(SchedulingPeriod $p, User $u): void
    {
        PeriodAssessor::create([
            'period_id' => $p->id, 'user_id' => $u->id,
            'activity' => 'interview', 'seat' => 'evaluator', 'daily_quota' => 2,
        ]);
    }

    // ═══ الشكل ═══

    public function test_the_grid_has_one_row_per_working_day_and_one_column_per_panel_member(): void
    {
        $p = $this->period();
        $k = $this->consultant('K');
        $b = $this->consultant('B');
        $this->onPanel($p, $k);
        $this->onPanel($p, $b);
        $this->consultant('Z');   // ليس في اللوحة — لا عمود له

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertOk();

        $this->assertCount(10, $res->json('days'), 'أيام العمل وحدها — الجمعة والسبت خارجها');
        $this->assertSame(['B', 'K'], collect($res->json('consultants'))->pluck('code')->all(),
            'مرتّبةٌ بالرمز، ومن ليس في اللوحة لا عمود له');
        $this->assertSame(12, $res->json('period.dailyCapacity'));
        $this->assertSame(120, $res->json('period.targetTotal'));
    }

    // ═══ الحفظ والمجاميع ═══

    public function test_saving_cells_lifts_the_row_and_column_totals(): void
    {
        $p = $this->period();
        $k = $this->consultant('K');
        $this->onPanel($p, $k);
        $days = collect($p->workingDays())->map(fn ($d) => $d->toDateString())->all();

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$p->id}/grid", [
            'cells' => [
                ['userId' => $k->id, 'date' => $days[0], 'planned' => 2],
                ['userId' => $k->id, 'date' => $days[1], 'planned' => 1],
            ],
        ])->assertOk()->assertJsonPath('saved', 2);

        $res = $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertOk();

        $this->assertSame(2, $res->json('days.0.total'));
        $this->assertSame(1, $res->json('days.1.total'));
        $this->assertSame(3, $res->json("columnTotals.{$k->id}"), 'مجموع العمود حصّة المستشار');
        $this->assertSame(3, $res->json('grandTotal'));
    }

    public function test_the_row_total_is_compared_to_the_daily_capacity(): void
    {
        $p = $this->period(3);
        $k = $this->consultant('K');
        $this->onPanel($p, $k);
        $day = $p->workingDays()[0]->toDateString();

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$p->id}/grid", [
            'cells' => [['userId' => $k->id, 'date' => $day, 'planned' => 3]],
        ])->assertOk();

        $res = $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertOk();
        $this->assertTrue($res->json('days.0.matchesCapacity'), 'ثلاثة من ثلاثة');
        $this->assertFalse($res->json('days.1.matchesCapacity'), 'ويومٌ فارغ لا يبلغها');
    }

    // بلا طاقةٍ معلَنة لا مقارنة — ولا يُخترع رقم
    public function test_without_a_capacity_there_is_no_comparison(): void
    {
        $p = $this->period();
        $p->update(['daily_capacity' => null]);
        $k = $this->consultant('K');
        $this->onPanel($p, $k);

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertOk();

        $this->assertNull($res->json('days.0.matchesCapacity'));
        $this->assertNull($res->json('period.targetTotal'));
    }

    // ═══ الحجب ═══

    public function test_an_absence_blocks_the_cell_and_names_its_reason(): void
    {
        $p = $this->period();
        $k = $this->consultant('K');
        $this->onPanel($p, $k);
        $days = collect($p->workingDays())->map(fn ($d) => $d->toDateString())->all();

        AssessorAbsence::create([
            'user_id' => $k->id, 'from_date' => $days[0], 'to_date' => $days[1], 'reason' => 'leave',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$p->id}/grid", [
            'cells' => [['userId' => $k->id, 'date' => $days[0], 'planned' => 2]],
        ])->assertOk();

        $res = $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertOk();

        $this->assertSame('leave', $res->json('days.0.cells.0.blocked'));
        $this->assertSame('إجازة', $res->json('days.0.cells.0.blockedLabel'));
        $this->assertNull($res->json('days.0.cells.0.planned'), 'المحجوب لا عدد له');
        $this->assertSame(0, $res->json('days.0.total'), 'ولا يدخل مجموع اليوم');
    }

    public function test_a_training_pairing_shows_its_host(): void
    {
        $p = $this->period();
        $l = $this->consultant('L');
        $n = $this->consultant('N');
        $this->onPanel($p, $l);
        $days = collect($p->workingDays())->map(fn ($d) => $d->toDateString())->all();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/assessor-absences', [
            'userId' => $l->id, 'hostUserId' => $n->id,
            'fromDate' => $days[0], 'toDate' => $days[0], 'reason' => 'training',
        ])->assertStatus(201);

        $res = $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertOk();

        $this->assertSame('training', $res->json('days.0.cells.0.blocked'));
        $this->assertSame('N', $res->json('days.0.cells.0.hostCode'), 'يُرافق المضيف');
    }

    // المضيف للتدريب وحده — «يرافق فلاناً» لا معنى لها في إجازة
    public function test_a_host_is_ignored_for_a_plain_leave(): void
    {
        $l = $this->consultant('L');
        $n = $this->consultant('N');

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/assessor-absences', [
            'userId' => $l->id, 'hostUserId' => $n->id,
            'fromDate' => now()->addDays(2)->toDateString(),
            'toDate' => now()->addDays(3)->toDateString(),
            'reason' => 'leave',
        ])->assertStatus(201);

        $this->assertNull(AssessorAbsence::firstOrFail()->host_user_id);
    }

    // ═══ الخطّة مقابل التنفيذ ═══

    public function test_the_grid_shows_what_was_actually_assigned(): void
    {
        $p = $this->period();
        $k = $this->consultant('K');
        $this->onPanel($p, $k);
        $day = $p->workingDays()[0]->toDateString();

        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'period_id' => $p->id,
            'schedule_date' => $day, 'schedule_time' => '10:15',
            'activity' => 'interview', 'evaluator_id' => $k->id,
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$p->id}/grid", [
            'cells' => [['userId' => $k->id, 'date' => $day, 'planned' => 2]],
        ])->assertOk();

        $res = $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertOk();

        $this->assertSame(2, $res->json('days.0.cells.0.planned'), 'الخطّة مقعدان');
        $this->assertSame(1, $res->json('days.0.cells.0.assigned'), 'والتنفيذ واحد');
    }

    // ═══ الحراسات ═══

    public function test_a_cell_outside_the_working_days_is_refused(): void
    {
        $p = $this->period();
        $k = $this->consultant('K');
        $this->onPanel($p, $k);
        // الجمعة: داخل المدى وخارج أيام العمل
        $friday = $p->start_date->copy()->next(Carbon::FRIDAY)->toDateString();

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$p->id}/grid", [
            'cells' => [['userId' => $k->id, 'date' => $friday, 'planned' => 2]],
        ])->assertStatus(422);
    }

    public function test_a_consultant_outside_the_panel_is_refused(): void
    {
        $p = $this->period();
        $stranger = $this->consultant('Z');
        $day = $p->workingDays()[0]->toDateString();

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$p->id}/grid", [
            'cells' => [['userId' => $stranger->id, 'date' => $day, 'planned' => 2]],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('period_grid_cells')->count());
    }

    public function test_an_approved_period_refuses_grid_edits(): void
    {
        $p = $this->period();
        $k = $this->consultant('K');
        $this->onPanel($p, $k);
        $day = $p->workingDays()[0]->toDateString();
        $p->update(['status' => 'approved']);

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$p->id}/grid", [
            'cells' => [['userId' => $k->id, 'date' => $day, 'planned' => 2]],
        ])->assertStatus(422);
    }

    public function test_viewing_needs_schedule_view_and_saving_needs_manage(): void
    {
        $p = $this->period();

        // مسؤول العمليات يملك عرض الجدولة ولا يملك إدارتها
        $this->actingAsRole('OPERATIONS');
        $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertOk();
        $this->putJson("/api/scheduling-periods/{$p->id}/grid", ['cells' => []])->assertStatus(403);

        // ومن لا يملك العرض لا يفتحها أصلاً
        $this->actingAsRole('EVALUATOR', 'DW');
        $this->getJson("/api/scheduling-periods/{$p->id}/grid")->assertStatus(403);
    }
}
