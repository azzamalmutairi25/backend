<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\DistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// التوزيع الأسبوعي: عدالة، حدّ لكل مقيّم/يوم، حصر القطاع، الزائد للأسبوع
// التالي، وإعادة التحقّق الحيّ عند الاعتماد (إسقاط ما بطل بدل جدولته).
class DistributionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function setCap(int $n): void
    {
        Setting::updateOrCreate(
            ['key' => 'distribution.daily_cap_per_evaluator'],
            ['value' => (string) $n]
        );
    }

    private function makeEvaluator(string $sectorCode = 'ED'): User
    {
        $role = Role::where('code', 'EVALUATOR')->firstOrFail();
        return User::create([
            'username' => 'ev_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مقيّم ' . $sectorCode,
            'password' => 'Kafaat@2026',
            'role_id' => $role->id,
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    // ينشئ n مرشحين جاهزين للتوزيع في قطاع
    private function readyCandidates(int $n, string $sectorCode = 'ED'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            [$c] = $this->makeCandidate([
                'status' => 'scheduled', 'sectorCode' => $sectorCode,
                'code' => 'D' . $sectorCode . $i . random_int(100, 999),
            ]);
            $out[] = $c;
        }
        return $out;
    }

    // ── الحدّ لكل مقيّم في اليوم ──

    public function test_no_evaluator_exceeds_the_daily_cap_on_any_day(): void
    {
        $this->setCap(2);
        $this->makeEvaluator('ED');
        $this->makeEvaluator('ED');
        $this->readyCandidates(40, 'ED'); // أكثر من سعة الأسبوع (2×5×2=20)

        $proposal = app(DistributionService::class)->propose($this->makeEvaluator('HI'));

        $byEvDay = $proposal->items->groupBy(fn ($i) => $i->evaluator_id . '|' . $i->scheduled_date);
        foreach ($byEvDay as $key => $items) {
            $this->assertLessThanOrEqual(2, $items->count(), "الحدّ 2 لكل مقيّم/يوم — {$key}");
        }
    }

    // ── الزائد يُترك للأسبوع التالي ──

    public function test_candidates_beyond_weekly_capacity_are_left_undistributed(): void
    {
        $this->setCap(1);
        $this->makeEvaluator('ED');
        $this->makeEvaluator('ED'); // سعة الأسبوع = 2 مقيّم × 5 أيام × 1 = 10
        $cands = $this->readyCandidates(14, 'ED');

        $proposal = app(DistributionService::class)->propose($this->makeEvaluator('HI'));

        $this->assertSame(10, $proposal->items->count(), 'يُوزَّع بقدر السعة فقط');
        $placedIds = $proposal->items->pluck('candidate_id')->all();
        $leftover = collect($cands)->pluck('id')->reject(fn ($id) => in_array($id, $placedIds, true));
        $this->assertCount(4, $leftover, 'أربعة يبقون للأسبوع التالي');
    }

    // ── حصر القطاع ──

    public function test_candidates_go_only_to_evaluators_of_their_own_sector(): void
    {
        $this->setCap(3);
        $this->makeEvaluator('ED');
        $this->makeEvaluator('HI');
        $this->readyCandidates(3, 'ED');
        $this->readyCandidates(3, 'HI');

        $proposal = app(DistributionService::class)->propose($this->makeEvaluator('MA'));

        foreach ($proposal->items as $item) {
            $ev = User::find($item->evaluator_id);
            $this->assertSame($item->sector_id, $ev->sector_id, 'المقيّم من قطاع المرشّح نفسه');
        }
        $this->assertSame(6, $proposal->items->count());
    }

    public function test_a_sector_without_an_active_evaluator_is_left_out(): void
    {
        $this->setCap(3);
        // قطاع HO بلا مقيّم فعّال البتّة
        $this->readyCandidates(3, 'HO');

        $proposal = app(DistributionService::class)->propose($this->makeEvaluator('ED'));

        $this->assertSame(0, $proposal->items->count(), 'لا مقيّم → لا توزيع');
    }

    public function test_inactive_evaluators_receive_no_candidates(): void
    {
        $this->setCap(5);
        $active = $this->makeEvaluator('ED');
        $inactive = $this->makeEvaluator('ED');
        $inactive->update(['is_active' => false]);
        $this->readyCandidates(4, 'ED');

        $proposal = app(DistributionService::class)->propose($this->makeEvaluator('HI'));

        $this->assertTrue(
            $proposal->items->every(fn ($i) => $i->evaluator_id === $active->id),
            'كل البنود للمقيّم الفعّال'
        );
    }

    // ── عدالة التوزيع بين المقيّمين ──

    public function test_distribution_spreads_evenly_across_evaluators(): void
    {
        $this->setCap(5);
        $a = $this->makeEvaluator('ED');
        $b = $this->makeEvaluator('ED');
        $this->readyCandidates(10, 'ED');

        $proposal = app(DistributionService::class)->propose($this->makeEvaluator('HI'));

        $counts = $proposal->items->groupBy('evaluator_id')->map->count();
        $this->assertEqualsWithDelta($counts[$a->id], $counts[$b->id], 1, 'فرق مقبول ±1');
    }

    // ── الاعتماد يصنع الجلسات ──

    public function test_approve_creates_schedules_for_every_surviving_item(): void
    {
        $this->setCap(5);
        $this->makeEvaluator('ED');
        $this->readyCandidates(3, 'ED');
        $svc = app(DistributionService::class);
        $actor = $this->actingAsRole('SCHEDULER');

        $proposal = $svc->propose($actor);
        $result = $svc->approve($proposal, $actor);

        $this->assertSame(3, $result['placed']);
        $this->assertSame(0, $result['dropped']);
        $this->assertSame(3, Schedule::where('activity', 'interview')->count());
        $this->assertTrue($proposal->fresh()->items->every(fn ($i) => $i->schedule_id !== null));
    }

    // ── إعادة التحقّق الحيّ: إسقاط ما بطل ──

    public function test_approve_drops_a_candidate_whose_status_changed(): void
    {
        $this->setCap(5);
        $this->makeEvaluator('ED');
        $cands = $this->readyCandidates(3, 'ED');
        $svc = app(DistributionService::class);
        $actor = $this->actingAsRole('SCHEDULER');
        $proposal = $svc->propose($actor);

        // مرشّح تقدّم لمرحلة أخرى بين الاقتراح والاعتماد
        $cands[0]->update(['status' => 'assessed']);

        $result = $svc->approve($proposal, $actor);

        $this->assertSame(2, $result['placed']);
        $this->assertSame(1, $result['dropped']);
        $dropped = $proposal->fresh()->items->firstWhere('candidate_id', $cands[0]->id);
        $this->assertNotNull($dropped->drop_reason);
        $this->assertNull($dropped->schedule_id);
    }

    public function test_approve_drops_items_of_a_deactivated_evaluator(): void
    {
        $this->setCap(5);
        $ev = $this->makeEvaluator('ED');
        $this->readyCandidates(3, 'ED');
        $svc = app(DistributionService::class);
        $actor = $this->actingAsRole('SCHEDULER');
        $proposal = $svc->propose($actor);

        // عُطّل المقيّم بعد الاقتراح — exists لا يمسكها، is_active يمسكها
        $ev->update(['is_active' => false]);

        $result = $svc->approve($proposal, $actor);

        $this->assertSame(0, $result['placed']);
        $this->assertSame(3, $result['dropped']);
        $this->assertSame(0, Schedule::where('activity', 'interview')->count());
    }

    public function test_approve_drops_a_candidate_scheduled_manually_in_the_gap(): void
    {
        $this->setCap(5);
        $this->makeEvaluator('ED');
        $cands = $this->readyCandidates(2, 'ED');
        $svc = app(DistributionService::class);
        $actor = $this->actingAsRole('SCHEDULER');
        $proposal = $svc->propose($actor);

        // جُدوِل أحدهم يدوياً منذ الاقتراح
        $assessment = $cands[0]->assessments()->first();
        Schedule::create([
            'candidate_id' => $cands[0]->id, 'assessment_id' => $assessment->id,
            'schedule_date' => now()->addDay()->toDateString(), 'activity' => 'interview',
        ]);

        $result = $svc->approve($proposal, $actor);

        $this->assertSame(1, $result['placed'], 'الآخر فقط يُجدوَل');
        $this->assertSame(1, $result['dropped']);
    }

    // ── الاعتماد المزدوج فكرة واحدة (idempotent) ──

    public function test_double_approve_does_not_create_schedules_twice(): void
    {
        $this->setCap(5);
        $this->makeEvaluator('ED');
        $this->readyCandidates(3, 'ED');
        $svc = app(DistributionService::class);
        $actor = $this->actingAsRole('SCHEDULER');
        $proposal = $svc->propose($actor);

        $first = $svc->approve($proposal, $actor);
        $second = $svc->approve($proposal->fresh(), $actor);

        $this->assertSame(3, $first['placed']);
        $this->assertTrue($second['alreadyDone']);
        $this->assertSame(3, Schedule::where('activity', 'interview')->count(), 'لا تكرار');
    }

    // ═══ عبر الـ API ═══

    public function test_propose_then_approve_over_the_api(): void
    {
        $this->setCap(5);
        $this->makeEvaluator('ED');
        $this->readyCandidates(2, 'ED');
        $this->actingAsRole('SCHEDULER');

        $proposeRes = $this->postJson('/api/distribution/propose')->assertCreated();
        $id = $proposeRes->json('proposal.id');
        $this->assertSame(2, $proposeRes->json('proposal.total'));

        $this->postJson("/api/distribution/{$id}/approve")->assertOk()
            ->assertJsonPath('placed', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'APPROVE_DISTRIBUTION']);
    }

    public function test_second_propose_for_same_week_is_rejected(): void
    {
        $this->setCap(5);
        $this->makeEvaluator('ED');
        $this->readyCandidates(1, 'ED');
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/distribution/propose')->assertCreated();
        $this->postJson('/api/distribution/propose')->assertStatus(422);
    }

    public function test_delete_draft_allows_re_proposing(): void
    {
        $this->setCap(5);
        $this->makeEvaluator('ED');
        $this->readyCandidates(1, 'ED');
        $this->actingAsRole('SCHEDULER');

        $id = $this->postJson('/api/distribution/propose')->json('proposal.id');
        $this->deleteJson("/api/distribution/{$id}")->assertOk();
        $this->postJson('/api/distribution/propose')->assertCreated();
    }

    public function test_cannot_delete_an_approved_proposal(): void
    {
        $this->setCap(5);
        $this->makeEvaluator('ED');
        $this->readyCandidates(1, 'ED');
        $this->actingAsRole('SCHEDULER');
        $id = $this->postJson('/api/distribution/propose')->json('proposal.id');
        $this->postJson("/api/distribution/{$id}/approve")->assertOk();

        $this->deleteJson("/api/distribution/{$id}")->assertStatus(422);
    }

    public function test_distribution_requires_the_permission(): void
    {
        $this->makeEvaluator('ED');
        $this->readyCandidates(1, 'ED');
        // مشرف القياس يجدول لكنه لا يملك صلاحية التوزيع
        $this->actingAsRole('MEASURE_SUPER');

        $this->getJson('/api/distribution')->assertStatus(403);
        $this->postJson('/api/distribution/propose')->assertStatus(403);
    }

    public function test_approve_of_missing_proposal_is_404(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/distribution/999999/approve')->assertStatus(404);
    }

    // ═══ إعداد الحدّ في الإعدادات ═══

    public function test_daily_cap_is_editable_only_by_settings_managers(): void
    {
        // مسؤول الجدولة يوزّع لكنه لا يضبط الحدّ
        $this->actingAsRole('SCHEDULER');
        $this->getJson('/api/settings/distribution')->assertStatus(403);
        $this->putJson('/api/settings/distribution', ['dailyCap' => 8])->assertStatus(403);

        $this->actingAsRole('ADMIN');
        $this->getJson('/api/settings/distribution')->assertOk()->assertJsonPath('distribution.dailyCap', 5);
        $this->putJson('/api/settings/distribution', ['dailyCap' => 8])->assertOk();
        $this->getJson('/api/settings/distribution')->assertJsonPath('distribution.dailyCap', 8);
        $this->assertDatabaseHas('audit_logs', ['action' => 'UPDATE_DISTRIBUTION_CAP']);
    }

    public function test_daily_cap_rejects_out_of_range(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/distribution', ['dailyCap' => 0])->assertStatus(422);
        $this->putJson('/api/settings/distribution', ['dailyCap' => 99])->assertStatus(422);
    }

    public function test_saved_cap_governs_the_next_proposal(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/distribution', ['dailyCap' => 1])->assertOk();

        $this->makeEvaluator('ED');
        $this->readyCandidates(3, 'ED');
        $proposal = app(DistributionService::class)->propose($this->makeEvaluator('HI'));

        // مقيّم واحد × 5 أيام × حدّ 1 → 3 مرشحين على 3 أيام، واحد لكل يوم
        $byDay = $proposal->items->groupBy('scheduled_date');
        foreach ($byDay as $items) {
            $this->assertLessThanOrEqual(1, $items->count(), 'الحدّ المحفوظ 1 يُحترَم');
        }
        $this->assertSame(1, $proposal->daily_cap, 'الاقتراح يلتقط الحدّ الحالي');
    }
}
