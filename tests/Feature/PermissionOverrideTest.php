<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// تخصيص صلاحية لمستخدم فوق دوره — منحاً أو سحباً.
class PermissionOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function override(User $u, string $perm, bool $granted): void
    {
        $u->permissionOverrides()->create(['permission' => $perm, 'granted' => $granted]);
        $u->unsetRelation('permissionOverrides');
    }

    // ── المنح ──

    public function test_granting_a_permission_the_role_lacks(): void
    {
        $u = $this->actingAsRole('EVALUATOR');
        $this->assertFalse($u->hasPermission(Permissions::REPORT_EXPORT));

        $this->override($u, Permissions::REPORT_EXPORT, true);
        $this->assertTrue($u->fresh()->hasPermission(Permissions::REPORT_EXPORT));
    }

    public function test_granted_permission_actually_opens_the_endpoint(): void
    {
        $u = $this->actingAsRole('EVALUATOR');
        $this->get('/api/reports/export')->assertStatus(403);

        $this->override($u, Permissions::REPORT_EXPORT, true);
        \Laravel\Sanctum\Sanctum::actingAs($u->fresh());
        $this->get('/api/reports/export')->assertOk();
    }

    // ── السحب ──

    public function test_revoking_a_permission_the_role_has(): void
    {
        $u = $this->actingAsRole('SCHEDULER');
        $this->assertTrue($u->hasPermission(Permissions::CANDIDATE_CREATE));

        $this->override($u, Permissions::CANDIDATE_CREATE, false);
        $this->assertFalse($u->fresh()->hasPermission(Permissions::CANDIDATE_CREATE));
    }

    public function test_revoked_permission_actually_closes_the_endpoint(): void
    {
        $u = $this->actingAsRole('SCHEDULER');
        $this->override($u, Permissions::CANDIDATE_CREATE, false);
        \Laravel\Sanctum\Sanctum::actingAs($u->fresh());

        $this->postJson('/api/candidates', ['fullName' => 'x'])->assertStatus(403);
    }

    // ── السحب يسبق '*' ──
    // لولا ذلك لصار مدير النظام خارج كل سحب، فلا معنى لكتابته
    public function test_revoke_beats_the_admin_wildcard(): void
    {
        $u = $this->actingAsRole('ADMIN');
        $this->assertTrue($u->hasPermission(Permissions::REPORT_APPROVE));

        $this->override($u, Permissions::REPORT_APPROVE, false);
        $this->assertFalse($u->fresh()->hasPermission(Permissions::REPORT_APPROVE), 'السحب ينفذ على * أيضاً');
    }

    public function test_effective_permissions_expand_the_wildcard_before_revoking(): void
    {
        $u = $this->actingAsRole('ADMIN');
        $this->override($u, Permissions::USER_MANAGE, false);

        $eff = $u->fresh()->effectivePermissions();
        $this->assertNotContains(Permissions::USER_MANAGE, $eff);
        $this->assertContains(Permissions::REPORT_VIEW, $eff, 'بقية صلاحيات * سليمة');
        $this->assertNotContains('*', $eff, "'*' يُفرَد فلا يعود يلتفّ على السحب");
    }

    // ── الدخول يرسل الفعلية لا صلاحيات الدور ──

    public function test_login_returns_effective_permissions(): void
    {
        $u = User::where('username', 'evaluator')->first()
            ?? $this->actingAsRole('EVALUATOR');
        $this->override($u, Permissions::REPORT_EXPORT, true);

        $res = $this->postJson('/api/login', ['username' => $u->username, 'password' => 'Kafaat@2026'])
            ->assertOk();

        // الواجهة تخفي الأزرار على هذي — لو أرسلت صلاحيات الدور لاختفى الاستثناء
        $this->assertContains(Permissions::REPORT_EXPORT, $res->json('user.permissions'));
    }

    // ── الحرّاس ──

    public function test_nobody_edits_their_own_permissions(): void
    {
        $admin = $this->actingAsRole('ADMIN');

        // وإلا صار كل من يملك USER_MANAGE قادراً على منح نفسه كل شيء
        $this->putJson("/api/users/{$admin->id}/permissions", ['overrides' => []])
            ->assertStatus(422)
            ->assertJsonPath('error', 'لا يمكنك تعديل صلاحيات حسابك');
    }

    public function test_only_user_manage_may_set_overrides(): void
    {
        $target = $this->actingAsRole('EVALUATOR');
        $this->actingAsRole('ASSESS_MANAGER'); // لا USER_MANAGE

        $this->getJson("/api/users/{$target->id}/permissions")->assertStatus(403);
        $this->putJson("/api/users/{$target->id}/permissions", ['overrides' => []])->assertStatus(403);
    }

    public function test_unknown_permission_is_rejected(): void
    {
        $target = $this->actingAsRole('EVALUATOR');
        $this->actingAsRole('ADMIN');

        $this->putJson("/api/users/{$target->id}/permissions", [
            'overrides' => [['permission' => 'not.a.real.permission', 'granted' => true]],
        ])->assertStatus(422);
    }

    public function test_an_override_that_matches_the_role_is_rejected_as_noise(): void
    {
        $target = $this->actingAsRole('EVALUATOR');
        $this->actingAsRole('ADMIN');

        // المقيّم يملك report.view أصلاً — «منحها» يوحي باستثناء حيث لا استثناء
        $this->putJson("/api/users/{$target->id}/permissions", [
            'overrides' => [['permission' => Permissions::REPORT_VIEW, 'granted' => true]],
        ])->assertStatus(422);
    }

    // ── الحفظ ──

    public function test_saving_overrides_replaces_and_revokes_tokens(): void
    {
        $target = $this->actingAsRole('EVALUATOR');
        $target->createToken('t');
        $this->assertSame(1, $target->tokens()->count());

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/users/{$target->id}/permissions", [
            'overrides' => [
                ['permission' => Permissions::REPORT_EXPORT, 'granted' => true, 'reason' => 'مكلّف بالتقارير'],
            ],
        ])->assertOk();

        $this->assertTrue($target->fresh()->hasPermission(Permissions::REPORT_EXPORT));
        // تغيّرت صلاحياته ⇒ يعيد الدخول
        $this->assertSame(0, $target->fresh()->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'UPDATE_USER_PERMISSIONS']);
    }

    public function test_saving_an_empty_set_clears_previous_overrides(): void
    {
        $target = $this->actingAsRole('EVALUATOR');
        $this->override($target, Permissions::REPORT_EXPORT, true);

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/users/{$target->id}/permissions", ['overrides' => []])->assertOk();

        $this->assertFalse($target->fresh()->hasPermission(Permissions::REPORT_EXPORT));
    }

    public function test_permissions_screen_reports_the_source_of_each(): void
    {
        $target = $this->actingAsRole('EVALUATOR');
        $this->override($target, Permissions::REPORT_EXPORT, true);
        $this->override($target, Permissions::REPORT_VIEW, false);

        $this->actingAsRole('ADMIN');
        $groups = $this->getJson("/api/users/{$target->id}/permissions")->assertOk()->json('groups');

        $flat = collect($groups)->flatMap(fn ($g) => $g['permissions'])->keyBy('permission');

        // ممنوحة استثناءً
        $this->assertFalse($flat[Permissions::REPORT_EXPORT]['byRole']);
        $this->assertTrue($flat[Permissions::REPORT_EXPORT]['effective']);
        // مسحوبة رغم الدور
        $this->assertTrue($flat[Permissions::REPORT_VIEW]['byRole']);
        $this->assertFalse($flat[Permissions::REPORT_VIEW]['effective']);
        // بلا استثناء
        $this->assertNull($flat[Permissions::EVALUATION_INPUT]['override']);
    }

    // ── كل الصلاحيات مكتشَفة ──

    public function test_permissions_all_covers_every_declared_constant(): void
    {
        $all = Permissions::all();
        $this->assertContains(Permissions::REPORT_APPROVE_CENTER, $all, 'الثوابت الجديدة تُلتقط بالانعكاس');
        $this->assertContains(Permissions::ATTENDANCE_RECORD_ANY, $all);
        $this->assertNotContains('*', $all);

        // كل صلاحية في مصفوفة الأدوار معروفة — دورٌ يمنح صلاحية غير معرّفة لا تُفرض أبداً
        foreach (Permissions::matrix() as $role => $perms) {
            foreach ($perms as $p) {
                if ($p === '*') {
                    continue;
                }
                $this->assertContains($p, $all, "{$role} يمنح صلاحية غير معرّفة: {$p}");
            }
        }
    }
}
