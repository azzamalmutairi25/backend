<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// الخدمة السابعة: المستشار يغطّي قطاعاً أو أكثر — والحارس نفسه يفرضه.
//
// هذا أخطر ما في الدفعة: `coversSector` يُستدعى في التقييم والتقارير
// والاستقبال والحضور والجدولة والإشعارات. فالمحكّات هنا على الحارس نفسه
// وعلى ما يحرسه معاً.
class MultiSectorConsultantTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(string $primary = 'DW', array $extra = []): User
    {
        $u = User::create([
            'username' => 'u_'.substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستشار',
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', $primary)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        if ($extra) {
            $u->sectors()->sync(Sector::whereIn('code', array_merge([$primary], $extra))->pluck('id')->all());
        }

        return $u;
    }

    // ═══ الحارس ═══

    public function test_the_primary_sector_alone_behaves_exactly_as_before(): void
    {
        $u = $this->evaluator('DW');
        $dw = Sector::where('code', 'DW')->value('id');
        $pr = Sector::where('code', 'PR')->value('id');

        $this->assertSame([$dw], $u->sectorIds(), 'العمود وحده يكفي — الجدول يُضيف ولا يستبدل');
        $this->assertTrue($u->coversSector($dw));
        $this->assertFalse($u->coversSector($pr));
    }

    public function test_a_consultant_can_cover_two_sectors(): void
    {
        $u = $this->evaluator('DW', ['PR']);

        $this->assertTrue($u->coversSector(Sector::where('code', 'DW')->value('id')));
        $this->assertTrue($u->coversSector(Sector::where('code', 'PR')->value('id')));
        $this->assertFalse($u->coversSector(Sector::where('code', 'BG')->value('id')));
    }

    // بياناتٌ ناقصة لا تُقرأ إذناً مفتوحاً
    public function test_a_bound_user_with_no_sector_covers_nothing(): void
    {
        $u = $this->evaluator('DW');
        $u->forceFill(['sector_id' => null])->save();
        $u->sectors()->sync([]);

        $this->assertSame([], $u->fresh()->sectorIds());
        $this->assertFalse($u->fresh()->coversSector(Sector::where('code', 'DW')->value('id')));
    }

    public function test_an_unbound_user_still_covers_every_sector(): void
    {
        $scheduler = $this->actingAsRole('SCHEDULER');

        $this->assertFalse($scheduler->isSectorBound());
        $this->assertTrue($scheduler->coversSector(Sector::where('code', 'PR')->value('id')));
    }

    // ═══ ما يحرسه ═══

    public function test_a_two_sector_consultant_sees_candidates_of_both(): void
    {
        [$dw] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled']);
        [$pr] = $this->makeCandidate(['sectorCode' => 'PR', 'status' => 'scheduled']);
        [$bg] = $this->makeCandidate(['sectorCode' => 'BG', 'status' => 'scheduled']);

        $u = $this->evaluator('DW', ['PR']);
        $this->actingAs($u);

        $ids = collect($this->getJson('/api/candidates')->assertOk()->json('candidates'))->pluck('id')->all();

        $this->assertContains($dw->id, $ids);
        $this->assertContains($pr->id, $ids);
        $this->assertNotContains($bg->id, $ids, 'وما ليس من قطاعاته يبقى محجوباً');
    }

    public function test_evaluating_across_a_covered_sector_is_allowed(): void
    {
        [$pr] = $this->makeCandidate(['sectorCode' => 'PR', 'status' => 'scheduled']);

        // مستشارٌ أساسيُّه ديوان الوزارة ويغطّي السجون معه
        $u = $this->evaluator('DW', ['PR']);
        $this->actingAs($u);

        $this->postJson('/api/evaluations/start', [
            'candidateId' => $pr->id, 'activity' => 'interview',
        ])->assertStatus(201);
    }

    public function test_evaluating_outside_every_covered_sector_is_refused(): void
    {
        [$bg] = $this->makeCandidate(['sectorCode' => 'BG', 'status' => 'scheduled']);

        $u = $this->evaluator('DW', ['PR']);
        $this->actingAs($u);

        // ٤٠٤ لا ٤٠٣ — لا يفرّق الردّ بين «غير موجود» و«خارج قطاعك»
        $this->postJson('/api/evaluations/start', [
            'candidateId' => $bg->id, 'activity' => 'interview',
        ])->assertStatus(404);
    }

    public function test_attendance_follows_the_same_guard(): void
    {
        [$pr, $a] = $this->makeCandidate(['sectorCode' => 'PR', 'status' => 'scheduled']);
        $u = $this->evaluator('DW', ['PR']);

        $schedule = Schedule::create([
            'candidate_id' => $pr->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:15',
            'activity' => 'interview', 'evaluator_id' => $u->id,
        ]);

        $this->actingAs($u);
        $this->postJson("/api/attendance/{$schedule->id}/checkin")->assertOk();
    }

    // ═══ إدارة القطاعات ═══

    public function test_extra_sectors_are_saved_and_returned(): void
    {
        $this->actingAsRole('ADMIN');
        $dw = Sector::where('code', 'DW')->value('id');
        $pr = Sector::where('code', 'PR')->value('id');
        $username = 'u'.substr(md5(uniqid('', true)), 0, 8);

        $this->postJson('/api/users', [
            'username' => $username,
            'fullName' => 'مستشار قطاعين',
            'roleId' => Role::where('code', 'EVALUATOR')->value('id'),
            'sectorId' => $dw,
            'sectorIds' => [$pr],
            'password' => 'Kafaat@2026!x',
            'userType' => 'external',
        ])->assertCreated();

        $u = User::where('username', $username)->firstOrFail();
        $this->assertEqualsCanonicalizing([$dw, $pr], $u->sectorIds());
    }

    // الأساسي يُدرَج دائماً ولو لم يُرسَل في القائمة
    public function test_the_primary_sector_is_always_included(): void
    {
        $u = $this->evaluator('DW');
        $pr = Sector::where('code', 'PR')->value('id');

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/users/{$u->id}", [
            'fullName' => $u->full_name,
            'roleId' => $u->role_id,
            'sectorId' => $u->sector_id,
            'sectorIds' => [$pr],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$u->sector_id, $pr],
            $u->fresh()->sectorIds(),
            'الأساسي لا يسقط بإرسال قائمةٍ لا تذكره'
        );
    }

    public function test_the_migration_backfilled_every_existing_holder(): void
    {
        // كل من له قطاعٌ في العمود له صفٌّ في الجدول — فلا يتغيّر سلوك حسابٍ قائم
        $missing = DB::table('users')
            ->whereNotNull('sector_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('user_sectors')
                ->whereColumn('user_sectors.user_id', 'users.id')
                ->whereColumn('user_sectors.sector_id', 'users.sector_id'))
            ->count();

        $this->assertSame(0, $missing);
    }
}
