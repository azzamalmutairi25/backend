<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\PeriodAssessor;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  الغياب — سببٌ يُكتب، ومقعدٌ يُفرَّغ، وقائمةٌ تُقرأ.
//
//  الغياب لا ينتهي بتسجيله: عليه يُبنى قرارُ مسؤول الجدولة — أيُعاد جدولةً
//  أم يُرجَع للقائمة. فالسبب ليس زينة، والقائمة ليست سجلّاً بل أداةَ قرار.
// ════════════════════════════════════════════════════════════
class AbsenteeListTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function sessionToday(string $activity = 'interview', ?User $ev = null): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '09:00',
            'activity' => $activity, 'evaluator_id' => $ev?->id,
        ]);

        return [$c, $a, $s];
    }

    private function markAbsent(Schedule $s, bool $excused, ?string $reason): TestResponse
    {
        // من يسجّل الغياب هو الاستقبال: تسجيلُ غيابِ جلسةٍ ليست مُسنَدةً إليك
        // يحتاج `attendance.record_any`، وهي عنده لا عند مسؤول الجدولة — وهذا
        // يقرأ القائمة ويقرّر، ولا يسجّل الحضور بيده.
        $this->actingAsRole('RECEPTIONIST');

        return $this->postJson("/api/attendance/{$s->id}/absence", array_filter([
            'excused' => $excused,
            'reason' => $reason,
        ], fn ($v) => $v !== null));
    }

    // ═══ السبب ═══

    // كان الإلزام في الواجهة وحدها — ونداءٌ مباشر يسجّل غياباً بلا كلمة
    public function test_an_unexcused_absence_demands_a_written_reason(): void
    {
        [, , $s] = $this->sessionToday();

        $this->markAbsent($s, false, null)
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'اكتب سبب الغياب — عليه يُبنى قرارُ إعادة الجدولة');

        $this->assertSame(0, Attendance::count());

        $this->markAbsent($s, false, 'لم يحضر ولم يعتذر')->assertOk();
        $this->assertSame('لم يحضر ولم يعتذر', Attendance::firstOrFail()->absence_reason);
    }

    // وبعذرٍ يُقبل بلا سبب — العذر نفسه بيانٌ، وإلزامُه يدفع لكتابة حشو
    public function test_an_excused_absence_may_omit_the_reason(): void
    {
        [, , $s] = $this->sessionToday();

        $this->markAbsent($s, true, null)->assertOk();
        $this->assertTrue(Attendance::firstOrFail()->isAbsent());
    }

    // ═══ القائمة ═══

    public function test_the_list_gathers_the_absentees_with_their_reasons(): void
    {
        [$c, , $s] = $this->sessionToday();
        $this->markAbsent($s, false, 'ارتباط أمني طارئ')->assertOk();

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson('/api/schedules/absentees')->assertOk();
        $row = collect($res->json('absentees'))->firstWhere('scheduleId', $s->id);

        $this->assertNotNull($row, 'كان الموجود مساراً لمشاركٍ واحدٍ بمعرّفه');
        $this->assertSame('ارتباط أمني طارئ', $row['reason']);
        $this->assertFalse($row['excused']);
        $this->assertSame('المقابلة الشخصية', $row['activityLabel']);
        $this->assertSame($c->id, $row['candidateId']);
        $this->assertSame(1, $res->json('totals.unexcused'));
    }

    public function test_a_present_participant_is_not_in_the_list(): void
    {
        [, , $s] = $this->sessionToday();
        Attendance::create(['schedule_id' => $s->id, 'status' => 'present', 'check_in_time' => now()]);

        $this->actingAsRole('SCHEDULER');
        $this->assertSame(0, $this->getJson('/api/schedules/absentees')->json('totals.shown'));
    }

    // ── المعالَج يُخفى ──
    // القائمة أداةُ قرارٍ لا سجلٌّ للقراءة: ما عولج يضيّع ما ينتظر قراراً
    public function test_a_rescheduled_absence_drops_out_unless_asked_for(): void
    {
        [$c, $a, $s] = $this->sessionToday();
        $this->markAbsent($s, true, 'مرض')->assertOk();

        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->addDays(3)->toDateString(), 'schedule_time' => '09:00',
            'activity' => 'interview',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->assertSame(0, $this->getJson('/api/schedules/absentees')->json('totals.shown'));

        $row = collect($this->getJson('/api/schedules/absentees?handled=1')->json('absentees'))
            ->firstWhere('scheduleId', $s->id);
        $this->assertSame(now()->addDays(3)->toDateString(), $row['rescheduledTo']);
    }

    // ويبقى على اليوم الذي جُدول فيه — نقلُه يمحو أن المقعد حُجز وتُرك فارغاً
    public function test_the_absence_stays_on_the_day_it_was_scheduled(): void
    {
        [, , $s] = $this->sessionToday();
        $this->markAbsent($s, true, 'سفر')->assertOk();

        $this->actingAsRole('SCHEDULER');
        $row = collect($this->getJson('/api/schedules/absentees')->json('absentees'))
            ->firstWhere('scheduleId', $s->id);

        $this->assertSame($s->schedule_date->toDateString(), $row['date']);
    }

    public function test_the_window_defaults_to_a_fortnight_back(): void
    {
        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson('/api/schedules/absentees')->assertOk();

        $this->assertSame(now()->subDays(14)->toDateString(), $res->json('from'));
        $this->assertSame(now()->toDateString(), $res->json('to'));
    }

    public function test_the_list_needs_the_scheduling_permission(): void
    {
        $this->actingAsRole('EVALUATOR', 'DW');
        $this->getJson('/api/schedules/absentees')->assertStatus(403);
    }

    // ═══ المقعد في الشبكة ═══

    // مقعد الغائب يُترك فارغاً فعلاً — وعدُّه مشغولاً يُخفي النقص عمّن يعالجه
    public function test_an_absentee_frees_the_seat_in_the_grid(): void
    {
        $ev = User::create([
            'username' => 'g_'.substr(md5(uniqid('', true)), 0, 8),
            'code' => 'G', 'full_name' => 'مستشار الشبكة', 'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', 'DW')->value('id'),
            'is_active' => true, 'must_change_password' => false,
        ]);

        $start = now()->startOfWeek(Carbon::SUNDAY);
        $period = SchedulingPeriod::create([
            'name' => 'موجة المقعد',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(13)->toDateString(),
            // أيامُ الأسبوع كلُّها أيامُ عمل في هذه الموجة: الغيابُ لا يُسجَّل إلا لجلسة
            // اليوم، والشبكةُ لا تعرض إلا أيام العمل. فبأيام الأحد–الخميس كان الاختبار
            // يسقط كلَّ جمعةٍ وسبت — اليومُ لا صفَّ له، فيُقرأ الصفُّ فارغاً.
            'status' => 'draft', 'work_days' => '0,1,2,3,4,5,6', 'daily_capacity' => 4,
        ]);
        PeriodAssessor::create(['period_id' => $period->id, 'user_id' => $ev->id,
            'activity' => 'interview', 'seat' => 'evaluator']);

        [, , $s1] = $this->sessionToday('interview', $ev);
        [, , $s2] = $this->sessionToday('discussion', $ev);
        Schedule::whereIn('id', [$s1->id, $s2->id])->update(['period_id' => $period->id]);

        $this->actingAsRole('SCHEDULER');
        $day = now()->toDateString();
        $before = collect($this->getJson("/api/scheduling-periods/{$period->id}/grid")->json('days'))
            ->firstWhere('date', $day);
        $this->assertSame(2, $before['cells'][0]['assigned']);

        $this->markAbsent($s1, false, 'لم يحضر')->assertOk();

        // `markAbsent` تُبدّل الفاعل إلى الاستقبال، وهو لا يفتح الشبكة
        $this->actingAsRole('SCHEDULER');
        $after = collect($this->getJson("/api/scheduling-periods/{$period->id}/grid")->json('days'))
            ->firstWhere('date', $day);
        $this->assertSame(1, $after['cells'][0]['assigned'],
            'المقعد تُرك فارغاً — فيظهر اليوم ناقصاً لمن يعالجه');
    }
}
