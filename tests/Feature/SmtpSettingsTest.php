<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// إعدادات خادم البريد — التخزين، تشفير كلمة المرور، الصلاحية، وتركيب المُرسِل
class SmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function payload(array $over = []): array
    {
        return array_merge([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@example.com',
            'password' => 'SECRET-PASS-123',
            'fromAddress' => 'no-reply@example.com',
            'fromName' => 'مركز تمكين الكفاءات',
        ], $over);
    }

    // ── الصلاحية ──

    public function test_all_smtp_routes_require_settings_manage(): void
    {
        $this->actingAsRole('EVALUATOR');
        $this->getJson('/api/settings/smtp')->assertStatus(403);
        $this->putJson('/api/settings/smtp', $this->payload())->assertStatus(403);
        $this->postJson('/api/settings/smtp/test', ['email' => 'a@b.com'])->assertStatus(403);
    }

    // ── التخزين والتشفير ──

    public function test_save_encrypts_the_password_at_rest(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload())->assertOk();

        $raw = DB::table('settings')->where('key', 'smtp.password')->value('value');
        $this->assertStringNotContainsString('SECRET-PASS-123', $raw, 'ليست واضحة في القاعدة');
        $this->assertSame('SECRET-PASS-123', Crypt::decryptString($raw), 'وتُفكّ صحيحة');
        $this->assertSame('SECRET-PASS-123', CommunicationService::smtpConfig()['password']);
    }

    public function test_get_never_returns_the_password(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload())->assertOk();

        $res = $this->getJson('/api/settings/smtp')->assertOk();
        $res->assertJsonPath('smtp.passwordSet', true);
        $res->assertJsonPath('smtp.host', 'smtp.example.com');
        $res->assertJsonPath('smtp.port', 587);
        $this->assertStringNotContainsString('SECRET-PASS-123', $res->getContent());
        $this->assertArrayNotHasKey('password', $res->json('smtp'));
    }

    public function test_empty_password_on_update_keeps_the_existing_one(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload())->assertOk();

        $this->putJson('/api/settings/smtp', $this->payload([
            'password' => '', 'host' => 'smtp2.example.com',
        ]))->assertOk();

        $c = CommunicationService::smtpConfig();
        $this->assertSame('SECRET-PASS-123', $c['password'], 'كلمة المرور باقية');
        $this->assertSame('smtp2.example.com', $c['host'], 'وبقية الحقول تحدّثت');
    }

    // ── التحقّق ──

    public function test_required_fields_when_enabled(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload(['host' => null]))->assertStatus(422);
        $this->putJson('/api/settings/smtp', $this->payload(['port' => null]))->assertStatus(422);
        $this->putJson('/api/settings/smtp', $this->payload(['fromAddress' => null]))->assertStatus(422);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload(['encryption' => 'rot13']))->assertStatus(422);
        $this->putJson('/api/settings/smtp', $this->payload(['fromAddress' => 'not-an-email']))->assertStatus(422);
        $this->putJson('/api/settings/smtp', $this->payload(['port' => 70000]))->assertStatus(422);
        $this->putJson('/api/settings/smtp', $this->payload(['port' => 0]))->assertStatus(422);
    }

    public function test_disabling_does_not_require_the_rest(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', [
            'enabled' => false, 'host' => null, 'port' => null,
            'encryption' => null, 'password' => '', 'fromAddress' => null,
        ])->assertOk();

        $this->assertFalse(CommunicationService::smtpConfig()['enabled']);
    }

    // ── تركيب المُرسِل ──

    public function test_saved_settings_configure_the_mailer_on_send(): void
    {
        Mail::fake();
        $this->actingAsRole('ADMIN');
        [$c] = $this->makeCandidate();
        $this->putJson('/api/settings/smtp', $this->payload())->assertOk();

        app(CommunicationService::class)
            ->sendEmail('x@example.com', 'اسم', 'موضوع', 'نص', 'notification', $c->id, null);

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'), 'tls => STARTTLS على مخطّط smtp');
        $this->assertSame('SECRET-PASS-123', config('mail.mailers.smtp.password'));
        $this->assertSame('no-reply@example.com', config('mail.from.address'));
        $this->assertNotNull(config('mail.mailers.smtp.timeout'), 'مهلة مضبوطة');
    }

    public function test_ssl_maps_to_the_implicit_tls_scheme(): void
    {
        Mail::fake();
        $this->actingAsRole('ADMIN');
        [$c] = $this->makeCandidate();
        $this->putJson('/api/settings/smtp', $this->payload(['encryption' => 'ssl', 'port' => 465]))->assertOk();

        app(CommunicationService::class)
            ->sendEmail('x@example.com', null, 'م', 'ن', 'notification', $c->id, null);

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_disabled_smtp_leaves_the_default_mailer_untouched(): void
    {
        Mail::fake();
        $default = config('mail.default');
        $this->actingAsRole('ADMIN');
        [$c] = $this->makeCandidate();
        $this->putJson('/api/settings/smtp', $this->payload(['enabled' => false]))->assertOk();

        app(CommunicationService::class)
            ->sendEmail('x@example.com', null, 'م', 'ن', 'notification', $c->id, null);

        $this->assertSame($default, config('mail.default'), 'لا تتدخّل عند التعطيل');
    }

    public function test_corrupt_password_disables_rather_than_authenticating_wrongly(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload())->assertOk();
        Setting::updateOrCreate(['key' => 'smtp.password'], ['value' => 'not-a-valid-ciphertext']);

        $this->assertSame('', CommunicationService::smtpConfig()['password']);
    }

    // ── الاختبار ──

    public function test_test_endpoint_refuses_when_smtp_incomplete(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload(['enabled' => false]))->assertOk();

        $this->postJson('/api/settings/smtp/test', ['email' => 'a@b.com'])
            ->assertOk()->assertJsonPath('success', false);
    }

    public function test_test_endpoint_validates_the_address(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/settings/smtp/test', ['email' => 'nope'])->assertStatus(422);
        $this->postJson('/api/settings/smtp/test', [])->assertStatus(422);
    }

    public function test_test_endpoint_sends_and_is_audited(): void
    {
        Mail::fake();
        $u = $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload())->assertOk();

        $this->postJson('/api/settings/smtp/test', ['email' => 'check@example.com'])
            ->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'TEST_SMTP', 'user_id' => $u->id]);
        $this->assertSame('check@example.com', \App\Models\EmailLog::latest('id')->first()->to_email);
    }

    public function test_audit_records_the_save_without_leaking_the_password(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/smtp', $this->payload())->assertOk();

        $row = DB::table('audit_logs')->where('action', 'UPDATE_SMTP_SETTINGS')->latest('id')->first();
        $this->assertStringNotContainsString('SECRET-PASS-123', $row->details);
        $this->assertStringContainsString('passwordReplaced', $row->details);
    }
}
