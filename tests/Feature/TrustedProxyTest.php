<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  الوسطاء الموثوقون خلف الوسيط العكسي.
//
//  العطل الذي تحرسه هذه الاختبارات صامت تماماً: يعمل في التطوير ويسقط في
//  الإنتاج وحده. السبب أن الإنتاج يشغّل `php artisan config:cache`، وعندها
//  يتخطّى الإطارُ تحميلَ .env كلياً — فكل env() خارج ملفات الإعدادات يرجع
//  افتراضيَّه. وكانت قائمة الوسطاء تُقرأ بـenv() في bootstrap/app.php.
//
//  أثره لو مرّ: عنوان العميل يصير عنوان الوسيط، فيسقط تقييد المعدّل في دلوٍ
//  واحد (كل المشاركين على البوّابة العامة يتقاسمون ٢٠ طلباً في الدقيقة)،
//  ويُسجَّل عنوان الوسيط في سجل التدقيق بدل عنوان صاحب الفعل.
// ════════════════════════════════════════════════════════════
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // ينتزع العنوان الذي يراه التطبيق بعد مرور الطلب بوسيط الثقة
    private function ipSeenBehindProxy(string $proxyIp, string $clientIp): string
    {
        $request = Request::create('/api/me', 'GET', server: ['REMOTE_ADDR' => $proxyIp]);
        $request->headers->set('X-Forwarded-For', $clientIp);

        $seen = '';
        (new TrustProxies())->handle($request, function (Request $r) use (&$seen) {
            $seen = (string) $r->ip();
            return response('');
        });

        return $seen;
    }

    public function test_a_trusted_proxy_reveals_the_real_client_address(): void
    {
        config(['security.trusted_proxies' => ['10.20.30.40']]);

        $this->assertSame('203.0.113.7', $this->ipSeenBehindProxy('10.20.30.40', '203.0.113.7'),
            'الطلب من وسيط موثوق يجب أن يُظهر عنوان العميل الحقيقي');
    }

    public function test_an_untrusted_source_cannot_spoof_the_client_address(): void
    {
        config(['security.trusted_proxies' => ['10.20.30.40']]);

        // مهاجم يرسل X-Forwarded-For من عنوان غير موثوق — يجب تجاهلها
        $this->assertSame('198.51.100.9', $this->ipSeenBehindProxy('198.51.100.9', '203.0.113.7'),
            'ترويسة X-Forwarded-For من مصدر غير موثوق لا تُصدَّق');
    }

    public function test_an_empty_proxy_list_trusts_nobody(): void
    {
        config(['security.trusted_proxies' => []]);

        $this->assertSame('10.20.30.40', $this->ipSeenBehindProxy('10.20.30.40', '203.0.113.7'),
            'قائمة فارغة = لا ثقة بأحد، لا ثقة بالجميع');
    }

    public function test_cidr_ranges_are_honoured(): void
    {
        config(['security.trusted_proxies' => ['10.0.0.0/29']]);

        $this->assertSame('203.0.113.7', $this->ipSeenBehindProxy('10.0.0.3', '203.0.113.7'));
        $this->assertSame('10.0.0.9', $this->ipSeenBehindProxy('10.0.0.9', '203.0.113.7'), 'خارج النطاق');
    }

    // ═══ صلب المسألة: هل تنجو الإعدادات من config:cache؟ ═══

    public function test_security_settings_live_in_a_config_file_not_in_raw_env_calls(): void
    {
        // ملفات الإعدادات وحدها تُخبَز في ذاكرة config:cache. أي قراءة لهذين
        // المفتاحين عبر env() خارجها تعود للافتراضي على الخادم بلا إنذار.
        $this->assertIsArray(config('security.trusted_proxies'));
        $this->assertIsInt(config('security.api_rate_limit'));

        foreach (['bootstrap/app.php', 'app/Providers/AppServiceProvider.php'] as $file) {
            $src = (string) file_get_contents(base_path($file));
            $this->assertStringNotContainsString("env('TRUSTED_PROXIES'", $src,
                "{$file} يقرأ TRUSTED_PROXIES بـenv() — تعود فارغة مع config:cache");
            $this->assertStringNotContainsString("env('API_RATE_LIMIT'", $src,
                "{$file} يقرأ API_RATE_LIMIT بـenv() — تعود للافتراضي مع config:cache");
        }
    }

    public function test_the_application_uses_the_config_aware_middleware(): void
    {
        // استبدالٌ فائت في bootstrap يُعيد وسيط الإطار الذي يقرأ من static
        // مضبوطة وقت الإقلاع — أي القيمة الفارغة نفسها في الإنتاج
        $globals = app(\Illuminate\Foundation\Http\Kernel::class)->getGlobalMiddleware();

        $this->assertContains(TrustProxies::class, $globals);
        $this->assertNotContains(\Illuminate\Http\Middleware\TrustProxies::class, $globals);
    }

    // حدّ المعدّل يُقرأ من الإعدادات فعلاً — لا من ثابت مخبوز.
    // المُقيِّد مُعطَّل في بيئة الاختبار عمداً (كي لا يتراكم عبر السويت)، فنُبدّل
    // البيئة لحظةَ القياس وحدها: المُقيِّد يفحصها داخل مُغلَّقه لا عند التسجيل.
    public function test_the_api_rate_limit_is_configurable(): void
    {
        config(['security.api_rate_limit' => 3]);
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->actingAsRole('EXTERNAL_ADD');
            for ($i = 0; $i < 3; $i++) {
                $this->getJson('/api/me')->assertOk();
            }
            $this->getJson('/api/me')->assertStatus(429);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
