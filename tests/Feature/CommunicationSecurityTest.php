<?php

namespace Tests\Feature;

use App\Models\SmsLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunicationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function smsFor(int $candidateId): SmsLog
    {
        $s = new SmsLog();
        $s->to_mobile = '0501234567';
        $s->message = 'عزيزي مرشح، رمزك: ED-001. للتأكيد: http://x/confirm/SECRETTOKEN12345678901234567890';
        $s->sms_type = 'invitation';
        $s->candidate_id = $candidateId;
        $s->status = 'sent';
        $s->save();
        return $s;
    }

    public function test_sms_log_pii_is_encrypted_at_rest(): void
    {
        [$c] = $this->makeCandidate();
        $s = $this->smsFor($c->id);
        $rawMsg = DB::table('sms_logs')->where('id', $s->id)->value('message');
        $rawMob = DB::table('sms_logs')->where('id', $s->id)->value('to_mobile');

        $this->assertStringNotContainsString('ED-001', $rawMsg);       // encrypted
        $this->assertStringNotContainsString('0501234567', $rawMob);   // encrypted
        $this->assertStringContainsString('ED-001', $s->fresh()->message); // model decrypts
    }

    public function test_history_requires_candidate_view(): void
    {
        [$c] = $this->makeCandidate();
        $this->smsFor($c->id);
        $this->actingAsRole('EXTERNAL_ADD'); // no CANDIDATE_VIEW

        $this->getJson("/api/communications/history/{$c->id}")->assertStatus(403);
    }

    public function test_history_hides_content_without_view_names_and_always_redacts_confirm_link(): void
    {
        [$c] = $this->makeCandidate();
        $this->smsFor($c->id);

        // CENTER_MANAGER: CANDIDATE_VIEW but not VIEW_NAMES -> message null
        $this->actingAsRole('CENTER_MANAGER');
        $res = $this->getJson("/api/communications/history/{$c->id}")->assertOk();
        $this->assertNull($res->json('sms.0.message'));

        // SCHEDULER: has VIEW_NAMES -> message shown but confirm link redacted
        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson("/api/communications/history/{$c->id}")->assertOk();
        $msg = $res->json('sms.0.message');
        $this->assertNotNull($msg);
        $this->assertStringNotContainsString('/confirm/', $msg);
    }

    public function test_history_is_404_for_classified_without_clearance(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'secret']);
        $this->smsFor($c->id);
        $this->actingAsRole('SCHEDULER'); // VIEW + VIEW_NAMES, no VIEW_CLASSIFIED

        $this->getJson("/api/communications/history/{$c->id}")->assertStatus(404);
    }

    public function test_invite_is_404_for_classified_without_clearance(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'top_secret']);
        $this->actingAsRole('SCHEDULER'); // has SEND_INVITATION, no VIEW_CLASSIFIED

        $this->postJson('/api/communications/invite', [
            'candidateId' => $c->id, 'date' => '2026-08-01', 'time' => '10:00', 'location' => 'قاعة',
            'sendSms' => true,
        ])->assertStatus(404);
    }
}
