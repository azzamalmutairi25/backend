<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// إطار الكفاءات المرجعي: الأوزان والمستويات المطلوبة + تحليل الفجوة مقابل ملف الوظيفة
class CompetencyFrameworkTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function comp(string $type, int $max, ?int $tUpper = null, int $sort = 1): Competency
    {
        return Competency::create([
            'name_ar' => "كفاءة {$sort}", 'type' => $type, 'max_level' => $max,
            'weight' => 1, 'target_upper' => $tUpper, 'sort_order' => $sort,
        ]);
    }

    public function test_framework_requires_competency_view(): void
    {
        $this->actingAsRole('EXTERNAL_ADD'); // لا COMPETENCY_VIEW
        $this->getJson('/api/competencies/framework')->assertStatus(403);
    }

    public function test_update_requires_competency_manage(): void
    {
        $c = $this->comp('behavioral', 5);
        $this->actingAsRole('CENTER_MANAGER'); // COMPETENCY_VIEW فقط
        $this->putJson("/api/competencies/{$c->id}", [
            'nameAr' => 'x', 'maxLevel' => 5, 'weight' => 1,
        ])->assertStatus(403);
    }

    public function test_manager_sets_targets_and_weight(): void
    {
        $c = $this->comp('leadership', 5);
        $this->actingAsRole('DEV_MANAGER'); // COMPETENCY_MANAGE

        $this->putJson("/api/competencies/{$c->id}", [
            'nameAr' => 'التفكير الاستراتيجي', 'maxLevel' => 5, 'weight' => 2.5,
            'targetUpper' => 4, 'targetMiddle' => 3,
        ])->assertOk();

        $c->refresh();
        $this->assertEquals(2.5, $c->weight);
        $this->assertSame(4, $c->target_upper);
        $this->assertSame(3, $c->target_middle);

        $fw = $this->getJson('/api/competencies/framework')->assertOk()->json('competencies');
        $mine = collect($fw)->firstWhere('id', $c->id);
        $this->assertEquals(4, $mine['targetUpper']);
    }

    public function test_target_above_max_level_is_rejected(): void
    {
        $c = $this->comp('technical', 5);
        $this->actingAsRole('DEV_MANAGER');
        $this->putJson("/api/competencies/{$c->id}", [
            'nameAr' => 'x', 'maxLevel' => 5, 'weight' => 1, 'targetUpper' => 9,
        ])->assertStatus(422);
    }

    public function test_competency_gap_compares_achieved_to_target(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed', 'tier' => 'upper']);
        $c1 = $this->comp('behavioral', 5, 4, 1);  // مطلوب 4
        $c2 = $this->comp('leadership', 5, 3, 2);   // مطلوب 3
        $e = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $evaluator->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $c1->id, 'score' => 3]); // فجوة -1
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $c2->id, 'score' => 5]); // محقّق

        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_VIEW
        $res = $this->getJson("/api/reports/competency-gap?candidateId={$c->id}")->assertOk();

        $this->assertSame('upper', $res->json('tier'));
        $this->assertEquals(2, $res->json('total'));
        $this->assertEquals(1, $res->json('met'));   // واحدة فقط محقّقة
        $items = collect($res->json('items'));
        $gap1 = $items->firstWhere('competency', 'كفاءة 1');
        $this->assertEquals(4, $gap1['target']);
        $this->assertEquals(3, $gap1['achieved']);
        $this->assertEquals(-1, $gap1['gap']);
        $this->assertFalse($gap1['met']);
    }
}
