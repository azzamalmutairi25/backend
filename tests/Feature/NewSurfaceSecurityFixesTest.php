<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\CvGuard;
use App\Services\DistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// اختبارات انحدار لإصلاحات التدقيق الأمني للسطح الجديد.
class NewSurfaceSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // ── ١) التوزيع لا يكشف المصنّفين لمُشغّل بلا تصريح ──
    public function test_distribution_excludes_classified_candidates_from_uncleared_scheduler(): void
    {
        // مقيّم في القطاع (وإلا لا يُوزَّع أحد)
        $edSector = Sector::where('code', 'ED')->value('id');
        User::create([
            'username' => 'ev_' . uniqid(), 'full_name' => 'مقيّم', 'password' => 'x',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => $edSector, 'is_active' => true, 'must_change_password' => false,
        ]);

        [$normal] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED', 'classification' => 'normal', 'code' => 'DN1']);
        [$secret] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED', 'classification' => 'top_secret', 'code' => 'DS1']);

        // SCHEDULER يملك DISTRIBUTION_MANAGE لكن لا CANDIDATE_VIEW_CLASSIFIED
        $scheduler = $this->actingAsRole('SCHEDULER');
        $proposal = app(DistributionService::class)->propose($scheduler);

        $distributedIds = $proposal->items->pluck('candidate_id')->all();
        $this->assertContains($normal->id, $distributedIds, 'العادي يُوزَّع');
        $this->assertNotContains($secret->id, $distributedIds, 'المصنّف لا يُوزَّع ولا يُكشف');
    }

    // ── ٢) سقف الامتيازات في منح الاستثناءات ──
    public function test_non_delegable_permissions_cannot_be_granted_via_override(): void
    {
        $this->actingAsRole('ADMIN');
        $target = $this->makeUser('EVALUATOR');

        $this->putJson("/api/users/{$target->id}/permissions", [
            'overrides' => [['permission' => 'settings.manage', 'granted' => true]],
        ])->assertStatus(422);
    }

    public function test_granter_cannot_grant_a_permission_they_do_not_hold(): void
    {
        // مُفوَّض: SCHEDULER مُنِح user.manage (لا يملك report.approve)
        $delegate = $this->makeUser('SCHEDULER');
        UserPermissionOverride::create([
            'user_id' => $delegate->id, 'permission' => 'user.manage', 'granted' => true, 'created_by' => $delegate->id,
        ]);
        $this->actingAs($delegate->fresh());

        $target = $this->makeUser('EVALUATOR');
        // report.approve لا يملكها المُفوَّض ⇒ يُرفض بـ403
        $this->putJson("/api/users/{$target->id}/permissions", [
            'overrides' => [['permission' => 'report.approve', 'granted' => true]],
        ])->assertStatus(403);

        // schedule.manage يملكها ⇒ يُقبل
        $this->putJson("/api/users/{$target->id}/permissions", [
            'overrides' => [['permission' => 'schedule.manage', 'granted' => true]],
        ])->assertOk();
    }

    // ── ٣) استثناءات المنح تُحذف عند تغيير الدور ──
    public function test_granted_overrides_are_cleared_on_role_change(): void
    {
        $admin = $this->actingAsRole('ADMIN');
        $target = $this->makeUser('EVALUATOR');
        UserPermissionOverride::create([
            'user_id' => $target->id, 'permission' => 'analytics.view', 'granted' => true, 'created_by' => $admin->id,
        ]);

        // تغيير لدور غير محصور بقطاع/مدير (SCHEDULER) — المهم أن الدور تغيّر
        $this->putJson("/api/users/{$target->id}", [
            'fullName' => 'مستخدم', 'roleId' => Role::where('code', 'SCHEDULER')->value('id'),
        ])->assertOk();

        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id, 'permission' => 'analytics.view', 'granted' => true,
        ]);
    }

    // ── ٤) حارس السيرة يمسك الاسم العربي المتباعد (تجاوز التقييم الأعمى) ──
    public function test_cvguard_catches_arabic_spaced_name(): void
    {
        $c = new Candidate();
        $c->full_name = 'محمد العتيبي';
        $c->national_id = '1122334455';
        $c->mobile = '0501234567';

        foreach ([
            'انا م ح م د خبير',   // مسافات
            'شركة م.ح.م.د',        // نقاط
            'م-ح-م-د العتيبي',     // شرطات
        ] as $evasion) {
            $doc = ['briefBio' => $evasion];
            $this->assertNotNull(CvGuard::directIdentifierHit($doc, $c), "يُحجب عند الحفظ: $evasion");
            $scrubbed = CvGuard::scrub($doc, $c)['briefBio'];
            $this->assertStringContainsString('«•••»', $scrubbed, "يُطمَس عند العرض: $evasion");
        }

        // رقم هوية بأرقام متباعدة يُطمَس أيضاً
        $doc = ['briefBio' => 'رقمي 1 1 2 2 3 3 4 4 5 5'];
        $this->assertStringContainsString('«•••»', CvGuard::scrub($doc, $c)['briefBio']);

        // نص شرعي لا يُطمَس (لا إفراط)
        $ok = ['briefBio' => 'خبير في إدارة الموارد البشرية والتخطيط الاستراتيجي'];
        $this->assertNull(CvGuard::directIdentifierHit($ok, $c));
        $this->assertStringNotContainsString('«•••»', CvGuard::scrub($ok, $c)['briefBio']);
    }

    private function makeUser(string $roleCode): User
    {
        return User::create([
            'username' => 'u_' . uniqid(), 'full_name' => "مستخدم {$roleCode}", 'password' => 'x',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'is_active' => true, 'must_change_password' => false,
        ]);
    }
}
