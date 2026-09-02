<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  إلزام تغيير كلمة المرور يقع **مرّة واحدة**.
//
//  عطلٌ هنا لا يبدو عطلاً: المستخدم يغيّر كلمته ثم يُطلَب منه تغييرها في كل
//  دخول، فيظنّ أن تغييره لم يُحفظ، ويكرّره، ويفقد الثقة بالنظام كلّه. ولا
//  يظهر في أي اختبار يفحص «هل نجح التغيير؟» — لأنه ينجح. يظهر فقط عند
//  فحص **الدخول التالي**.
// ════════════════════════════════════════════════════════════
class ForcedPasswordChangeOnceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const USER = 'pw_once_user';

    private const TEMP = 'Temp@12345';

    private const NEW = 'Chosen@98765';

    protected function setUp(): void
    {
        parent::setUp();
        // مسار الدخول محدود بعشر محاولات في الدقيقة لكل عنوان، وذاكرة الاختبار
        // من نوع array تعيش مع العملية لا مع الاختبار — فعدّاد المحاولات يتراكم
        // عبر الاختبارات حتى يُخنق دخولٌ مشروع، فيبدو العطل في المنتَج وهو في
        // عزل الاختبارات. تصفيره هنا يجعل كل اختبار يبدأ من صفر.
        Cache::flush();
    }

    private function makeUser(bool $forced = true): User
    {
        return User::create([
            'username' => self::USER,
            'full_name' => 'موظّف',
            'email' => 'pwonce@kafaat.local',
            'password' => self::TEMP,
            'role_id' => Role::where('code', 'SCHEDULER')->firstOrFail()->id,
            'is_active' => true,
            'must_change_password' => $forced,
        ]);
    }

    private function login(string $password): TestResponse
    {
        return $this->postJson('/api/login', [
            'username' => self::USER,
            'password' => $password,
        ]);
    }

    public function test_the_first_login_with_a_temporary_password_demands_a_change(): void
    {
        $this->makeUser();

        $this->login(self::TEMP)
            ->assertOk()
            ->assertJsonPath('user.mustChangePassword', true);
    }

    public function test_the_next_login_after_changing_does_not_demand_it_again(): void
    {
        $this->makeUser();

        $token = $this->login(self::TEMP)->json('token');
        $this->withToken($token)->postJson('/api/change-password', [
            'currentPassword' => self::TEMP,
            'newPassword' => self::NEW,
        ])->assertOk();

        // جوهر الاختبار: الدخول **التالي** بالكلمة الجديدة
        $this->login(self::NEW)
            ->assertOk()
            ->assertJsonPath('user.mustChangePassword', false);
    }

    public function test_it_stays_off_across_repeated_logins(): void
    {
        $this->makeUser();

        $token = $this->login(self::TEMP)->json('token');
        $this->withToken($token)->postJson('/api/change-password', [
            'currentPassword' => self::TEMP,
            'newPassword' => self::NEW,
        ])->assertOk();

        // ثلاث مرّات: عطلٌ دوريّ أو مرتبطٌ بعدّاد لا يظهر في دخولٍ واحد
        for ($i = 1; $i <= 3; $i++) {
            $this->login(self::NEW)
                ->assertOk()
                ->assertJsonPath('user.mustChangePassword', false);
        }
    }

    public function test_an_incomplete_change_keeps_demanding_it(): void
    {
        $this->makeUser();

        $token = $this->login(self::TEMP)->json('token');
        // محاولة فاشلة (كلمة حالية خاطئة) — يجب ألّا تُسقط الإلزام
        $this->withToken($token)->postJson('/api/change-password', [
            'currentPassword' => 'WrongCurrent@1',
            'newPassword' => self::NEW,
        ])->assertStatus(422);

        $this->login(self::TEMP)
            ->assertOk()
            ->assertJsonPath('user.mustChangePassword', true);
    }

    // العطل الفعلي الذي شكا منه المستخدم: الواجهة تُرفق الرمز المخزَّن بكل
    // طلب — بما فيه الدخول. ومستخدمٌ ترك شاشة التغيير دون إتمامها يبقى رمزه
    // في المتصفّح، فكان وسيط الإلزام يردّ **طلب الدخول نفسه** بـ403، فيُقال
    // له إن بياناته خاطئة وهي صحيحة، ولا مخرج إلا بمسح تخزين المتصفّح.
    public function test_login_works_even_when_a_stale_token_is_attached(): void
    {
        $this->makeUser();
        $token = $this->login(self::TEMP)->json('token');

        $this->withToken($token)
            ->postJson('/api/login', ['username' => self::USER, 'password' => self::TEMP])
            ->assertOk()
            ->assertJsonPath('user.mustChangePassword', true)
            ->assertJsonStructure(['token']);
    }

    public function test_logging_out_stays_possible_while_the_change_is_pending(): void
    {
        $this->makeUser();
        $token = $this->login(self::TEMP)->json('token');

        // الخروج مخرجٌ مشروع أيضاً — سدّه يحبس المستخدم في الشاشة
        $this->withToken($token)->postJson('/api/logout')->assertOk();
    }

    public function test_a_user_created_without_the_flag_is_never_asked(): void
    {
        $this->makeUser(forced: false);

        $this->login(self::TEMP)
            ->assertOk()
            ->assertJsonPath('user.mustChangePassword', false);
    }

    public function test_the_forced_user_cannot_use_the_platform_before_changing(): void
    {
        $this->makeUser();
        $token = $this->login(self::TEMP)->json('token');

        // الحجب خادمي لا واجهي: تخطّي الشاشة بتغيير الرابط لا يفتح شيئاً
        $this->withToken($token)->getJson('/api/candidates')->assertStatus(403);

        $this->withToken($token)->postJson('/api/change-password', [
            'currentPassword' => self::TEMP,
            'newPassword' => self::NEW,
        ])->assertOk();

        // وبعد التغيير يُفتح فوراً بالرمز نفسه — لا يلزم دخولٌ جديد
        $this->withToken($token)->getJson('/api/candidates')->assertOk();
    }
}
