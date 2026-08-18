<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  الوصول الجماعي — تحديد الكل أو مجموعة من الموظفين وتطبيق قرارٍ واحد.
//
//  الخطر هنا ليس في العدد بل في أنّ نداءً واحداً يغيّر ما يستطيعه عشرات
//  الحسابات. فحدودُ الامتيازات يجب أن تكون حدود المسار الفردي نفسها تماماً:
//  ما يُرفض بالإفراد يُرفض بالجملة، ولا يصير «تحديد الكل» طريقاً ملتوياً.
// ════════════════════════════════════════════════════════════
class BulkUserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function userWithRole(string $roleCode, string $name = 'موظف'): User
    {
        return User::create([
            'username' => 'u_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => $name,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'sector_id' => in_array($roleCode, User::SECTOR_BOUND_ROLES, true)
                ? Sector::where('code', 'DW')->value('id') : null,
            'manager_id' => null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function bulk(array $userIds, array $changes, ?string $reason = null)
    {
        return $this->postJson('/api/users/bulk-permissions', [
            'userIds' => $userIds,
            'changes' => $changes,
            'reason' => $reason,
        ]);
    }

    // ── الحارس ──

    public function test_requires_user_manage(): void
    {
        $this->actingAsRole('CENTER_MANAGER');   // يشرف ولا يدير المستخدمين
        $target = $this->userWithRole('EVALUATOR');

        $this->bulk([$target->id], [['permission' => 'report.export', 'action' => 'grant']])
            ->assertStatus(403);
    }

    // ── التطبيق ──

    public function test_grants_one_permission_to_a_group_in_one_call(): void
    {
        $this->actingAsRole('ADMIN');
        $a = $this->userWithRole('EVALUATOR', 'خالد');
        $b = $this->userWithRole('ASSISTANT', 'ريم');

        $res = $this->bulk([$a->id, $b->id], [['permission' => 'report.export', 'action' => 'grant']], 'قرار المدير')
            ->assertOk();

        $this->assertCount(2, $res->json('applied'));
        foreach ([$a, $b] as $u) {
            $this->assertTrue($u->fresh()->hasPermission('report.export'));
            $this->assertSame('قرار المدير', UserPermissionOverride::where('user_id', $u->id)
                ->where('permission', 'report.export')->value('reason'));
        }
    }

    public function test_revoke_pulls_a_permission_the_role_grants(): void
    {
        $this->actingAsRole('ADMIN');
        $a = $this->userWithRole('EVALUATOR');
        $this->assertTrue($a->hasPermission('evaluation.input'), 'الاختبار بلا معنى إن لم يملكها بدوره');

        $this->bulk([$a->id], [['permission' => 'evaluation.input', 'action' => 'revoke']])->assertOk();

        $this->assertFalse($a->fresh()->hasPermission('evaluation.input'));
    }

    public function test_reset_lifts_an_existing_override(): void
    {
        $this->actingAsRole('ADMIN');
        $a = $this->userWithRole('EVALUATOR');
        UserPermissionOverride::create([
            'user_id' => $a->id, 'permission' => 'evaluation.input', 'granted' => false,
        ]);
        $this->assertFalse($a->fresh()->hasPermission('evaluation.input'));

        $this->bulk([$a->id], [['permission' => 'evaluation.input', 'action' => 'reset']])->assertOk();

        $this->assertSame(0, UserPermissionOverride::where('user_id', $a->id)->count());
        $this->assertTrue($a->fresh()->hasPermission('evaluation.input'));   // عاد لدوره
    }

    // تحديدٌ فيه أدوارٌ مختلفة: الصلاحية نفسها استثناءٌ عند واحد وتحصيلُ حاصلٍ
    // عند آخر. المسار الفردي يرفض الثاني؛ الجماعي يمحوه صامتاً ولا يُسقط الدفعة.
    public function test_mixed_roles_do_not_fail_the_batch(): void
    {
        $this->actingAsRole('ADMIN');
        $has = $this->userWithRole('EVALUATOR');      // يملك evaluation.input بدوره
        $hasNot = $this->userWithRole('OPERATIONS');  // لا يملكها

        $this->bulk([$has->id, $hasNot->id], [['permission' => 'evaluation.input', 'action' => 'grant']])
            ->assertOk();

        $this->assertTrue($has->fresh()->hasPermission('evaluation.input'));
        $this->assertTrue($hasNot->fresh()->hasPermission('evaluation.input'));
        // مَن يعطيه دورُه لا يُكتب له استثناءٌ لا أثر له
        $this->assertSame(0, UserPermissionOverride::where('user_id', $has->id)->count());
        $this->assertSame(1, UserPermissionOverride::where('user_id', $hasNot->id)->count());
    }

    // ── حدود الامتيازات: هي هي في الإفراد والجملة ──

    public function test_non_delegable_permissions_are_refused(): void
    {
        $this->actingAsRole('ADMIN');
        $a = $this->userWithRole('EVALUATOR');

        foreach (Permissions::NON_DELEGABLE as $perm) {
            $this->bulk([$a->id], [['permission' => $perm, 'action' => 'grant']])
                ->assertStatus(422)
                ->assertJsonPath('errors.changes.0', fn ($m) => str_contains($m, 'تُدار بالدور'));
        }
        $this->assertSame(0, UserPermissionOverride::where('user_id', $a->id)->count());
    }

    public function test_cannot_grant_a_permission_the_actor_lacks(): void
    {
        // مدير المركز يملك user.manage؟ لا — نمنحه إياها بدورٍ مخصّص لاختبار السقف
        $actor = $this->actingAsRole('ADMIN');
        $limited = $this->userWithRole('SCHEDULER');
        UserPermissionOverride::create([
            'user_id' => $limited->id, 'permission' => Permissions::USER_MANAGE, 'granted' => true,
        ]);
        $this->assertFalse($limited->fresh()->hasPermission('report.approve_center'));

        \Laravel\Sanctum\Sanctum::actingAs($limited->fresh());
        $target = $this->userWithRole('EVALUATOR');

        $this->bulk([$target->id], [['permission' => 'report.approve_center', 'action' => 'grant']])
            ->assertStatus(403)
            ->assertJsonPath('errors.changes.0', fn ($m) => str_contains($m, 'لا يمكنك منح صلاحية لا تملكها'));

        $this->assertFalse($target->fresh()->hasPermission('report.approve_center'));
    }

    // «تحديد الكل» يشمل حسابك — والخادم يتخطّاه لا يطبّق عليه، وإلا صار
    // الطريق الملتوي لمنح النفس ما لا تملك
    public function test_self_is_skipped_even_inside_a_wide_selection(): void
    {
        $actor = $this->actingAsRole('ADMIN');
        $other = $this->userWithRole('EVALUATOR');

        // صلاحيةٌ يعطيها دورُ المقيّم فعلاً — كي يكون السحب استثناءً محسوساً
        $res = $this->bulk([$actor->id, $other->id], [['permission' => 'evaluation.input', 'action' => 'revoke']])
            ->assertOk();

        $this->assertSame([$actor->id], array_column($res->json('skipped'), 'id'));
        $this->assertSame(0, UserPermissionOverride::where('user_id', $actor->id)->count());
        $this->assertSame(1, UserPermissionOverride::where('user_id', $other->id)->count());
    }

    // ── الأثر الجانبي: الجلسات والسجل ──

    public function test_touched_users_are_logged_out_and_audited(): void
    {
        $this->actingAsRole('ADMIN');
        $a = $this->userWithRole('EVALUATOR');
        $a->createToken('test');
        $this->assertSame(1, $a->tokens()->count());

        $this->bulk([$a->id], [['permission' => 'report.export', 'action' => 'grant']])->assertOk();

        $this->assertSame(0, $a->fresh()->tokens()->count(), 'تغيّرت صلاحياته فتُطرد جلساته');

        $log = AuditLog::where('action', 'BULK_UPDATE_USER_PERMISSIONS')
            ->where('entity_id', (string) $a->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(['report.export'], $log->details['granted']);
    }

    // لا شيء تغيّر ⇒ لا تُطرد جلسته: إعادةُ تطبيقِ ما هو قائم ليست حدثاً
    public function test_a_no_op_change_does_not_kill_sessions(): void
    {
        $this->actingAsRole('ADMIN');
        $a = $this->userWithRole('EVALUATOR');
        UserPermissionOverride::create([
            'user_id' => $a->id, 'permission' => 'report.export', 'granted' => true,
        ]);
        $a->createToken('test');

        $res = $this->bulk([$a->id], [['permission' => 'report.export', 'action' => 'grant']])->assertOk();

        $this->assertSame([], $res->json('applied'));
        $this->assertSame(1, $a->fresh()->tokens()->count());
    }

    // ── سلامة الطلب ──

    public function test_contradicting_actions_on_one_permission_are_refused(): void
    {
        $this->actingAsRole('ADMIN');
        $a = $this->userWithRole('EVALUATOR');

        $this->bulk([$a->id], [
            ['permission' => 'report.export', 'action' => 'grant'],
            ['permission' => 'report.export', 'action' => 'revoke'],
        ])->assertStatus(422);

        $this->assertSame(0, UserPermissionOverride::where('user_id', $a->id)->count());
    }

    public function test_unknown_permission_is_refused(): void
    {
        $this->actingAsRole('ADMIN');
        $a = $this->userWithRole('EVALUATOR');

        $this->bulk([$a->id], [['permission' => 'candidate.everything', 'action' => 'grant']])
            ->assertStatus(422);
    }

    // ── القائمة التي تقرؤها الشاشة ──

    public function test_catalog_labels_permissions_and_marks_the_locked_ones(): void
    {
        $this->actingAsRole('ADMIN');

        $groups = $this->getJson('/api/users/permission-catalog')->assertOk()->json('groups');
        $all = collect($groups)->flatMap(fn ($g) => $g['permissions'])->keyBy('permission');

        $this->assertSame('القيادة التنفيذية للمركز', $all['analytics.executive']['label']);
        $this->assertTrue($all['analytics.executive']['canGrant']);

        // سلطات النظام تظهر مقفلةً بسببها لا تختفي صامتة
        foreach (Permissions::NON_DELEGABLE as $perm) {
            $this->assertFalse($all[$perm]['canGrant']);
            $this->assertFalse($all[$perm]['canRevoke']);
            $this->assertNotNull($all[$perm]['lockedReason']);
        }
    }
}
