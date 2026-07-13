<?php

namespace Tests\Feature;

use App\Models\DevelopmentPlanItem;
use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// خطة التطوير الفردية: إضافة/متابعة/حذف البنود + توليدها من مجالات التطوير في التقرير
class DevelopmentPlanTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_view_requires_report_view(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed']);
        $this->actingAsRole('EXTERNAL_ADD'); // لا REPORT_VIEW
        $this->getJson("/api/development-plans/{$c->id}")->assertStatus(403);
    }

    public function test_create_requires_report_create(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed']);
        $this->actingAsRole('CENTER_MANAGER'); // REPORT_VIEW فقط
        $this->postJson('/api/development-plans', ['candidateId' => $c->id, 'area' => 'التفويض'])
            ->assertStatus(403);
    }

    public function test_create_then_list_roundtrip(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_VIEW + REPORT_CREATE

        $this->postJson('/api/development-plans', [
            'candidateId' => $c->id, 'area' => 'إدارة الوقت', 'action' => 'ورشة تدريبية', 'targetDate' => '2026-09-01',
        ])->assertCreated();

        $items = $this->getJson("/api/development-plans/{$c->id}")->assertOk()->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('إدارة الوقت', $items[0]['area']);
        $this->assertSame('pending', $items[0]['status']);
    }

    public function test_update_to_done_sets_completed_at(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $this->actingAsRole('ASSESS_MANAGER');
        $id = $this->postJson('/api/development-plans', ['candidateId' => $c->id, 'area' => 'التفويض'])
            ->assertCreated()->json('item.id');

        $this->putJson("/api/development-plan-items/{$id}", ['status' => 'done'])->assertOk();
        $item = DevelopmentPlanItem::find($id);
        $this->assertSame('done', $item->status);
        $this->assertNotNull($item->completed_at);

        // العودة لغير مكتمل تمسح تاريخ الإكمال
        $this->putJson("/api/development-plan-items/{$id}", ['status' => 'in_progress'])->assertOk();
        $this->assertNull(DevelopmentPlanItem::find($id)->completed_at);
    }

    public function test_seed_from_report_development_areas_no_duplicates(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'development_areas' => ['التفويض', 'إدارة الوقت'], 'status' => 'approved', 'created_by' => null,
        ]);
        $this->actingAsRole('ASSESS_MANAGER');

        $this->postJson('/api/development-plans/seed', ['candidateId' => $c->id])
            ->assertOk()->assertJsonPath('created', 2);
        $this->assertSame(2, DevelopmentPlanItem::where('candidate_id', $c->id)->count());

        // إعادة التوليد لا تُكرّر
        $this->postJson('/api/development-plans/seed', ['candidateId' => $c->id])
            ->assertOk()->assertJsonPath('created', 0);
        $this->assertSame(2, DevelopmentPlanItem::where('candidate_id', $c->id)->count());
    }

    public function test_seed_without_report_areas_is_422(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $this->actingAsRole('ASSESS_MANAGER');
        $this->postJson('/api/development-plans/seed', ['candidateId' => $c->id])->assertStatus(422);
    }

    public function test_classified_candidate_is_404_without_clearance(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'classification' => 'secret']);
        $this->actingAsRole('CENTER_MANAGER'); // REPORT_VIEW لكن لا VIEW_CLASSIFIED
        $this->getJson("/api/development-plans/{$c->id}")->assertStatus(404);
    }

    public function test_delete_removes_item(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $this->actingAsRole('ASSESS_MANAGER');
        $id = $this->postJson('/api/development-plans', ['candidateId' => $c->id, 'area' => 'التفويض'])
            ->assertCreated()->json('item.id');

        $this->deleteJson("/api/development-plan-items/{$id}")->assertOk();
        $this->assertNull(DevelopmentPlanItem::find($id));
    }

    public function test_seed_deduplicates_repeated_areas_in_one_run(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'development_areas' => ['القيادة', 'القيادة'], 'status' => 'approved', 'created_by' => null,
        ]);
        $this->actingAsRole('ASSESS_MANAGER');

        $this->postJson('/api/development-plans/seed', ['candidateId' => $c->id])
            ->assertOk()->assertJsonPath('created', 1); // المجال المتكرّر لا يُنتِج بندين
        $this->assertSame(1, DevelopmentPlanItem::where('candidate_id', $c->id)->count());
    }
}
