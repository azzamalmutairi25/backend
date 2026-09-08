<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\CandidateCv;
use App\Models\CandidateCvRevision;
use App\Models\ReceptionVisit;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  السيرة عند مكتب الاستقبال — تُصحَّح، ثم تُعتمد، ثم تُجمَّد.
//
//  الاعتماد بوّابةٌ لا زينة: بلا اعتمادٍ لا تُطبع بطاقةٌ ولا يصل المستشار
//  شيء. والتصحيح بعده ينقضه — فاعتمادٌ يبقى على نصٍّ تغيّر يشهد على ما لم
//  يُقرأ. وعند الإرسال تُجمَّد الصورة، فما أرسله الاستقبال هو ما يُقيَّم عليه.
// ════════════════════════════════════════════════════════════
class ReceptionCvReviewTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAf'
        .'FcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** مشاركٌ وصل ووقّع، وسيرته محفوظة ولم تُعتمد بعد */
    private function arrived(array $cvOverrides = []): array
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'fullName' => 'فيصل بن ناصر القحطاني']);
        // `validCvDoc` لا تحمل المنصب، وهو أوّل ما يُصحَّح عند المكتب
        $this->giveCv($c, array_merge(['currentPosition' => 'مدير إدارة'], $cvOverrides));

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        $this->postJson("/api/reception/visits/{$visitId}/sign", [
            'signature' => self::PNG, 'attested' => true,
        ])->assertOk();

        return [$c, $a, $visitId];
    }

    private function sendableVisit(): array
    {
        [$c, $a, $visitId] = $this->arrived();

        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $this->actingAsRole('RECEPTIONIST');
        $asg = $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'interview', 'evaluatorId' => $ev->id,
        ])->assertStatus(201)->json('assignmentId');

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asg}/accept")->assertOk();

        return [$c, $a, $visitId];
    }

    // ═══ التصحيح ═══

    public function test_reception_corrects_the_seven_fields_and_the_rest_survives(): void
    {
        [$c, , $visitId] = $this->arrived();
        $birth = $c->cv->data['birthDate'];

        $this->actingAsRole('RECEPTIONIST');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => 'مدير عام الشؤون الفنية', 'totalYearsExperience' => 18],
        ])->assertOk()->assertJsonPath('version', 2);

        $doc = $c->fresh()->cv->data;
        $this->assertSame('مدير عام الشؤون الفنية', $doc['currentPosition']);
        $this->assertSame(18, $doc['totalYearsExperience']);
        // الوثيقة تُغطّى لا تُستبدل — نموذجٌ جزئيّ لا يمحو ما لم يُعرَض فيه
        $this->assertSame($birth, $doc['birthDate'], 'ما لم يُرسَل يبقى كما أدخلته الجهة');
    }

    // ما لا يُصحَّح من هنا يُردّ صراحةً — التجاهل الصامت يوهم بتغييرٍ لم يقع
    public function test_a_field_outside_the_seven_is_refused_not_ignored(): void
    {
        [$c, , $visitId] = $this->arrived();

        $this->actingAsRole('RECEPTIONIST');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['birthDate' => '1990-01-01'],
        ])->assertStatus(422);

        $this->assertSame('1985-03-01', $c->fresh()->cv->data['birthDate']);
    }

    public function test_each_correction_writes_a_revision_naming_what_changed(): void
    {
        [$c, , $visitId] = $this->arrived();

        $this->actingAsRole('RECEPTIONIST');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => 'وكيل الوزارة المساعد'],
            'note' => 'صُحّح مع المشارك عند المكتب',
        ])->assertOk();

        $rev = CandidateCvRevision::where('candidate_id', $c->id)->firstOrFail();
        $this->assertSame(2, $rev->version);
        $this->assertSame(['currentPosition'], $rev->changed_fields);
        $this->assertSame('reception', $rev->source);
        $this->assertSame('صُحّح مع المشارك عند المكتب', $rev->note);
        $this->assertSame('المنصب الحالي', $rev->changedLabels());
        // الوثيقة كاملةً بعد التغيير — لا فرقاً يُركَّب من سلسلة
        $this->assertSame('وكيل الوزارة المساعد', $rev->data['currentPosition']);
    }

    // حفظٌ بلا تغيير لا يُقيَّد — سطرٌ فارغ يُغرق السجلّ بما لا يُقرأ
    public function test_saving_without_a_change_writes_no_revision(): void
    {
        [$c, , $visitId] = $this->arrived();
        $position = $c->cv->data['currentPosition'];

        $this->actingAsRole('RECEPTIONIST');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => $position],
        ])->assertOk()->assertJsonPath('changed', []);

        $this->assertSame(0, CandidateCvRevision::where('candidate_id', $c->id)->count());
    }

    // الاستقبال ليس معفىً من فحص التسرّب — المستشار يقرأ بلا اسم
    public function test_a_name_smuggled_into_a_corrected_field_is_refused(): void
    {
        [$c, , $visitId] = $this->arrived();

        $this->actingAsRole('RECEPTIONIST');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => 'مدير مكتب فيصل بن ناصر القحطاني'],
        ])->assertStatus(422);

        $this->assertSame(0, CandidateCvRevision::where('candidate_id', $c->id)->count());
    }

    // ═══ الاعتماد ═══

    public function test_approval_opens_the_badge_and_the_send(): void
    {
        [, , $visitId] = $this->arrived();

        $this->actingAsRole('RECEPTIONIST');
        // قبل الاعتماد: البطاقة مغلقة
        $this->postJson("/api/reception/visits/{$visitId}/badge-printed")->assertStatus(422);

        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();

        $this->postJson("/api/reception/visits/{$visitId}/badge-printed")->assertOk();
        $this->assertNotNull(ReceptionVisit::find($visitId)->badge_printed_at);
    }

    public function test_an_empty_cv_is_not_approved(): void
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW']);
        CandidateCv::create(['candidate_id' => $c->id, 'data' => CandidateCv::emptyDoc(), 'version' => 1]);

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');

        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', 'السيرة فارغة — صحّحها مع المشارك قبل اعتمادها');
    }

    // ── والتصحيح ينقض الاعتماد ──
    public function test_correcting_after_approval_revokes_it(): void
    {
        [, , $visitId] = $this->arrived();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();

        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['department' => 'الإدارة العامة للعمليات'],
        ])->assertOk()->assertJsonPath('cvApproved', false);

        $this->assertNull(ReceptionVisit::find($visitId)->cv_approved_at,
            'اعتمادٌ يبقى بعد تغيير ما اعتُمد يشهد على نصٍّ لم يُقرأ');
        // والبطاقة تُقفل معه
        $this->postJson("/api/reception/visits/{$visitId}/badge-printed")->assertStatus(422);
    }

    // وحفظٌ لم يغيّر شيئاً لا ينقضه — لا شيء تغيّر ليُعاد النظر فيه
    public function test_a_no_op_save_keeps_the_approval(): void
    {
        [$c, , $visitId] = $this->arrived();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => $c->cv->data['currentPosition']],
        ])->assertOk()->assertJsonPath('cvApproved', true);

        $this->assertNotNull(ReceptionVisit::find($visitId)->cv_approved_at);
    }

    // ═══ الإرسال والتجميد ═══

    public function test_an_unapproved_cv_is_never_sent_to_the_consultant(): void
    {
        [, , $visitId] = $this->sendableVisit();

        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', 'لم تُعتمَد السيرة بعد — راجِعها مع المشارك ثم اعتمِدها');
    }

    public function test_sending_freezes_the_cv_at_that_moment(): void
    {
        [$c, $a, $visitId] = $this->sendableVisit();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();
        $this->assertNull(Assessment::find($a->id)->cv_snapshot_enc, 'لا صورة قبل الإرسال');

        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertOk();

        $frozen = Assessment::find($a->id);
        $this->assertNotNull($frozen->cv_snapshotted_at, 'الصورة تُؤخذ لحظة الإرسال لا عند بدء التقييم');
        $this->assertSame($c->cv->version, $frozen->cv_snapshot_version);
        $this->assertNotNull(ReceptionVisit::find($visitId)->sent_at);
    }

    // ما أرسله الاستقبال هو ما يُقيَّم عليه — لا أقدم ولا أحدث
    public function test_the_frozen_copy_does_not_follow_a_later_edit(): void
    {
        [$c, $a, $visitId] = $this->sendableVisit();

        $this->actingAsRole('RECEPTIONIST');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => 'المنصب المرسل'],
        ])->assertOk();
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();

        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertOk();

        // تعديلٌ لاحق على الملفّ الحيّ — من مسار الإدارة لا من الاستقبال
        $cv = $c->fresh()->cv;
        $cv->data = array_merge($cv->data, ['currentPosition' => 'منصب بعد الإرسال']);
        $cv->version = $cv->version + 1;
        $cv->save();

        $this->assertSame('المنصب المرسل',
            Assessment::find($a->id)->cv_snapshot['currentPosition'],
            'الصورة لا تتبع الملفّ الحيّ');
    }

    // وبعد الإرسال لا يُصحَّح من الاستقبال — تصحيحٌ لا يصل لا يُقبَل صامتاً
    public function test_reception_cannot_correct_after_the_send(): void
    {
        [, , $visitId] = $this->sendableVisit();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();
        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertOk();

        $this->actingAsRole('RECEPTIONIST');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => 'متأخّر'],
        ])->assertStatus(422);
    }

    // ═══ الإرسال الجماعي ═══

    public function test_the_batch_send_routes_the_ready_and_names_the_rest(): void
    {
        [, , $readyId] = $this->sendableVisit();
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$readyId}/cv/approve")->assertOk();

        // ثانٍ: أُسنِد واستُلم ولم تُعتمَد سيرته
        [, , $noCvId] = $this->sendableVisit();
        // ثالث: وصل ووقّع بلا إسناد
        [, , $bareId] = $this->arrived();
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$bareId}/cv/approve")->assertOk();

        $this->actingAsRole('OPERATIONS');
        $res = $this->postJson('/api/reception/send')->assertOk();

        $this->assertSame(1, $res->json('sent'));
        $this->assertSame(1, $res->json('schedulesCreated'));

        $blocked = collect($res->json('blocked'))->pluck('reason', 'visitId');
        $this->assertSame('لم تُعتمَد سيرته', $blocked[$noCvId]);
        $this->assertSame('لا إسناد مستلَم', $blocked[$bareId]);
        $this->assertNotNull(ReceptionVisit::find($readyId)->sent_at);
    }

    // ═══ الحراسات ═══

    public function test_editing_and_approving_need_their_own_permissions(): void
    {
        [, , $visitId] = $this->arrived();

        // مسؤول العمليات يعتمد الترحيل ولا يمسّ السيرة
        $this->actingAsRole('OPERATIONS');
        $this->putJson("/api/reception/visits/{$visitId}/cv", ['cv' => ['currentPosition' => 'س']])
            ->assertStatus(403);
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertStatus(403);
    }

    public function test_the_two_permissions_are_separable(): void
    {
        // موظّفٌ يصحّح ولا يعتمد — الفصل ممكنٌ بلا شيفرة
        $u = $this->actingAsRole('RECEPTIONIST');
        $u->permissionOverrides()->create([
            'permission' => Permissions::RECEPTION_CV_APPROVE, 'granted' => false,
        ]);

        [, , $visitId] = $this->arrived();
        $this->actingAs(User::find($u->id));

        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => 'منصبٌ مصحَّح'],
        ])->assertOk();
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertStatus(403);
    }

    // سجلّ الإصدارات يقول ماذا ومن ومتى — ولا يحمل الوثائق نفسها
    public function test_the_revision_log_lists_changes_without_shipping_the_documents(): void
    {
        [, , $visitId] = $this->arrived();

        $this->actingAsRole('RECEPTIONIST');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => 'الأوّل'],
        ])->assertOk();
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['totalYearsExperience' => 21],
        ])->assertOk();

        $res = $this->getJson("/api/reception/visits/{$visitId}/cv/revisions")->assertOk();
        $rows = $res->json('revisions');

        $this->assertCount(2, $rows);
        $this->assertSame(3, $rows[0]['version'], 'الأحدث أوّلاً');
        $this->assertSame('إجمالي سنوات الخبرة', $rows[0]['changedLabels']);
        $this->assertArrayNotHasKey('data', $rows[0], 'السطر يقول ماذا تغيّر ولا يحمل الوثيقة');
        $this->assertStringNotContainsString('الأوّل', $res->getContent());
    }
}
