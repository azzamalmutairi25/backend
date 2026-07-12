<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Sector;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// اختبارات انحدار تُثبّت إصلاحات مراجعة الصحّة/المتانة (منطق، أعطال، آلات حالة، نزاهة بيانات)
class CorrectnessRegressionTest extends TestCase
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

    // ── #1: حذف مرشح له سجل رسالة نصية ينجح ويُوثَّق مرّة (لا 500 من قيد FK، لا تنافر تدقيقي) ──
    public function test_destroy_candidate_with_sms_log_succeeds_and_audits_once(): void
    {
        [$c] = $this->makeCandidate(['status' => 'draft']);
        SmsLog::create([
            'to_mobile' => '0501234567', 'message' => 'دعوة', 'sms_type' => 'invitation',
            'candidate_id' => $c->id, 'status' => 'sent',
        ]);

        $this->actingAsRole('SCHEDULER'); // CANDIDATE_EDIT
        $this->deleteJson("/api/candidates/{$c->id}", ['reason' => 'سبب موثّق للحذف'])->assertOk();

        $this->assertNull(\App\Models\Candidate::find($c->id));
        $this->assertSame(0, SmsLog::where('candidate_id', $c->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'DELETE_CANDIDATE')
            ->where('entity_id', (string) $c->id)->count());
    }

    // ── #6: اعتماد مرشح غادر المسودة مرفوض ولا يُرجِع دورة مكتملة ──
    public function test_approve_rejects_non_draft_and_preserves_completed_assessment(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'completed', 'assessmentStatus' => 'completed']);
        $this->actingAsRole('SCHEDULER'); // CANDIDATE_APPROVE
        $this->postJson("/api/candidates/{$c->id}/approve")->assertStatus(422);

        $this->assertSame('completed', $a->fresh()->status);
        $this->assertSame('completed', $c->fresh()->status);
    }

    // ── #2: إرجاع التقييم يُعيد المرشح ودورته من assessed إلى scheduled ──
    public function test_return_evaluation_reverts_candidate_and_assessment(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $ids = $this->linkCompetencies('interview', 2);

        $this->actingAsRole('EVALUATOR');
        $evalId = $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->assertCreated()->json('evaluation.id') ?? Evaluation::latest('id')->value('id');
        $this->postJson("/api/evaluations/{$evalId}/scores", ['scores' => [
            ['competencyId' => $ids[0], 'score' => 3], ['competencyId' => $ids[1], 'score' => 4],
        ]])->assertOk();
        $this->postJson("/api/evaluations/{$evalId}/submit")->assertOk();
        $this->assertSame('assessed', $c->fresh()->status);

        $this->actingAsRole('ASSESS_MANAGER'); // EVALUATION_APPROVE
        $this->postJson("/api/evaluations/{$evalId}/return", ['reason' => 'يرجى مراجعة الدرجات'])->assertOk();

        $this->assertSame('scheduled', $c->fresh()->status);
        $this->assertSame('scheduled', $a->fresh()->status);
        $this->assertSame('draft', Evaluation::find($evalId)->status);
    }

    // ── #5/#13: الاستيراد يُنشئ دورة تقييم ولا يفسد تسلسل الرمز (إضافة تالية لا تُصادم بـ 500) ──
    public function test_import_creates_assessment_cycle_and_next_add_does_not_collide(): void
    {
        $ed = Sector::where('code', 'ED')->firstOrFail();
        $this->actingAsRole('SCHEDULER'); // CANDIDATE_CREATE

        $importedNid = $this->validNationalId();
        $res = $this->postJson('/api/candidates/import', ['rows' => [[
            'nationalId' => $importedNid, 'fullName' => 'مستورد', 'mobile' => '0505550000',
            'email' => '', 'sectorCode' => 'ED', 'rankLabel' => 'مدير عام',
        ]]])->assertOk();
        $this->assertSame(1, $res->json('imported'));

        $imported = \App\Models\Candidate::where('national_id_hash', hash('sha256', $importedNid))->first();
        $this->assertNotNull($imported);
        $this->assertSame(1, Assessment::where('candidate_id', $imported->id)->count()); // دورة أُنشئت

        // إضافة يدوية تالية لنفس القطاع يجب ألا تُصادم على participant_code
        $this->postJson('/api/candidates', [
            'nationalId' => $this->validNationalId(), 'fullName' => 'تالٍ', 'mobile' => '0505550001',
            'sectorId' => $ed->id, 'rankLabel' => 'مدير عام',
        ])->assertCreated();
    }

    // ── #17: resubmit يُرجِع 422 (كبقية حرّاس الحالة) لا 400 عند حالة خاطئة ──
    public function test_resubmit_returns_422_for_wrong_state(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'status' => 'draft', 'created_by' => null,
        ]);
        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_CREATE + REPORT_EDIT_ANY
        $this->postJson("/api/reports/{$report->id}/resubmit")->assertStatus(422);
    }

    // ── #16: fit رقم (float) لا نص في قائمة التقارير ──
    public function test_report_index_fit_is_numeric(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'behavioral_fit' => 85.5, 'technical_fit' => 70.25, 'status' => 'draft', 'created_by' => null,
        ]);
        $this->actingAsRole('ASSESS_MANAGER');
        $res = $this->getJson('/api/reports')->assertOk();
        $this->assertSame(85.5, $res->json('reports.0.behavioralFit'));
        $this->assertSame(70.25, $res->json('reports.0.technicalFit'));
    }

    // ── #10: سجل التصدير الجماعي (entity_id='0') لا يُحجب عن مدقّق بلا تصريح تصنيف ──
    public function test_export_audit_entry_not_redacted_for_uncleared_auditor(): void
    {
        $this->actingAsRole('CENTER_MANAGER'); // CANDIDATE_VIEW + AUDIT_VIEW, بلا VIEW_CLASSIFIED
        $this->getJson('/api/candidates/export')->assertOk(); // يكتب EXPORT_CANDIDATES

        $entries = $this->getJson('/api/audit/log')->assertOk()->json('entries');
        $export = collect($entries)->firstWhere('actionCode', 'EXPORT_CANDIDATES');
        $this->assertNotNull($export);
        $this->assertFalse($export['redacted']);
        $this->assertNotNull($export['details']);
    }

    // ── #9: إعادة تعيين كلمة مرور مستخدم داخلي (AD) مرفوضة (لا حلقة حبس) ──
    public function test_reset_password_rejects_internal_user(): void
    {
        $role = Role::where('code', 'EVALUATOR')->firstOrFail();
        $internal = User::create([
            'username' => 'ad_user_x', 'full_name' => 'مستخدم داخلي', 'role_id' => $role->id,
            'is_active' => true, 'must_change_password' => false, 'user_type' => 'internal',
            'ad_username' => 'aduser', 'password' => 'Kafaat@2026',
        ]);
        $this->actingAsRole('ADMIN'); // USER_MANAGE
        $this->patchJson("/api/users/{$internal->id}/password", ['password' => 'Kafaat@2026'])
            ->assertStatus(422);
    }

    // ── #11: تبديل كفاءات نشاط له تقييم نشط مرفوض (لا يكسر التقييمات الجارية) ──
    public function test_activity_competency_swap_blocked_with_active_evaluation(): void
    {
        $ids = Competency::orderBy('id')->limit(3)->pluck('id')->all();
        DB::table('activity_competency')->insert([
            ['activity' => 'interview', 'competency_id' => $ids[0], 'created_at' => now(), 'updated_at' => now()],
            ['activity' => 'interview', 'competency_id' => $ids[1], 'created_at' => now(), 'updated_at' => now()],
        ]);
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('EVALUATOR');
        $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->assertCreated();

        $this->actingAsRole('DEV_MANAGER'); // COMPETENCY_MANAGE
        // إزالة [0],[1] وإحلال [2] بينما ثمّة مسودة نشطة → 422
        $this->putJson('/api/activity-competencies/interview', ['competencyIds' => [$ids[2]]])
            ->assertStatus(422);
    }

    // ── #24: perPage الفارغ لا يقسّم على 15 (كل الإشعارات تظهر) ──
    public function test_empty_perpage_does_not_cap_at_15(): void
    {
        $user = $this->actingAsRole('EVALUATOR');
        for ($i = 0; $i < 16; $i++) {
            Notification::create([
                'recipient_id' => $user->id, 'type' => 'info', 'title' => "إشعار {$i}",
                'body' => 'نص', 'is_read' => false,
            ]);
        }
        $res = $this->getJson('/api/notifications?perPage=')->assertOk();
        $this->assertCount(16, $res->json('notifications')); // لا 15
    }

    // ── #25: isClosed قيمة منطقية (false) عند أوّل إنشاء للمحادثة، لا null ──
    public function test_chat_thread_isclosed_is_boolean_on_first_create(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'status' => 'draft', 'created_by' => null,
        ]);
        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_VIEW
        $res = $this->getJson("/api/chat/report/{$report->id}")->assertOk();
        $this->assertSame(false, $res->json('isClosed'));
    }
}
