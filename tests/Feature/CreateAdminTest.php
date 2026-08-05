<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// أمر استعادة الدخول: يُستعمل على نظامٍ لا سبيل إليه غيره، فخطؤه مُقفِل.
class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // ⚠ قاعدة الاختبار مبذورة مرّة واحدة لكل تشغيل وفيها حساب «admin».
    // استعمال الاسم نفسه كان يُنجح الاختبارات منفردةً ويُخفقها في التشغيل
    // الكامل. اسمٌ غير مبذور يجعل النيّة صريحة: هذا حسابٌ ينشئه الأمر.
    private const NAME = 'sysadmin';

    private function createAdmin(string $args = ''): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan(trim('kafaat:create-admin ' . self::NAME . ' ' . $args));
    }

    private function account(): ?User
    {
        return User::where('username', self::NAME)->first();
    }

    public function test_it_creates_an_admin_with_a_generated_password(): void
    {
        $this->createAdmin()->assertSuccessful();

        $u = $this->account();
        $this->assertNotNull($u);
        $this->assertTrue($u->is_active);
        // من نفّذ الأمر رأى الكلمة — فيجب ألّا تبقى صالحة بعد أول دخول
        $this->assertTrue($u->must_change_password, 'كلمة المرور المطبوعة تبقى صالحة بلا حدّ');
    }

    public function test_the_generated_password_is_printed_and_stored_hashed(): void
    {
        // كلمة تُطبع ولا تُقبل عند الدخول تترك النظام مقفلاً بينما يبدو مفتوحاً
        $this->createAdmin()->expectsOutputToContain('كلمة المرور')->assertSuccessful();

        $this->assertTrue(Hash::isHashed($this->account()->password), 'كلمة المرور مخزَّنة بلا تجزئة');
    }

    public function test_a_supplied_password_is_hashed_and_authenticates(): void
    {
        $this->createAdmin('--password=Tamkeen@2026x')->assertSuccessful();

        $this->assertTrue(Hash::check('Tamkeen@2026x', $this->account()->password));
    }

    public function test_it_refuses_to_clobber_an_existing_account_without_reset(): void
    {
        $this->createAdmin('--password=First@12345')->assertSuccessful();
        $before = $this->account()->password;

        $this->createAdmin('--password=Second@12345')->assertFailed();

        $this->assertSame($before, $this->account()->password,
            'أُعيد ضبط كلمة مرور حسابٍ قائم بلا --reset');
    }

    public function test_reset_flag_changes_the_password_of_an_existing_account(): void
    {
        $this->createAdmin('--password=First@12345')->assertSuccessful();

        $this->createAdmin('--password=Second@12345 --reset')->assertSuccessful();

        $this->assertTrue(Hash::check('Second@12345', $this->account()->password));
        $this->assertSame(1, User::where('username', self::NAME)->count(), 'أُنشئ حساب ثانٍ بالاسم نفسه');
    }

    public function test_it_fails_clearly_on_an_unknown_role(): void
    {
        $this->createAdmin('--role=WIZARD')->assertFailed();

        $this->assertNull($this->account(), 'أُنشئ حساب بدورٍ غير موجود');
    }

    public function test_reset_clears_a_lockout(): void
    {
        $this->createAdmin('--password=First@12345')->assertSuccessful();
        User::where('username', self::NAME)->update([
            'failed_attempts' => 9,
            'locked_until' => now()->addHour(),
        ]);

        // حسابٌ مقفل بعد محاولات فاشلة: إعادة الضبط يجب أن تفتحه، وإلا بقي
        // الأمر بلا فائدة في الحالة التي يُستدعى فيها غالباً.
        $this->createAdmin('--password=Second@12345 --reset')->assertSuccessful();

        $u = $this->account();
        $this->assertSame(0, (int) $u->failed_attempts);
        $this->assertNull($u->locked_until);
    }
}
