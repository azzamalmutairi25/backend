<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_reference_data_is_seeded(): void
    {
        $this->assertDatabaseCount('roles', 11);
        $this->assertDatabaseCount('sectors', 8);
        $this->assertGreaterThanOrEqual(3, \App\Models\User::count());
    }

    public function test_admin_can_login_and_gets_permissions(): void
    {
        $res = $this->postJson('/api/login', ['username' => 'admin', 'password' => 'Kafaat@2026']);
        $res->assertOk()
            ->assertJsonPath('user.role', 'ADMIN')
            ->assertJsonStructure(['token', 'user' => ['permissions']]);
        $this->assertContains('*', $res->json('user.permissions'));
    }
}
