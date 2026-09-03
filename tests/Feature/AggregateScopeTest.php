<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// المؤشّر لا يعدّ ما تخفيه قائمته.
// رقمٌ أكبر من قائمته يُفشي حجم ما وراءها ولو لم يُفشِ تفاصيله.
class AggregateScopeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_candidate_stats_match_the_list_for_a_bound_user(): void
    {
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $this->makeCandidate(['status' => 'draft', 'sectorCode' => 'DW']);
        // خارج قطاعه — لا في القائمة ولا في العدّ
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'PR']);
        $this->makeCandidate(['status' => 'approved', 'sectorCode' => 'PR']);

        $this->actingAsRole('EVALUATOR', 'DW');

        $rows = $this->getJson('/api/candidates')->assertOk()->json('candidates');
        $stats = $this->getJson('/api/candidates/stats')->assertOk()->json();

        $this->assertCount(2, $rows);
        $this->assertSame(2, $stats['total'], 'المؤشّر يطابق القائمة');
        $this->assertSame(array_sum($stats['byStatus']), count($rows), 'التوزيع يجمع لعدد القائمة');
    }

    public function test_candidate_stats_are_unrestricted_for_an_unbound_user(): void
    {
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'PR']);
        $this->actingAsRole('SCHEDULER');

        $rows = $this->getJson('/api/candidates')->assertOk()->json('candidates');
        $stats = $this->getJson('/api/candidates/stats')->assertOk()->json();

        $this->assertCount(2, $rows);
        $this->assertSame(2, $stats['total']);
    }

    public function test_attendance_stats_match_the_list_for_a_bound_user(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'DW');

        foreach ([['DW', $ev], ['PR', null]] as [$sector, $owner]) {
            [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => $sector]);
            Schedule::create([
                'candidate_id' => $c->id, 'assessment_id' => $a->id,
                'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
                'activity' => 'interview', 'evaluator_id' => $owner?->id, 'location' => 'قاعة',
            ]);
        }

        Sanctum::actingAs($ev);
        $rows = $this->getJson('/api/attendance/today')->assertOk()->json('attendance');
        $stats = $this->getJson('/api/attendance/stats')->assertOk()->json('stats');

        $this->assertCount(1, $rows, 'قطاعه وحده');
        $this->assertSame(1, $stats['total'], 'المؤشّر يطابق');
    }

    // التحليلات: كل من يملك analytics.view غير محصور بقطاع، فالحصر بلا معنى.
    // يسقط هذا الاختبار لحظةَ مُنحت التحليلات لدور محصور — وهي اللحظة التي
    // يصير فيها AnalyticsController تسريباً.
    public function test_no_sector_bound_role_holds_analytics(): void
    {
        foreach (User::SECTOR_BOUND_ROLES as $code) {
            $perms = Permissions::forRole($code);
            $this->assertNotContains('analytics.view', $perms,
                "{$code} محصور بقطاع ويملك التحليلات — AnalyticsController بلا حصر قطاع");
        }
    }
}
