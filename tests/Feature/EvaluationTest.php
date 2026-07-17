<?php

namespace Tests\Feature;

use App\Models\Competency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function linkCompetencies(string $activity, int $count = 2): array
    {
        $ids = Competency::orderBy('id')->limit($count)->pluck('id')->all();
        foreach ($ids as $cid) {
            DB::table('activity_competency')->insert([
                'activity' => $activity, 'competency_id' => $cid, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $ids;
    }

    public function test_start_is_404_for_classified_candidate(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'classification' => 'secret']);
        $this->actingAsRole('EVALUATOR');

        $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->assertStatus(404);
    }

    public function test_save_scores_requires_session_ownership(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->linkCompetencies('interview');
        $author = $this->actingAsRole('EVALUATOR');
        $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->assertCreated();
        $evalId = \App\Models\Evaluation::latest('id')->value('id');

        // مقيّم آخر لا يكتب في جلسة غيره — 404 موحّد (لا عرّاف وجود بالمعرّف) لا 403
        $this->actingAsRole('EVALUATOR');
        $this->postJson("/api/evaluations/{$evalId}/scores", [
            'scores' => [['competencyId' => Competency::first()->id, 'score' => 3]],
        ])->assertStatus(404);
    }

    public function test_save_scores_rejects_duplicate_competency(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $ids = $this->linkCompetencies('interview');
        $this->actingAsRole('EVALUATOR');
        $evalId = $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->json('id') ?? \App\Models\Evaluation::latest('id')->value('id');

        $this->postJson("/api/evaluations/{$evalId}/scores", [
            'scores' => [
                ['competencyId' => $ids[0], 'score' => 3],
                ['competencyId' => $ids[0], 'score' => 4], // duplicate competency
            ],
        ])->assertStatus(422);
    }

    public function test_show_is_scoped_to_own_evaluation_or_reviewer(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->linkCompetencies('interview');
        $author = $this->actingAsRole('EVALUATOR');
        $evalId = $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->json('id') ?? \App\Models\Evaluation::latest('id')->value('id');

        // another evaluator: not owner -> 404
        $this->actingAsRole('EVALUATOR');
        $this->getJson("/api/evaluations/{$evalId}")->assertStatus(404);

        // the author sees it
        Sanctum::actingAs($author);
        $this->getJson("/api/evaluations/{$evalId}")->assertOk();

        // a reviewer (EVALUATION_APPROVE) sees any
        $this->actingAsRole('ASSESS_MANAGER');
        $this->getJson("/api/evaluations/{$evalId}")->assertOk();
    }

    public function test_submit_requires_all_activity_competencies_scored(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $ids = $this->linkCompetencies('interview', 2);
        $this->actingAsRole('EVALUATOR');
        $evalId = $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->json('id') ?? \App\Models\Evaluation::latest('id')->value('id');

        // score only 1 of 2 -> incomplete
        $this->postJson("/api/evaluations/{$evalId}/scores", [
            'scores' => [['competencyId' => $ids[0], 'score' => 3]],
        ])->assertOk();
        $this->postJson("/api/evaluations/{$evalId}/submit")->assertStatus(422);

        // score both -> submit succeeds
        $this->postJson("/api/evaluations/{$evalId}/scores", [
            'scores' => [
                ['competencyId' => $ids[0], 'score' => 3],
                ['competencyId' => $ids[1], 'score' => 4],
            ],
        ])->assertOk();
        $this->postJson("/api/evaluations/{$evalId}/submit")->assertOk();
    }
}
