<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PostponementRequest;
use App\Models\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  طلب التأجيل — الاستقبال يرفع، ومسؤول الجدولة يبتّ.
//
//  الفترة المعتمَدة مقفلةٌ على الاستقبال، ومن يفكّها صاحبها. والاستقبال هو
//  من يرى المانع بعينه فيرفعه، ولا يؤجّل بيده — ولو أجّل لصار كلُّ ازدحامٍ
//  في يومٍ تأجيلاً، وضاعت الموجة التي بُنيت واعتُمدت.
// ════════════════════════════════════════════════════════════
class PostponementRequestTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function sessionFor(?string $date = null): Schedule
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);

        return Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => $date ?? now()->toDateString(),
            'schedule_time' => '11:00', 'activity' => 'interview',
        ]);
    }

    private function raise(Schedule $s, string $reason = 'المشارك استُدعي لاجتماع طارئ'): int
    {
        $this->actingAsRole('RECEPTIONIST');

        return $this->postJson('/api/postponements', [
            'scheduleId' => $s->id, 'reason' => $reason,
        ])->assertStatus(201)->json('request.id');
    }

    // ═══ الرفع ═══

    public function test_reception_raises_a_request_with_its_reason(): void
    {
        $s = $this->sessionFor();
        $id = $this->raise($s);

        $req = PostponementRequest::findOrFail($id);
        $this->assertSame('pending', $req->status);
        $this->assertSame('interview', $req->station);
        $this->assertSame($s->candidate_id, $req->candidate_id);
        $this->assertSame('المشارك استُدعي لاجتماع طارئ', $req->reason);
    }

    // الطلب كلُّه سببٌ يُقرأ عند البتّ — وطلبٌ بلا سبب يُحيل القرار لتخمين
    public function test_a_request_without_a_reason_is_refused(): void
    {
        $s = $this->sessionFor();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/postponements', ['scheduleId' => $s->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'اكتب سبب التأجيل — عليه يُبنى قرار مسؤول الجدولة');

        $this->assertSame(0, PostponementRequest::count());
    }

    // طلبان معلّقان يجعلان البتّ في أحدهما لا يعني شيئاً للآخر
    public function test_a_second_pending_request_on_the_same_session_is_refused(): void
    {
        $s = $this->sessionFor();
        $this->raise($s);

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/postponements', ['scheduleId' => $s->id, 'reason' => 'مرّة أخرى'])
            ->assertStatus(422);

        $this->assertSame(1, PostponementRequest::count());
    }

    // والمبتوت لا يمنع طلباً جديداً — الظرف يتغيّر
    public function test_a_decided_request_does_not_block_a_new_one(): void
    {
        $s = $this->sessionFor();
        $id = $this->raise($s);

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", [
            'decision' => 'rejected', 'note' => 'الموعد ثابت',
        ])->assertOk();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/postponements', ['scheduleId' => $s->id, 'reason' => 'طرأ ما هو أهمّ'])
            ->assertStatus(201);
    }

    // ── والاستقبال لا يبتّ ──
    public function test_reception_cannot_decide_its_own_request(): void
    {
        $s = $this->sessionFor();
        $id = $this->raise($s);

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/postponements/{$id}/decide", ['decision' => 'accepted'])
            ->assertStatus(403);

        $this->assertSame('pending', PostponementRequest::find($id)->status);
    }

    // ═══ البتّ ═══

    public function test_rescheduling_creates_the_new_session_in_the_same_decision(): void
    {
        $s = $this->sessionFor();
        $id = $this->raise($s);
        $newDate = now()->addDays(5)->toDateString();

        $this->actingAsRole('SCHEDULER');
        $res = $this->postJson("/api/postponements/{$id}/decide", [
            'decision' => 'rescheduled', 'newDate' => $newDate,
        ])->assertOk();

        $fresh = Schedule::find($res->json('newScheduleId'));
        $this->assertNotNull($fresh, 'لا تُترك خطوةٌ ثانية قد تُنسى');
        $this->assertSame($newDate, $fresh->schedule_date->toDateString());
        $this->assertSame('interview', $fresh->activity);
        $this->assertSame($s->assessment_id, $fresh->assessment_id);
        $this->assertSame('rescheduled', PostponementRequest::find($id)->status);
    }

    // «أُعيدت جدولتها» بلا تاريخ لا تعني شيئاً
    public function test_rescheduling_demands_a_date(): void
    {
        $id = $this->raise($this->sessionFor());

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", ['decision' => 'rescheduled'])
            ->assertStatus(422)
            ->assertJsonPath('errors.newDate.0', 'اكتب التاريخ الجديد — إعادة جدولةٍ بلا تاريخ لا تعني شيئاً');
    }

    public function test_a_past_date_is_refused(): void
    {
        $id = $this->raise($this->sessionFor());

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", [
            'decision' => 'rescheduled', 'newDate' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    // الرفض يلزمه تعليل — من رفع الطلب يعتذر به للمشارك
    public function test_a_rejection_demands_a_note(): void
    {
        $id = $this->raise($this->sessionFor());

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", ['decision' => 'rejected'])
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0', 'اكتب سبب الرفض — من رفع الطلب يعتذر به للمشارك');
    }

    // القبولُ وحده يترك المشارك بلا موعد — وهو حالٌ مقصودة تُميَّز عن الجدولة
    public function test_accepting_leaves_no_new_session(): void
    {
        $s = $this->sessionFor();
        $id = $this->raise($s);
        $before = Schedule::count();

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", ['decision' => 'accepted'])
            ->assertOk()->assertJsonPath('newScheduleId', null);

        $this->assertSame($before, Schedule::count());
        $this->assertSame('قُبل التأجيل', PostponementRequest::find($id)->fresh()
            ? PostponementRequest::statusLabel(PostponementRequest::find($id)->status) : '');
    }

    public function test_a_decided_request_is_not_decided_twice(): void
    {
        $id = $this->raise($this->sessionFor());

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", ['decision' => 'accepted'])->assertOk();
        $this->postJson("/api/postponements/{$id}/decide", ['decision' => 'returned'])
            ->assertStatus(422);
    }

    // ═══ الإشعاران ═══

    public function test_the_decider_is_told_a_request_arrived(): void
    {
        $decider = $this->actingAsRole('SCHEDULER');
        $this->raise($this->sessionFor());

        $this->assertTrue(
            Notification::where('recipient_id', $decider->id)
                ->where('title', 'طلب تأجيل جديد')->exists(),
            'طلبٌ لا يعلم به من يبتّ فيه ينتظر إلى أن يُفتَح السجلّ صدفةً'
        );
    }

    public function test_the_requester_is_told_the_decision(): void
    {
        $clerk = $this->actingAsRole('RECEPTIONIST');
        $s = $this->sessionFor();
        $id = $this->postJson('/api/postponements', [
            'scheduleId' => $s->id, 'reason' => 'ظرف طارئ',
        ])->assertStatus(201)->json('request.id');

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id}/decide", [
            'decision' => 'rejected', 'note' => 'الموجة مقفلة',
        ])->assertOk();

        $n = Notification::where('recipient_id', $clerk->id)
            ->where('entity_type', 'postponement')->first();
        $this->assertNotNull($n, 'هو من يواجه المشارك');
        $this->assertStringContainsString('الموجة مقفلة', $n->body);
    }

    // ═══ القائمة ═══

    public function test_the_list_shows_the_pending_first_and_both_sides_read_it(): void
    {
        $s1 = $this->sessionFor();
        $id1 = $this->raise($s1);
        $id2 = $this->raise($this->sessionFor());

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/postponements/{$id1}/decide", ['decision' => 'accepted'])->assertOk();

        $res = $this->getJson('/api/postponements')->assertOk();
        $ids = collect($res->json('requests'))->pluck('id');

        $this->assertTrue($ids->contains($id2), 'المعلّق يُعرَض');
        $this->assertFalse($ids->contains($id1), 'والمبتوت يُطوى — الشاشة أداةُ بتٍّ لا سجلّ');
        $this->assertTrue($res->json('canDecide'));

        // والاستقبال يقرأ ولا يبتّ
        $this->actingAsRole('RECEPTIONIST');
        $this->assertFalse($this->getJson('/api/postponements')->assertOk()->json('canDecide'));
    }

    public function test_the_list_is_closed_to_others(): void
    {
        $this->actingAsRole('EVALUATOR', 'DW');
        $this->getJson('/api/postponements')->assertStatus(403);
    }

    // ═══ الحارس في القاعدة ═══

    public function test_the_status_vocabulary_is_guarded_in_the_database(): void
    {
        $s = $this->sessionFor();

        $this->expectException(QueryException::class);
        DB::table('postponement_requests')->insert([
            'candidate_id' => $s->candidate_id, 'schedule_id' => $s->id,
            'reason' => 'س', 'status' => 'maybe',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
