<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalReport;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// إصلاحات مراجعة المحرّك الأساسي: قصر النسبة، تضييق المقيّم، 404 عبر القطاع/الملكية،
// وخصم الجلسات القائمة من حدّ التوزيع.
class CoreEngineFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(string $sector = 'ED'): User
    {
        return User::create(['username' => 'ev_' . substr(md5(uniqid('', true)), 0, 8), 'full_name' => 'مقيّم',
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', $sector)->value('id'), 'is_active' => true, 'must_change_password' => false]);
    }

    // ── #5: النسبة لا تتجاوز 100٪ ولو خُفّض الحد الأقصى بعد الرصد ──
    public function test_fit_pct_is_clamped_to_100(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed', 'sectorCode' => 'ED']);
        $comp = Competency::create(['name_ar' => 'كفاءة', 'type' => 'behavioral', 'max_level' => 5, 'weight' => 1, 'sort_order' => 90]);
        $ev = Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $this->evaluator()->id, 'activity' => 'interview', 'status' => 'submitted']);
        EvaluationScore::create(['evaluation_id' => $ev->id, 'competency_id' => $comp->id, 'score' => 5]);
        $comp->update(['max_level' => 3]); // خُفّض تحت الدرجة المرصودة

        $fit = app(ScoringService::class)->computeFit($a);
        $this->assertSame(100.0, $fit['breakdown'][0]['pct']);
        $this->assertLessThanOrEqual(100, $fit['behavioralFit']);
    }

    // ── #7: المقيّم المحصور لا يرى فجوة مرشّح لم يقيّمه ──
    public function test_evaluator_cannot_get_gap_for_uncevaluated_sector_mate(): void
    {
        [$c] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed', 'sectorCode' => 'ED']);
        // مقيّم قطاع ED لم يقيّم هذا المرشّح
        $this->actingAsRole('EVALUATOR', 'ED');
        $this->getJson("/api/reports/competency-gap?candidateId={$c->id}")->assertStatus(404);
    }

    public function test_evaluator_gets_gap_for_own_candidate(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed', 'sectorCode' => 'ED']);
        $user = $this->actingAsRole('EVALUATOR', 'ED');
        Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $user->id, 'activity' => 'interview', 'status' => 'submitted']);
        $this->getJson("/api/reports/competency-gap?candidateId={$c->id}")->assertOk();
    }

    // ── #8: بدء تقييم لمرشّح خارج القطاع = 404 لا 403 ──
    public function test_start_cross_sector_is_404_not_403(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'HI']);
        $this->actingAsRole('EVALUATOR', 'ED'); // قطاع آخر
        $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])
            ->assertStatus(404);
    }

    // ── #6: تعديل تقرير خارج نطاق الكاتب = 404 ──
    public function test_update_report_out_of_sector_is_404(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed', 'sectorCode' => 'HI']);
        $r = FinalReport::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => 'draft', 'recommendation' => 'x', 'created_by' => null]);
        // مساعد قطاع ED (REPORT_CREATE، محصور بقطاعه) لا يعدّل تقرير قطاع HI
        $this->actingAsRole('ASSISTANT', 'ED');
        $this->putJson("/api/reports/{$r->id}", ['recommendation' => 'محاولة', 'strengths' => [], 'developmentAreas' => []])
            ->assertStatus(404);
    }

    // ── #9: اعتماد تقييم مصنّف بمفوَّض بلا تصريح = 404 ──
    public function test_evaluation_approve_respects_classification_scope(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'classification' => 'secret', 'sectorCode' => 'ED']);
        $ev = Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $this->evaluator()->id, 'activity' => 'interview', 'status' => 'submitted']);
        // مفوَّض EVALUATION_APPROVE بلا رؤية المصنّفين
        $user = $this->actingAsRole('SCHEDULER');
        $user->permissionOverrides()->create(['permission' => 'evaluation.approve', 'granted' => true]);
        $this->postJson("/api/evaluations/{$ev->id}/approve")->assertStatus(404);
    }

    // ── #4: حدّ التوزيع يخصم الجلسات القائمة للمقيّم في اليوم ──
    public function test_distribution_cap_accounts_for_existing_schedules(): void
    {
        $svc = app(DistributionService::class);
        $day = $svc->nextWeekStart(); // أول أيام الأسبوع الموزّع
        $ev = $this->evaluator('ED');
        // جلسة مقابلة قائمة للمقيّم في ذلك اليوم (حدّ 2 → يبقى مقعد واحد)
        [$existingCand, $ea] = $this->makeCandidate(['status' => 'assessed', 'sectorCode' => 'ED']);
        Schedule::create(['candidate_id' => $existingCand->id, 'assessment_id' => $ea->id, 'schedule_date' => $day->toDateString(), 'activity' => 'interview', 'evaluator_id' => $ev->id]);

        \App\Models\Setting::updateOrCreate(['key' => 'distribution.daily_cap_per_evaluator'], ['value' => '2']);
        // مرشّحان جاهزان في ED
        for ($i = 0; $i < 2; $i++) $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED', 'code' => 'DC' . $i . random_int(100, 999)]);

        $proposal = $svc->propose($this->evaluator('HI'));
        $onDay = $proposal->items->where('evaluator_id', $ev->id)
            ->filter(fn ($it) => (string) $it->scheduled_date === $day->toDateString())->count();
        // القائم (1) + الموزّع ≤ 2 → لا يوزَّع أكثر من مقعد واحد ذلك اليوم
        $this->assertLessThanOrEqual(1, $onDay, 'الحدّ يخصم الجلسة القائمة');
    }
}
