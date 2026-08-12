<?php

namespace Tests\Feature;

use App\Models\CandidateCv;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// مراجعة السيرة قبل الجدولة + قائمة مستشاري المقابلة المؤهّلين لتعيين المستشار.
class InterviewerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(string $sectorCode, bool $active = true): User
    {
        return User::create([
            'username' => 'ev_' . substr(md5(uniqid('', true)), 0, 8), 'full_name' => 'مستشار ' . $sectorCode,
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'), 'is_active' => $active, 'must_change_password' => false,
        ]);
    }

    public function test_lists_only_active_evaluators_of_the_candidate_sector(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $edActive = $this->evaluator('ED');
        $this->evaluator('ED', false);  // معطّل
        $this->evaluator('HI');         // قطاع آخر

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson("/api/candidates/{$c->id}/interviewers")->assertOk();
        $ids = collect($res->json('interviewers'))->pluck('id')->all();
        $this->assertSame([$edActive->id], $ids, 'فقط مقيّم القطاع الفعّال');
        $this->assertFalse($res->json('hasCv'));
    }

    public function test_reports_cv_presence_for_review(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        CandidateCv::create(['candidate_id' => $c->id, 'data' => CandidateCv::emptyDoc(), 'version' => 1, 'source' => 'portal']);
        $this->actingAsRole('SCHEDULER');
        $this->getJson("/api/candidates/{$c->id}/interviewers")->assertOk()->assertJsonPath('hasCv', true);
    }

    public function test_requires_schedule_manage(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $this->actingAsRole('EVALUATOR', 'ED'); // لا SCHEDULE_MANAGE
        $this->getJson("/api/candidates/{$c->id}/interviewers")->assertStatus(403);
    }

    public function test_out_of_scope_candidate_is_404(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'classification' => 'secret']);
        $this->actingAsRole('SCHEDULER'); // لا يرى المصنّفين → 404 لا 403
        $this->getJson("/api/candidates/{$c->id}/interviewers")->assertStatus(404);
    }

    public function test_scheduling_interview_assigns_the_interviewer(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $ev = $this->evaluator('ED');
        $this->actingAsRole('SCHEDULER');

        $res = $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => now()->addDays(2)->toDateString(), 'time' => '10:00', 'evaluatorId' => $ev->id,
        ])->assertCreated();

        $this->assertDatabaseHas('schedules', ['candidate_id' => $c->id, 'evaluator_id' => $ev->id, 'activity' => 'interview']);
    }
}
