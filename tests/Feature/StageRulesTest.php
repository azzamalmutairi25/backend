<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// قواعد المرحلة على الكاتب + الإرجاع والإلغاء لمدير المركز وحده.
//
// القاعدتان إعدادان على workflow_stages لا شرطان محفوران — تُبدَّلان من الشاشة.
class StageRulesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function reportBy(?User $author, string $status = 'pending_manager'): FinalReport
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        return FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => $status, 'created_by' => $author?->id,
        ]);
    }

    // ══════ «من يكتب لا يعتمد» ══════

    public function test_manager_cannot_approve_a_report_they_wrote(): void
    {
        $mgr = $this->actingAsRole('ASSESS_MANAGER');
        $r = $this->reportBy($mgr);

        $res = $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
        $this->assertStringContainsString('كتبته بنفسك', $res->json('error'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'DENIED_APPROVE_STAGE_RULE']);
    }

    public function test_manager_approves_a_report_written_by_their_assistant(): void
    {
        $mgr = $this->actingAsRole('ASSESS_MANAGER');
        $assistant = $this->actingAsRole('ASSISTANT', 'ED', $mgr);
        $r = $this->reportBy($assistant);

        \Laravel\Sanctum\Sanctum::actingAs($mgr);
        $this->postJson("/api/reports/{$r->id}/approve")
            ->assertOk()->assertJsonPath('status', 'pending_dev_approval');
    }

    public function test_manager_cannot_approve_a_report_by_someone_elses_assistant(): void
    {
        $otherMgr = $this->actingAsRole('ASSESS_MANAGER');
        $theirAssistant = $this->actingAsRole('ASSISTANT', 'ED', $otherMgr);
        $r = $this->reportBy($theirAssistant);

        $mgr = $this->actingAsRole('ASSESS_MANAGER'); // مدير آخر
        $res = $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
        $this->assertStringContainsString('ليس ضمن فريقك', $res->json('error'));
    }

    public function test_a_report_with_no_author_cannot_pass_the_team_rule(): void
    {
        $this->actingAsRole('ASSESS_MANAGER');
        $r = $this->reportBy(null);

        // لا كاتب ⇒ لا فريق يُنسب إليه — فشلٌ مغلق لا مفتوح
        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
    }

    // التجاوز لا يكون باباً خلفياً للقاعدة
    public function test_skipping_still_enforces_the_stage_rules(): void
    {
        $mgr = $this->actingAsRole('ASSESS_MANAGER');
        $r = $this->reportBy($mgr, 'pending_evaluator'); // كتبه هو

        // يقفز مرحلة المقيّم — لكن مرحلته هو تمنع كاتبها
        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);
        $this->assertSame('pending_evaluator', $r->fresh()->status);
    }

    // ══════ القاعدة إعداد لا شرط محفور ══════

    public function test_the_rules_are_data_and_can_be_switched_off(): void
    {
        $mgr = $this->actingAsRole('ASSESS_MANAGER');
        $r = $this->reportBy($mgr);

        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(403);

        // تُطفأ القاعدتان من البيانات — بلا لمس كود
        WorkflowStage::where('status_key', 'pending_manager')
            ->update(['blocks_self_authored' => false, 'requires_team_authorship' => false]);

        $this->postJson("/api/reports/{$r->id}/approve")->assertOk();
    }

    public function test_only_the_manager_stage_carries_the_rules_by_default(): void
    {
        foreach (WorkflowStage::chain() as $s) {
            $expected = $s->status_key === 'pending_manager';
            $this->assertSame($expected, (bool) $s->blocks_self_authored, $s->status_key);
            $this->assertSame($expected, (bool) $s->requires_team_authorship, $s->status_key);
        }
    }

    // ══════ الإرجاع لمدير المركز وحده ══════

    public function test_only_the_center_manager_may_return(): void
    {
        foreach (['ASSESS_MANAGER', 'DEV_MANAGER', 'EVALUATOR'] as $role) {
            $this->assertNotContains(Permissions::REPORT_RETURN, Permissions::forRole($role), $role);
        }
        $this->assertContains(Permissions::REPORT_RETURN, Permissions::forRole('CENTER_MANAGER'));
    }

    public function test_return_to_draft_sends_it_back_to_the_author(): void
    {
        $r = $this->reportBy(null, 'pending_dev_approval');
        $this->actingAsRole('CENTER_MANAGER');

        $this->postJson("/api/reports/{$r->id}/return", ['reason' => 'يحتاج مراجعة', 'target' => 'draft'])
            ->assertOk()->assertJsonPath('status', 'returned');
    }

    public function test_return_to_previous_steps_back_one_stage(): void
    {
        $r = $this->reportBy(null, 'pending_dev_approval');
        $this->actingAsRole('CENTER_MANAGER');

        // خطوة واحدة للوراء — لا للمسودة
        $this->postJson("/api/reports/{$r->id}/return", ['reason' => 'راجعها مع المدير', 'target' => 'previous'])
            ->assertOk()->assertJsonPath('status', 'pending_manager');
    }

    public function test_return_to_previous_at_the_first_stage_falls_back_to_draft(): void
    {
        $r = $this->reportBy(null, 'pending_evaluator'); // أول مرحلة — لا سابق لها
        $this->actingAsRole('CENTER_MANAGER');

        $this->postJson("/api/reports/{$r->id}/return", ['reason' => 'يحتاج إعادة', 'target' => 'previous'])
            ->assertOk()->assertJsonPath('status', 'returned');
    }

    public function test_return_defaults_to_draft_when_no_target_given(): void
    {
        $r = $this->reportBy(null, 'pending_center');
        $this->actingAsRole('CENTER_MANAGER');

        $this->postJson("/api/reports/{$r->id}/return", ['reason' => 'يحتاج مراجعة'])
            ->assertOk()->assertJsonPath('status', 'returned');
    }

    // ══════ الإلغاء ══════

    public function test_only_the_center_manager_may_cancel(): void
    {
        $r = $this->reportBy(null);

        foreach (['ASSESS_MANAGER', 'DEV_MANAGER', 'EVALUATOR', 'ASSISTANT'] as $role) {
            $this->actingAsRole($role, 'ED');
            $this->postJson("/api/reports/{$r->id}/cancel", ['reason' => 'محاولة إلغاء'])
                ->assertStatus(403);
        }
    }

    public function test_cancel_stops_the_report_and_reopens_the_candidate(): void
    {
        $r = $this->reportBy(null, 'pending_manager');
        $this->actingAsRole('CENTER_MANAGER');

        $this->postJson("/api/reports/{$r->id}/cancel", ['reason' => 'تغيّرت ظروف المرشّح'])
            ->assertOk()->assertJsonPath('status', 'cancelled');

        // لا يُمحى — يبقى للتدقيق
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertStringContainsString('تغيّرت ظروف', $r->fresh()->return_reason);
        // والمرشّح يعود «مُقيَّم» فيُكتب له تقرير جديد
        $this->assertSame('assessed', $r->candidate->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CANCEL_REPORT']);
    }

    public function test_an_approved_report_cannot_be_cancelled(): void
    {
        $r = $this->reportBy(null, 'approved');
        $this->actingAsRole('CENTER_MANAGER');

        // وثيقة نافذة قد تكون طُبعت ووُقّعت — سحبها إجراء لا زرّ
        $this->postJson("/api/reports/{$r->id}/cancel", ['reason' => 'محاولة'])->assertStatus(422);
    }

    public function test_a_cancelled_report_cannot_be_cancelled_twice(): void
    {
        $r = $this->reportBy(null, 'cancelled');
        $this->actingAsRole('CENTER_MANAGER');

        $this->postJson("/api/reports/{$r->id}/cancel", ['reason' => 'محاولة'])->assertStatus(422);
    }

    public function test_cancel_requires_a_reason(): void
    {
        $r = $this->reportBy(null);
        $this->actingAsRole('CENTER_MANAGER');

        $this->postJson("/api/reports/{$r->id}/cancel", [])->assertStatus(422);
        $this->postJson("/api/reports/{$r->id}/cancel", ['reason' => 'قصير'])->assertStatus(422);
    }

    // ══════ المدير إلزامي للمساعد ══════

    public function test_creating_an_assistant_without_a_manager_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/users', [
            'username' => 'as_no_mgr', 'fullName' => 'مساعد بلا مدير',
            'roleId' => Role::where('code', 'ASSISTANT')->value('id'),
            'sectorId' => Sector::value('id'),
            'password' => 'Kafaat@2026', 'userType' => 'external',
        ])->assertStatus(422)->assertJsonPath('errors.managerId.0', 'المدير مطلوب لهذا الدور — تقاريره يعتمدها مديره');
    }

    public function test_the_manager_must_actually_hold_the_manager_stage(): void
    {
        $notAManager = $this->actingAsRole('RECEPTIONIST');
        $this->actingAsRole('ADMIN');

        $this->postJson('/api/users', [
            'username' => 'as_bad_mgr', 'fullName' => 'مساعد',
            'roleId' => Role::where('code', 'ASSISTANT')->value('id'),
            'sectorId' => Sector::value('id'),
            'managerId' => $notAManager->id,
            'password' => 'Kafaat@2026', 'userType' => 'external',
        ])->assertStatus(422)->assertJsonPath('errors.managerId.0', 'المدير المختار لا يملك اعتماد تقارير فريقه');
    }

    public function test_a_manager_on_an_unmanaged_role_is_rejected(): void
    {
        $mgr = $this->actingAsRole('ASSESS_MANAGER');
        $this->actingAsRole('ADMIN');

        $this->postJson('/api/users', [
            'username' => 'ev_with_mgr', 'fullName' => 'مقيّم',
            'roleId' => Role::where('code', 'EVALUATOR')->value('id'),
            'sectorId' => Sector::value('id'),
            'managerId' => $mgr->id,
            'password' => 'Kafaat@2026', 'userType' => 'external',
        ])->assertStatus(422)->assertJsonPath('errors.managerId.0', 'هذا الدور لا يُسنَد لمدير');
    }

    public function test_a_user_cannot_be_their_own_manager(): void
    {
        $mgr = $this->actingAsRole('ASSESS_MANAGER');
        $target = $this->actingAsRole('ASSISTANT', 'ED', $mgr);
        $this->actingAsRole('ADMIN');

        // حلقة تجعل قاعدة الفريق تقبله على تقريره
        $this->putJson("/api/users/{$target->id}", [
            'fullName' => $target->full_name,
            'roleId' => $target->role_id,
            'sectorId' => $target->sector_id,
            'managerId' => $target->id,
        ])->assertStatus(422);
    }
}
