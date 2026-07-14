<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Services\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// إرسال الدعوة بالبريد — المسار الذي كان يرجّع 500 لكل دعوة (to_name صار NOT NULL بالغلط)
class InvitationEmailTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_invite_endpoint_sends_email_and_returns_ok(): void
    {
        Mail::fake();
        $this->actingAsRole('SCHEDULER'); // SEND_INVITATION
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'fullName' => 'سعد المطيري']);
        $c->email = 'cand@example.com';
        $c->save();

        $this->postJson('/api/communications/invite', [
            'candidateId' => $c->id,
            'sendEmail' => true,
            'sendSms' => false,
            'date' => '2026-08-01', 'time' => '10:00', 'location' => 'الرياض',
        ])->assertOk();

        $log = EmailLog::latest('id')->first();
        $this->assertNotNull($log, 'سجل البريد مكتوب');
        $this->assertSame('sent', $log->status);
        $this->assertSame('cand@example.com', $log->to_email);
        $this->assertSame('سعد المطيري', $log->to_name, 'اسم المرشح يُمرَّر لا null');
        $this->assertSame('invitation', $log->email_type);
        $this->assertStringContainsString('2026-08-01', $log->body, 'متغيّرات القالب مُستبدَلة');
        $this->assertStringContainsString('الرياض', $log->body);
        // status=sent هو البرهان أن Mail::raw مرّ بلا استثناء —
        // MailFake::raw دالة فارغة فلا يصلح Mail::assertSentCount هنا
    }

    // الخدمة تقبل اسماً فارغاً: العمود nullable ولا يجوز أن ينفجر
    public function test_send_email_accepts_null_name(): void
    {
        Mail::fake();
        $this->actingAsRole('SCHEDULER');
        [$c] = $this->makeCandidate();

        $ok = app(CommunicationService::class)
            ->sendEmail('x@example.com', null, 'موضوع', 'نص', 'invitation', $c->id, null);

        $this->assertTrue($ok);
        $this->assertNull(EmailLog::latest('id')->first()->to_name);
    }

    // فشل كتابة السجل يهبط بلطف إلى false — لا استثناء يتسرّب لطرف النداء (مثل sendSms)
    public function test_email_log_write_failure_degrades_to_false(): void
    {
        Mail::fake();
        $this->actingAsRole('SCHEDULER');
        [$c] = $this->makeCandidate();

        // نوع يخالف قيد CHECK في القاعدة
        $ok = app(CommunicationService::class)
            ->sendEmail('x@example.com', 'اسم', 'موضوع', 'نص', 'BAD_TYPE', $c->id, null);

        // لا استعلام بعدها: Postgres يجهض المعاملة بعد إدراج فاشل
        $this->assertFalse($ok, 'فشل السجل => false، بلا 500');
    }

    public function test_email_pii_is_encrypted_at_rest(): void
    {
        Mail::fake();
        $this->actingAsRole('SCHEDULER');
        [$c] = $this->makeCandidate();

        app(CommunicationService::class)
            ->sendEmail('secret@example.com', 'اسم سرّي', 'موضوع', 'نص', 'invitation', $c->id, null);

        $raw = \Illuminate\Support\Facades\DB::table('email_logs')->latest('id')->first();
        $this->assertStringNotContainsString('secret@example.com', $raw->to_email);
        $this->assertStringNotContainsString('اسم سرّي', $raw->to_name);
    }
}
