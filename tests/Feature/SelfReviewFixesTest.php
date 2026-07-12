<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Evaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// انحدار لإصلاحات المراجعة الذاتية الخصمية (أخطاء أُدخلت في دفعة الـ26 ثم صُحّحت)
class SelfReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // B: تعديل مرشح لا يعيد كتابة نوع دورة مكتملة (سجل تاريخي)
    public function test_update_does_not_rewrite_completed_cycle_assessment_type(): void
    {
        $nid = $this->validNationalId();
        [$c, $a] = $this->makeCandidate([
            'status' => 'completed', 'assessmentStatus' => 'completed', 'nationalId' => $nid,
        ]);
        $this->assertSame('comprehensive', $a->assessment_type);

        $this->actingAsRole('SCHEDULER'); // CANDIDATE_EDIT
        $this->putJson("/api/candidates/{$c->id}", [
            'nationalId' => $nid, 'fullName' => 'محدّث', 'sectorId' => $c->sector_id,
            'rankLabel' => 'مدير عام', 'assessmentType' => 'executive',
        ])->assertOk();

        $this->assertSame('executive', $c->fresh()->assessment_type);      // سجل الشخص يتحدّث
        $this->assertSame('comprehensive', $a->fresh()->assessment_type);  // الدورة المكتملة لا تُمَسّ
    }

    // C: إرجاع تقييم من دورة أقدم منتهية لا يُنزِل حالة الدورة الحالية الحيّة
    public function test_return_old_cycle_eval_does_not_demote_current_cycle(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a1] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);

        // الدورة 1 اكتملت وتقييمها بقي «submitted» (لم يُعتمد)
        $a1->update(['status' => 'completed']);
        $e1 = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a1->id, 'evaluator_id' => $evaluator->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        // الدورة 2 حيّة ومُقيَّمة (assessed) بتقييمها الخاص
        $a2 = Assessment::create([
            'candidate_id' => $c->id, 'participant_code' => $a1->participant_code . 'B',
            'assessment_type' => 'comprehensive', 'status' => 'assessed', 'created_by' => null,
        ]);
        $c->update(['status' => 'assessed']);
        Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a2->id, 'evaluator_id' => $evaluator->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        // إرجاع تقييم الدورة القديمة
        $this->actingAsRole('ASSESS_MANAGER'); // EVALUATION_APPROVE
        $this->postJson("/api/evaluations/{$e1->id}/return", ['reason' => 'مراجعة دورة قديمة'])->assertOk();

        $this->assertSame('draft', $e1->fresh()->status);          // التقييم القديم رُجِع
        $this->assertSame('completed', $a1->fresh()->status);      // الدورة القديمة تبقى مكتملة
        $this->assertSame('assessed', $a2->fresh()->status);       // الدورة الحالية لا تُنزَّل ← الإصلاح
        $this->assertSame('assessed', $c->fresh()->status);        // المرشح لا يُنزَّل
    }

    // C-إيجابي: إرجاع تقييم الدورة الحالية الوحيد ما زال يُنزِل المرشح إلى scheduled
    public function test_return_current_cycle_sole_eval_still_reverts_candidate(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $e = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $evaluator->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        $this->actingAsRole('ASSESS_MANAGER');
        $this->postJson("/api/evaluations/{$e->id}/return", ['reason' => 'إرجاع الدورة الحالية'])->assertOk();

        $this->assertSame('scheduled', $c->fresh()->status);
        $this->assertSame('scheduled', $a->fresh()->status);
    }

    // E: وسيط رقمي غير-{id} (entityId) بقيمة غير رقمية يخطئ المسار (404) لا TypeError (500)
    public function test_nonnumeric_sibling_route_param_is_404_not_500(): void
    {
        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_VIEW
        $this->getJson('/api/chat/report/abc')->assertStatus(404);
    }
}
