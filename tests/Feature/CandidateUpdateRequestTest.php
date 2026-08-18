<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\CandidateCv;
use App\Models\CandidateUpdateRequest;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// بوّابة المستخدم الخارجي: إدخال نموذج المركز مع المشارك، كشف المكرّر بتاريخه،
// ثم طلب تحديث يُعتمد أو يُرفض من صاحب صلاحية — والسجلّ لا يتغيّر قبل الاعتماد.
class CandidateUpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function validCv(array $over = []): array
    {
        return array_merge([
            'birthDate' => '1982-04-11',
            'appointmentDate' => '2006-09-01',
            'personnelCategory' => 'military',
            'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'department' => 'الإدارة العامة للعمليات',
            'region' => 'الرياض',
            'currentPosition' => 'مدير عام',
            'totalYearsExperience' => 15,
            'briefBio' => 'قيادي متمرّس في القطاع الحكومي',
            'qualifications' => [[
                'degree' => 'master', 'major' => 'إدارة أعمال', 'institution' => 'جامعة الملك سعود',
                'studyPlace' => 'السعودية — الرياض', 'gradYear' => 2008,
            ]],
            'experiences' => [[
                'position' => 'مدير إدارة', 'organization' => 'وزارة', 'fromYear' => 2010,
                'toYear' => null, 'current' => true, 'summary' => 'قيادة الفريق',
            ]],
            'certifications' => [['name' => 'شهادة احترافية', 'issuer' => 'المعهد', 'year' => 2015]],
        ], $over);
    }

    private function edId(): int
    {
        return Sector::where('code', 'DW')->value('id');
    }

    // مشارك مسجّل + طلب تحديث معلّق من مستخدم خارجي — يرجع [candidate, requestId, nationalId]
    private function pendingRequest(array $cvOver = [], array $identityOver = []): array
    {
        [$c] = $this->makeCandidate(['sectorCode' => 'DW', 'fullName' => 'الاسم الأصلي', 'rankLabel' => 'مقدم']);
        $nid = $c->national_id;

        $this->actingAsRole('EXTERNAL_ADD');
        $res = $this->postJson('/api/candidate-update-requests', array_merge([
            'nationalId' => $nid,
            'fullName' => 'الاسم المحدّث',
            'mobile' => '0505550001',
            'sectorId' => $this->edId(),
            'personnelCategory' => 'military',
            'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'note' => 'تغيّرت رتبته',
            'cv' => $this->validCv($cvOver),
        ], $identityOver))->assertStatus(201);

        return [$c, $res->json('requestId'), $nid];
    }

    // ═══ الإضافة مع نموذج السيرة ═══

    public function test_external_add_stores_the_cv_with_the_candidate(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');

        $res = $this->postJson('/api/candidates', array_replace($this->candidateRequired(), [
            'nationalId' => $this->validNationalId(),
            'fullName' => 'مشارك جديد',
            'mobile' => '0501112223',
            'sectorId' => $this->edId(),
            'personnelCategory' => 'military',
            'rankLabel' => 'عميد',
            'cv' => $this->validCv(),
        ]))->assertStatus(201);

        $this->assertTrue($res->json('cvSaved'));

        $cv = CandidateCv::first();
        $this->assertNotNull($cv);
        $this->assertSame('external', $cv->source, 'مصدر السيرة جهة خارجية لا إدارة');
        $this->assertSame(1, $cv->version);
        $this->assertSame('الإدارة العامة للعمليات', $cv->data['department']);
    }

    // انقلبت القاعدة: كانت السيرة اختياريةً فيدخل المشارك بلا سيرة ثم يقف عند
    // الترشيح بلا سبب ظاهر. صارت إلزامية — فالطلب بلا مفتاح `cv` يُردّ ٤٢٢
    // ولا يُنشأ منه مشارك ولا سيرة. باقي الحقول الإلزامية سليمة عمداً ليقع
    // الردّ على غياب السيرة وحدها لا على سواها.
    public function test_add_without_a_cv_is_422(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');

        $this->postJson('/api/candidates', [
            'nationalId' => $this->validNationalId(), 'fullName' => 'بلا سيرة',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'gender' => 'male', 'technicalAreaIds' => $this->technicalAreaIds(),
        ])->assertStatus(422)
            ->assertJsonPath('error', 'السيرة الذاتية إلزامية — أكمل بيانات السيرة قبل الحفظ');

        $this->assertSame(0, Candidate::count(), 'لا يُنشأ مشارك بلا سيرة');
        $this->assertSame(0, CandidateCv::count());
    }

    public function test_cv_carrying_the_candidate_name_is_refused(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');

        $this->postJson('/api/candidates', array_replace($this->candidateRequired(), [
            'nationalId' => $this->validNationalId(), 'fullName' => 'سلطان العتيبي',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'cv' => $this->validCv(['briefBio' => 'قاد سلطان فريق العمليات']),
        ]))->assertStatus(422)->assertJsonPath('field', 'briefBio');

        $this->assertSame(0, Candidate::count(), 'لا يُنشأ مشارك حين تُرفض سيرته');
    }

    public function test_invalid_cv_is_422_and_creates_nothing(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');

        $this->postJson('/api/candidates', array_replace($this->candidateRequired(), [
            'nationalId' => $this->validNationalId(), 'fullName' => 'مشارك',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'cv' => $this->validCv(['appointmentDate' => '2100-01-01']),
        ]))->assertStatus(422)->assertJsonStructure(['error', 'fields']);

        $this->assertSame(0, Candidate::count());
    }

    // ═══ كشف المكرّر ═══

    public function test_duplicate_add_reports_the_original_date_and_offers_an_update_request(): void
    {
        [$c] = $this->makeCandidate(['sectorCode' => 'DW', 'fullName' => 'الاسم الأصلي']);
        $c->assessments()->update(['status' => 'completed']);
        $c->update(['status' => 'completed']);
        $nid = $c->national_id;

        $this->actingAsRole('EXTERNAL_ADD');
        $res = $this->postJson('/api/candidates', array_replace($this->candidateRequired(), [
            'nationalId' => $nid, 'fullName' => 'اسم مزروع',
            'sectorId' => Sector::where('code', 'PR')->value('id'), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'cv' => $this->validCv(),
        ]))->assertStatus(403);

        $res->assertJsonPath('duplicate', true)
            ->assertJsonPath('candidateId', $c->id)
            ->assertJsonPath('canRequestUpdate', true)
            ->assertJsonPath('pendingRequest', null);
        $this->assertNotEmpty($res->json('addedAt'), 'يُعرض تاريخ الإضافة السابق');

        // السجلّ لم يُمسّ، ولا سيرة كُتبت فوقه
        $this->assertSame('الاسم الأصلي', $c->fresh()->full_name);
        $this->assertSame(0, CandidateCv::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'DUPLICATE_CANDIDATE_ADD']);
    }

    public function test_duplicate_response_does_not_leak_the_participant_code(): void
    {
        // دورة نشطة: كان حارسها يسبق فحص الصلاحية ويردّ الرمز في نصّ الخطأ
        [$c] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled', 'code' => 'DW-9911']);
        $nid = $c->national_id;

        $this->actingAsRole('EXTERNAL_ADD');
        $res = $this->postJson('/api/candidates', array_replace($this->candidateRequired(), [
            'nationalId' => $nid, 'fullName' => 'أيّ اسم',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
        ]))->assertStatus(403);

        $this->assertStringNotContainsString('DW-9911', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
        $this->assertNull($res->json('participantCode'));
    }

    public function test_duplicate_add_flags_an_existing_pending_request(): void
    {
        [$c, $requestId, $nid] = $this->pendingRequest();

        $res = $this->postJson('/api/candidates', array_replace($this->candidateRequired(), [
            'nationalId' => $nid, 'fullName' => 'أيّ اسم',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
        ]))->assertStatus(403);

        $this->assertSame($requestId, $res->json('pendingRequest.id'));
    }

    // ═══ رفع الطلب ═══

    public function test_request_is_stored_pending_and_touches_nothing(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        $req = CandidateUpdateRequest::find($requestId);
        $this->assertSame(CandidateUpdateRequest::PENDING, $req->status);
        $this->assertSame('الاسم المحدّث', $req->payload['identity']['fullName']);
        $this->assertSame('الاسم الأصلي', $req->snapshot['identity']['fullName']);
        $this->assertSame('عميد', $req->payload['cv']['rankLabel']);

        // السجلّ الحيّ لم يتغيّر قبل الاعتماد
        $this->assertSame('الاسم الأصلي', $c->fresh()->full_name);
        $this->assertSame('مقدم', $c->fresh()->rank_label);
        $this->assertSame(0, CandidateCv::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'REQUEST_CANDIDATE_UPDATE']);
    }

    public function test_request_notifies_the_approvers(): void
    {
        $scheduler = $this->actingAsRole('SCHEDULER'); // يملك candidate.update_approve
        $this->pendingRequest();

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $scheduler->id,
            'type' => 'approval',
            'entity_type' => 'candidate_update_request',
        ]);
    }

    public function test_second_pending_request_is_refused(): void
    {
        [$c, $requestId, $nid] = $this->pendingRequest();

        $this->postJson('/api/candidate-update-requests', [
            'nationalId' => $nid, 'fullName' => 'محاولة ثانية',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'cv' => $this->validCv(),
        ])->assertStatus(409)->assertJsonPath('pendingRequest.id', $requestId);

        $this->assertSame(1, CandidateUpdateRequest::count());
    }

    public function test_request_for_an_unknown_national_id_is_404(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');

        $this->postJson('/api/candidate-update-requests', [
            'nationalId' => $this->validNationalId(), 'fullName' => 'لا أحد',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'cv' => $this->validCv(),
        ])->assertStatus(404);
    }

    public function test_request_for_a_classified_candidate_is_indistinguishable_from_missing(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'secret', 'sectorCode' => 'DW']);
        $nid = $c->national_id;

        $this->actingAsRole('EXTERNAL_ADD'); // بلا صلاحية رؤية المصنّفين
        $this->postJson('/api/candidate-update-requests', [
            'nationalId' => $nid, 'fullName' => 'مهاجم',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'cv' => $this->validCv(),
        ])->assertStatus(404);

        $this->assertSame(0, CandidateUpdateRequest::count());
    }

    public function test_request_cv_carrying_the_candidate_name_is_refused(): void
    {
        [$c] = $this->makeCandidate(['sectorCode' => 'DW', 'fullName' => 'سلطان العتيبي']);
        $nid = $c->national_id;

        $this->actingAsRole('EXTERNAL_ADD');
        $this->postJson('/api/candidate-update-requests', [
            'nationalId' => $nid, 'fullName' => 'سلطان العتيبي',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'cv' => $this->validCv(['briefBio' => 'قاد سلطان فريق العمليات']),
        ])->assertStatus(422)->assertJsonPath('field', 'briefBio');

        $this->assertSame(0, CandidateUpdateRequest::count());
    }

    public function test_request_without_a_cv_is_422(): void
    {
        [$c] = $this->makeCandidate(['sectorCode' => 'DW']);
        $nid = $c->national_id;

        $this->actingAsRole('EXTERNAL_ADD');
        $this->postJson('/api/candidate-update-requests', [
            'nationalId' => $nid, 'fullName' => 'اسم', 'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
        ])->assertStatus(422);
    }

    // ═══ الحرّاس على القائمة والبتّ ═══

    public function test_requester_sees_only_their_own_requests_without_the_participant_code(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        $res = $this->getJson('/api/candidate-update-requests/mine')->assertOk();
        $this->assertCount(1, $res->json('requests'));
        $this->assertSame($requestId, $res->json('requests.0.id'));
        $this->assertArrayNotHasKey('participantCode', $res->json('requests.0'));
    }

    public function test_external_cannot_list_or_decide(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        $this->getJson('/api/candidate-update-requests')->assertStatus(403);
        $this->getJson("/api/candidate-update-requests/{$requestId}")->assertStatus(403);
        $this->postJson("/api/candidate-update-requests/{$requestId}/approve")->assertStatus(403);
        $this->postJson("/api/candidate-update-requests/{$requestId}/reject", ['reason' => 'لا سبب'])->assertStatus(403);
    }

    public function test_approver_lists_pending_with_changed_field_labels(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson('/api/candidate-update-requests?status=pending')->assertOk();

        $this->assertSame(1, $res->json('counts.pending'));
        $this->assertSame($requestId, $res->json('requests.0.id'));
        $this->assertContains('الاسم', $res->json('requests.0.changedFields'));
        $this->assertContains('الرتبة / المرتبة', $res->json('requests.0.changedFields'));
    }

    public function test_show_returns_the_comparison(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson("/api/candidate-update-requests/{$requestId}")->assertOk();

        $this->assertSame('الاسم الأصلي', $res->json('request.current.identity.fullName'));
        $this->assertSame('الاسم المحدّث', $res->json('request.proposed.identity.fullName'));
        $this->assertFalse($res->json('request.stale'));

        $names = array_column($res->json('request.diff.changes'), 'key');
        $this->assertContains('fullName', $names);
        $this->assertContains('rankLabel', $names);
    }

    public function test_a_classified_candidates_request_is_hidden_from_an_uncleared_approver(): void
    {
        [$c, $requestId] = $this->pendingRequest();
        $c->update(['classification' => 'top_secret']);

        $this->actingAsRole('SCHEDULER'); // بلا CANDIDATE_VIEW_CLASSIFIED
        $this->getJson('/api/candidate-update-requests')->assertOk()->assertJsonCount(0, 'requests');
        $this->getJson("/api/candidate-update-requests/{$requestId}")->assertStatus(404);
        $this->postJson("/api/candidate-update-requests/{$requestId}/approve")->assertStatus(404);
    }

    // ═══ الاعتماد والرفض ═══

    public function test_approval_applies_identity_and_cv_and_reclassifies_the_tier(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/candidate-update-requests/{$requestId}/approve", ['note' => 'مطابق'])->assertOk();

        $fresh = $c->fresh();
        $this->assertSame('الاسم المحدّث', $fresh->full_name);
        $this->assertSame('0505550001', $fresh->mobile);
        $this->assertSame('عميد', $fresh->rank_label);
        // الفئة تُطبَّق مع الهوية، والطبقة تُحتسب من قائمتها هي لا من قائمة أخرى
        $this->assertSame('military', $fresh->personnel_category);
        $this->assertSame(Candidate::classifyTier('عميد', 'military'), $fresh->tier, 'أُعيد احتساب الفئة من الرتبة الجديدة');

        $cv = CandidateCv::where('candidate_id', $c->id)->first();
        $this->assertNotNull($cv);
        $this->assertSame('external', $cv->source);
        $this->assertSame('الرياض', $cv->data['region']);

        $req = CandidateUpdateRequest::find($requestId);
        $this->assertSame(CandidateUpdateRequest::APPROVED, $req->status);
        $this->assertNotNull($req->reviewed_at);
        $this->assertNotNull($req->reviewed_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'APPROVE_CANDIDATE_UPDATE']);
    }

    public function test_a_request_cannot_be_decided_twice(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/candidate-update-requests/{$requestId}/approve")->assertOk();
        $this->postJson("/api/candidate-update-requests/{$requestId}/approve")->assertStatus(422);
        $this->postJson("/api/candidate-update-requests/{$requestId}/reject", ['reason' => 'متأخر جداً'])->assertStatus(422);
    }

    public function test_rejection_requires_a_reason_and_leaves_the_record_alone(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/candidate-update-requests/{$requestId}/reject", ['reason' => 'لا'])->assertStatus(422);
        $this->postJson("/api/candidate-update-requests/{$requestId}/reject", ['reason' => 'البيانات غير مطابقة للسجلّ الرسمي'])->assertOk();

        $this->assertSame('الاسم الأصلي', $c->fresh()->full_name);
        $this->assertSame(0, CandidateCv::count());

        $req = CandidateUpdateRequest::find($requestId);
        $this->assertSame(CandidateUpdateRequest::REJECTED, $req->status);
        $this->assertSame('البيانات غير مطابقة للسجلّ الرسمي', $req->review_note);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REJECT_CANDIDATE_UPDATE']);
    }

    public function test_the_requester_is_told_the_outcome(): void
    {
        [$c] = $this->makeCandidate(['sectorCode' => 'DW', 'fullName' => 'الاسم الأصلي']);
        $nid = $c->national_id;

        $external = $this->actingAsRole('EXTERNAL_ADD');
        $requestId = $this->postJson('/api/candidate-update-requests', [
            'nationalId' => $nid, 'fullName' => 'الاسم المحدّث',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد', 'cv' => $this->validCv(),
        ])->assertStatus(201)->json('requestId');

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/candidate-update-requests/{$requestId}/reject", ['reason' => 'غير مطابق للسجلّ'])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'recipient_id' => $external->id,
            'entity_type' => 'candidate_update_request',
            'entity_id' => (string) $requestId,
            'title' => 'رُفض طلب تحديث بيانات المشارك',
        ]);
    }

    public function test_a_new_request_may_follow_a_decided_one(): void
    {
        [$c, $requestId, $nid] = $this->pendingRequest();

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/candidate-update-requests/{$requestId}/reject", ['reason' => 'ناقص البيانات'])->assertOk();

        // البتّ يفتح الباب لطلب جديد — القيد على «المعلّق» وحده
        $this->actingAsRole('EXTERNAL_ADD');
        $this->postJson('/api/candidate-update-requests', [
            'nationalId' => $nid, 'fullName' => 'الاسم المحدّث ثانيةً',
            'sectorId' => $this->edId(), 'personnelCategory' => 'military', 'rankLabel' => 'عميد', 'cv' => $this->validCv(),
        ])->assertStatus(201);

        $this->assertSame(2, CandidateUpdateRequest::count());
    }

    public function test_show_flags_a_stale_comparison_when_the_cv_moved_after_the_request(): void
    {
        [$c, $requestId] = $this->pendingRequest();

        // الإدارة عدّلت السيرة بعد رفع الطلب — المقارنة المعروضة صارت أقدم
        $cv = CandidateCv::firstOrNew(['candidate_id' => $c->id]);
        $cv->data = $this->validCv(['region' => 'جدة']);
        $cv->version = 1;
        $cv->source = 'admin';
        $cv->save();

        $this->actingAsRole('SCHEDULER');
        $this->getJson("/api/candidate-update-requests/{$requestId}")->assertOk()
            ->assertJsonPath('request.stale', true);
    }
}
