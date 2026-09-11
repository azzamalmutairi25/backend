<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\DispatchAuthority;
use App\Models\MeasurementResult;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleDispatch;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// نظرة شاملة — طاقة الجدولة، والتسليم للجهات، والمقياس الشخصي، وعدّاداتٌ تُفتح
class ExecutiveOverviewActionableTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function section(string $key): array
    {
        return collect($this->getJson('/api/analytics/executive/overview')->assertOk()->json('sections'))
            ->keyBy('key')[$key];
    }

    private function metric(array $section, string $label)
    {
        return collect($section['metrics'])->firstWhere('label', $label);
    }

    private function scheduledSession(string $category, ?int $periodId, ?string $attendance): Schedule
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled', 'personnelCategory' => $category]);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
            'activity' => 'interview', 'location' => 'قاعة',
        ]);
        if ($periodId) {
            DB::table('schedules')->where('id', $s->id)->update(['period_id' => $periodId]);
        }
        if ($attendance) {
            Attendance::create([
                'schedule_id' => $s->id, 'status' => $attendance,
                'check_in_time' => $attendance === 'present' ? now() : null, 'recorded_by' => null,
            ]);
        }

        return $s;
    }

    public function test_capacity_compares_target_planned_scheduled_and_held(): void
    {
        $user = $this->actingAsRole('CENTER_MANAGER');
        // خمسة أيام عمل (كل أيام الأسبوع عاملة) × طاقة يومية ٢ = ١٠
        $period = SchedulingPeriod::create([
            'name' => 'موجة الطاقة', 'status' => 'draft',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(4)->toDateString(),
            'work_days' => '0,1,2,3,4,5,6', 'daily_capacity' => 2,
        ]);
        DB::table('period_grid_cells')->insert([
            'period_id' => $period->id, 'user_id' => $user->id, 'cell_date' => now()->toDateString(),
            'planned' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->scheduledSession('civilian', $period->id, 'present');
        $this->scheduledSession('civilian', $period->id, null);

        $cap = $this->section('capacity');

        $this->assertSame(10, $this->metric($cap, 'المستهدف')['value']);
        $this->assertSame(3, $this->metric($cap, 'مخطَّط في الشبكة')['value']);
        $this->assertSame(20, $this->metric($cap, 'مجدول من المستهدف')['value']);
        $this->assertSame(10, $this->metric($cap, 'انعقد من المستهدف')['value']);
    }

    public function test_dispatch_matches_sent_sessions_with_the_ones_that_were_held(): void
    {
        $user = $this->actingAsRole('CENTER_MANAGER');
        $authority = DispatchAuthority::all()->first(fn ($a) => in_array('civilian', $a->categoryList(), true));
        $this->assertNotNull($authority, 'لا جهة مبذورة للفئة المدنية');

        ScheduleDispatch::create([
            'authority_id' => $authority->id, 'period_id' => null,
            'date_from' => now()->subDays(3)->toDateString(), 'date_to' => now()->toDateString(),
            'rows_count' => 4, 'channel' => 'email', 'checksum' => str_repeat('a', 64),
            'sent_by' => $user->id, 'sent_at' => now(),
        ]);
        $this->scheduledSession('civilian', null, 'present');
        $this->scheduledSession('civilian', null, 'absent_unexcused');
        $this->scheduledSession('military', null, 'present');   // فئةٌ ليست للجهة — لا تُحسب لها

        $d = $this->section('dispatch');

        $this->assertSame(1, $this->metric($d, 'تسليمات')['value']);
        $this->assertSame(4, $this->metric($d, 'جلسات مُسلَّمة')['value']);
        $this->assertSame(1, $this->metric($d, 'انعقدت بحضور')['value']);
        $this->assertSame(25, $this->metric($d, 'نسبة الانعقاد')['value']);
    }

    public function test_measurement_card_shows_the_personality_average(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'assessed']);
        MeasurementResult::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'personality_score' => 70, 'analytical_score' => 60, 'english_score' => 80,
        ]);

        $m = $this->section('measurement');

        $this->assertEquals(70, $this->metric($m, 'متوسط المقياس الشخصي')['value']);
        $this->assertSame(1, $this->metric($m, 'نتائج ٣٠ يوماً')['value']);
    }

    public function test_counters_with_a_filtered_destination_carry_it(): void
    {
        $this->actingAsRole('CENTER_MANAGER');

        $returned = $this->metric($this->section('reports'), 'مُعادة للتعديل');
        $this->assertSame(['path' => '/reports', 'query' => ['status' => 'returned']], $returned['to']);

        $completed = $this->metric($this->section('candidates'), 'مكتمل');
        $this->assertSame('/candidates', $completed['to']['path']);

        // عدّادٌ لا تقبل شاشته فلتراً يبقى بلا وجهة
        $this->assertArrayNotHasKey('to', $this->metric($this->section('candidates'), 'الإجمالي'));
    }
}
