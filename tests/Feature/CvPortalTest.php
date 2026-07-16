<?php

namespace Tests\Feature;

use App\Models\CandidateCv;
use App\Models\Evaluation;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// بوّابة السيرة الذاتية: كتابة المرشح، قفل بعد التقييم، تزامن، لقطة مجمَّدة،
// عرض المقيّم بلا اسم، مسار الإدارة بصلاحية مستقلّة، وحجب تسرّب الاسم.
class CvPortalTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function gate(array $attrs = []): array
    {
        [$c, $a] = $this->makeCandidate(array_merge(['status' => 'scheduled', 'sectorCode' => 'ED'], $attrs));
        $token = Str::random(48);
        $a->update(['confirm_token' => $token]);
        return [$c, $a, $token];
    }

    private function at(string $token, string $nid): string
    {
        return $this->postJson("/api/public/assessment/{$token}/verify", ['nationalId' => $nid])->json('accessToken');
    }

    private function evaluatorUser(string $sectorCode = 'ED'): User
    {
        return User::create([
            'username' => 'ev_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مقيّم',
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function validCv(array $over = []): array
    {
        return array_merge([
            'currentPosition' => 'مدير عام',
            'totalYearsExperience' => 15,
            'briefBio' => 'قيادي متمرّس في القطاع الحكومي',
            'qualifications' => [['degree' => 'master', 'major' => 'إدارة أعمال', 'institution' => 'جامعة الملك سعود', 'gradYear' => 2008]],
            'experiences' => [['position' => 'مدير إدارة', 'organization' => 'وزارة', 'fromYear' => 2010, 'toYear' => null, 'current' => true, 'summary' => 'قيادة الفريق']],
            'certifications' => [['name' => 'شهادة احترافية', 'issuer' => 'المعهد', 'year' => 2015]],
        ], $over);
    }

    // ═══ الكتابة عبر البوّابة ═══

    public function test_valid_save_stores_portal_cv_and_audits_without_pii(): void
    {
        [$c, $a, $token] = $this->gate();
        $at = $this->at($token, $c->national_id);

        $res = $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $this->validCv()])
            ->assertOk();
        $this->assertNotEmpty($res->json('accessToken'), 'يعيد رمز جلسة جديداً');
        $this->assertTrue($res->json('assessment.hasCv'));

        $cv = CandidateCv::where('candidate_id', $c->id)->first();
        $this->assertSame('portal', $cv->source);
        $this->assertNull($cv->updated_by);
        $this->assertSame(1, $cv->version);
        // تدقيق بلا محتوى وبلا مستخدم نظام
        $this->assertDatabaseHas('audit_logs', ['action' => 'PUBLIC_CV_SAVE', 'user_id' => null, 'details' => null]);
    }

    public function test_save_requires_valid_access_token(): void
    {
        [$c, $a, $token] = $this->gate();
        $this->postJson("/api/public/assessment/{$token}/cv", ['cv' => $this->validCv()])->assertStatus(401);
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => 'bad', 'cv' => $this->validCv()])->assertStatus(401);
    }

    public function test_empty_cv_does_not_wipe_existing(): void
    {
        [$c, $a, $token] = $this->gate();
        $at = $this->at($token, $c->national_id);
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $this->validCv()])->assertOk();

        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => []])->assertStatus(422);
        $this->assertSame(1, CandidateCv::where('candidate_id', $c->id)->first()->version, 'لم تُمحَ');
    }

    public function test_array_bomb_is_413(): void
    {
        [$c, $a, $token] = $this->gate();
        $at = $this->at($token, $c->national_id);
        $bomb = $this->validCv(['experiences' => array_fill(0, 500, ['position' => 'x', 'organization' => 'y', 'fromYear' => 2010, 'current' => true])]);
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $bomb])->assertStatus(413);
    }

    public function test_invalid_fields_are_422(): void
    {
        [$c, $a, $token] = $this->gate();
        $at = $this->at($token, $c->national_id);
        $bad = $this->validCv(['qualifications' => [['degree' => 'phd', 'institution' => 'x', 'gradYear' => 3000]]]);
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $bad])
            ->assertStatus(422)->assertJsonStructure(['error', 'fields']);
    }

    // ── قفل التحرير: بعد بدء التقييم لا الوصول ──

    public function test_arrival_alone_does_not_lock_cv(): void
    {
        [$c, $a, $token] = $this->gate();
        $a->update(['arrived_at' => now()]); // وصل لكن لم يُقيَّم
        $at = $this->at($token, $c->national_id);
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $this->validCv()])->assertOk();
    }

    public function test_started_evaluation_locks_cv(): void
    {
        [$c, $a, $token] = $this->gate();
        Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $this->evaluatorUser()->id, 'activity' => 'interview', 'status' => 'draft']);
        $at = $this->at($token, $c->national_id);
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $this->validCv()])
            ->assertStatus(422);
    }

    // ── التزامن: نسخة متوقَّعة إلزامية ──

    public function test_stale_expected_version_conflicts(): void
    {
        [$c, $a, $token] = $this->gate();
        $at = $this->at($token, $c->national_id);
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $this->validCv()])->assertOk();

        // نسخة قديمة → 409
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $this->validCv(['totalYearsExperience' => 20]), 'expectedVersion' => 0])
            ->assertStatus(409);
        // النسخة الصحيحة → نجاح
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => $this->validCv(['totalYearsExperience' => 20]), 'expectedVersion' => 1])
            ->assertOk();
        $this->assertSame(2, CandidateCv::where('candidate_id', $c->id)->first()->version);
    }

    public function test_rate_limited_after_ten_saves(): void
    {
        [$c, $a, $token] = $this->gate();
        $at = $this->at($token, $c->national_id);
        // حمولة تُمرَّر فحص المصفوفة ثم تسقط عند التحقّق — تعدّ في المعدّل
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => ['qualifications' => 'x']]);
        }
        $this->postJson("/api/public/assessment/{$token}/cv", ['accessToken' => $at, 'cv' => ['qualifications' => 'x']])
            ->assertStatus(429);
    }

    // ── حجب تسرّب الاسم على الحفظ العام ──

    public function test_own_name_in_bio_is_blocked_and_audited(): void
    {
        [$c, $a, $token] = $this->gate(['fullName' => 'محمد عبدالله الشهري']);
        $at = $this->at($token, $c->national_id);
        $res = $this->postJson("/api/public/assessment/{$token}/cv", [
            'accessToken' => $at, 'cv' => $this->validCv(['briefBio' => 'أنا الشهري قيادي متمرّس']),
        ])->assertStatus(422);
        $this->assertSame('briefBio', $res->json('field'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'PUBLIC_CV_BLOCKED', 'user_id' => null, 'details' => null]);
        $this->assertNull(CandidateCv::where('candidate_id', $c->id)->first(), 'لم تُحفَظ');
    }

    public function test_national_id_in_cv_is_blocked(): void
    {
        [$c, $a, $token] = $this->gate(['nationalId' => '1010203040']);
        $at = $this->at($token, $c->national_id);
        $this->postJson("/api/public/assessment/{$token}/cv", [
            'accessToken' => $at, 'cv' => $this->validCv(['currentPosition' => 'موظف ١٠١٠٢٠٣٠٤٠']),
        ])->assertStatus(422);
    }

    // ═══ عرض المقيّم بلا اسم (الميزة ٧) ═══

    private function frozenEvaluation(string $name = 'محمد عبدالله الشهري'): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED', 'fullName' => $name]);
        CandidateCv::create(['candidate_id' => $c->id, 'data' => $this->validCv(['currentPosition' => 'مدير في مكتب الشهري']), 'version' => 1, 'source' => 'portal']);
        return [$c, $a];
    }

    public function test_evaluator_sees_cv_without_name_scrubbed(): void
    {
        [$c, $a] = $this->frozenEvaluation();
        $user = $this->actingAsRole('EVALUATOR', 'ED');
        $ev = Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $user->id, 'activity' => 'interview', 'status' => 'draft']);
        $a->load('candidate.cv');
        $a->freezeCvSnapshot();

        $res = $this->getJson("/api/evaluations/{$ev->id}/cv")->assertOk();
        $this->assertNull($res->json('cv.name'), 'لا اسم للمقيّم');
        $this->assertSame($a->participant_code, $res->json('cv.candidateCode'));
        $this->assertTrue($res->json('cv.hasCv'));
        // الاسم داخل النصّ مطموس، والمحتوى باقٍ
        $this->assertStringContainsString('«•••»', $res->json('cv.document.currentPosition'));
        $this->assertStringNotContainsString('الشهري', json_encode($res->json('cv'), JSON_UNESCAPED_UNICODE));
    }

    public function test_evaluator_response_leaks_no_internal_fields(): void
    {
        [$c, $a] = $this->frozenEvaluation();
        $user = $this->actingAsRole('EVALUATOR', 'ED');
        $ev = Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $user->id, 'activity' => 'interview', 'status' => 'draft']);
        $a->load('candidate.cv');
        $a->freezeCvSnapshot();

        $cv = $this->getJson("/api/evaluations/{$ev->id}/cv")->json('cv');
        foreach (['candidate_id', 'updated_by', 'version', 'cvUpdatedAt', 'nationalId', 'mobile', 'email'] as $leak) {
            $this->assertArrayNotHasKey($leak, $cv);
        }
    }

    public function test_evaluator_reads_frozen_snapshot_not_live(): void
    {
        [$c, $a] = $this->frozenEvaluation();
        $user = $this->actingAsRole('EVALUATOR', 'ED');
        $ev = Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $user->id, 'activity' => 'interview', 'status' => 'draft']);
        $a->load('candidate.cv');
        $a->freezeCvSnapshot(); // لقطة v1: currentPosition = «مدير في مكتب ...»

        // تعديل السيرة الحيّة لاحقاً (كأنه دورة تالية)
        $c->cv->update(['data' => $this->validCv(['currentPosition' => 'منصب جديد مختلف تماماً']), 'version' => 2]);

        $doc = $this->getJson("/api/evaluations/{$ev->id}/cv")->json('cv.document');
        $this->assertStringNotContainsString('منصب جديد', $doc['currentPosition'], 'يقرأ اللقطة لا الحيّة');
    }

    public function test_evaluator_cannot_read_unowned_evaluation(): void
    {
        [$c, $a] = $this->frozenEvaluation();
        $ev = Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $this->evaluatorUser()->id, 'activity' => 'interview', 'status' => 'draft']);
        $this->actingAsRole('EVALUATOR', 'ED'); // ليست جلسته، ولا يملك APPROVE
        $this->getJson("/api/evaluations/{$ev->id}/cv")->assertStatus(404);
    }

    public function test_manager_with_view_names_sees_name_and_raw(): void
    {
        [$c, $a] = $this->frozenEvaluation();
        $ev = Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $this->evaluatorUser()->id, 'activity' => 'interview', 'status' => 'submitted']);
        $a->load('candidate.cv');
        $a->freezeCvSnapshot();
        $this->actingAsRole('ASSESS_MANAGER'); // EVALUATION_APPROVE + CANDIDATE_VIEW_NAMES

        $cv = $this->getJson("/api/evaluations/{$ev->id}/cv")->assertOk()->json('cv');
        $this->assertSame('محمد عبدالله الشهري', $cv['name']);
        $this->assertStringContainsString('الشهري', $cv['document']['currentPosition'], 'خام بلا طمس');
    }

    // ═══ مسار الإدارة (صلاحية مستقلّة) ═══

    public function test_evaluator_cannot_reach_candidate_cv_route(): void
    {
        [$c, $a] = $this->frozenEvaluation();
        $this->actingAsRole('EVALUATOR', 'ED'); // لا CANDIDATE_CV_VIEW
        $this->getJson("/api/candidates/{$c->id}/cv")->assertStatus(403);
    }

    public function test_reception_and_measure_cannot_reach_candidate_cv(): void
    {
        [$c, $a] = $this->frozenEvaluation();
        foreach (['RECEPTIONIST', 'MEASURE_SUPER'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/candidates/{$c->id}/cv")->assertStatus(403);
        }
    }

    public function test_scheduler_sees_name_dev_manager_scrubbed(): void
    {
        [$c, $a] = $this->frozenEvaluation();

        $this->actingAsRole('SCHEDULER'); // CV_VIEW + view_names
        $cv = $this->getJson("/api/candidates/{$c->id}/cv")->assertOk()->json('cv');
        $this->assertSame('محمد عبدالله الشهري', $cv['name']);
        $this->assertStringContainsString('الشهري', $cv['document']['currentPosition']);

        $this->actingAsRole('DEV_MANAGER'); // CV_VIEW بلا view_names
        $cv2 = $this->getJson("/api/candidates/{$c->id}/cv")->assertOk()->json('cv');
        $this->assertNull($cv2['name']);
        $this->assertStringContainsString('«•••»', $cv2['document']['currentPosition']);
    }

    public function test_admin_cv_read_is_404_out_of_scope(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'classification' => 'secret']);
        // مسؤول جدولة لا يملك رؤية المصنّفين → خارج النطاق → 404 لا 403
        $this->actingAsRole('SCHEDULER');
        $this->getJson("/api/candidates/{$c->id}/cv")->assertStatus(404);
    }

    public function test_admin_save_sets_admin_source_and_actor(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        $user = $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/candidates/{$c->id}/cv", ['cv' => $this->validCv()])->assertOk();

        $cv = CandidateCv::where('candidate_id', $c->id)->first();
        $this->assertSame('admin', $cv->source);
        $this->assertSame($user->id, $cv->updated_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CV_UPDATE']);
    }

    public function test_admin_is_not_exempt_from_name_leak_block(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED', 'fullName' => 'سعد الغامدي']);
        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/candidates/{$c->id}/cv", ['cv' => $this->validCv(['briefBio' => 'أنا الغامدي مدير'])])
            ->assertStatus(422);
    }

    // ═══ التجميد عبر مسار البدء الفعلي ═══

    public function test_starting_evaluation_freezes_snapshot(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'ED']);
        CandidateCv::create(['candidate_id' => $c->id, 'data' => $this->validCv(), 'version' => 1, 'source' => 'portal']);
        $this->actingAsRole('EVALUATOR', 'ED');

        $this->postJson('/api/evaluations/start', ['candidateId' => $c->id, 'activity' => 'interview'])->assertCreated();
        $this->assertNotNull($a->fresh()->cv_snapshotted_at, 'جُمِّدت اللقطة عند البدء');
    }
}
