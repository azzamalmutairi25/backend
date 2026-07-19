<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Rank;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// إدارة الرتب/المراتب + قيادتها لتصنيف الفئة القيادية (مع احتياط المنطق القديم).
class RankManagementTest extends TestCase
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

    public function test_index_lists_managed_ranks(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/ranks', ['label' => 'طيار', 'category' => 'military', 'tier' => 'upper'])->assertCreated();
        $res = $this->getJson('/api/ranks')->assertOk();
        $this->assertTrue($res->json('canManage'));
        $this->assertContains('طيار', collect($res->json('ranks'))->pluck('label')->all());
    }

    public function test_add_rank_requires_settings_manage(): void
    {
        $this->actingAsRole('EVALUATOR', 'ED');
        $this->postJson('/api/ranks', ['label' => 'طيار', 'category' => 'military', 'tier' => 'upper'])
            ->assertStatus(403);
    }

    public function test_add_and_duplicate_rank(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/ranks', ['label' => 'طيار', 'category' => 'military', 'tier' => 'upper'])->assertCreated();
        $this->postJson('/api/ranks', ['label' => 'طيار', 'category' => 'military', 'tier' => 'middle'])->assertStatus(422);
    }

    public function test_managed_rank_drives_tier_classification(): void
    {
        // مطابقة مباشرة من البذور
        $this->assertSame('upper', Candidate::classifyTier('عميد ركن', true));
        $this->assertSame('middle', Candidate::classifyTier('نقيب', true));
        // رتبة جديدة تُغيّر التصنيف فوراً
        Rank::create(['label' => 'طيار', 'category' => 'military', 'tier' => 'upper', 'sort_order' => 0, 'is_active' => true]);
        $this->assertSame('upper', Candidate::classifyTier('طيار أول', true));
    }

    public function test_fallback_when_not_managed(): void
    {
        // عسكري غير مُدرَج ولا يطابق عليا → وسطى (المنطق القديم)
        $this->assertSame('middle', Candidate::classifyTier('جندي', true));
        // مدني بدرجة عالية → عليا عبر عتبة الدرجة (م-14 ≥ 13)
        $this->assertSame('upper', Candidate::classifyTier('م-14', false));
    }

    public function test_update_and_delete_rank(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/ranks', ['label' => 'نقيب', 'category' => 'military', 'tier' => 'middle'])
            ->assertCreated()->json('rankId');
        $this->putJson("/api/ranks/{$id}", ['label' => 'نقيب', 'category' => 'military', 'tier' => 'upper'])->assertOk();
        $this->assertSame('upper', Rank::find($id)->tier);
        $this->deleteJson("/api/ranks/{$id}")->assertOk();
        $this->assertDatabaseMissing('ranks', ['id' => $id]);
    }
}
