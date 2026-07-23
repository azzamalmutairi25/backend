<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\IdentityVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// إعدادات بوّابة التحقق من الهوية — التخزين، تشفير المفتاح، الصلاحية، والاختبار
class IdVerifySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function validPayload(array $over = []): array
    {
        return array_merge([
            'enabled' => true,
            'url' => 'https://idp.example.com/verify',
            'apiKey' => 'SECRET-IDV-KEY',
            'appId' => 'kafaat-app',
        ], $over);
    }

    public function test_all_routes_require_settings_manage(): void
    {
        $this->actingAsRole('SCHEDULER'); // لا يملك SETTINGS_MANAGE
        $this->getJson('/api/settings/idverify')->assertStatus(403);
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertStatus(403);
        $this->postJson('/api/settings/idverify/test', ['nationalId' => '1234567890'])->assertStatus(403);
    }

    public function test_save_then_get_never_returns_the_key(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertOk();

        $res = $this->getJson('/api/settings/idverify')->assertOk();
        $res->assertJsonPath('idVerify.url', 'https://idp.example.com/verify');
        $res->assertJsonPath('idVerify.appId', 'kafaat-app');
        $res->assertJsonPath('idVerify.keySet', true);
        $this->assertArrayNotHasKey('key', $res->json('idVerify'));
        $this->assertStringNotContainsString('SECRET-IDV-KEY', $res->getContent());
    }

    public function test_key_is_stored_encrypted_at_rest(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertOk();

        $stored = Setting::where('key', 'idverify.key')->value('value');
        $this->assertNotSame('SECRET-IDV-KEY', $stored, 'المفتاح لا يُخزَّن نصّاً');
        $this->assertSame('SECRET-IDV-KEY', Crypt::decryptString($stored));
    }

    public function test_empty_key_on_update_keeps_the_existing_one(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertOk();
        // حفظ ثانٍ بلا مفتاح — يجب أن يبقى المفتاح الأول
        $this->putJson('/api/settings/idverify', $this->validPayload(['apiKey' => '']))->assertOk();
        $this->assertSame('SECRET-IDV-KEY', IdentityVerificationService::config()['key']);
    }

    public function test_enabling_without_any_key_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload(['apiKey' => '']))
            ->assertStatus(422);
    }

    public function test_non_https_url_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');
        foreach (['http://idp.example.com/v', 'file:///etc/passwd', 'gopher://x'] as $bad) {
            $this->putJson('/api/settings/idverify', $this->validPayload(['url' => $bad]))
                ->assertStatus(422);
        }
    }

    public function test_test_endpoint_validates_national_id_format(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/settings/idverify/test', ['nationalId' => 'abc'])->assertStatus(422);
        $this->postJson('/api/settings/idverify/test', ['nationalId' => '9990000000'])->assertStatus(422); // لا يبدأ بـ1/2
    }

    public function test_test_endpoint_refuses_when_gateway_incomplete(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/settings/idverify/test', ['nationalId' => '1234567890'])
            ->assertOk()->assertJsonPath('success', false);
    }

    public function test_verify_calls_gateway_and_maps_matched(): void
    {
        Http::fake(['idp.example.com/*' => Http::response(['matched' => true], 200)]);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertOk();

        $this->postJson('/api/settings/idverify/test', ['nationalId' => '1234567890'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('matched', true);

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer SECRET-IDV-KEY')
            && str_contains($req->url(), 'idp.example.com'));
    }

    public function test_corrupt_key_fails_loudly_not_dev_mode(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertOk();
        Setting::updateOrCreate(['key' => 'idverify.key'], ['value' => 'not-a-valid-ciphertext']);

        $cfg = IdentityVerificationService::config();
        $this->assertTrue($cfg['key_error']);
        $result = app(IdentityVerificationService::class)->verify('1234567890');
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['devMode']);
    }

    public function test_yakeen_provider_uses_its_own_request_shape(): void
    {
        Http::fake(['idp.example.com/*' => Http::response(['data' => ['firstName' => 'محمد']], 200)]);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload(['provider' => 'yakeen']))->assertOk();

        $result = app(IdentityVerificationService::class)->verify('1234567890');
        $this->assertTrue($result['matched'], 'وجود بيانات الشخص = تطابق في يقين');
        Http::assertSent(fn ($req) => isset($req->data()['nin']) && $req->data()['nin'] === '1234567890');
    }

    public function test_invalid_provider_falls_back_to_generic(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload(['provider' => 'not-a-provider']))
            ->assertStatus(422);
    }

    public function test_verify_and_log_writes_an_audit_row(): void
    {
        Http::fake(['idp.example.com/*' => Http::response(['matched' => true], 200)]);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertOk();

        app(IdentityVerificationService::class)->verifyAndLog('1234567890', null, null);

        $this->assertDatabaseHas('identity_verifications', ['status' => 'matched', 'provider' => 'generic']);
    }

    public function test_log_endpoint_lists_recent_attempts(): void
    {
        Http::fake(['idp.example.com/*' => Http::response(['matched' => false], 200)]);
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertOk();
        app(IdentityVerificationService::class)->verifyAndLog('1234567890', null, null);

        $this->getJson('/api/settings/idverify/log')
            ->assertOk()
            ->assertJsonPath('log.0.status', 'not_matched');
    }

    public function test_candidate_creation_records_verification_when_configured(): void
    {
        Http::fake(['idp.example.com/*' => Http::response(['matched' => true], 200)]);
        $this->actingAsRole('ADMIN'); // يملك SETTINGS_MANAGE و candidate.create
        $this->putJson('/api/settings/idverify', $this->validPayload())->assertOk();

        $sector = \App\Models\Sector::where('code', 'ED')->value('id');
        $res = $this->postJson('/api/candidates', [
            'nationalId' => $this->validNationalId(), 'fullName' => 'مرشح', 'mobile' => '0501112223',
            'sectorId' => $sector, 'rankLabel' => 'مدير عام',
        ]);
        $res->assertStatus(201)->assertJsonPath('idVerification.status', 'matched');
        $this->assertDatabaseHas('identity_verifications', ['status' => 'matched']);
    }

    public function test_candidate_creation_unaffected_when_gateway_not_configured(): void
    {
        // بلا إعداد: idVerification = null ولا سجلّ (لا أثر على التدفّق)
        $this->actingAsRole('ADMIN');
        $sector = \App\Models\Sector::where('code', 'ED')->value('id');
        $this->postJson('/api/candidates', [
            'nationalId' => $this->validNationalId(), 'fullName' => 'مرشح', 'mobile' => '0501112223',
            'sectorId' => $sector, 'rankLabel' => 'مدير عام',
        ])->assertStatus(201)->assertJsonPath('idVerification', null);

        $this->assertDatabaseCount('identity_verifications', 0);
    }
}
