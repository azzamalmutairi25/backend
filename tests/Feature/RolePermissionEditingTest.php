<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  تحرير صلاحيات الأدوار من الشاشة.
//
//  هذا أخطر سطحٍ في المنصّة: تعديل الدور يمسّ **كل من يحمله** دفعةً واحدة،
//  بخلاف استثناء المستخدم الذي يمسّ واحداً. ولذلك كانت المصفوفة ثابتةً في
//  الشيفرة أوّلاً. فتحُها مقصود، والحرّاس هي ثمن الفتح — وهذه الاختبارات
//  هي ما يمنع سقوط أحدها في تعديلٍ لاحق.
// ════════════════════════════════════════════════════════════
class RolePermissionEditingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function roleId(string $code): int
    {
        return Role::where('code', $code)->value('id');
    }

    private function permsOf(string $code): array
    {
        Permissions::forgetCache();
        return Permissions::forRole($code);
    }

    // ═══ الأثر الحقيقي ═══

    public function test_saving_a_role_changes_what_its_holders_can_do_immediately(): void
    {
        // مشرف القياس لا يملك عرض التقارير
        $holder = $this->actingAsRole('MEASURE_SUPER');
        $this->getJson('/api/reports')->assertStatus(403);

        // مدير النظام يمنح الدور صلاحية عرض التقارير
        $this->actingAsRole('ADMIN');
        $perms = array_merge($this->permsOf('MEASURE_SUPER'), [Permissions::REPORT_VIEW]);
        $this->putJson("/api/roles/{$this->roleId('MEASURE_SUPER')}/permissions", ['permissions' => $perms])
            ->assertOk();

        // نفس المستخدم، بلا تغيير في حسابه — الدور وحده تغيّر
        $this->actingAs($holder->fresh());
        $this->getJson('/api/reports')->assertOk();
    }

    public function test_revoking_from_a_role_closes_the_screen_on_its_holders(): void
    {
        $holder = $this->actingAsRole('MEASURE_SUPER');
        $this->getJson('/api/measurements/1')->assertStatus(404); // يصل للمتحكّم (لا 403)

        $this->actingAsRole('ADMIN');
        $perms = array_values(array_diff($this->permsOf('MEASURE_SUPER'), [Permissions::MEASUREMENT_VIEW]));
        $this->putJson("/api/roles/{$this->roleId('MEASURE_SUPER')}/permissions", ['permissions' => $perms])
            ->assertOk();

        $this->actingAs($holder->fresh());
        $this->getJson('/api/measurements/1')->assertStatus(403);
    }

    // ═══ الحرّاس الأربعة ═══

    public function test_the_system_administrator_role_cannot_be_edited_or_deleted(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->roleId('ADMIN');

        $this->putJson("/api/roles/{$id}/permissions", ['permissions' => [Permissions::CANDIDATE_VIEW]])
            ->assertStatus(422);
        $this->deleteJson("/api/roles/{$id}")->assertStatus(422);

        // ولا يزال يملك كل شيء
        $this->assertContains('*', $this->permsOf('ADMIN'));
    }

    public function test_nobody_edits_the_role_they_hold_themselves(): void
    {
        // مدير نظام ثانٍ: يملك USER_MANAGE لكنه يحمل دور ADMIN — يُمنع مرّتين
        $second = $this->actingAsRole('CENTER_MANAGER');
        $second->permissionOverrides()->create([
            'permission' => Permissions::USER_MANAGE, 'granted' => true,
        ]);
        $this->actingAs($second->fresh());

        $this->putJson("/api/roles/{$this->roleId('CENTER_MANAGER')}/permissions", ['permissions' => []])
            ->assertStatus(422);
        $this->deleteJson("/api/roles/{$this->roleId('CENTER_MANAGER')}")->assertStatus(422);
    }

    public function test_a_permission_the_editor_does_not_hold_cannot_be_granted(): void
    {
        // مسؤول جدولة مُنِح إدارة المستخدمين استثناءً — لكنه لا يملك اعتماد التقارير
        $actor = $this->actingAsRole('SCHEDULER');
        $actor->permissionOverrides()->create([
            'permission' => Permissions::USER_MANAGE, 'granted' => true,
        ]);
        $this->actingAs($actor->fresh());
        $this->assertFalse($actor->fresh()->hasPermission(Permissions::REPORT_APPROVE));

        $target = $this->roleId('MEASURE_SUPER');
        $perms = array_merge($this->permsOf('MEASURE_SUPER'), [Permissions::REPORT_APPROVE]);

        $this->putJson("/api/roles/{$target}/permissions", ['permissions' => $perms])
            ->assertStatus(422);

        $this->assertNotContains(Permissions::REPORT_APPROVE, $this->permsOf('MEASURE_SUPER'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'DENIED_ROLE_ESCALATION']);
    }

    public function test_system_authorities_cannot_be_granted_or_revoked_from_here(): void
    {
        $this->actingAsRole('ADMIN');
        $target = $this->roleId('MEASURE_SUPER');

        foreach (Permissions::NON_DELEGABLE as $p) {
            $perms = array_merge($this->permsOf('MEASURE_SUPER'), [$p]);
            $this->putJson("/api/roles/{$target}/permissions", ['permissions' => $perms])
                ->assertStatus(422);
            $this->assertNotContains($p, $this->permsOf('MEASURE_SUPER'));
        }

        // والسحب كذلك: مدير المركز يملك سجل التدقيق، ولا يُسحب من هنا
        $cm = $this->roleId('CENTER_MANAGER');
        $perms = array_values(array_diff($this->permsOf('CENTER_MANAGER'), [Permissions::AUDIT_VIEW]));
        $this->putJson("/api/roles/{$cm}/permissions", ['permissions' => $perms])->assertStatus(422);
        $this->assertContains(Permissions::AUDIT_VIEW, $this->permsOf('CENTER_MANAGER'));
    }

    // ═══ الفراغ المقصود ═══
    //
    // تجريد دورٍ من كل صلاحياته كان يحذف صفوفه، فيقع على المصفوفة ويستعيد
    // افتراضياته — أي أنّ السحب الكامل ينقلب إلى استعادةٍ صامتة. العلامة
    // PLACEHOLDER تمنع ذلك، وهذا الاختبار يمنع عودتها.
    public function test_stripping_a_role_bare_does_not_silently_restore_its_defaults(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson("/api/roles/{$this->roleId('MEASURE_SUPER')}/permissions", ['permissions' => []])
            ->assertOk();

        $this->assertSame([], $this->permsOf('MEASURE_SUPER'),
            'الدور المجرَّد استعاد صلاحياته الافتراضية');

        // وحاملُه لا يفتح شيئاً
        $holder = $this->actingAsRole('MEASURE_SUPER');
        $this->actingAs($holder->fresh());
        $this->getJson('/api/measurements/1')->assertStatus(403);
    }

    public function test_the_placeholder_marker_is_never_exposed_as_a_permission(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson("/api/roles/{$this->roleId('MEASURE_SUPER')}/permissions", ['permissions' => []])
            ->assertOk();

        $body = $this->getJson("/api/roles/{$this->roleId('MEASURE_SUPER')}/permissions")
            ->assertOk()->getContent();
        $this->assertStringNotContainsString(Permissions::PLACEHOLDER, $body);
        $this->assertNotContains(Permissions::PLACEHOLDER, Permissions::all());
    }

    // ═══ دورة حياة الدور ═══

    public function test_a_new_role_is_born_with_no_permissions_at_all(): void
    {
        $this->actingAsRole('ADMIN');

        $id = $this->postJson('/api/roles', [
            'code' => 'ARCHIVIST', 'nameAr' => 'أمين الأرشيف',
        ])->assertStatus(201)->json('role.id');

        $this->assertSame([], Permissions::forRole('ARCHIVIST'));
        $this->assertTrue(Permissions::roleIsCustomised('ARCHIVIST'));

        // ثم يُمنح ما يُراد
        $this->putJson("/api/roles/{$id}/permissions", [
            'permissions' => [Permissions::CANDIDATE_VIEW, Permissions::REPORT_VIEW],
        ])->assertOk();
        $this->assertEqualsCanonicalizing(
            [Permissions::CANDIDATE_VIEW, Permissions::REPORT_VIEW],
            $this->permsOf('ARCHIVIST')
        );
    }

    public function test_a_role_code_must_be_a_valid_unique_key(): void
    {
        $this->actingAsRole('ADMIN');

        $this->postJson('/api/roles', ['code' => 'bad code', 'nameAr' => 'س'])->assertStatus(422);
        $this->postJson('/api/roles', ['code' => 'ADMIN', 'nameAr' => 'س'])->assertStatus(422);
    }

    public function test_a_role_with_holders_cannot_be_deleted(): void
    {
        $holder = $this->actingAsRole('MEASURE_SUPER');
        $this->actingAsRole('ADMIN');

        $this->deleteJson("/api/roles/{$this->roleId('MEASURE_SUPER')}")->assertStatus(422);
        $this->assertDatabaseHas('roles', ['code' => 'MEASURE_SUPER']);

        // ينتقل الحامل إلى دورٍ آخر، فيُحذف
        $holder->update(['role_id' => $this->roleId('ASSISTANT')]);
        $this->deleteJson("/api/roles/{$this->roleId('MEASURE_SUPER')}")->assertOk();
        $this->assertDatabaseMissing('roles', ['code' => 'MEASURE_SUPER']);
    }

    public function test_reset_restores_the_built_in_defaults(): void
    {
        $this->actingAsRole('ADMIN');
        $id = $this->roleId('MEASURE_SUPER');
        $default = Permissions::matrix()['MEASURE_SUPER'];

        $this->putJson("/api/roles/{$id}/permissions", ['permissions' => []])->assertOk();
        $this->assertSame([], $this->permsOf('MEASURE_SUPER'));

        $this->postJson("/api/roles/{$id}/reset")->assertOk();
        $this->assertEqualsCanonicalizing($default, $this->permsOf('MEASURE_SUPER'));
    }

    // ═══ البوّابة ═══

    public function test_a_role_without_user_manage_reaches_none_of_it(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $id = $this->roleId('MEASURE_SUPER');

        $this->getJson('/api/roles')->assertStatus(403);
        $this->getJson("/api/roles/{$id}/permissions")->assertStatus(403);
        $this->putJson("/api/roles/{$id}/permissions", ['permissions' => []])->assertStatus(403);
        $this->postJson('/api/roles', ['code' => 'X', 'nameAr' => 'س'])->assertStatus(403);
        $this->deleteJson("/api/roles/{$id}")->assertStatus(403);
        $this->postJson("/api/roles/{$id}/reset")->assertStatus(403);
    }

    // ═══ سلامة قائمة الصلاحيات ═══

    public function test_every_permission_has_an_arabic_label(): void
    {
        $missing = array_values(array_filter(
            Permissions::all(),
            fn ($p) => Permissions::label($p) === $p
        ));

        $this->assertSame([], $missing,
            'صلاحيات بلا وصف عربي — تظهر للمدير كمفتاح خام: ' . implode('، ', $missing));
    }

    // مفتاح ذاكرةٍ داخلي اسمه 'kafaat.rolePermissions' كان يمرّ بفحص «فيه نقطة»
    // فيصير صلاحيةً وهمية بمربّع اختيار في الشاشة. النمط الآن صارم.
    public function test_the_permission_list_holds_nothing_but_real_permissions(): void
    {
        foreach (Permissions::all() as $p) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z_]*\.[a-z][a-z_]*$/', $p,
                "قيمة ليست صلاحية تسرّبت إلى القائمة: {$p}");
        }
        $this->assertNotContains(Permissions::PLACEHOLDER, Permissions::all());
    }

    // ═══ كل شاشة لها صلاحيتها ═══

    public function test_screens_that_used_to_share_a_permission_now_have_their_own(): void
    {
        foreach ([
            Permissions::ANALYTICS_EXECUTIVE,
            Permissions::ANALYTICS_DAILY_REPORT,
            Permissions::DEVELOPMENT_PLAN_VIEW,
            Permissions::CHAT_VIEW,
            Permissions::WORKFLOW_MANAGE,
        ] as $p) {
            $this->assertContains($p, Permissions::all());
            $this->assertNotSame($p, Permissions::label($p), "بلا وصف: {$p}");
        }
    }

    // ═══ الافتراضي يبقى مصدر الرجوع ═══

    public function test_a_role_with_no_rows_falls_back_to_the_built_in_default(): void
    {
        RolePermission::where('role_id', $this->roleId('EVALUATOR'))->delete();
        Permissions::forgetCache();

        $this->assertEqualsCanonicalizing(
            Permissions::matrix()['EVALUATOR'],
            Permissions::forRole('EVALUATOR'),
            'دورٌ بلا صفوف يجب أن يقع على المصفوفة لا على قائمة فارغة'
        );
    }
}
