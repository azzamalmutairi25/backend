<?php

namespace Tests\Feature;

use App\Models\Competency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// إضافة كفاءات جديدة + تجميع سلوكي (group) ومجال فنّي (domain).
class CompetencyCreateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_manager_creates_a_behavioral_competency_with_group(): void
    {
        $this->actingAsRole('DEV_MANAGER'); // COMPETENCY_MANAGE
        $res = $this->postJson('/api/competencies', [
            'nameAr' => 'الحسّ القيادي', 'type' => 'behavioral', 'group' => 'الإحساس',
            'maxLevel' => 5, 'weight' => 1.5, 'targetUpper' => 4, 'targetMiddle' => 3,
        ])->assertCreated();

        $c = Competency::find($res->json('id'));
        $this->assertSame('الإحساس', $c->group);
        $this->assertSame('behavioral', $c->type);
        $this->assertGreaterThan(0, $c->sort_order);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE_COMPETENCY']);
    }

    public function test_manager_creates_a_technical_competency_with_domain(): void
    {
        $this->actingAsRole('DEV_MANAGER');
        $res = $this->postJson('/api/competencies', [
            'nameAr' => 'التحليل المالي', 'type' => 'technical', 'domain' => 'المالية',
            'maxLevel' => 5, 'weight' => 1,
        ])->assertCreated();
        $this->assertSame('المالية', Competency::find($res->json('id'))->domain);
    }

    public function test_create_requires_competency_manage(): void
    {
        $this->actingAsRole('SCHEDULER'); // لا COMPETENCY_MANAGE
        $this->postJson('/api/competencies', ['nameAr' => 'x', 'type' => 'behavioral', 'maxLevel' => 5, 'weight' => 1])
            ->assertStatus(403);
    }

    public function test_create_rejects_bad_type(): void
    {
        $this->actingAsRole('DEV_MANAGER');
        $this->postJson('/api/competencies', ['nameAr' => 'x', 'type' => 'nonsense', 'maxLevel' => 5, 'weight' => 1])
            ->assertStatus(422);
    }

    public function test_framework_exposes_group_and_domain(): void
    {
        $c = Competency::create(['name_ar' => 'كفاءة', 'type' => 'technical', 'domain' => 'التخطيط', 'max_level' => 5, 'weight' => 1, 'sort_order' => 99]);
        $this->actingAsRole('DEV_MANAGER');
        $rows = collect($this->getJson('/api/competencies/framework')->assertOk()->json('competencies'))->keyBy('id');
        $this->assertSame('التخطيط', $rows[$c->id]['domain']);
    }

    public function test_update_can_edit_group_and_domain(): void
    {
        $c = Competency::create(['name_ar' => 'كفاءة', 'type' => 'behavioral', 'max_level' => 5, 'weight' => 1, 'sort_order' => 50]);
        $this->actingAsRole('DEV_MANAGER');
        $this->putJson("/api/competencies/{$c->id}", [
            'nameAr' => 'كفاءة', 'group' => 'التميز', 'maxLevel' => 5, 'weight' => 1,
        ])->assertOk();
        $this->assertSame('التميز', $c->fresh()->group);
    }
}
