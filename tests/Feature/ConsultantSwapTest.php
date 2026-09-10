<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\ReceptionAssignment;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  تبديل المستشار يوم التنفيذ — بلا موافقة، وبسببٍ مكتوب.
//
//  «الاستقبال يبدّل بلا موافقة، ويُدوَّن السبب» — فالسببُ هو كلُّ ما يبقى من
//  القرار. وسحبٌ صامت يجعل مسؤول الجدولة يرى مستشاراً تغيّر ولا يعرف أمريضٌ
//  كان أم ردّ المشارك أم انشغل، وهي ثلاثةُ أحوالٍ يُبنى على كلٍّ منها إجراء.
// ════════════════════════════════════════════════════════════
class ConsultantSwapTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAf'
        .'FcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** زيارةٌ وصلت ووقّعت واعتُمدت سيرتها، وأُسنِدت لمستشار */
    private function assigned(): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->giveCv($c);

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        $this->postJson("/api/reception/visits/{$visitId}/sign", [
            'signature' => self::PNG, 'attested' => true,
        ])->assertOk();
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();

        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $this->actingAsRole('RECEPTIONIST');
        $asg = $this->postJson("/api/reception/visits/{$visitId}/assign", [
            'activity' => 'interview', 'evaluatorId' => $ev->id,
        ])->assertStatus(201)->json('assignmentId');

        return [$c, $a, $visitId, $asg, $ev];
    }

    // ═══ السبب ═══

    public function test_a_withdrawal_without_a_reason_is_refused(): void
    {
        [, , , $asg] = $this->assigned();

        $this->actingAsRole('RECEPTIONIST');
        $this->deleteJson("/api/reception/assignments/{$asg}")
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0',
                'اكتب سبب السحب — التبديل يقع بلا موافقة، فالسببُ كلُّ ما يبقى منه');

        $this->assertNotNull(ReceptionAssignment::find($asg));
    }

    public function test_the_reason_is_kept_in_the_audit(): void
    {
        [, , , $asg] = $this->assigned();

        $this->actingAsRole('RECEPTIONIST');
        $this->deleteJson("/api/reception/assignments/{$asg}", [
            'reason' => 'المستشار استُدعي لاجتماع',
        ])->assertOk();

        $log = AuditLog::where('action', 'RECEPTION_WITHDRAW')->latest('id')->firstOrFail();
        $this->assertSame('المستشار استُدعي لاجتماع', $log->details['reason']);
        $this->assertSame('interview', $log->details['activity']);
        $this->assertNotNull($log->details['from'], 'ومن كان المستشار قبله');
    }

    // ═══ بعد الاستلام ═══

    // كان السحب مقصوراً على المعلّق والبتُّ كذلك — فمن استلم ثم غاب لا مخرج
    // لحاله، والمشارك واقفٌ في الردهة
    public function test_an_accepted_assignment_can_still_be_withdrawn(): void
    {
        [, , , $asg, $ev] = $this->assigned();

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asg}/accept")->assertOk();

        $this->actingAsRole('RECEPTIONIST');
        $this->deleteJson("/api/reception/assignments/{$asg}", [
            'reason' => 'المستشار غادر لطارئ',
        ])->assertOk()->assertJsonPath('wasAccepted', true);

        $this->assertNull(ReceptionAssignment::find($asg));
    }

    // والمردود انتهى أمرُه — سببُه مكتوبٌ أصلاً
    public function test_a_rejected_assignment_is_not_withdrawn(): void
    {
        [, , , $asg, $ev] = $this->assigned();

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asg}/reject", ['reason' => 'خارج مجالي'])
            ->assertOk();

        $this->actingAsRole('RECEPTIONIST');
        $this->deleteJson("/api/reception/assignments/{$asg}", ['reason' => 'أيّاً كان'])
            ->assertStatus(422);
    }

    // ═══ الجلسة المُرحَّلة ═══

    // إسنادٌ رُحّل ثم سُحب كان يترك جلسته باسم المستشار القديم — فالجدول يقول
    // شيئاً والاستقبال يقول غيره، ويُبنى على المتناقضين حضورٌ وتقييم
    public function test_withdrawing_a_routed_assignment_clears_its_session(): void
    {
        [, $a, $visitId, $asg, $ev] = $this->assigned();

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asg}/accept")->assertOk();

        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertOk();

        $schedule = Schedule::where('assessment_id', $a->id)->firstOrFail();
        $this->assertSame($ev->id, $schedule->evaluator_id);

        $this->actingAsRole('RECEPTIONIST');
        $this->deleteJson("/api/reception/assignments/{$asg}", [
            'reason' => 'انشغل بعد الترحيل',
        ])->assertOk()->assertJsonPath('scheduleCleared', true);

        $this->assertNull($schedule->fresh()->evaluator_id,
            'الجدول لا يبقى باسم من لم يعد مسؤولاً');
    }

    // ═══ حارس التجميد ═══

    public function test_editing_a_frozen_cv_is_announced_before_it_is_written(): void
    {
        [$c, $a, $visitId, $asg, $ev] = $this->assigned();

        $this->actingAs($ev);
        $this->postJson("/api/reception/assignments/{$asg}/accept")->assertOk();
        $this->actingAsRole('OPERATIONS');
        $this->postJson("/api/reception/visits/{$visitId}/approve")->assertOk();
        $this->assertNotNull(Assessment::find($a->id)->cv_snapshot_enc);

        $doc = array_merge($c->fresh()->cv->data, ['currentPosition' => 'منصب جديد']);

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/candidates/{$c->id}/cv", [
            'cv' => $doc, 'expectedVersion' => $c->fresh()->cv->version,
        ])->assertStatus(409)
            ->assertJsonPath('error', 'سيرةُ هذه الدورة مجمَّدة — التعديل لا يصل المستشار');

        // ولا يُمنع — للدورة القادمة تُكتب السيرة حيّةً
        $this->putJson("/api/candidates/{$c->id}/cv", [
            'cv' => $doc, 'expectedVersion' => $c->fresh()->cv->version,
            'acknowledgeFrozen' => true,
        ])->assertOk();

        $this->assertSame('منصب جديد', $c->fresh()->cv->data['currentPosition']);
        // واللقطة لا تتبعه
        $this->assertNotSame('منصب جديد', Assessment::find($a->id)->cv_snapshot['currentPosition'] ?? null);
    }

    public function test_an_unfrozen_cv_is_edited_without_a_warning(): void
    {
        [$c] = $this->makeCandidate();
        $this->giveCv($c);
        $doc = array_merge($c->cv->data, ['currentPosition' => 'بلا تجميد']);

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/candidates/{$c->id}/cv", [
            'cv' => $doc, 'expectedVersion' => $c->cv->version,
        ])->assertOk();
    }
}
