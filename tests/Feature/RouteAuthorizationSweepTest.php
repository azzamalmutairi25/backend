<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  شبكة الترخيص الشاملة — تمرّ على كل مسار في النظام لا على مسارٍ بعينه.
//
//  الاختبار الموجَّه يحرس ما كُتب له؛ والمسار الذي يُضاف غداً بلا حارس لا
//  يحرسه أحد. هذا الملفّ يقلب المعادلة: يعدّ المسارات من الموجّه نفسه،
//  فكل مسار جديد يدخل التغطية تلقائياً ويلزمه إعلانٌ صريح هنا.
//
//  محكّان مستقلّان:
//   ١) بلا مصادقة ⇒ 401 لكل مسار محميّ.
//   ٢) بأقلّ دورٍ امتيازاً (EXTERNAL_ADD) ⇒ لا 2xx، والردّ 403/404 لا 422.
//      لماذا 422 مرفوضة؟ لأنها تعني أن التحقّق من المدخلات سبق فحص الصلاحية،
//      فيتعلّم من لا يملك الصلاحية شكلَ الحمولة ورسائل التحقّق من الحقول.
// ════════════════════════════════════════════════════════════
class RouteAuthorizationSweepTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // مسارات مفتوحة لكل مستخدم مُصادَق — بقرار مقصود لا سهو.
    // كل سطر هنا دعوى تُراجَع: «لا ضرر أن يصلها أدنى دور في النظام».
    private const OPEN_TO_ANY_AUTHENTICATED = [
        'GET api/me',                        // هويّته وصلاحياته هو
        'POST api/logout',                   // إبطال رمزه هو
        'POST api/change-password',          // كلمة مروره هو
        'GET api/dashboard/overview',        // أقسامها تُحجب فرادى داخلها
        'GET api/notifications',             // محصورة بـrecipient_id
        'GET api/notifications/unread-count',
        'PATCH api/notifications/read-all',
        'PATCH api/notifications/{id}/read',
        // بيانات مرجعية يلزمها نموذج الترشيح. مفتوحة بتصميم ظاهر في الشيفرة:
        // كلاهما يُعيد canManage ويُخفي ما لا يخصّ غير المدير (الرتب غير المفعّلة،
        // بادئة القطاع وأعداد المرتبطين به).
        'GET api/sectors',
        'GET api/ranks',
    ];

    // مسارات يبلغها الدور الخارجي بحقٍّ — هي صلاحيته المعلنة:
    // candidate.create و candidate.update_request. وجودها هنا إقرارٌ بأن
    // الاستيراد الجماعي يتقاسم CANDIDATE_CREATE مع الترشيح الفردي.
    //
    // وقراءة المجالات الفنية مكانها هنا لا في قائمة المفتوح لكل مُصادَق:
    // المتحكّم يشترط candidate.view أو candidate.create أو settings.manage،
    // فالدور الخارجي يبلغها بـcandidate.create وحدها لا بانفتاح المسار. وهي
    // لازمةٌ له: `technicalAreaIds` حقلٌ إلزامي في إنشاء المشارك ومصدر قائمته
    // هذا المسار. أمّا الكتابة عليها فمحصورة في settings.manage، فتبقى أفعالها
    // الثلاثة تحت الشبكة.
    private const WITHIN_EXTERNAL_ROLE = [
        'POST api/candidates',
        // فحص تكرار الهوية: صلاحيته candidate.create نفسها، فهو داخل سلطة هذا
        // الدور لا خارجها. ولا يمنحه شيئاً جديداً — store كان يردّ عليه
        // «مُضاف مسبقاً» بعد حمولةٍ كاملة، وهذا يردّها قبل بذل العمل. والرمز
        // والاسم محجوبان عنه هنا كما هناك (CandidateLookupTest).
        'POST api/candidates/lookup',
        'POST api/candidates/import',
        'POST api/import/candidates',
        'POST api/candidate-update-requests',
        'GET api/candidate-update-requests/mine',
        'GET api/technical-areas',
    ];

    // هل هذا المسار خارج ما يُفترض أن يبلغه أدنى دور؟
    private function shouldBeDenied(string $key): bool
    {
        return !in_array($key, $this->publicRoutes(), true)
            && !in_array($key, self::OPEN_TO_ANY_AUTHENTICATED, true)
            && !in_array($key, self::WITHIN_EXTERNAL_ROLE, true);
    }

    // ── مسارات عامة بلا مصادقة — تُستثنى من محكّ 401 ──
    //
    // القائمة تتبع مفاتيح التشغيل لا تُكتب ثابتة: البوّابة والكشك يُسجَّلان
    // بشرط، فقائمةٌ جامدة إمّا تستثني مسارَ خدمةٍ مغلقة (سطرٌ ميت يُخفي
    // إعادة تسمية) أو تترك مسارَ خدمةٍ مفتوحة بلا استثناء فيُقرأ تسريباً.
    //
    // «عام» هنا لا تعني «مكشوف»: كلاهما بوّابةُ هويةٍ قبل أي بيان، وحدُّ
    // محاولاتٍ خلفها. المستثنى هو المصادقة بجلسة موظّف، لا الحراسة.
    private function publicRoutes(): array
    {
        $routes = ['POST api/login'];

        if (config('features.candidate_portal')) {
            array_push(
                $routes,
                'POST api/public/assessment/{token}/verify',
                'POST api/public/assessment/{token}/confirm',
                'POST api/public/assessment/{token}/arrive',
                'POST api/public/assessment/{token}/cv',
            );
        }

        if (config('features.reception_kiosk')) {
            array_push(
                $routes,
                'GET api/kiosk/{token}',
                'POST api/kiosk/{token}/identify',
                'POST api/kiosk/{token}/arrive',
                'POST api/kiosk/{token}/sign',
                'POST api/kiosk/{token}/badge',
            );
        }

        return $routes;
    }

    // كل مسارات /api مع فعلها الأول — مصدرها الموجّه لا قائمة مكتوبة بيد
    private function apiRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            if ($method === null) {
                continue;
            }
            $out["{$method} {$uri}"] = $uri;
        }
        ksort($out);
        return $out;
    }

    // قيم معقولة لمعاملات المسار — الهدف الوصول إلى الحارس، لا نجاح العملية
    private function fill(string $uri, array $ids): string
    {
        $map = [
            '{id}' => (string) $ids['id'],
            '{entityId}' => (string) $ids['id'],
            '{candidateId}' => (string) $ids['candidate'],
            '{scheduleId}' => (string) $ids['id'],
            '{threadId}' => (string) $ids['id'],
            '{entityType}' => 'report',
            '{activity}' => 'interview',
            '{token}' => str_repeat('a', 48),
        ];
        return '/' . strtr($uri, $map);
    }

    private function seedTargets(): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'مشارك', 'status' => 'draft', 'created_by' => null,
        ]);
        return ['candidate' => $c->id, 'id' => $report->id];
    }

    // ═══ ١) بلا مصادقة: كل مسار محميّ يردّ 401 ═══
    public function test_every_protected_route_rejects_an_unauthenticated_caller(): void
    {
        $ids = $this->seedTargets();
        $leaks = [];

        foreach ($this->apiRoutes() as $key => $uri) {
            if (in_array($key, $this->publicRoutes(), true)) {
                continue;
            }
            [$method, ] = explode(' ', $key, 2);
            $res = $this->json($method, $this->fill($uri, $ids));
            if ($res->getStatusCode() !== 401) {
                $leaks[] = "{$key} ⇒ {$res->getStatusCode()}";
            }
        }

        $this->assertSame([], $leaks, "مسارات لا تردّ 401 لغير المُصادَق:\n" . implode("\n", $leaks));
    }

    // ═══ ٢) أدنى دور: لا 2xx على ما ليس له ═══
    public function test_the_least_privileged_role_reaches_nothing_beyond_its_own_routes(): void
    {
        $ids = $this->seedTargets();
        $this->actingAsRole('EXTERNAL_ADD'); // candidate.create + candidate.update_request فقط

        $reachable = [];
        foreach ($this->apiRoutes() as $key => $uri) {
            if (!$this->shouldBeDenied($key)) {
                continue;
            }
            [$method, ] = explode(' ', $key, 2);
            $status = $this->json($method, $this->fill($uri, $ids))->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $reachable[] = "{$key} ⇒ {$status}";
            }
        }

        $this->assertSame([], $reachable, "مسارات وصلها أدنى دور بنجاح:\n" . implode("\n", $reachable));
    }

    // ═══ ٣) ترتيب الحارس: الصلاحية قبل التحقّق من المدخلات ═══
    // ردّ 422 لمن لا يملك الصلاحية يُفصح بشكل الحمولة وقواعد الحقول — تسريب
    // خفيف لكنه حقيقي، ودليلٌ على أن الحارس جاء بعد validate() لا قبله.
    public function test_authorization_is_checked_before_input_validation(): void
    {
        $ids = $this->seedTargets();
        $this->actingAsRole('EXTERNAL_ADD');

        $validatedFirst = [];
        foreach ($this->apiRoutes() as $key => $uri) {
            if (!$this->shouldBeDenied($key)) {
                continue;
            }
            [$method, ] = explode(' ', $key, 2);
            if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                continue; // المسارات القارئة لا حمولة لها
            }
            if ($this->json($method, $this->fill($uri, $ids))->getStatusCode() === 422) {
                $validatedFirst[] = $key;
            }
        }

        $this->assertSame([], $validatedFirst,
            "مسارات تتحقّق من المدخلات قبل الصلاحية (تُفصح بقواعد الحقول لغير المُصرَّح له):\n"
            . implode("\n", $validatedFirst));
    }

    // ═══ ٤) الشبكة تغطّي فعلاً ═══
    // اختبارٌ يمرّ على صفر مسارات يمرّ دائماً — نُثبّت أن العدّ منطقي
    public function test_the_sweep_actually_covers_the_api_surface(): void
    {
        $routes = $this->apiRoutes();
        $this->assertGreaterThan(100, count($routes), 'عدد المسارات أقلّ من المتوقّع — تغيّر التحميل؟');

        // كل مسار في القوائم المستثناة موجود فعلاً — سطرٌ ميت يعني استثناءً
        // لمسارٍ أُعيدت تسميته، فيمرّ نظيره الجديد بلا حارس ولا أحد يلاحظ
        foreach ([...self::OPEN_TO_ANY_AUTHENTICATED, ...$this->publicRoutes(), ...self::WITHIN_EXTERNAL_ROLE] as $key) {
            $this->assertArrayHasKey($key, $routes, "استثناء لمسار غير موجود: {$key}");
        }
    }
}
