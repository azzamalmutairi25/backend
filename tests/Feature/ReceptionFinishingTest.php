<?php

namespace Tests\Feature;

use App\Models\PostponementRequest;
use App\Models\ReceptionVisit;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  إتمام مرحلة الاستقبال — ما بقي من الخريطة.
//
//  ثلاثةٌ صغيرة وأثرُها ليس صغيراً: سجلٌّ يُقرأ بالعربية، وطريقٌ خلفيّ إلى
//  البطاقة أُغلق، ومدّةُ انتظارٍ تُحسب في الخادم لا في رأس الموظّف.
// ════════════════════════════════════════════════════════════
class ReceptionFinishingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAf'
        .'FcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function arrived(bool $sign = true, bool $approveCv = true): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->giveCv($c);

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        if ($sign) {
            $this->postJson("/api/reception/visits/{$visitId}/sign", [
                'signature' => self::PNG, 'attested' => true,
            ])->assertOk();
        }
        if ($approveCv) {
            $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();
        }

        return [$c, $a, $visitId];
    }

    // ═══ الباب الخلفي إلى البطاقة ═══

    // الطباعة الأولى تشترط التوقيع والاعتماد — وإعادةُ الطباعة كانت لا تشترط
    // شيئاً، فتصير طريقاً ثانياً إلى بطاقةٍ لمن لا يستحقّها
    public function test_a_reprint_demands_what_the_first_print_demands(): void
    {
        [, , $unsigned] = $this->arrived(sign: false, approveCv: false);

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$unsigned}/badge-reprint")
            ->assertStatus(422)
            ->assertJsonPath('error', 'لم يوقّع المشارك ولم يُقرّ بصحّة بياناته');

        [, , $noCv] = $this->arrived(sign: true, approveCv: false);
        $this->postJson("/api/reception/visits/{$noCv}/badge-reprint")
            ->assertStatus(422)
            ->assertJsonPath('error', 'اعتمِد السيرة أوّلاً — البطاقة تُطبع بعد المراجعة');

        $this->assertNull(ReceptionVisit::find($noCv)->badge_requested_at);
    }

    public function test_a_complete_visit_may_reprint(): void
    {
        [, , $visitId] = $this->arrived();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/badge-printed")->assertOk();
        $this->postJson("/api/reception/visits/{$visitId}/badge-reprint")->assertOk();

        $v = ReceptionVisit::find($visitId);
        $this->assertTrue($v->badgePending(), 'عادت إلى الطابور');
    }

    // ═══ مدّة الانتظار ═══

    public function test_the_wait_is_measured_on_the_server(): void
    {
        [$c, , $visitId] = $this->arrived();
        ReceptionVisit::whereKey($visitId)->update(['arrived_at' => now()->subMinutes(25)]);

        $this->actingAsRole('RECEPTIONIST');
        $row = collect($this->getJson('/api/reception')->json('visits'))
            ->firstWhere('candidateId', $c->id);

        $this->assertEqualsWithDelta(25, $row['waitedMinutes'], 1,
            'الطرحُ في رأس الموظّف لا يقع في زحمة الردهة');
    }

    // ومن أُرسِل توقّف عدّاده — انتظارُه انتهى
    public function test_the_wait_stops_at_the_send(): void
    {
        [$c, , $visitId] = $this->arrived();
        ReceptionVisit::whereKey($visitId)->update([
            'arrived_at' => now()->subMinutes(90),
            'sent_at' => now()->subMinutes(60),
        ]);

        $this->actingAsRole('RECEPTIONIST');
        $row = collect($this->getJson('/api/reception')->json('visits'))
            ->firstWhere('candidateId', $c->id);

        $this->assertEqualsWithDelta(30, $row['waitedMinutes'], 1);
    }

    // ═══ التأجيل في سير الأحداث ═══

    // حدثان لا واحد: عرضُ البتّ وحده يُخفي من طلب ولماذا — وهو نصف القصّة
    public function test_the_timeline_carries_both_the_request_and_the_decision(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00',
            'activity' => 'interview',
        ]);

        $this->actingAsRole('RECEPTIONIST');
        $id = $this->postJson('/api/postponements', [
            'scheduleId' => $s->id, 'reason' => 'استُدعي لمهمة رسمية',
        ])->assertStatus(201)->json('request.id');

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", [
            'decision' => 'rescheduled', 'newDate' => now()->addDays(4)->toDateString(),
        ])->assertOk();

        $this->actingAsRole('ADMIN');
        $events = collect($this->getJson("/api/candidates/{$c->id}/journey")->json('journey'));

        $raised = $events->firstWhere('type', 'postponement');
        $this->assertSame('رُفع طلب تأجيل: المقابلة الشخصية', $raised['title']);
        $this->assertSame('استُدعي لمهمة رسمية', $raised['meta'], 'السبب هو نصف القصّة');

        $decided = $events->firstWhere('type', 'postponement_decided');
        $this->assertSame('أُعيدت جدولتها', $decided['title']);
        $this->assertSame(now()->addDays(4)->toDateString(), $decided['meta']);
    }

    public function test_a_rejection_carries_its_note_into_the_timeline(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00',
            'activity' => 'discussion',
        ]);

        $this->actingAsRole('RECEPTIONIST');
        $id = $this->postJson('/api/postponements', ['scheduleId' => $s->id, 'reason' => 'ظرف'])
            ->assertStatus(201)->json('request.id');
        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", [
            'decision' => 'rejected', 'note' => 'الموجة مقفلة ولا بديل',
        ])->assertOk();

        $this->actingAsRole('ADMIN');
        $ev = collect($this->getJson("/api/candidates/{$c->id}/journey")->json('journey'))
            ->firstWhere('type', 'postponement_decided');

        $this->assertSame('رُفض الطلب', $ev['title']);
        $this->assertSame('الموجة مقفلة ولا بديل', $ev['meta']);
        $this->assertSame(PostponementRequest::REJECTED, $ev['status']);
    }

    // ═══ سجلُّ التدقيق يُقرأ ═══

    // سطرُ تدقيقٍ يقول «RECEPTION_CV_APPROVE» لا يُقرأ، وسجلٌّ لا يُقرأ لا يُراجَع
    public function test_every_reception_action_reads_in_arabic(): void
    {
        [, , $visitId] = $this->arrived();
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/reception/visits/{$visitId}/badge-printed")->assertOk();

        $this->actingAsRole('ADMIN');
        $rows = collect($this->getJson('/api/audit/log')->assertOk()->json('logs'));

        $raw = $rows->filter(fn ($r) => preg_match('/^[A-Z_]+$/', (string) ($r['actionLabel'] ?? '')))
            ->pluck('actionLabel')->unique()->values();

        $this->assertSame([], $raw->all(), 'أفعالٌ تظهر برموزها الخام: '.$raw->implode('، '));
    }
}
