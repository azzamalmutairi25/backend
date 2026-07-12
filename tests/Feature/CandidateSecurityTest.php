<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_classified_candidate_reads_as_404_for_uncleared_viewer(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'secret']);
        $this->actingAsRole('SCHEDULER'); // CANDIDATE_VIEW but not VIEW_CLASSIFIED

        $this->getJson("/api/candidates/{$c->id}")->assertStatus(404);
    }

    public function test_normal_candidate_is_visible_to_viewer(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'normal']);
        $this->actingAsRole('SCHEDULER');

        $this->getJson("/api/candidates/{$c->id}")->assertOk();
    }

    public function test_approve_on_classified_candidate_is_404_for_uncleared_approver(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'top_secret']);
        $this->actingAsRole('SCHEDULER'); // has CANDIDATE_APPROVE, no clearance

        $this->postJson("/api/candidates/{$c->id}/approve")->assertStatus(404);
        $this->assertDatabaseHas('candidates', ['id' => $c->id, 'status' => 'draft']); // unchanged
    }

    public function test_store_dedup_refuses_to_touch_a_classified_record(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'secret', 'status' => 'completed']);
        $nid = $c->national_id; // the raw id
        $this->actingAsRole('EXTERNAL_ADD'); // CANDIDATE_CREATE only, no view/clearance

        $res = $this->postJson('/api/candidates', [
            'nationalId' => $nid, 'fullName' => 'مهاجم', 'sectorId' => \App\Models\Sector::first()->id,
            'rankLabel' => 'مدير عام',
        ]);
        $res->assertStatus(422);
        // the classified record's name must NOT have been overwritten
        $this->assertNotEquals('مهاجم', $c->fresh()->full_name);
    }

    public function test_viewer_without_permission_is_forbidden(): void
    {
        $this->makeCandidate();
        $this->actingAsRole('MEASURE_SUPER'); // has CANDIDATE_VIEW actually; use a role without it

        // EXTERNAL_ADD has no CANDIDATE_VIEW
        $this->actingAsRole('EXTERNAL_ADD');
        $this->getJson('/api/candidates')->assertStatus(403);
    }
}
