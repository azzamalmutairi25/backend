<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// إعدادات بوّابة الرسائل — التخزين، تشفير المفتاح، الصلاحية، والإرسال الفعلي
class SmsGatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function validPayload(array $over = []): array
    {
        return array_merge([
            'enabled' => true,
            'url' => 'https://api.provider.com/send',
            'apiKey' => 'SECRET-KEY-123',
            'senderId' => 'Kafaat',
            'supportPhone' => '0112345678',
        ], $over);
    }

    // ── الصلاحية ──

    public function test_all_sms_settings_routes_require_settings_manage(): void
    {
        $this->actingAsRole('EVALUATOR'); // لا يملك SETTINGS_MANAGE
        $this->getJson('/api/settings/sms')->assertStatus(403);
        $this->putJson('/api/settings/sms', $this->validPayload())->assertStatus(403);
        $this->postJson('/api/settings/sms/test', ['mobile' => '0501234567'])->assertStatus(403);
    }

    // ── الحفظ والتشفير ──

    public function test_save_encrypts_the_api_key_at_rest(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $raw = DB::table('settings')->where('key', 'sms.key')->value('value');
        $this->assertNotSame('SECRET-KEY-123', $raw, 'المفتاح ليس واضحاً في القاعدة');
        $this->assertStringNotContainsString('SECRET-KEY-123', $raw);
        $this->assertSame('SECRET-KEY-123', Crypt::decryptString($raw), 'ويُفكّ صحيحاً');

        // والخدمة تقرأه مفكوكاً
        $this->assertSame('SECRET-KEY-123', CommunicationService::gatewayConfig()['key']);
    }

    public function test_get_never_returns_the_api_key(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $res = $this->getJson('/api/settings/sms')->assertOk();
        $res->assertJsonPath('sms.keySet', true);
        $res->assertJsonPath('sms.url', 'https://api.provider.com/send');
        $this->assertStringNotContainsString('SECRET-KEY-123', $res->getContent(), 'المفتاح لا يُسرَّب أبداً');
        $this->assertArrayNotHasKey('apiKey', $res->json('sms'));
        $this->assertArrayNotHasKey('key', $res->json('sms'));
    }

    public function test_empty_key_on_update_keeps_the_existing_one(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        // الواجهة لا تملك المفتاح لتعيده — تحديث بلا مفتاح يجب ألا يمحوه
        $this->putJson('/api/settings/sms', $this->validPayload([
            'apiKey' => '', 'senderId' => 'Tamkeen',
        ]))->assertOk();

        $g = CommunicationService::gatewayConfig();
        $this->assertSame('SECRET-KEY-123', $g['key'], 'المفتاح القديم باقٍ');
        $this->assertSame('Tamkeen', $g['sender'], 'وبقية الحقول تحدّثت');
    }

    public function test_enabling_without_any_key_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload(['apiKey' => '']))
            ->assertStatus(422)
            ->assertJsonPath('errors.apiKey.0', 'مفتاح البوّابة مطلوب عند التفعيل');
    }

    // ── التحقّق ──

    public function test_non_https_url_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        foreach (['http://api.provider.com/send', 'file:///etc/passwd', 'gopher://x'] as $bad) {
            $this->putJson('/api/settings/sms', $this->validPayload(['url' => $bad]))
                ->assertStatus(422);
        }
    }

    public function test_url_and_sender_required_when_enabled(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload(['url' => null]))->assertStatus(422);
        $this->putJson('/api/settings/sms', $this->validPayload(['senderId' => null]))->assertStatus(422);
    }

    public function test_disabling_does_not_require_url_or_key(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', [
            'enabled' => false, 'url' => null, 'apiKey' => '', 'senderId' => null,
        ])->assertOk();

        $this->assertFalse(CommunicationService::gatewayConfig()['enabled']);
    }

    // ── الإرسال يستعمل الإعدادات المحفوظة ──

    public function test_send_uses_saved_gateway_settings(): void
    {
        Http::fake(['api.provider.com/*' => Http::response('OK-REF-9', 200)]);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $ok = app(CommunicationService::class)->sendSms('0501234567', 'نص', 'notification', null, null);
        $this->assertTrue($ok);

        Http::assertSent(function ($req) {
            return $req->url() === 'https://api.provider.com/send'
                && $req['appSid'] === 'SECRET-KEY-123'
                && $req['sender'] === 'Kafaat'
                && $req['recipient'] === '0501234567';
        });

        $log = SmsLog::latest('id')->first();
        $this->assertSame('sent', $log->status);
        $this->assertSame('OK-REF-9', $log->provider_ref);
    }

    public function test_disabled_gateway_falls_back_to_dev_mode_without_calling_out(): void
    {
        Http::fake();
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload(['enabled' => false]))->assertOk();

        $ok = app(CommunicationService::class)->sendSms('0501234567', 'نص', 'notification', null, null);

        $this->assertTrue($ok, 'وضع التطوير: نجاح صامت');
        Http::assertNothingSent();
        $this->assertStringContainsString('وضع التطوير', SmsLog::latest('id')->first()->error_message);
    }

    public function test_provider_failure_marks_log_failed_and_returns_false(): void
    {
        Http::fake(['api.provider.com/*' => Http::response('denied', 401)]);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $ok = app(CommunicationService::class)->sendSms('0501234567', 'نص', 'notification', null, null);

        $this->assertFalse($ok);
        $log = SmsLog::latest('id')->first();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('401', $log->error_message);
    }

    public function test_5xx_gateway_error_is_retryable_and_throws(): void
    {
        // عطل مؤقّت في البوّابة (5xx) = عابر: يوسم failed ثم يرمي ليعيد الطابور المحاولة.
        Http::fake(['api.provider.com/*' => Http::response('overloaded', 503)]);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $log = SmsLog::create([
            'to_mobile' => '0501234567', 'message' => 'نص', 'sms_type' => 'notification',
            'status' => 'pending', 'created_by' => null,
        ]);

        $threw = false;
        try {
            app(CommunicationService::class)->deliverPendingSms($log);
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, '5xx يجب أن يرمي ليعيد الطابور المحاولة');
        $this->assertSame('failed', $log->fresh()->status);
    }

    public function test_4xx_gateway_error_is_permanent_and_does_not_throw(): void
    {
        // طلب خاطئ (4xx) = دائم: يوسم failed ويُرجع false بلا رمي (لا جدوى من الإعادة).
        Http::fake(['api.provider.com/*' => Http::response('bad request', 400)]);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $log = SmsLog::create([
            'to_mobile' => '0501234567', 'message' => 'نص', 'sms_type' => 'notification',
            'status' => 'pending', 'created_by' => null,
        ]);

        $result = app(CommunicationService::class)->deliverPendingSms($log);

        $this->assertFalse($result, '4xx يجب ألا يرمي (لا إعادة محاولة)');
        $this->assertSame('failed', $log->fresh()->status);
    }

    public function test_corrupt_key_fails_loudly_instead_of_faking_success(): void
    {
        Http::fake();
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        // قيمة لا تُفكّ (مفتاح تطبيق تغيّر مثلاً — يحدث عند التبديل لموقع التعافي)
        Setting::updateOrCreate(['key' => 'sms.key'], ['value' => 'not-a-valid-ciphertext']);

        // بوّابة مُعَدّة لكن مفتاحها لا يُفكّ: فشل حقيقي لا وضع تطوير.
        $cfg = CommunicationService::gatewayConfig();
        $this->assertSame('', $cfg['key']);
        $this->assertTrue($cfg['key_error'], 'يجب تمييز فشل فكّ التشفير عن «غير مُعَدّ»');

        $ok = app(CommunicationService::class)->sendSms('0501234567', 'نص', 'notification', null, null);

        // لا يُرسل بمفتاح خاطئ، ولا يُبلّغ «أُرسلت» زوراً بينما لا يصل المرشّح رابطه.
        $this->assertFalse($ok, 'مفتاح تالف يجب أن يُخفق لا أن ينجح صامتاً');
        Http::assertNothingSent();
        $log = SmsLog::latest('id')->first();
        $this->assertSame('failed', $log->status);
        $this->assertStringNotContainsString('وضع التطوير', (string) $log->error_message);
    }

    // ── الرجوع لمتغيّرات البيئة (تنصيب سابق لصفحة الإعدادات) ──

    public function test_falls_back_to_env_when_no_settings_rows_exist(): void
    {
        config(['services.sms.url' => 'https://env.provider.com/s', 'services.sms.key' => 'ENV-KEY']);

        $g = CommunicationService::gatewayConfig();
        $this->assertTrue($g['enabled'], 'إعداد بيئة مكتمل => مفعّل ضمناً');
        $this->assertSame('https://env.provider.com/s', $g['url']);
        $this->assertSame('ENV-KEY', $g['key']);
    }

    public function test_settings_take_precedence_over_env(): void
    {
        config(['services.sms.url' => 'https://env.provider.com/s', 'services.sms.key' => 'ENV-KEY']);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $g = CommunicationService::gatewayConfig();
        $this->assertSame('https://api.provider.com/send', $g['url']);
        $this->assertSame('SECRET-KEY-123', $g['key']);
    }

    // ── الاختبار ──

    public function test_test_endpoint_validates_mobile_format(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/settings/sms/test', ['mobile' => '123'])->assertStatus(422);
        $this->postJson('/api/settings/sms/test', ['mobile' => '0601234567'])->assertStatus(422);
    }

    public function test_test_endpoint_refuses_when_gateway_incomplete(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload(['enabled' => false]))->assertOk();

        $this->postJson('/api/settings/sms/test', ['mobile' => '0501234567'])
            ->assertOk()
            ->assertJsonPath('success', false);
    }

    public function test_test_endpoint_sends_and_is_audited(): void
    {
        Http::fake(['api.provider.com/*' => Http::response('OK', 200)]);
        $u = $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $this->postJson('/api/settings/sms/test', ['mobile' => '0501234567'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'TEST_SMS', 'user_id' => $u->id]);
    }

    public function test_audit_records_the_save_without_leaking_the_key(): void
    {
        $u = $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/sms', $this->validPayload())->assertOk();

        $row = DB::table('audit_logs')->where('action', 'UPDATE_SMS_SETTINGS')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('SECRET-KEY-123', $row->details, 'المفتاح لا يُسجَّل في التدقيق');
        $this->assertStringContainsString('keyReplaced', $row->details);
    }
}
