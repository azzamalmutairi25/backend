<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// تكامل آلة حالة المرشح: draft → scheduled → assessed → (تقرير) → completed
// يُثبّت تزامن candidate ⇄ assessment عبر كل انتقال (الإصلاحات: حارس approve، مزامنة submit، اعتماد التقرير)
class CandidateLifecycleTest extends TestCase
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

    public function test_full_happy_path_keeps_candidate_and_assessment_in_lockstep(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'draft', 'assessmentStatus' => 'draft']);
        $ids = $this->linkCompetencies('interview', 2);
        $this->actingAsRole('ADMIN'); // كل الصلاحيات — فاعل واحد يقود الدورة كاملة

        // 1) اعتماد: draft → scheduled (المرشح + الدورة)
        $this->postJson("/api/candidates/{$c->id}/approve")->assertOk();
        $this->assertSame('scheduled', $c->fresh()->status);
        $this->assertSame('scheduled', $a->fresh()->status);

        // 2) المقيّم يُجري التقييم — لا مدير النظام:
        // المقيّم لا يرى ولا يعتمد إلا تقارير من قيّمهم هو، فلو قيّمه ADMIN
        // لما رأى المقيّمُ التقريرَ عند مرحلته.
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        $evalId = $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->assertCreated()->json('evaluation.id') ?? Evaluation::latest('id')->value('id');
        $this->postJson("/api/evaluations/{$evalId}/scores", ['scores' => [
            ['competencyId' => $ids[0], 'score' => 3], ['competencyId' => $ids[1], 'score' => 4],
        ]])->assertOk();
        $this->postJson("/api/evaluations/{$evalId}/submit")->assertOk();
        $this->assertSame('assessed', $c->fresh()->status);
        $this->assertSame('assessed', $a->fresh()->status);

        // 3) المساعد يكتب التقرير ويرسله — لا مدير النظام:
        // مرحلة مدير التقييم تمنع كاتب التقرير من اعتمادها («من يكتب لا يعتمد»)،
        // فلو كتبه الفاعل نفسه لعلق عندها. هذا هو المسار الحقيقي أصلاً.
        $mgr = $this->actingAsRole('ASSESS_MANAGER');
        $this->actingAsRole('ASSISTANT', 'ED', $mgr);

        $reportId = $this->postJson('/api/reports', [
            'candidateId' => $c->id, 'recommendation' => 'مرشّح قوي',
            'behavioralFit' => 88.5, 'technicalFit' => 77.0, 'submit' => true,
        ])->assertCreated()->json('reportId') ?? FinalReport::latest('id')->value('id');
        $this->assertSame('pending_evaluator', FinalReport::find($reportId)->status);

        // 4) سلسلة الاعتماد كاملة — تُقرأ من workflow_stages، فيصمد الاختبار إن
        // أُعيد ترتيبها من الشاشة. كل مرحلة يعتمدها صاحبها.
        $chain = \App\Models\WorkflowStage::chain();
        foreach ($chain as $stage) {
            $this->assertSame($stage->status_key, FinalReport::find($reportId)->status,
                "التقرير عند المرحلة {$stage->position}");
            $this->assertSame('assessed', $c->fresh()->status, 'المرشح لا يكتمل قبل نهاية السلسلة');

            // يُعاد استعمال الفاعلَين لا إنشاء غيرهما: المقيّم هو من قيّم المرشّح
            // (وإلا لم يرَ تقريره)، والمدير هو مدير كاتب التقرير (تشترطه قاعدة
            // الفريق). المراحل الأخرى غير محصورة فيكفيها دورٌ جديد.
            if ($stage->role_code === 'EVALUATOR')          { \Laravel\Sanctum\Sanctum::actingAs($ev); }
            elseif ($stage->role_code === 'ASSESS_MANAGER') { \Laravel\Sanctum\Sanctum::actingAs($mgr); }
            else                                            { $this->actingAsRole($stage->role_code); }

            $this->postJson("/api/reports/{$reportId}/approve")->assertOk();
        }

        // 5) بعد آخر مرحلة: المرشح + الدورة → completed
        $this->assertSame('approved', FinalReport::find($reportId)->status);
        $this->assertSame('completed', $c->fresh()->status);
        $this->assertSame('completed', $a->fresh()->status);
    }

    public function test_reassess_opens_a_second_cycle_and_resets_status(): void
    {
        // مرشح أنهى دورة (completed) — «تقييم جديد» يفتح دورة ثانية بحالة draft
        [$c, $a1] = $this->makeCandidate(['status' => 'completed', 'assessmentStatus' => 'completed']);
        $this->actingAsRole('ADMIN'); // CANDIDATE_CREATE

        $this->postJson("/api/candidates/{$c->id}/reassess")->assertCreated();

        $this->assertSame(2, Assessment::where('candidate_id', $c->id)->count());
        $this->assertSame('draft', $c->fresh()->status);
        $latest = Assessment::where('candidate_id', $c->id)->latest('id')->first();
        $this->assertSame('draft', $latest->status);
        $this->assertNotNull($latest->confirm_token); // دورة جديدة قابلة لمسار التأكيد
        // الرمز الجديد فريد عن الأول
        $this->assertNotSame($a1->participant_code, $latest->participant_code);
    }

    public function test_active_cycle_blocks_a_new_reassess(): void
    {
        // دورة نشطة (غير مكتملة) تمنع فتح دورة جديدة حتى تُكمل
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'assessmentStatus' => 'scheduled']);
        $this->actingAsRole('ADMIN');
        $this->postJson("/api/candidates/{$c->id}/reassess")->assertStatus(422);
        $this->assertSame(1, Assessment::where('candidate_id', $c->id)->count());
    }
}
