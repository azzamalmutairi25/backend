<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// فرض تغيير كلمة المرور خادمياً: مستخدم must_change_password=true يُحظر من
// كل الإجراءات عدا تغيير كلمة المرور والخروج وقراءة الملف الشخصي.
class PasswordChangeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function actAsMustChange(): User
    {
        $user = $this->actingAsRole('SCHEDULER'); // يملك candidate.view
        $user->forceFill(['must_change_password' => true])->save();

        return $user;
    }

    public function test_blocked_from_normal_endpoint_until_password_changed(): void
    {
        $this->actAsMustChange();

        $this->getJson('/api/candidates')
            ->assertStatus(403)
            ->assertJsonPath('mustChangePassword', true);
    }

    public function test_change_password_endpoint_is_allowed(): void
    {
        $this->actAsMustChange();

        // مسموح بالوصول (لا 403 من الوسيط) — قد يردّ 422 على مدخلات ناقصة، المهم ليس 403
        $this->postJson('/api/change-password', [])->assertStatus(422);
    }

    public function test_me_and_logout_are_allowed(): void
    {
        $this->actAsMustChange();

        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/logout')->assertOk();
    }

    public function test_normal_user_is_unaffected(): void
    {
        $this->actingAsRole('SCHEDULER'); // must_change_password=false افتراضاً

        $this->getJson('/api/candidates')->assertOk();
    }
}
