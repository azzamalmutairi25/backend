<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  الردّ على الطلب غير المُصادَق.
//
//  الواجهة ترسل Accept: application/json دائماً، فالعطل هنا لا يظهر منها
//  إطلاقاً: يظهر لفاحص الجاهزية، ولماسح الأمن، ولمن يفتح مسار API في
//  المتصفّح — وكلّهم يرون «500 عطل خادم» على نظامٍ يعمل ويرفض الدخول رفضاً
//  صحيحاً. وسيط المصادقة كان يستدعي route('login') وهو غير معرَّف.
// ════════════════════════════════════════════════════════════
class UnauthenticatedResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_api_request_without_a_json_header_returns_401_not_500(): void
    {
        // بلا أي ترويسة Accept — كما يفعل curl ومعظم أدوات المراقبة
        $res = $this->get('/api/me');

        $res->assertStatus(401);
    }

    public function test_an_api_request_with_a_browser_accept_header_returns_401(): void
    {
        // ما يرسله المتصفّح عند فتح المسار مباشرة في شريط العنوان
        $res = $this->get('/api/me', [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);

        $res->assertStatus(401);
    }

    public function test_an_api_request_with_a_json_header_still_returns_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_the_health_endpoint_is_reachable_without_authentication(): void
    {
        // فاحص الجاهزية ومُوازِن الأحمال يستدعيانه بلا رمز
        $this->get('/up')->assertStatus(200);
    }

    public function test_an_unknown_api_path_returns_404_not_500(): void
    {
        $this->get('/api/no-such-route')->assertStatus(404);
    }
}
