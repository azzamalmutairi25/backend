<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// إصلاحات مراجعة الأساس الأمني: سقف امتياز إدارة المستخدمين، متانة الاستيراد،
// وردّ المصادقة الموحّد + إبطال الجلسات عند تغيير كلمة المرور.
class SecurityCoreFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // مدير مستخدمين غير مدير نظام (SCHEDULER + استثناء user.manage مباشرةً)
    private function delegatedUserManager(): User
    {
        $u = $this->actingAsRole('SCHEDULER');
        $u->permissionOverrides()->create(['permission' => 'user.manage', 'granted' => true]);
        return $u;
    }

    private function adminUser(): User
    {
        return User::create([
            'username' => 'root_' . substr(md5(uniqid('', true)), 0, 6), 'full_name' => 'مدير النظام',
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'ADMIN')->value('id'),
            'user_type' => 'external', 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    // ── #1: لا يُنشئ مدير مستخدمين مفوَّض حساب ADMIN ──
    public function test_delegated_manager_cannot_create_an_admin(): void
    {
        $this->delegatedUserManager();
        $adminRole = Role::where('code', 'ADMIN')->value('id');
        $this->postJson('/api/users', [
            'username' => 'pwn', 'fullName' => 'x', 'userType' => 'external',
            'password' => 'Chosen#12345', 'roleId' => $adminRole,
        ])->assertStatus(403);
    }

    public function test_delegated_manager_cannot_assign_a_role_beyond_own_authority(): void
    {
        $this->delegatedUserManager(); // SCHEDULER لا يملك report.approve/competency.manage
        $devRole = Role::where('code', 'DEV_MANAGER')->value('id');
        $this->postJson('/api/users', [
            'username' => 'up', 'fullName' => 'x', 'userType' => 'external',
            'password' => 'Chosen#12345', 'roleId' => $devRole,
        ])->assertStatus(403);
    }

    public function test_admin_can_still_create_an_admin(): void
    {
        Sanctum::actingAs($this->adminUser());
        $adminRole = Role::where('code', 'ADMIN')->value('id');
        $this->postJson('/api/users', [
            'username' => 'root2', 'fullName' => 'x', 'userType' => 'external',
            'password' => 'Chosen#12345', 'roleId' => $adminRole,
        ])->assertCreated();
    }

    // ── #3: لا يُعيد تعيين كلمة مرور حساب أعلى رتبةً ──
    public function test_delegated_manager_cannot_reset_an_admin_password(): void
    {
        $admin = $this->adminUser();
        $this->delegatedUserManager();
        $this->patchJson("/api/users/{$admin->id}/password", ['password' => 'NewPass#12345'])->assertStatus(403);
    }

    public function test_delegated_manager_cannot_disable_an_admin(): void
    {
        $admin = $this->adminUser();
        $this->delegatedUserManager();
        $this->patchJson("/api/users/{$admin->id}/toggle")->assertStatus(403);
    }

    // ── #4: سطر استيراد مشوّه لا يُسقط الدفعة بـ500 ──
    public function test_malformed_import_row_becomes_an_error_not_a_500(): void
    {
        $this->actingAsRole('SCHEDULER'); // CANDIDATE_CREATE
        $res = $this->postJson('/api/candidates/import', ['rows' => [
            'this-is-not-an-object',
            ['nationalId' => '1010101010', 'fullName' => 'مرشح', 'mobile' => '0500000000', 'sectorCode' => 'ED', 'rankLabel' => 'مدير عام'],
        ]])->assertOk();
        $this->assertSame(1, $res->json('failed'));
        $this->assertStringContainsString('تنسيق غير صحيح', implode(' ', $res->json('errors')));
    }

    // ── #5: الحساب المقفل يردّ رسالة عامة (لا «مقفل» ولا مدّة) ──
    public function test_locked_account_returns_generic_message(): void
    {
        $u = User::create([
            'username' => 'locked1', 'full_name' => 'x', 'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'SCHEDULER')->value('id'), 'user_type' => 'external',
            'is_active' => true, 'must_change_password' => false, 'locked_until' => now()->addMinutes(10),
        ]);
        $res = $this->postJson('/api/login', ['username' => 'locked1', 'password' => 'Kafaat@2026'])
            ->assertStatus(422);
        $msg = implode(' ', $res->json('errors.username'));
        $this->assertStringContainsString('غير صحيحة', $msg);
        $this->assertStringNotContainsString('مقفل', $msg);
        $this->assertStringNotContainsString('دقيقة', $msg);
    }

    // ── #7: تغيير كلمة المرور يُبطل الجلسات الأخرى ويُبقي الحالية ──
    public function test_change_password_revokes_other_sessions(): void
    {
        $u = User::create([
            'username' => 'cp1', 'full_name' => 'x', 'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'SCHEDULER')->value('id'), 'user_type' => 'external',
            'is_active' => true, 'must_change_password' => false,
        ]);
        $u->createToken('other')->plainTextToken;   // جلسة أخرى
        $current = $u->createToken('current')->plainTextToken;
        $this->assertSame(2, $u->tokens()->count());

        Sanctum::actingAs($u->fresh(), ['*']); // نتصرّف كالجلسة الحالية
        $this->postJson('/api/change-password', ['currentPassword' => 'Kafaat@2026', 'newPassword' => 'NewPass#12345'])
            ->assertOk();
        // بقيت جلسة واحدة (الحالية) — الأخرى أُبطِلت
        $this->assertLessThanOrEqual(1, $u->fresh()->tokens()->count());
    }
}
