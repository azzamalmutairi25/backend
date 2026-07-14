<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// سلسلة اعتماد التقرير:
//   مسودة → المقيّم → مدير التقييم → تطوير الكفاءات → معتمد
class ReportApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function reportAt(string $status): FinalReport
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        return FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => $status, 'created_by' => null,
        ]);
    }

    // ── من يكتب ──

    public function test_assistant_and_manager_can_author_reports(): void
    {
        foreach (['ASSISTANT', 'ASSESS_MANAGER'] as $role) {
            [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
            $this->actingAsRole($role);
            $this->postJson('/api/reports', ['candidateId' => $c->id, 'recommendation' => 'يوصى به'])
                ->assertCreated();
        }
    }

    public function test_evaluator_can_no_longer_author_reports(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $this->actingAsRole('EVALUATOR');
        // من يعتمد لا يكتب
        $this->postJson('/api/reports', ['candidateId' => $c->id, 'recommendation' => 'يوصى به'])
            ->assertStatus(403);
    }

    // ── تسلسل المراحل ──

    public function test_evaluator_approval_advances_to_the_manager_stage(): void
    {
        $r = $this->reportAt('pending_evaluator');
        $this->actingAsRole('EVALUATOR');

        $this->postJson("/api/reports/{$r->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'pending_manager')
            ->assertJsonPath('skippedEvaluator', false);
    }

    public function test_manager_approval_advances_to_the_dev_stage(): void
    {
        $r = $this->reportAt('pending_manager');
        $this->actingAsRole('ASSESS_MANAGER');

        $this->postJson("/api/reports/{$r->id}/approve")
            ->assertOk()->assertJsonPath('status', 'pending_dev_approval');
    }

    public function test_dev_manager_gives_the_final_approval_and_completes_the_candidate(): void
    {
        $r = $this->reportAt('pending_dev_approval');
        $this->actingAsRole('DEV_MANAGER');

        $this->postJson("/api/reports/{$r->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame('completed', $r->candidate->fresh()->status, 'نهاية السلسلة تُكمل المرشح');
    }

    public function test_candidate_is_not_completed_before_the_chain_ends(): void
    {
        $r = $this->reportAt('pending_evaluator');
        $this->actingAsRole('EVALUATOR');
        $this->postJson("/api/reports/{$r->id}/approve")->assertOk();

        $this->assertSame('assessed', $r->candidate->fresh()->status);
    }

    // ── كل دور يعتمد مرحلته وحدها ──

    public function test_evaluator_cannot_approve_the_manager_stage(): void
    {
        $r = $this->reportAt('pending_manager');
        $this->actingAsRole('EVALUATOR');
        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
    }

    public function test_evaluator_cannot_approve_the_final_stage(): void
    {
        $r = $this->reportAt('pending_dev_approval');
        $this->actingAsRole('EVALUATOR');
        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
    }

    public function test_dev_manager_cannot_approve_the_evaluator_stage(): void
    {
        $r = $this->reportAt('pending_evaluator');
        $this->actingAsRole('DEV_MANAGER');
        // لا يقفز للأمام: الاعتماد النهائي لا يُغني عن المراحل قبله
        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
    }

    public function test_manager_cannot_approve_the_final_stage(): void
    {
        $r = $this->reportAt('pending_dev_approval');
        $this->actingAsRole('ASSESS_MANAGER');
        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
    }

    // ── تجاوز المدير للمقيّم ──

    public function test_manager_may_approve_directly_skipping_the_evaluator(): void
    {
        $r = $this->reportAt('pending_evaluator');
        $u = $this->actingAsRole('ASSESS_MANAGER');

        $this->postJson("/api/reports/{$r->id}/approve")
            ->assertOk()
            // يقفز إلى ما بعد مرحلة المدير لا إليها — وإلا اعتمد مرحلته مرتين
            ->assertJsonPath('status', 'pending_dev_approval')
            ->assertJsonPath('skippedEvaluator', true);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'APPROVE_REPORT_SKIPPED_EVALUATOR',
            'user_id' => $u->id,
            'entity_id' => (string) $r->id,
        ]);
    }

    public function test_normal_approval_is_not_logged_as_a_skip(): void
    {
        $r = $this->reportAt('pending_manager');
        $u = $this->actingAsRole('ASSESS_MANAGER');
        $this->postJson("/api/reports/{$r->id}/approve")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'APPROVE_REPORT', 'user_id' => $u->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'APPROVE_REPORT_SKIPPED_EVALUATOR']);
    }

    // ── حرّاس الحالة ──

    public function test_draft_and_approved_reports_cannot_be_approved(): void
    {
        foreach (['draft', 'approved', 'returned'] as $status) {
            $r = $this->reportAt($status);
            $this->actingAsRole('ADMIN');
            $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(422);
        }
    }

    // ── الإرجاع من أي مرحلة ──

    public function test_any_stage_can_return_the_report(): void
    {
        foreach ([
            'pending_evaluator' => 'EVALUATOR',
            'pending_manager' => 'ASSESS_MANAGER',
            'pending_dev_approval' => 'DEV_MANAGER',
        ] as $status => $role) {
            $r = $this->reportAt($status);
            $this->actingAsRole($role);
            $this->postJson("/api/reports/{$r->id}/return", ['reason' => 'يحتاج مراجعة الأرقام'])
                ->assertOk();
            $this->assertSame('returned', $r->fresh()->status, "الإرجاع من {$status}");
        }
    }

    public function test_resubmit_re_enters_at_the_first_stage(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $author = $this->actingAsRole('ASSISTANT');
        $r = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'يوصى به',
            'status' => 'returned', 'created_by' => $author->id,
        ]);

        $this->postJson("/api/reports/{$r->id}/resubmit")->assertOk();
        // يعود لأول السلسلة لا لآخرها: التعديل يستحق مراجعة المراحل كلها
        $this->assertSame('pending_evaluator', $r->fresh()->status);
    }

    // ── الإشعارات ──

    public function test_each_stage_notifies_the_role_that_owns_the_next_one(): void
    {
        $r = $this->reportAt('pending_evaluator');
        $manager = $this->actingAsRole('ASSESS_MANAGER');
        $this->actingAsRole('EVALUATOR');

        $this->postJson("/api/reports/{$r->id}/approve")->assertOk();

        $this->assertSame(1, Notification::where('recipient_id', $manager->id)
            ->where('type', 'approval')->count(), 'المرحلة التالية تُبلَّغ');
    }

    public function test_final_approval_notifies_the_author(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $author = $this->actingAsRole('ASSISTANT');
        $r = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'يوصى به',
            'status' => 'pending_dev_approval', 'created_by' => $author->id,
        ]);

        $this->actingAsRole('DEV_MANAGER');
        $this->postJson("/api/reports/{$r->id}/approve")->assertOk();

        // نهاية السلسلة: لا مرحلة تالية تُبلَّغ — يُبلَّغ من كتب
        $this->assertSame(1, Notification::where('recipient_id', $author->id)
            ->where('type', 'report')->count());
    }

    // ── الإحصاءات ──

    public function test_pending_counts_the_whole_chain_not_just_the_last_stage(): void
    {
        foreach (['pending_evaluator', 'pending_manager', 'pending_dev_approval'] as $s) {
            $this->reportAt($s);
        }
        $this->reportAt('draft');
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/reports/stats')->assertOk();
        $this->assertSame(3, $res->json('stats.pending'), 'المؤشّر يعدّ السلسلة كاملة');
        $this->assertSame(1, $res->json('stats.pendingEvaluator'));
        $this->assertSame(1, $res->json('stats.pendingManager'));
        $this->assertSame(1, $res->json('stats.pendingDev'));
    }

    public function test_pending_filter_returns_the_whole_chain(): void
    {
        foreach (['pending_evaluator', 'pending_manager', 'pending_dev_approval'] as $s) {
            $this->reportAt($s);
        }
        $this->reportAt('approved');
        $this->actingAsRole('ADMIN');

        // الرقم في اللوحة يفتح قائمة بحجمه — لا أصغر منه
        $this->assertCount(3, $this->getJson('/api/reports?status=pending')->assertOk()->json('reports'));
        $this->assertCount(1, $this->getJson('/api/reports?status=pending_manager')->assertOk()->json('reports'));
    }

    // ── بوابة التصنيف ──

    public function test_classified_report_cannot_be_approved_without_clearance(): void
    {
        [$c, $a] = $this->makeCandidate([
            'status' => 'assessed', 'assessmentStatus' => 'assessed', 'classification' => 'secret',
        ]);
        $r = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'يوصى به',
            'status' => 'pending_evaluator', 'created_by' => null,
        ]);

        $this->actingAsRole('EVALUATOR'); // لا يملك view_classified
        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
        $this->assertSame('pending_evaluator', $r->fresh()->status);
    }
}
