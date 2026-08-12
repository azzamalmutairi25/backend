<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ثوابت إدارة المستخدمين: حرّاس الذات + إبطال الجلسات عند تغيّر الدور/التعطيل/إعادة التعيين
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function makeUser(string $roleCode = 'EVALUATOR', array $attrs = []): User
    {
        $role = Role::where('code', $roleCode)->firstOrFail();
        return User::create(array_merge([
            'username' => 'u_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستخدم', 'role_id' => $role->id, 'is_active' => true,
            // الأدوار المحصورة بقطاع لا توجد بلا قطاع
            'sector_id' => in_array($roleCode, User::SECTOR_BOUND_ROLES, true)
                ? \App\Models\Sector::value('id') : null,
            'must_change_password' => false, 'user_type' => 'external', 'password' => 'Kafaat@2026',
        ], $attrs));
    }

    private function sectorId(): int
    {
        return \App\Models\Sector::value('id');
    }

    public function test_non_admin_cannot_manage_users(): void
    {
        $this->actingAsRole('SCHEDULER'); // لا USER_MANAGE
        $this->getJson('/api/users')->assertStatus(403);
        $this->postJson('/api/users', [])->assertStatus(403);
    }

    public function test_create_external_user_must_change_password(): void
    {
        $role = Role::where('code', 'EVALUATOR')->firstOrFail();
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/users', [
            'username' => 'ext1', 'fullName' => 'خارجي', 'roleId' => $role->id,
            'sectorId' => $this->sectorId(), // المقيّم محصور بقطاع
            'userType' => 'external', 'password' => 'Kafaat@2026',
        ])->assertCreated();

        $u = User::where('username', 'ext1')->first();
        $this->assertSame('external', $u->user_type);
        $this->assertTrue((bool) $u->must_change_password);
        $this->assertNull($u->ad_username);
    }

    public function test_create_internal_user_uses_ad_and_no_change_flag(): void
    {
        $role = Role::where('code', 'EVALUATOR')->firstOrFail();
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/users', [
            'username' => 'int1', 'fullName' => 'داخلي', 'roleId' => $role->id,
            'sectorId' => $this->sectorId(),
            'userType' => 'internal', 'adUsername' => 'int1.ad',
        ])->assertCreated();

        $u = User::where('username', 'int1')->first();
        $this->assertSame('internal', $u->user_type);
        $this->assertFalse((bool) $u->must_change_password); // AD — لا كلمة مرور محلية
        $this->assertSame('int1.ad', $u->ad_username);
    }

    public function test_cannot_change_own_role(): void
    {
        $admin = $this->actingAsRole('ADMIN');
        $other = Role::where('code', '!=', $admin->role->code)->first();
        $this->putJson("/api/users/{$admin->id}", [
            'fullName' => $admin->full_name, 'roleId' => $other->id,
        ])->assertStatus(422);
    }

    public function test_role_change_revokes_target_tokens(): void
    {
        $target = $this->makeUser('EVALUATOR');
        $target->createToken('t');
        $this->assertSame(1, $target->tokens()->count());
        $newRole = Role::where('code', 'DISCUSSION_EVAL')->firstOrFail();

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/users/{$target->id}", [
            'fullName' => 'محدّث', 'roleId' => $newRole->id,
            'sectorId' => $target->sector_id, // كلا الدورين محصور بقطاع
        ])->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count()); // تغيّر الصلاحيات ⇒ طرد الجلسات
    }

    public function test_cannot_disable_own_account(): void
    {
        $admin = $this->actingAsRole('ADMIN');
        $this->patchJson("/api/users/{$admin->id}/toggle")->assertStatus(422);
    }

    public function test_disable_user_revokes_tokens(): void
    {
        $target = $this->makeUser('EVALUATOR');
        $target->createToken('t');

        $this->actingAsRole('ADMIN');
        $this->patchJson("/api/users/{$target->id}/toggle")->assertOk()->assertJsonPath('isActive', false);
        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_reset_password_external_sets_flag_and_revokes_tokens(): void
    {
        $target = $this->makeUser('EVALUATOR');
        $target->createToken('t');

        $this->actingAsRole('ADMIN');
        $this->patchJson("/api/users/{$target->id}/password", ['password' => 'Kafaat@2026'])->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertSame(0, $fresh->tokens()->count());
    }
}
