<?php

namespace Tests\Feature;

use App\Models\ChatThread;
use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ثوابت المحادثة (منع انتحال نوع الرسالة + المحادثة المغلقة) وحاجز منفذ اختبار LDAP (SSRF)
class ChatSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function reportWithThread(): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'status' => 'draft', 'created_by' => null,
        ]);
        // GET ينشئ المحادثة
        $threadId = $this->getJson("/api/chat/report/{$report->id}")->assertOk()->json('threadId');
        return [$report, $threadId];
    }

    public function test_send_forces_comment_type_and_ignores_spoofed_fields(): void
    {
        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_VIEW
        [$report, $threadId] = $this->reportWithThread();

        // محاولة انتحال رسالة نظام/إجراء — يجب أن تُحفظ كـ comment/null
        $this->postJson("/api/chat/{$threadId}/message", [
            'message' => 'تعليق', 'message_type' => 'system', 'action_type' => 'approve',
        ])->assertCreated();

        $msg = $this->getJson("/api/chat/report/{$report->id}")->assertOk()->json('messages.0');
        $this->assertSame('comment', $msg['messageType']);
        $this->assertNull($msg['actionType']);
    }

    public function test_send_rejected_on_closed_thread(): void
    {
        $this->actingAsRole('ASSESS_MANAGER');
        [, $threadId] = $this->reportWithThread();
        ChatThread::where('id', $threadId)->update(['is_closed' => true]);

        $this->postJson("/api/chat/{$threadId}/message", ['message' => 'مغلقة'])->assertStatus(400);
    }

    public function test_thread_unsupported_entity_is_422(): void
    {
        $this->actingAsRole('ASSESS_MANAGER');
        // نوع كيان غير مدعوم — لا محادثات يتيمة لمدخلات عشوائية
        $this->getJson('/api/chat/candidate/1')->assertStatus(422);
    }

    public function test_send_without_report_view_is_403(): void
    {
        // أنشئ المحادثة كمخوّل، ثم حاول الإرسال كغير مخوّل
        $this->actingAsRole('ASSESS_MANAGER');
        [, $threadId] = $this->reportWithThread();

        $this->actingAsRole('EXTERNAL_ADD'); // لا REPORT_VIEW
        $this->postJson("/api/chat/{$threadId}/message", ['message' => 'x'])->assertStatus(403);
    }

    public function test_testldap_rejects_nonstandard_port(): void
    {
        $this->actingAsRole('ADMIN'); // SETTINGS_MANAGE
        // منفذ خارج منافذ LDAP القياسية → يمنع استخدام الاختبار كماسح منافذ (SSRF)
        $this->postJson('/api/settings/ldap/test', [
            'host' => '10.0.0.5', 'port' => 8080, 'useSsl' => false,
        ])->assertStatus(422);
    }

    public function test_testldap_accepts_standard_port(): void
    {
        $this->actingAsRole('ADMIN');
        // 389 مقبول شكلياً — الرد 200 يحمل نتيجة الاتصال (ينجح/يفشل) لا 422
        $this->postJson('/api/settings/ldap/test', [
            'host' => '127.0.0.1', 'port' => 389, 'useSsl' => false,
        ])->assertOk()->assertJsonStructure(['success', 'message']);
    }

    public function test_saveldap_requires_settings_manage(): void
    {
        $this->actingAsRole('SCHEDULER'); // لا SETTINGS_MANAGE
        $this->putJson('/api/settings/ldap', ['enabled' => false])->assertStatus(403);
    }
}
