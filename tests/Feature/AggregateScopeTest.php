<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// المؤشّر لا يعدّ ما تخفيه قائمته.
// رقمٌ أكبر من قائمته يُفشي حجم ما وراءها ولو لم يُفشِ تفاصيله.
class AggregateScopeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_candidate_stats_match_the_list_for_a_bound_user(): void
    {
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->makeCandidate(['status' => 'draft', 'sectorCode' => 'ED']);
        // خارج قطاعه — لا في القائمة ولا في العدّ
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'HO']);
        $this->makeCandidate(['status' => 'approved', 'sectorCode' => 'HO']);

        $this->actingAsRole('EVALUATOR', 'ED');

        $rows = $this->getJson('/api/candidates')->assertOk()->json('candidates');
        $stats = $this->getJson('/api/candidates/stats')->assertOk()->json();

        $this->assertCount(2, $rows);
        $this->assertSame(2, $stats['total'], 'المؤشّر يطابق القائمة');
        $this->assertSame(array_sum($stats['byStatus']), count($rows), 'التوزيع يجمع لعدد القائمة');
    }

    public function test_candidate_stats_are_unrestricted_for_an_unbound_user(): void
    {
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'HO']);
        $this->actingAsRole('SCHEDULER');

        $rows = $this->getJson('/api/candidates')->assertOk()->json('candidates');
        $stats = $this->getJson('/api/candidates/stats')->assertOk()->json();

        $this->assertCount(2, $rows);
        $this->assertSame(2, $stats['total']);
    }

    public function test_attendance_stats_match_the_list_for_a_bound_user(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');

        foreach ([['ED', $ev], ['HO', null]] as [$sector, $owner]) {
            [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => $sector]);
            \App\Models\Schedule::create([
                'candidate_id' => $c->id, 'assessment_id' => $a->id,
                'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
                'activity' => 'interview', 'evaluator_id' => $owner?->id, 'location' => 'قاعة',
            ]);
        }

        \Laravel\Sanctum\Sanctum::actingAs($ev);
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
        foreach (\App\Models\User::SECTOR_BOUND_ROLES as $code) {
            $perms = \App\Security\Permissions::forRole($code);
            $this->assertNotContains('analytics.view', $perms,
                "{$code} محصور بقطاع ويملك التحليلات — AnalyticsController بلا حصر قطاع");
        }
    }
}
