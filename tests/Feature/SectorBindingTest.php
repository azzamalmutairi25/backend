<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// قاعدة ثابتة: كل مقيّم ومساعد مخصَّص لقطاع ولا يُقيّم إلا مرشحيه.
// عبر القطاعات يُمنع إلا لحامل إدارة المرشحين، وبتأكيد صريح ومُدقَّق.
class SectorBindingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function userIn(string $roleCode, ?string $sectorCode): User
    {
        $u = $this->actingAsRole($roleCode, $sectorCode);
        // حالة «محصور بلا قطاع» لا يُنتجها المصنع (ولا الواجهة) — تُصنَع هنا صراحةً
        if ($sectorCode === null && $u->isSectorBound()) {
            $u->sector_id = null;
            $u->save();
        }
        return $u->fresh();
    }

    // ── نموذج الحصر ──

    public function test_only_evaluators_assistants_and_discussion_evals_are_sector_bound(): void
    {
        foreach (['EVALUATOR', 'DISCUSSION_EVAL', 'ASSISTANT'] as $r) {
            $this->assertTrue($this->userIn($r, 'HO')->isSectorBound(), $r);
        }
        foreach (['ADMIN', 'SCHEDULER', 'RECEPTIONIST', 'ASSESS_MANAGER', 'DEV_MANAGER', 'CENTER_MANAGER'] as $r) {
            $this->assertFalse($this->userIn($r, null)->isSectorBound(), $r);
        }
    }

    public function test_sector_bound_user_covers_only_their_own_sector(): void
    {
        $ho = Sector::where('code', 'HO')->value('id');
        $ed = Sector::where('code', 'ED')->value('id');
        $u = $this->userIn('EVALUATOR', 'HO');

        $this->assertTrue($u->coversSector($ho));
        $this->assertFalse($u->coversSector($ed));
    }

    public function test_unbound_user_covers_every_sector(): void
    {
        $u = $this->userIn('SCHEDULER', null);
        foreach (Sector::pluck('id') as $id) {
            $this->assertTrue($u->coversSector($id));
        }
    }

    // بيانات ناقصة لا تُقرأ كإذن مفتوح
    public function test_bound_user_without_a_sector_covers_nothing(): void
    {
        $u = $this->userIn('EVALUATOR', null);
        $this->assertFalse($u->coversSector(Sector::first()->id));
        $this->assertFalse($u->coversSector(null));
    }

    // ── القطاع إلزامي عند إنشاء المستخدم ──

    public function test_creating_an_evaluator_without_a_sector_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/users', [
            'username' => 'ev_no_sector', 'fullName' => 'مقيّم بلا قطاع',
            'roleId' => Role::where('code', 'EVALUATOR')->value('id'),
            'password' => 'Kafaat@2026', 'userType' => 'external',
        ])->assertStatus(422)->assertJsonPath('errors.sectorId.0', 'القطاع مطلوب لهذا الدور — كل مقيّم ومساعد مخصَّص لقطاع');
    }

    public function test_creating_an_evaluator_with_a_sector_succeeds(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/users', [
            'username' => 'ev_ho', 'fullName' => 'مقيّم الإسكان',
            'roleId' => Role::where('code', 'EVALUATOR')->value('id'),
            'sectorId' => Sector::where('code', 'HO')->value('id'),
            'password' => 'Kafaat@2026', 'userType' => 'external',
        ])->assertCreated();

        $this->assertSame('HO', User::where('username', 'ev_ho')->first()->sector->code);
    }

    public function test_a_sector_on_an_unbound_role_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/users', [
            'username' => 'sch_sector', 'fullName' => 'جدولة بقطاع',
            'roleId' => Role::where('code', 'SCHEDULER')->value('id'),
            'sectorId' => Sector::first()->id,
            'password' => 'Kafaat@2026', 'userType' => 'external',
        ])->assertStatus(422)->assertJsonPath('errors.sectorId.0', 'هذا الدور غير محصور بقطاع');
    }

    public function test_promoting_a_user_into_a_bound_role_requires_a_sector(): void
    {
        $u = $this->userIn('RECEPTIONIST', null);
        $this->actingAsRole('ADMIN');

        // القاعدة تُعاد على الدور الجديد لا القديم
        $this->putJson("/api/users/{$u->id}", [
            'fullName' => $u->full_name,
            'roleId' => Role::where('code', 'EVALUATOR')->value('id'),
        ])->assertStatus(422);
    }

    // ── التقييم محصور بالقطاع ──

    public function test_evaluator_cannot_start_an_evaluation_outside_their_sector(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->userIn('EVALUATOR', 'HO');

        // 404 لا 403: لا يفرّق الردّ بين «غير موجود» و«خارج قطاعك» (لا عرّاف قطاع)
        $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->assertStatus(404);
        $this->assertDatabaseHas('audit_logs', ['action' => 'DENIED_EVAL_CROSS_SECTOR']);
    }

    public function test_evaluator_can_start_an_evaluation_in_their_own_sector(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'HO']);
        $this->userIn('EVALUATOR', 'HO');

        $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->assertCreated();
    }

    // ── العرض محصور بالقطاع ──

    public function test_bound_user_sees_only_their_sector_in_lists(): void
    {
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'HO']);
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->userIn('EVALUATOR', 'HO');

        $rows = $this->getJson('/api/candidates')->assertOk()->json('candidates');
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertSame('الإسكان', $r['sectorName']);
        }
    }

    public function test_bound_user_cannot_widen_the_scope_via_the_sector_filter(): void
    {
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->userIn('EVALUATOR', 'HO');

        // فلتر لقطاع آخر لا يوسّع النطاق — الحصر قبل الفلتر
        $ed = Sector::where('code', 'ED')->value('id');
        $this->assertCount(0, $this->getJson("/api/candidates?sectorId={$ed}")->assertOk()->json('candidates'));
    }

    public function test_unbound_user_sees_every_sector(): void
    {
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'HO']);
        $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->userIn('SCHEDULER', null);

        $this->assertCount(2, $this->getJson('/api/candidates')->assertOk()->json('candidates'));
    }

    // ── التوزيع عبر القطاعات ──

    private function crossPayload(int $candidateId, int $evaluatorId, array $extra = []): array
    {
        return array_merge([
            'candidateId' => $candidateId,
            'date' => now()->addDay()->toDateString(),
            'time' => '10:00',
            'activity' => 'interview',
            'evaluatorId' => $evaluatorId,
        ], $extra);
    }

    public function test_cross_sector_assignment_warns_before_it_is_allowed(): void
    {
        $ev = $this->userIn('EVALUATOR', 'HO');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->actingAsRole('SCHEDULER'); // يملك CROSS_SECTOR_ASSIGN

        $res = $this->postJson('/api/schedules', $this->crossPayload($c->id, $ev->id))
            ->assertStatus(409)
            ->assertJsonPath('requiresConfirmation', true);

        $this->assertStringContainsString('ليس من نفس القطاع', $res->json('error'));
        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_confirmed_cross_sector_assignment_passes_and_is_audited(): void
    {
        $ev = $this->userIn('EVALUATOR', 'HO');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/schedules', $this->crossPayload($c->id, $ev->id, ['confirmCrossSector' => true]))
            ->assertCreated()
            ->assertJsonPath('crossSector', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE_SCHEDULE_CROSS_SECTOR']);
    }

    public function test_same_sector_assignment_needs_no_confirmation(): void
    {
        $ev = $this->userIn('EVALUATOR', 'HO');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'HO']);
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/schedules', $this->crossPayload($c->id, $ev->id))
            ->assertCreated()
            ->assertJsonPath('crossSector', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE_SCHEDULE']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'CREATE_SCHEDULE_CROSS_SECTOR']);
    }

    public function test_reassignment_via_update_hits_the_same_wall(): void
    {
        $ho = $this->userIn('EVALUATOR', 'HO');
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->addDay()->toDateString(), 'schedule_time' => '10:00:00',
            'activity' => 'interview', 'location' => 'قاعة 1',
        ]);
        $this->actingAsRole('SCHEDULER');

        // التعديل لا يكون باباً خلفياً للتوزيع
        $this->putJson("/api/schedules/{$s->id}", ['evaluatorId' => $ho->id])
            ->assertStatus(409)->assertJsonPath('requiresConfirmation', true);

        $this->putJson("/api/schedules/{$s->id}", ['evaluatorId' => $ho->id, 'confirmCrossSector' => true])
            ->assertOk()->assertJsonPath('crossSector', true);
    }

    public function test_assistant_from_another_sector_also_triggers_the_warning(): void
    {
        $as = $this->userIn('ASSISTANT', 'HO');
        $ev = $this->userIn('EVALUATOR', 'ED');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->actingAsRole('SCHEDULER');

        // المقيّم مطابق لكن المساعد لا — الحدّ يشمل الاثنين
        $this->postJson('/api/schedules', $this->crossPayload($c->id, $ev->id, ['assistantId' => $as->id]))
            ->assertStatus(409);
    }
}
