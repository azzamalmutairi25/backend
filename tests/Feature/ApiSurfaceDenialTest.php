<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  سطح الواجهة كلّه مُغلَق أمام أقلّ المستخدمين صلاحية
//
//  الاختبارات الأخرى تفحص المسارات التي كُتبت لها اختبارات. هذا يفحص
//  **كل مسار مسجَّل**، ويكتشف الجديد وحده: مسارٌ يُضاف غداً بلا بوّابة يسقط
//  هنا في اليوم نفسه، لا بعد أشهر حين يجرّبه أحد.
//
//  ولماذا «٤٠٣ لا ٤٢٢»: من لا يملك المسار لا يُعلَّم شكله. متحكّمٌ يتحقّق من
//  المدخلات قبل الصلاحية يردّ على غير المُصرَّح له بقواعد الحقول مفصَّلةً —
//  فيعرف أسماء الحقول وأنواعها وحدودها قبل أن يُمنع. الترتيب قاعدة أمنية لا
//  ذوق: **الصلاحية أولاً، ثم المدخلات**.
// ════════════════════════════════════════════════════════════
class ApiSurfaceDenialTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // مسارات مفتوحة لكل مُصادَق عمداً — لا تحتاج صلاحية أصلاً
    private const OPEN_TO_EVERY_USER = [
        'api/me',
        'api/logout',
        'api/change-password',
        'api/dashboard/overview',
        'api/notifications',
        'api/notifications/unread-count',
        'api/notifications/{id}/read',
        'api/notifications/read-all',
    ];

    // قوائم مرجعية يحتاجها كل من يملأ نموذجاً — ومنها المستخدم الخارجي:
    // `sectorId` و`rankLabel` و`technicalAreaIds` حقولٌ إلزامية في إنشاء
    // المشارك، ومصدرها هذه المسارات. والقطاعات والرتب تُشكّل استجابتها
    // بالصلاحية: البادئات وأعداد المرتبطين — وهي أرقامٌ تكشف حجم كل قطاع —
    // لا تُرسَل إلا لمدير الإعدادات.
    //
    // والمجالات الفنية ليست مفتوحة لكل مُصادَق كأختيها: قراءتها تشترط
    // candidate.view أو candidate.create أو settings.manage. فبلوغُ الدور
    // الخارجي إيّاها ليس ثغرة بل أثرُ صلاحيته المعلنة candidate.create —
    // ولولاه لخرج نموذج الإضافة بحقلٍ إلزاميٍّ بلا قائمة تملؤه. أمّا الكتابة
    // عليها فمحصورة في settings.manage، فتُفحَص أفعالها الثلاثة كغيرها أدناه.
    private const REFERENCE_LISTS = [
        'api/sectors',
        'api/ranks',
        'api/technical-areas',
    ];

    // ما يملكه المستخدم الخارجي فعلاً بدوره — يجب ألّا يُمنَع منها
    private const ALLOWED_FOR_EXTERNAL = [
        'api/candidates',                        // POST وحده؛ وGET مُختبَر أدناه
        // فحص تكرار الهوية: صلاحيته candidate.create نفسها، فهو مملوكٌ لهذا
        // الدور لا مكشوفٌ له. ولا يمنحه ما لم يكن يملكه: store كان يردّ عليه
        // بـ«مُضاف مسبقاً» بعد حمولةٍ كاملة، وهذا يردّها قبلها — والرمز والاسم
        // محجوبان عنه هنا كما هناك، والباب مخنوق بالمعدّل ومُقيَّد في السجلّ.
        'api/candidates/lookup',
        'api/candidate-update-requests',
        'api/candidate-update-requests/mine',
        'api/candidates/import',
        'api/candidates/import/batch',   // نظيرُه الضخم — candidate.create نفسها
        'api/import/candidates',
    ];

    // قيم وسائط المسار — معرّفات غير موجودة عمداً: البوّابة تسبق البحث
    private const PARAMS = [
        'id' => '999999',
        'entityId' => '999999',
        'threadId' => '999999',
        'scheduleId' => '999999',
        'candidateId' => '999999',
        'entityType' => 'report',
        'activity' => 'interview',
        'token' => 'nonexistent-token',
    ];

    /** كل مسارات api المحمية بـauth:sanctum */
    private function protectedRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/') || str_starts_with($uri, 'api/public/')) {
                continue;
            }
            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $out[] = [$verb, $uri];
            }
        }

        return $out;
    }

    private function fill(string $uri): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function ($m) {
            return self::PARAMS[$m[1]] ?? '1';
        }, $uri);
    }

    // ── الفحص الأساسي ──
    public function test_the_least_privileged_user_is_denied_everywhere(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');   // candidate.create + candidate.update_request فقط

        $leaked = [];
        $taught = [];

        foreach ($this->protectedRoutes() as [$verb, $uri]) {
            if (in_array($uri, self::OPEN_TO_EVERY_USER, true)) {
                continue;
            }
            if (in_array($uri, self::ALLOWED_FOR_EXTERNAL, true)) {
                continue;
            }
            if ($verb === 'GET' && in_array($uri, self::REFERENCE_LISTS, true)) {
                continue;   // القراءة مفتوحة؛ أمّا الكتابة عليها فتُفحَص كغيرها
            }

            $status = $this->json($verb, '/'.$this->fill($uri))->getStatusCode();

            if ($status >= 200 && $status < 300) {
                $leaked[] = "{$verb} /{$uri} ⇒ {$status}";
            } elseif ($status === 422) {
                // مرّ فحص الصلاحية ووصل إلى التحقّق من المدخلات
                $taught[] = "{$verb} /{$uri} ⇒ ٤٢٢ (تحقّقٌ قبل الصلاحية)";
            }
        }

        $this->assertSame([], $leaked,
            "مسارات استجابت لمن لا يملكها:\n".implode("\n", $leaked));

        $this->assertSame([], $taught,
            "مسارات تكشف قواعد حقولها لغير المُصرَّح له — الصلاحية تسبق التحقّق:\n"
            .implode("\n", $taught));
    }

    // المستخدم الخارجي يُدخل ولا يقرأ — حدٌّ يُنسى بسهولة حين تُضاف قراءةٌ للنموذج
    public function test_the_external_user_writes_but_never_reads_the_database(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');

        $this->getJson('/api/candidates')->assertStatus(403);
        $this->getJson('/api/candidates/stats')->assertStatus(403);
        $this->getJson('/api/candidates/export')->assertStatus(403);
    }

    // كل مسارٍ يفتح اتصالاً خارجياً بمضيفٍ أو رقمٍ يختاره الطالب لا بدّ له من
    // سقف. الاختبار الذي يتصل بما يُملى عليه هو ماسحُ شبكةٍ إن لم يُخنَق —
    // يكشف الموجود من المعدوم بفارق زمن الردّ، ولو حُصر منفذه ودُقّقت محاولته.
    // نُقص هذا عن `settings/ldap/test` وحده بينما أخواته الثلاث محدودة.
    public function test_every_external_integration_test_route_is_throttled(): void
    {
        $bare = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! preg_match('#^api/settings/[a-z]+/test$#', $uri)) {
                continue;
            }
            $throttled = collect($route->gatherMiddleware())
                ->contains(fn ($m) => str_contains(strtolower((string) $m), 'throttle'));

            if (! $throttled) {
                $bare[] = '/'.$uri;
            }
        }

        $this->assertSame([], $bare,
            "مسارات اختبار تكامل خارجي بلا سقف معدّل:\n  ".implode("\n  ", $bare));
    }

    // بلا رمز أصلاً: كل شيء ٤٠١، ولا مسار ينسى المصادقة
    public function test_no_protected_route_answers_without_a_token(): void
    {
        $leaked = [];

        foreach ($this->protectedRoutes() as [$verb, $uri]) {
            $status = $this->json($verb, '/'.$this->fill($uri))->getStatusCode();
            if ($status !== 401) {
                $leaked[] = "{$verb} /{$uri} ⇒ {$status} (يُنتظَر ٤٠١)";
            }
        }

        $this->assertSame([], $leaked,
            "مسارات لا تطلب مصادقة:\n".implode("\n", $leaked));
    }
}
