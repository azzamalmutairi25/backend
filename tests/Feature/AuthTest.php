<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_login_returns_token_and_role_permissions(): void
    {
        $this->postJson('/api/login', ['username' => 'evaluator', 'password' => 'Kafaat@2026'])
            ->assertOk()
            ->assertJsonPath('user.role', 'EVALUATOR')
            ->assertJsonStructure(['token', 'user' => ['permissions']]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/login', ['username' => 'evaluator', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_account_locks_after_five_failed_attempts(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', ['username' => 'evaluator', 'password' => 'wrong'])
                ->assertStatus(422);
        }
        // 5th failure triggers the lockout
        $this->postJson('/api/login', ['username' => 'evaluator', 'password' => 'wrong'])
            ->assertStatus(422);
        // even the correct password is now locked out
        $this->postJson('/api/login', ['username' => 'evaluator', 'password' => 'Kafaat@2026'])
            ->assertStatus(422);
    }
}
