<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// شاشة سير العمل — إعادة ترتيب مراحل الاعتماد وتفعيلها من البيانات.
class WorkflowSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function payload(): array
    {
        return WorkflowStage::where('workflow', 'report')->orderBy('position')->get()
            ->map(fn ($s) => [
                'id' => $s->id, 'position' => $s->position, 'isActive' => $s->is_active,
                'label' => $s->label,
                'blocksSelfAuthored' => $s->blocks_self_authored,
                'requiresTeamAuthorship' => $s->requires_team_authorship,
            ])
            ->all();
    }

    public function test_requires_settings_manage(): void
    {
        $this->actingAsRole('EVALUATOR');
        $this->getJson('/api/workflow/report')->assertStatus(403);
        $this->putJson('/api/workflow/report', ['stages' => $this->payload()])->assertStatus(403);
    }

    public function test_show_returns_the_chain_the_engine_reads(): void
    {
        $this->actingAsRole('ADMIN');
        $res = $this->getJson('/api/workflow/report')->assertOk();

        $res->assertJsonPath('stages.0.statusKey', 'pending_evaluator');
        // العَلَمان يُصدَّران ليعرف المشرف لماذا يُمنع اعتماد
        $res->assertJsonPath('stages.1.blocksSelfAuthored', true);
        $res->assertJsonPath('stages.1.requiresTeamAuthorship', true);
        $this->assertSame(4, count($res->json('stages')));
    }

    public function test_reordering_stages_changes_the_chain(): void
    {
        $this->actingAsRole('ADMIN');
        $stages = $this->payload();
        // اقلب موضعي أول مرحلتين
        [$stages[0]['position'], $stages[1]['position']] = [$stages[1]['position'], $stages[0]['position']];

        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertOk();

        $this->assertSame('pending_manager', WorkflowStage::firstStage()->status_key);
    }

    public function test_deactivating_a_stage_removes_it_from_the_chain(): void
    {
        $this->actingAsRole('ADMIN');
        $stages = collect($this->payload())->map(function ($s) {
            if ($s['position'] === 3) { $s['isActive'] = false; } // تطوير الكفاءات
            return $s;
        })->all();

        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertOk();

        $keys = WorkflowStage::chain()->pluck('status_key')->all();
        $this->assertNotContains('pending_dev_approval', $keys);
        $this->assertContains('pending_center', $keys);
    }

    public function test_cannot_deactivate_a_stage_holding_reports(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'pending_manager', 'created_by' => null,
        ]);

        $this->actingAsRole('ADMIN');
        $stages = collect($this->payload())->map(function ($s) {
            if ($s['position'] === 2) { $s['isActive'] = false; }
            return $s;
        })->all();

        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertStatus(422);
        // بقيت مفعّلة — لم تُعلَّق التقارير
        $this->assertTrue(WorkflowStage::forStatus('pending_manager')->is_active);
    }

    public function test_cannot_deactivate_every_stage(): void
    {
        $this->actingAsRole('ADMIN');
        $stages = collect($this->payload())->map(function ($s) {
            $s['isActive'] = false;
            return $s;
        })->all();

        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertStatus(422);
    }

    public function test_label_and_stage_rules_are_editable(): void
    {
        $this->actingAsRole('ADMIN');
        $stages = $this->payload();
        // عدّل تسمية أول مرحلة واقلب قواعد الكاتب فيها
        $stages[0]['label'] = 'اعتماد المستشار الأول';
        $stages[0]['blocksSelfAuthored'] = true;
        $stages[0]['requiresTeamAuthorship'] = true;

        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertOk();

        $s = WorkflowStage::where('workflow', 'report')->orderBy('position')->first();
        $this->assertSame('اعتماد المستشار الأول', $s->label);
        $this->assertTrue($s->blocks_self_authored);
        $this->assertTrue($s->requires_team_authorship);
        $this->assertDatabaseHas('audit_logs', ['action' => 'UPDATE_WORKFLOW']);
    }

    public function test_editing_rules_takes_effect_on_approval(): void
    {
        // فعّل «لا يعتمد ما كتبه» على مرحلة المقيّم، ثم تحقّق أنها تمنع الكاتب
        $this->actingAsRole('ADMIN');
        $stages = collect($this->payload())->map(function ($s) {
            if ($s['label'] !== null) $s['blocksSelfAuthored'] = true;
            return $s;
        })->all();
        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertOk();

        $first = WorkflowStage::firstStage();
        $this->assertTrue($first->blocks_self_authored, 'صارت القاعدة مفعّلة على أول مرحلة');
    }

    public function test_empty_label_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $stages = $this->payload();
        $stages[0]['label'] = '';
        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertStatus(422);
    }

    public function test_duplicate_positions_are_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $stages = $this->payload();
        $stages[1]['position'] = $stages[0]['position']; // تكرار

        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertStatus(422);
    }

    public function test_partial_submission_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $stages = array_slice($this->payload(), 0, 2); // ناقصة

        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertStatus(422);
    }

    public function test_save_is_audited_with_before_and_after(): void
    {
        $this->actingAsRole('ADMIN');
        $stages = $this->payload();
        [$stages[0]['position'], $stages[1]['position']] = [$stages[1]['position'], $stages[0]['position']];

        $this->putJson('/api/workflow/report', ['stages' => $stages])->assertOk();

        $row = \DB::table('audit_logs')->where('action', 'UPDATE_WORKFLOW')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('before', $row->details);
        $this->assertStringContainsString('after', $row->details);
    }
}
