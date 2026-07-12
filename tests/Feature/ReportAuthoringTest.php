<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_create_report_requires_assessed_candidate(): void
    {
        [$c] = $this->makeCandidate(['status' => 'draft', 'assessmentStatus' => 'draft']);
        $this->actingAsRole('ASSESS_MANAGER');

        $this->postJson('/api/reports', ['candidateId' => $c->id, 'recommendation' => 'يوصى به'])
            ->assertStatus(422);
    }

    public function test_create_report_for_assessed_candidate_succeeds_and_is_unique(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $this->actingAsRole('ASSESS_MANAGER');

        $this->postJson('/api/reports', [
            'candidateId' => $c->id, 'recommendation' => 'يوصى به', 'behavioralFit' => 80, 'technicalFit' => 75,
        ])->assertCreated();

        // duplicate for the same assessment -> 422
        $this->postJson('/api/reports', ['candidateId' => $c->id, 'recommendation' => 'يوصى به'])
            ->assertStatus(422);
    }

    public function test_only_author_or_manager_can_edit_a_report(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $author = $this->actingAsRole('EVALUATOR');
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'يوصى به',
            'status' => 'draft', 'created_by' => $author->id,
        ]);

        // a DIFFERENT evaluator cannot edit
        $this->actingAsRole('EVALUATOR');
        $this->putJson("/api/reports/{$report->id}", ['recommendation' => 'محاولة'])->assertStatus(403);

        // the author can
        \Laravel\Sanctum\Sanctum::actingAs($author);
        $this->putJson("/api/reports/{$report->id}", ['recommendation' => 'يوصى به بقوة'])->assertOk();

        // an ASSESS_MANAGER (report.edit_any) can edit anyone's
        $this->actingAsRole('ASSESS_MANAGER');
        $this->putJson("/api/reports/{$report->id}", ['recommendation' => 'يوصى به'])->assertOk();
    }

    public function test_classified_candidate_report_create_is_404_for_uncleared_author(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed', 'classification' => 'secret']);
        $this->actingAsRole('EVALUATOR'); // report.create, no view_classified

        $this->postJson('/api/reports', ['candidateId' => $c->id, 'recommendation' => 'يوصى به'])
            ->assertStatus(404);
    }

    public function test_submit_moves_report_to_pending_approval(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $this->actingAsRole('ASSESS_MANAGER');

        $res = $this->postJson('/api/reports', [
            'candidateId' => $c->id, 'recommendation' => 'يوصى به', 'submit' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('final_reports', [
            'id' => $res->json('id'), 'status' => 'pending_dev_approval',
        ]);
    }
}
