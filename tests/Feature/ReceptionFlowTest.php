<?php

namespace Tests\Feature;

use App\Models\ReceptionAssignment;
use App\Models\ReceptionVisit;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  استقبال الموظفين — المسار كاملاً وحدوده.
//
//  الخطر الأول هنا ليس عطلاً وظيفياً بل تسرّب هوية: المقيّم يجب ألّا يرى اسم
//  المشارك ولا رقم هويته في أي مخرَج من هذا المسار. تسرّبٌ كهذا لا يُسقط طلباً
//  ولا يرمي خطأ — يمرّ صامتاً ويُبطل حياد التقييم كلّه.
//
//  والخطر الثاني تداخل المراحل: من يوقّع ليس من يوزّع، ومن يوزّع ليس من يقرّر،
//  ومن يقرّر ليس من يعتمد. لكلٍّ صلاحيته، والاختبار يثبت أن كسرها مرفوض.
// ════════════════════════════════════════════════════════════
class ReceptionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // أصغر PNG صالح — الشكل هو المهم لا المحتوى
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAf'
        . 'FcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const NAME = 'سلطان بن فيصل الشهراني';

    // ── يصل المشارك ويوقّع، ويعود معرّف زيارته ──
    private function arrivedAndSigned(string $sectorCode = 'DW'): array
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => $sectorCode, 'fullName' => self::NAME]);

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        $this->postJson("/api/reception/visits/{$visitId}/sign", [
            'signature' => self::PNG, 'attested' => true,
        ])->assertOk();

        return [$c, $a, $visitId];
    }

    private function assignTo(int $visitId, User $evaluator, string $activity = 'interview'): int
    {
        $this->actingAsRole('RECEPTIONIST');
        return $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => $activity, 'evaluatorId' => $evaluator->id,
        ])->assertStatus(201)->json('assignmentId');
    }

    // ═══ ١) المسار ═══

    public function test_the_full_path_runs_from_arrival_to_a_scheduled_session(): void
    {
        [$c, $a, $visitId] = $this->arrivedAndSigned();

        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asgId}/accept")->assertOk();

        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")
            ->assertOk()->assertJson(['schedulesCreated' => 1]);

        // الأثر الحقيقي: جلسة في الجدول لنفس الدورة والنشاط والمقيّم
        $this->assertDatabaseHas('schedules', [
            'candidate_id' => $c->id,
            'assessment_id' => $a->id,
            'activity' => 'interview',
            'evaluator_id' => $ev->id,
        ]);
        $this->assertSame(ReceptionVisit::APPROVED, ReceptionVisit::find($visitId)->status);
    }

    public function test_arriving_twice_yields_one_visit_not_two(): void
    {
        [, $a] = $this->makeCandidate();
        $this->actingAsRole('RECEPTIONIST');

        $first = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])->assertStatus(201);
        $second = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])->assertOk();

        $this->assertSame($first->json('visitId'), $second->json('visitId'));
        $this->assertSame(1, ReceptionVisit::where('assessment_id', $a->id)->count());
    }

    public function test_arrival_time_is_recorded_automatically_and_stays_editable(): void
    {
        [, $a] = $this->makeCandidate();
        $this->actingAsRole('RECEPTIONIST');

        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        $this->assertNotNull(ReceptionVisit::find($visitId)->arrived_at, 'وقت الوصول لم يُملأ تلقائياً');

        $this->patchJson("/api/reception/visits/{$visitId}/arrival", ['arrivedAt' => '07:45'])
            ->assertOk()->assertJson(['arrivedAt' => '07:45']);

        // الوقت يُركَّب على تاريخ الزيارة — تعديل زيارةٍ سابقة لا ينقلها إلى اليوم
        $v = ReceptionVisit::find($visitId);
        $this->assertSame($v->visit_date->toDateString(), $v->arrived_at->toDateString());
    }

    // ═══ ٢) التوقيع والإقرار ═══

    public function test_distribution_is_blocked_until_the_candidate_signs_and_attests(): void
    {
        [, $a] = $this->makeCandidate();
        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])->json('visitId');

        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'interview', 'evaluatorId' => $ev->id,
        ])->assertStatus(422);

        $this->assertSame(0, ReceptionAssignment::where('visit_id', $visitId)->count());
    }

    public function test_a_signature_without_attestation_is_refused(): void
    {
        [, $a] = $this->makeCandidate();
        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])->json('visitId');

        $this->postJson("/api/reception/visits/{$visitId}/sign", [
            'signature' => self::PNG, 'attested' => false,
        ])->assertStatus(422);

        $this->assertFalse(ReceptionVisit::find($visitId)->isSigned());
    }

    public function test_the_signature_is_stored_encrypted_not_in_the_clear(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();

        $raw = \DB::table('reception_visits')->where('id', $visitId)->value('signature_enc');
        $this->assertNotNull($raw);
        // العمود لا يحمل الصورة كما هي — لو حملها لكانت بيانات شخصية بلا تشفير
        $this->assertStringNotContainsString('data:image/png', $raw);
        // وتُقرأ صحيحة عبر الخاصية
        $this->assertSame(self::PNG, ReceptionVisit::find($visitId)->signature);
    }

    // ═══ ٣) سرّية المشارك أمام المقيّم ═══

    public function test_the_evaluator_never_sees_the_candidate_name_or_national_id(): void
    {
        [$c, , $visitId] = $this->arrivedAndSigned();
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);

        $this->actingAs($ev);
        $board = $this->getJson('/api/reception')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::NAME, $board, 'اسم المشارك ظهر في كشف المقيّم');
        $this->assertStringNotContainsString($c->national_id, $board);

        $this->postJson("/api/reception/assignments/{$asgId}/accept")->assertOk();
        $cv = $this->getJson("/api/reception/assignments/{$asgId}/cv")->assertOk();

        $this->assertStringNotContainsString(self::NAME, $cv->getContent());
        $this->assertStringNotContainsString($c->national_id, $cv->getContent());
        // الرمز وحده هو هوية المشارك عند المقيّم
        $cv->assertJsonPath('cv.participantCode', $c->participant_code);
        $this->assertArrayNotHasKey('name', $cv->json('cv'));
    }

    // مدير إدارة التقييم يملك رؤية الأسماء في بقية المنصّة. لو اعتمد هذا المسار
    // على الصلاحية وحدها لَسرّب الاسم لمن يحملها؛ القاعدة هنا إجرائية لا صلاحية.
    public function test_holding_the_view_names_permission_does_not_unlock_the_name_here(): void
    {
        [$c, , $visitId] = $this->arrivedAndSigned();
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asgId}/accept")->assertOk();

        // امنح المقيّم رؤية الأسماء استثناءً — يجب ألّا يتغيّر شيء في هذا المسار
        $ev->permissionOverrides()->create([
            'permission' => \App\Security\Permissions::CANDIDATE_VIEW_NAMES, 'granted' => true,
        ]);
        $this->actingAs($ev->fresh());

        $cv = $this->getJson("/api/reception/assignments/{$asgId}/cv")->assertOk();
        $this->assertStringNotContainsString(self::NAME, $cv->getContent());
    }

    public function test_the_cv_opens_only_after_the_evaluator_takes_the_candidate(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);

        $this->actingAs($ev);
        $this->getJson("/api/reception/assignments/{$asgId}/cv")->assertStatus(422);
    }

    // ═══ ٤) حدود الصلاحيات — مرحلةٌ لكلٍّ ═══

    public function test_each_stage_refuses_the_holder_of_another_stage(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);

        // الاستقبال يوزّع ولا يعتمد
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertStatus(403);

        // العمليات تعتمد ولا تأخذ توقيعاً
        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/sign", [
            'signature' => self::PNG, 'attested' => true,
        ])->assertStatus(403);

        // المقيّم يقرّر ولا يوزّع
        $this->actingAs($ev);
        $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'discussion', 'evaluatorId' => $ev->id,
        ])->assertStatus(403);

        // ولا يعتمد
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertStatus(403);
    }

    public function test_a_role_without_reception_view_cannot_open_the_screen(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');
        $this->getJson('/api/reception')->assertStatus(403);
    }

    public function test_an_evaluator_cannot_decide_on_someone_elses_assignment(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $mine = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $mine);

        // مقيّم آخر في القطاع نفسه — 404 لا 403: إسناد غيره ليس شأنه فلا يُعلَم بوجوده
        $this->actingAsRole('EVALUATOR', 'DW');
        $this->postJson("/api/reception/assignments/{$asgId}/accept")->assertStatus(404);
        $this->postJson("/api/reception/assignments/{$asgId}/reject", ['reason' => 'محاولة'])
            ->assertStatus(404);

        $this->assertSame(ReceptionAssignment::PENDING, ReceptionAssignment::find($asgId)->status);
    }

    public function test_an_evaluator_from_another_sector_cannot_be_assigned(): void
    {
        [, , $visitId] = $this->arrivedAndSigned('DW');
        $other = $this->actingAsRole('EVALUATOR', 'PR');   // السجون لا ديوان الوزارة

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'interview', 'evaluatorId' => $other->id,
        ])->assertStatus(422);
    }

    public function test_an_activity_cannot_be_given_to_a_role_that_does_not_run_it(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $discussion = $this->actingAsRole('DISCUSSION_EVAL', 'DW');

        // مستشار حلقة النقاش لا يُجري المقابلة الشخصية
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'interview', 'evaluatorId' => $discussion->id,
        ])->assertStatus(422);
    }

    // القائمة المعروضة للاستقبال يجب أن تطابق ما يقبله الإسناد تماماً — وإلا
    // اختار الاستقبالُ اسماً من القائمة فردّه الخادم بلا سبب مفهوم.
    public function test_every_evaluator_offered_for_an_activity_can_actually_be_assigned(): void
    {
        [$c, , $visitId] = $this->arrivedAndSigned();
        $this->actingAsRole('EVALUATOR', 'DW');

        $this->actingAsRole('RECEPTIONIST');
        $offered = $this->getJson('/api/reception/evaluators?activity=interview&sectorId=' . $c->sector_id)
            ->assertOk()->json('evaluators');

        $this->assertNotEmpty($offered, 'لا مقيّم معروض — الاختبار لا يثبت شيئاً');
        foreach ($offered as $i => $e) {
            $status = $this->postJson("/api/reception/visits/{$visitId}/assign", [
                'activity' => 'interview', 'evaluatorId' => $e['id'],
            ])->getStatusCode();
            $this->assertSame(201, $status, "المقيّم «{$e['name']}» معروض لكن الإسناد إليه رُفض");
            // أفرغ الموضع للتالي
            ReceptionAssignment::where('visit_id', $visitId)->delete();
        }
    }

    // ═══ ٥) الردّ وإعادة الإسناد ═══

    public function test_a_rejection_needs_a_reason_and_returns_the_candidate_to_operations(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $ops = $this->actingAsRole('OPERATIONS');
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asgId}/reject", [])->assertStatus(422);
        $this->postJson("/api/reception/assignments/{$asgId}/reject", [
            'reason' => 'تعارض مع جلسة أخرى',
        ])->assertOk();

        $this->assertSame(ReceptionAssignment::REJECTED, ReceptionAssignment::find($asgId)->status);

        // العمليات تُشعَر ليعاد الإسناد — الإشعار يحمل الرمز لا الاسم
        $notif = \DB::table('notifications')
            ->where('recipient_id', $ops->id)
            ->where('entity_type', 'reception_visit')->first();
        $this->assertNotNull($notif, 'لم تُشعَر العمليات بالردّ');
        $this->assertStringNotContainsString(self::NAME, $notif->title . ' ' . $notif->body);
    }

    public function test_operations_can_reassign_a_rejected_candidate_and_history_survives(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $first = $this->actingAsRole('EVALUATOR', 'DW');
        $asg1 = $this->assignTo($visitId, $first);

        $this->actingAs($first);
        $this->postJson("/api/reception/assignments/{$asg1}/reject", ['reason' => 'غير متاح'])->assertOk();

        // مقيّم بديل — والإسناد المردود يبقى في السجلّ بسببه
        $second = $this->actingAsRole('EVALUATOR', 'DW');
        $this->actingAsRole('OPERATIONS');
        $asg2 = $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'interview', 'evaluatorId' => $second->id,
        ])->assertStatus(201)->json('assignmentId');

        $this->assertSame(2, ReceptionAssignment::where('visit_id', $visitId)->count());
        $this->assertSame('غير متاح', ReceptionAssignment::find($asg1)->reject_reason);
        $this->assertSame($second->id, ReceptionAssignment::find($asg2)->evaluator_id);
    }

    public function test_operations_can_reassign_a_rejected_candidate_to_a_different_activity(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asg1 = $this->assignTo($visitId, $ev);

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asg1}/reject", ['reason' => 'الأنسب النقاش'])->assertOk();

        $disc = $this->actingAsRole('DISCUSSION_EVAL', 'DW');
        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'discussion', 'evaluatorId' => $disc->id,
        ])->assertStatus(201);
    }

    public function test_the_same_activity_cannot_be_open_with_two_evaluators_at_once(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $a = $this->actingAsRole('EVALUATOR', 'DW');
        $b = $this->actingAsRole('EVALUATOR', 'DW');
        $this->assignTo($visitId, $a);

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'interview', 'evaluatorId' => $b->id,
        ])->assertStatus(422);
    }

    // ═══ ٦) الاعتماد ═══

    public function test_approval_waits_for_every_pending_decision(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asg1 = $this->assignTo($visitId, $ev);

        $disc = $this->actingAsRole('DISCUSSION_EVAL', 'DW');
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'discussion', 'evaluatorId' => $disc->id,
        ])->assertStatus(201);

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asg1}/accept")->assertOk();

        // واحد مستلَم وواحد معلّق — الاعتماد الآن يُسقط المعلّق صامتاً
        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertStatus(422);
        $this->assertSame(0, Schedule::where('assessment_id', ReceptionVisit::find($visitId)->assessment_id)
            ->where('activity', 'interview')->count());
    }

    public function test_approving_twice_does_not_duplicate_the_schedule(): void
    {
        [, $a, $visitId] = $this->arrivedAndSigned();
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asgId}/accept")->assertOk();

        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertOk();
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertStatus(422);

        $this->assertSame(1, Schedule::where('assessment_id', $a->id)->where('activity', 'interview')->count());
    }

    public function test_an_approved_visit_is_frozen(): void
    {
        [, , $visitId] = $this->arrivedAndSigned();
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);
        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asgId}/accept")->assertOk();
        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertOk();

        // لا وقت يُعدَّل، ولا توقيع يُستبدل بعد أن اعتُمدت الوثيقة
        $this->actingAsRole('RECEPTIONIST');
        $this->patchJson("/api/reception/visits/{$visitId}/arrival", ['arrivedAt' => '06:00'])
            ->assertStatus(422);
        $this->postJson("/api/reception/visits/{$visitId}/sign", [
            'signature' => self::PNG, 'attested' => true,
        ])->assertStatus(422);
    }

    // ═══ ٧) حدّ القطاع على الكشف نفسه ═══

    public function test_a_sector_bound_user_does_not_see_another_sectors_visits(): void
    {
        [, , $visitId] = $this->arrivedAndSigned('DW');
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $asgId = $this->assignTo($visitId, $ev);

        // مقيّم السجون لا يبلغ زيارة ديوان الوزارة لا قراءةً ولا قراراً
        $this->actingAsRole('EVALUATOR', 'PR');
        $this->getJson('/api/reception')->assertOk()->assertJsonPath('mine', []);
        $this->postJson("/api/reception/assignments/{$asgId}/accept")->assertStatus(404);
        $this->getJson("/api/reception/visits/{$visitId}/cv")->assertStatus(403);
    }

    // ═══ ٨) قائمة المنتظَرين تُعلن قصّها ═══

    public function test_the_expected_list_reports_the_full_count_when_truncated(): void
    {
        $this->actingAsRole('RECEPTIONIST');
        $body = $this->getJson('/api/reception')->assertOk()->json('expected');

        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('shown', $body);
        $this->assertSame(count($body['rows']), $body['shown']);
        $this->assertGreaterThanOrEqual($body['shown'], $body['total']);
    }
}
