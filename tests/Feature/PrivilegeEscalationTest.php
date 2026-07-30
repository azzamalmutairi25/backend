<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  تصعيد الامتيازات — الطريق الذي يسلكه من يملك قليلاً ليملك كثيراً.
//
//  نظام الصلاحيات لا ينكسر عادةً عند الحارس المباشر، بل عند الباب الجانبي:
//  من يستطيع إنشاء حساب، أو تغيير دور، أو منح استثناء، أو إعادة تعيين كلمة
//  مرور حسابٍ أعلى منه — يستطيع أن يصير هو ذلك الحساب.
//
//  المحكّ هنا واحد: هل يستطيع حاملُ صلاحيةٍ إداريةٍ محدودة أن يبلغ ما لا
//  يملكه بأي طريق غير مباشر؟
// ════════════════════════════════════════════════════════════
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // مستخدم بدور محدّد + استثناء يمنحه USER_MANAGE — «مدير مستخدمين غير مدير
    // نظام»، وهو الحالة التي تنهار عندها الحدود لو لم تُحرَس
    private function userManagerWithoutAdmin(string $baseRole = 'SCHEDULER'): User
    {
        $actor = $this->actingAsRole($baseRole);
        UserPermissionOverride::create([
            'user_id' => $actor->id,
            'permission' => Permissions::USER_MANAGE,
            'granted' => true,
            'created_by' => null,
        ]);
        return $actor->fresh();
    }

    private function roleId(string $code): int
    {
        return Role::where('code', $code)->value('id');
    }

    private function makeUserPayload(array $over = []): array
    {
        return array_merge([
            'username' => 'esc_' . substr(md5(uniqid('', true)), 0, 8),
            'fullName' => 'حساب اختبار',
            'password' => 'Kafaat@2026x',
            'roleId' => $this->roleId('RECEPTIONIST'),
            'userType' => 'external',
        ], $over);
    }

    // ═══ إنشاء الحسابات ═══

    public function test_a_user_manager_cannot_create_a_system_administrator(): void
    {
        $this->userManagerWithoutAdmin();

        $this->postJson('/api/users', $this->makeUserPayload(['roleId' => $this->roleId('ADMIN')]))
            ->assertStatus(403);

        $this->assertSame(0, User::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))
            ->where('username', 'like', 'esc_%')->count());
    }

    public function test_a_user_manager_cannot_create_a_role_beyond_their_own_permissions(): void
    {
        // مسؤول الجدولة لا يملك REPORT_APPROVE ولا CANDIDATE_VIEW_CLASSIFIED،
        // فلا يُنشئ «إدارة تطوير الكفاءات» التي تملكهما
        $this->userManagerWithoutAdmin('SCHEDULER');

        $this->postJson('/api/users', $this->makeUserPayload(['roleId' => $this->roleId('DEV_MANAGER')]))
            ->assertStatus(403);
    }

    // الحارس يمنع التجاوز لا العمل. والسقف صارم عملياً: الأدوار متعامدة لا
    // متداخلة، فلا دور تشمله صلاحيات دورٍ آخر شمولاً تامّاً — ومن ثمّ لا يُنشئ
    // مفوَّضُ USER_MANAGE أي حساب إلا إن غُطّي النقص باستثناءات صريحة.
    // هذا فشلٌ مغلق مقصود، ونُثبته في الاتجاهين كي لا يُخفّف سهواً.
    public function test_a_user_manager_may_create_a_role_fully_covered_by_their_permissions(): void
    {
        $actor = $this->userManagerWithoutAdmin('SCHEDULER');

        // ينقص مسؤولَ الجدولة تسجيلُ الحضور — وهو من صلاحيات الاستقبال
        $this->assertFalse($actor->hasPermission(Permissions::ATTENDANCE_RECORD));
        $this->postJson('/api/users', $this->makeUserPayload(['roleId' => $this->roleId('RECEPTIONIST')]))
            ->assertStatus(403);

        foreach ([Permissions::ATTENDANCE_RECORD, Permissions::ATTENDANCE_RECORD_ANY] as $perm) {
            UserPermissionOverride::create([
                'user_id' => $actor->id, 'permission' => $perm, 'granted' => true, 'created_by' => null,
            ]);
        }

        // اكتمل الغطاء ⇒ يُسمح. لولا ذلك لكان الحارس مانعاً مطلقاً لا سقفاً
        $this->postJson('/api/users', $this->makeUserPayload(['roleId' => $this->roleId('RECEPTIONIST')]))
            ->assertStatus(201);
    }

    public function test_the_system_administrator_may_still_create_any_role(): void
    {
        $this->actingAsRole('ADMIN');

        $this->postJson('/api/users', $this->makeUserPayload(['roleId' => $this->roleId('ADMIN')]))
            ->assertStatus(201);
    }

    // ═══ تغيير الأدوار ═══

    public function test_nobody_promotes_themselves_by_changing_their_own_role(): void
    {
        $actor = $this->actingAsRole('ADMIN');

        $this->putJson("/api/users/{$actor->id}", [
            'fullName' => $actor->full_name,
            'roleId' => $this->roleId('RECEPTIONIST'),
        ])->assertStatus(422);

        $this->assertSame($actor->role_id, $actor->fresh()->role_id);
    }

    public function test_a_user_manager_cannot_promote_an_account_to_administrator(): void
    {
        $target = $this->actingAsRole('RECEPTIONIST');
        $this->userManagerWithoutAdmin('SCHEDULER');

        $this->putJson("/api/users/{$target->id}", [
            'fullName' => $target->full_name,
            'roleId' => $this->roleId('ADMIN'),
        ])->assertStatus(403);

        $this->assertSame('RECEPTIONIST', $target->fresh()->role->code);
    }

    // ═══ الحسابات الأعلى ═══

    public function test_a_user_manager_cannot_take_over_an_administrator_account(): void
    {
        $admin = $this->actingAsRole('ADMIN');
        $this->userManagerWithoutAdmin('SCHEDULER');

        // إعادة تعيين كلمة المرور أقصر طريق للاستيلاء على حساب أعلى
        $this->patchJson("/api/users/{$admin->id}/password", ['password' => 'Takeover@2026'])
            ->assertStatus(403);

        // والتعطيل طريقٌ آخر: شلّ المدراء ثم الانفراد بالنظام
        $this->patchJson("/api/users/{$admin->id}/toggle")->assertStatus(403);
        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_nobody_disables_their_own_account(): void
    {
        $actor = $this->actingAsRole('ADMIN');

        $this->patchJson("/api/users/{$actor->id}/toggle")->assertStatus(422);
        $this->assertTrue((bool) $actor->fresh()->is_active);
    }

    // ═══ الاستثناءات الفردية ═══

    public function test_nobody_edits_their_own_overrides(): void
    {
        $actor = $this->userManagerWithoutAdmin('SCHEDULER');

        $this->putJson("/api/users/{$actor->id}/permissions", [
            'overrides' => [['permission' => Permissions::CANDIDATE_VIEW_CLASSIFIED, 'granted' => true]],
        ])->assertStatus(422);

        $this->assertFalse($actor->fresh()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED));
    }

    public function test_administrative_permissions_are_never_delegated_by_override(): void
    {
        $target = $this->actingAsRole('RECEPTIONIST');
        $this->actingAsRole('ADMIN'); // حتى مدير النظام لا يفوّضها بالاستثناء

        foreach (Permissions::NON_DELEGABLE as $perm) {
            $this->putJson("/api/users/{$target->id}/permissions", [
                'overrides' => [['permission' => $perm, 'granted' => true]],
            ])->assertStatus(422);

            $this->assertFalse($target->fresh()->hasPermission($perm), "فُوِّضت صلاحية إدارية: {$perm}");
        }
    }

    public function test_you_cannot_grant_a_permission_you_do_not_hold(): void
    {
        $target = $this->actingAsRole('RECEPTIONIST');
        $this->userManagerWithoutAdmin('SCHEDULER'); // لا يملك رؤية المصنّفين

        $this->putJson("/api/users/{$target->id}/permissions", [
            'overrides' => [['permission' => Permissions::CANDIDATE_VIEW_CLASSIFIED, 'granted' => true]],
        ])->assertStatus(403);

        $this->assertFalse($target->fresh()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED));
    }

    public function test_a_non_administrator_cannot_strip_an_administrator(): void
    {
        $admin = $this->actingAsRole('ADMIN');
        $this->userManagerWithoutAdmin('SCHEDULER');

        // سحبُ صلاحيةٍ من مدير النظام قفلٌ للنظام بيد من هو دونه
        $this->putJson("/api/users/{$admin->id}/permissions", [
            'overrides' => [['permission' => Permissions::CANDIDATE_VIEW, 'granted' => false]],
        ])->assertStatus(403);

        $this->assertTrue($admin->fresh()->hasPermission(Permissions::CANDIDATE_VIEW));
    }

    // ═══ دلالات الفحص ═══

    public function test_a_revoked_override_beats_the_administrator_wildcard(): void
    {
        $admin = $this->actingAsRole('ADMIN');
        UserPermissionOverride::create([
            'user_id' => $admin->id,
            'permission' => Permissions::CANDIDATE_VIEW_NAMES,
            'granted' => false,
            'created_by' => null,
        ]);

        $fresh = $admin->fresh();
        $this->assertFalse($fresh->hasPermission(Permissions::CANDIDATE_VIEW_NAMES));
        $this->assertNotContains(Permissions::CANDIDATE_VIEW_NAMES, $fresh->effectivePermissions());
        // ولا يزال يملك ما لم يُسحب — السحب جراحيّ لا شامل
        $this->assertTrue($fresh->hasPermission(Permissions::CANDIDATE_VIEW));
    }

    public function test_an_undeclared_permission_is_denied_even_for_the_administrator(): void
    {
        $admin = $this->actingAsRole('ADMIN');

        // خطأ مطبعي في فحصٍ داخل متحكّم لا يجوز أن يمرّ لأحد — وإلا صمت العطل
        $this->assertFalse($admin->hasPermission('candidate.viewww'));
        $this->assertFalse($admin->hasPermission(''));
    }

    // ═══ الرمز يتبع الحساب ═══

    public function test_disabling_an_account_kills_its_live_token(): void
    {
        $victim = $this->actingAsRole('RECEPTIONIST');
        $token = $victim->createToken('t')->plainTextToken;

        $this->actingAsRole('ADMIN');
        $this->patchJson("/api/users/{$victim->id}/toggle")->assertOk();

        // رمزٌ يعيش بعد تعطيل صاحبه يجعل التعطيل إجراءً شكلياً.
        // نفحص الرمز نفسه لا عبر نداء HTTP: Sanctum::actingAs يتجاوز الحارس
        // في الاختبار، فنداءٌ بترويسة Bearer لا يثبت شيئاً عن صلاحية الرمز.
        $this->assertSame(0, $victim->fresh()->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($token), 'الرمز ما زال قابلاً للحلّ بعد التعطيل');
    }

    public function test_changing_a_role_kills_the_targets_tokens(): void
    {
        $victim = $this->actingAsRole('RECEPTIONIST');
        $victim->createToken('t');
        $this->assertSame(1, $victim->tokens()->count());

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/users/{$victim->id}", [
            'fullName' => $victim->full_name,
            'roleId' => $this->roleId('MEASURE_SUPER'),
        ])->assertOk();

        // الرمز القديم يحمل صلاحيات الدور القديم في ذهن الواجهة — يُبطَل
        $this->assertSame(0, $victim->fresh()->tokens()->count());
    }
}
