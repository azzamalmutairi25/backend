<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\DevelopmentPlanItem;
use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\MeasurementResult;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// يقفل ما كشفه تدقيق الصلاحيات.
//
// النمط الجامع: القائمة محصورة وأشقّاؤها مكشوفون — show/export/approve/
// المستند/المحادثة تُفتح بالمعرّف لما تُخفيه القائمة.
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** مرشّح في قطاع آخر غير قطاع الفاعل */
    private function foreign(array $attrs = []): array
    {
        return $this->makeCandidate(array_merge(['sectorCode' => 'HO'], $attrs));
    }

    // ══════ المرشحون ══════

    public function test_show_is_404_for_a_candidate_outside_the_sector(): void
    {
        [$c] = $this->foreign();
        $this->actingAsRole('ASSISTANT', 'ED');

        // كان يرجّع السجل كاملاً — القطاع والرتبة والفئة والحالة
        $this->getJson("/api/candidates/{$c->id}")->assertStatus(404);
    }

    public function test_assessments_is_404_outside_the_sector(): void
    {
        [$c] = $this->foreign(['status' => 'assessed']);
        $this->actingAsRole('EVALUATOR', 'ED');

        // كان يكشف كل الدرجات وأسماء المقيّمين وتوصية التقرير
        $this->getJson("/api/candidates/{$c->id}/assessments")->assertStatus(404);
    }

    // رحلة المرشح محروسة بـCANDIDATE_JOURNEY، ولا يملكها أيّ دور محصور بقطاع
    // اليوم — فحصر القطاع فيها دفاعيّ. يسقط هذا الاختبار لحظةَ مُنحت لدور محصور،
    // وهي اللحظة التي يصير فيها الحصر لازماً.
    public function test_journey_is_scoped_for_any_bound_holder(): void
    {
        foreach (User::SECTOR_BOUND_ROLES as $code) {
            $this->assertNotContains('candidate.journey', \App\Security\Permissions::forRole($code),
                "{$code} محصور بقطاع ويملك رحلة المرشح — تحقّق من حصرها");
        }

        [$c] = $this->foreign();
        $this->actingAsRole('ASSESS_MANAGER'); // غير محصور — يمرّ
        $this->getJson("/api/candidates/{$c->id}/journey")->assertOk();
    }

    public function test_export_is_limited_to_the_users_sector(): void
    {
        [$mine] = $this->makeCandidate(['sectorCode' => 'ED']);
        [$theirs] = $this->foreign();
        $this->actingAsRole('DISCUSSION_EVAL', 'ED');

        $csv = $this->get('/api/candidates/export')->assertOk()->getContent();
        $this->assertStringContainsString($mine->participant_code, $csv);
        $this->assertStringNotContainsString($theirs->participant_code, $csv, 'التصدير لا يجمع ما تخفيه الشاشة');
    }

    public function test_export_cannot_be_aimed_at_another_sector(): void
    {
        [$theirs] = $this->foreign();
        $this->actingAsRole('EVALUATOR', 'ED');

        $ho = Sector::where('code', 'HO')->value('id');
        $csv = $this->get("/api/candidates/export?sectorId={$ho}")->assertOk()->getContent();
        $this->assertStringNotContainsString($theirs->participant_code, $csv);
    }

    // ── CANDIDATE_CREATE لا يمنح قوة التعديل ──

    public function test_create_cannot_overwrite_an_existing_candidate_without_edit(): void
    {
        [$existing] = $this->makeCandidate(['sectorCode' => 'ED', 'fullName' => 'الاسم الأصلي']);
        $existing->assessments()->update(['status' => 'completed']);
        $existing->update(['status' => 'completed']);
        $nid = $existing->national_id;

        $this->actingAsRole('EXTERNAL_ADD'); // candidate.create فقط

        // كان يعيد تسميته وينقله بين القطاعات بمجرّد «إضافته» بهويته
        $this->postJson('/api/candidates', [
            'nationalId' => $nid, 'fullName' => 'اسم مزروع',
            'sectorId' => Sector::where('code', 'HO')->value('id'),
            'rankLabel' => 'عميد',
        ])->assertStatus(403);

        $this->assertSame('الاسم الأصلي', $existing->fresh()->full_name);
    }

    public function test_a_role_with_edit_may_still_update_a_returning_candidate(): void
    {
        [$existing] = $this->makeCandidate(['sectorCode' => 'ED', 'fullName' => 'الاسم الأصلي']);
        $existing->assessments()->update(['status' => 'completed']);
        $existing->update(['status' => 'completed']);

        $this->actingAsRole('SCHEDULER'); // يملك create + edit
        $this->postJson('/api/candidates', [
            'nationalId' => $existing->national_id, 'fullName' => 'الاسم المحدّث',
            'sectorId' => $existing->sector_id, 'rankLabel' => 'مدير عام',
        ])->assertCreated();

        $this->assertSame('الاسم المحدّث', $existing->fresh()->full_name);
    }

    // ── reassess تتطلّب القراءة ──

    public function test_reassess_requires_read_permission(): void
    {
        [$c] = $this->makeCandidate(['status' => 'completed']);
        $this->actingAsRole('EXTERNAL_ADD'); // create فقط، لا view

        // كان يمرّ بالمعرّفات فيحصد الرموز ويُطلق رسائل نصية
        $this->postJson("/api/candidates/{$c->id}/reassess")->assertStatus(403);
    }

    // ══════ التقارير ══════

    public function test_evaluator_cannot_approve_a_report_outside_their_sector(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        [$c, $a] = $this->foreign(['status' => 'assessed']);
        // قيّمه فعلاً، لكن المرشّح خارج قطاعه — القطاع حدّ أعلى
        Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $ev->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $r = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'pending_evaluator', 'created_by' => null,
        ]);

        $this->postJson("/api/reports/{$r->id}/approve")->assertStatus(404);
        $this->assertSame('pending_evaluator', $r->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'DENIED_REPORT_OUT_OF_SCOPE']);
    }

    // الإرجاع صار لمدير المركز وحده — لم يعد المقيّم يملكه أصلاً، فالنطاق
    // لا يُبلَغ عنده. يبقى النطاق مفروضاً لمن يملك الإرجاع.
    public function test_return_is_refused_for_roles_that_no_longer_hold_it(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'sectorCode' => 'ED']);
        Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $ev->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $r = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'pending_evaluator', 'created_by' => null,
        ]);

        // قيّمه ويراه — ومع ذلك لا يُرجعه
        $this->postJson("/api/reports/{$r->id}/return", ['reason' => 'محاولة إرجاع'])->assertStatus(403);

        foreach (['ASSESS_MANAGER', 'DEV_MANAGER'] as $role) {
            $this->actingAsRole($role);
            $this->postJson("/api/reports/{$r->id}/return", ['reason' => 'محاولة إرجاع'])->assertStatus(403);
        }
    }

    public function test_assistant_cannot_write_a_report_for_another_sector(): void
    {
        [$c] = $this->foreign(['status' => 'assessed']);
        $this->actingAsRole('ASSISTANT', 'ED');

        // eligible-candidates يُخفيه، فلا يُقبل بمعرّفه
        $this->postJson('/api/reports', ['candidateId' => $c->id, 'recommendation' => 'يوصى به'])
            ->assertStatus(404);
    }

    public function test_score_preview_is_scoped_like_eligible_candidates(): void
    {
        [$c] = $this->foreign(['status' => 'assessed']);
        $this->actingAsRole('ASSISTANT', 'ED');

        $this->getJson("/api/reports/score-preview?candidateId={$c->id}")->assertStatus(404);
    }

    // ══════ أدوات القياس ══════

    public function test_measurement_results_are_scoped_to_the_sector(): void
    {
        [$c, $a] = $this->foreign(['status' => 'assessed']);
        MeasurementResult::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'personality_score' => 80, 'analytical_score' => 75, 'english_score' => 70,
        ]);

        $this->actingAsRole('ASSISTANT', 'ED');
        $this->getJson("/api/measurements/{$c->id}")->assertStatus(404);
    }

    // ══════ خطط التطوير ══════

    public function test_development_plan_reads_are_scoped(): void
    {
        [$c] = $this->foreign(['status' => 'completed']);
        $this->actingAsRole('ASSISTANT', 'ED');

        $this->getJson("/api/development-plans/{$c->id}")->assertStatus(404);
    }

    public function test_development_plan_writes_are_scoped(): void
    {
        [$c] = $this->foreign(['status' => 'completed']);
        $this->actingAsRole('ASSISTANT', 'ED');

        $this->postJson('/api/development-plans', [
            'candidateId' => $c->id, 'area' => 'التفويض', 'action' => 'ورشة',
        ])->assertStatus(404);
    }

    public function test_development_plan_item_update_is_scoped_via_its_candidate(): void
    {
        [$c, $a] = $this->foreign(['status' => 'completed']);
        $item = DevelopmentPlanItem::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'area' => 'التفويض', 'status' => 'pending', 'created_by' => null,
        ]);

        $this->actingAsRole('ASSISTANT', 'ED');
        // يُحلّ بمعرّف البند — لولا النطاق عبر العلاقة لمرّ
        $this->putJson("/api/development-plan-items/{$item->id}", ['status' => 'done'])->assertStatus(404);
        $this->deleteJson("/api/development-plan-items/{$item->id}")->assertStatus(404);
    }

    // ══════ المحادثات ══════

    public function test_report_chat_is_scoped_like_the_report(): void
    {
        [$c, $a] = $this->foreign(['status' => 'assessed']);
        $r = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'pending_evaluator', 'created_by' => null,
        ]);

        $this->actingAsRole('EVALUATOR', 'ED');
        // المحادثة تحمل سبب الإرجاع ونقاش المقيّمين — أي مضمون التقرير المحجوب
        $this->getJson("/api/chat/report/{$r->id}")->assertStatus(404);
    }

    // ══════ التقييم ══════

    public function test_save_scores_requires_the_input_permission_not_just_ownership(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $e = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $ev->id,
            'activity' => 'interview', 'status' => 'draft',
        ]);

        // تُسحب الصلاحية بعد بدء الجلسة — الملكية وحدها كانت تُبقي الباب مفتوحاً
        $ev->permissionOverrides()->create([
            'permission' => \App\Security\Permissions::EVALUATION_INPUT, 'granted' => false,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($ev->fresh());

        $this->postJson("/api/evaluations/{$e->id}/scores", ['scores' => []])->assertStatus(403);
        $this->postJson("/api/evaluations/{$e->id}/submit")->assertStatus(403);
    }

    // ══════ سجل التدقيق ══════

    // candidateHistory كان محروساً بـCANDIDATE_VIEW بينما شقيقه systemLog على
    // الجدول نفسه محروس بـAUDIT_VIEW. صُحّح إلى AUDIT_VIEW — لكن الدالّة لا مسار
    // لها أصلاً، فأثر الخطأ كان صفراً. الاختبار يقفل الحارس الآن، فإن رُبطت
    // بمسار لاحقاً وُلدت محروسة.
    public function test_candidate_history_is_gated_on_audit_view_not_candidate_view(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/AuditController.php'));
        // من ترويسة الدالّة إلى أول hasPermission فيها
        $body = substr($src, strpos($src, 'function candidateHistory'));
        $upToCheck = substr($body, 0, strpos($body, ') {') ?: 1200);

        $this->assertStringContainsString('Permissions::AUDIT_VIEW', $upToCheck);
        $this->assertStringNotContainsString('Permissions::CANDIDATE_VIEW)', $upToCheck);
    }

    public function test_system_audit_log_still_requires_audit_view(): void
    {
        $this->actingAsRole('RECEPTIONIST'); // لا AUDIT_VIEW
        $this->getJson('/api/audit/log')->assertStatus(403);

        $this->actingAsRole('CENTER_MANAGER'); // يملكها
        $this->getJson('/api/audit/log')->assertOk();
    }
}
