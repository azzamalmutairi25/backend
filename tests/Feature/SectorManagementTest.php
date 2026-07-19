<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// إدارة القطاعات القابلة للتعديل: إضافة/تعديل الاسم/حذف — بصلاحية إدارة الإعدادات.
class SectorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(): User
    {
        return User::create([
            'username' => 'adm_' . substr(md5(uniqid('', true)), 0, 6), 'full_name' => 'مدير النظام',
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'ADMIN')->value('id'),
            'user_type' => 'external', 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    public function test_add_sector(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/sectors', ['code' => 'AV', 'nameAr' => 'الطيران', 'isMilitary' => true])
            ->assertCreated();
        $this->assertDatabaseHas('sectors', ['code' => 'AV', 'name_ar' => 'الطيران', 'is_military' => true]);
    }

    public function test_add_sector_requires_settings_manage(): void
    {
        $this->actingAsRole('EVALUATOR', 'ED'); // لا يملك settings.manage
        $this->postJson('/api/sectors', ['code' => 'AV', 'nameAr' => 'الطيران'])->assertStatus(403);
    }

    public function test_duplicate_code_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/sectors', ['code' => 'ED', 'nameAr' => 'مكرر'])->assertStatus(422);
    }

    public function test_duplicate_prefix_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/sectors', ['code' => 'AV', 'nameAr' => 'أ', 'participantPrefix' => 'XY'])->assertCreated();
        $this->postJson('/api/sectors', ['code' => 'BV', 'nameAr' => 'ب', 'participantPrefix' => 'XY'])->assertStatus(422);
    }

    public function test_rename_sector(): void
    {
        Sanctum::actingAs($this->admin());
        $s = Sector::where('code', 'ED')->first();
        $this->putJson("/api/sectors/{$s->id}", ['nameAr' => 'التعليم والتدريب'])->assertOk();
        $this->assertDatabaseHas('sectors', ['id' => $s->id, 'name_ar' => 'التعليم والتدريب']);
    }

    public function test_cannot_delete_sector_with_candidates(): void
    {
        Sanctum::actingAs($this->admin());
        $this->makeCandidate(['sectorCode' => 'ED']);
        $s = Sector::where('code', 'ED')->first();
        $this->deleteJson("/api/sectors/{$s->id}")->assertStatus(422);
    }

    public function test_delete_empty_sector(): void
    {
        Sanctum::actingAs($this->admin());
        $s = Sector::create(['code' => 'ZZ', 'name_ar' => 'مؤقت', 'is_military' => false, 'participant_prefix' => 'ZZ']);
        $this->deleteJson("/api/sectors/{$s->id}")->assertOk();
        $this->assertDatabaseMissing('sectors', ['id' => $s->id]);
    }
}
