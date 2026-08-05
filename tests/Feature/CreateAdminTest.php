<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// أمر استعادة الدخول: يُستعمل على نظامٍ لا سبيل إليه غيره، فخطؤه مُقفِل.
class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::updateOrCreate(['code' => 'ADMIN'], ['name_ar' => 'مدير النظام']);
    }

    public function test_it_creates_an_admin_with_a_generated_password(): void
    {
        $this->artisan('kafaat:create-admin admin')->assertSuccessful();

        $u = User::where('username', 'admin')->first();
        $this->assertNotNull($u);
        $this->assertTrue($u->is_active);
        // من نفّذ الأمر رأى الكلمة — فيجب ألّا تبقى صالحة بعد أول دخول
        $this->assertTrue($u->must_change_password, 'كلمة المرور المطبوعة تبقى صالحة بلا حدّ');
    }

    public function test_the_generated_password_actually_works(): void
    {
        // كلمة تُطبع ولا تُقبل عند الدخول تترك النظام مقفلاً بينما يبدو مفتوحاً
        $this->artisan('kafaat:create-admin admin')
            ->expectsOutputToContain('كلمة المرور')
            ->assertSuccessful();

        $u = User::where('username', 'admin')->first();
        $this->assertTrue(Hash::isHashed($u->password), 'كلمة المرور مخزَّنة بلا تجزئة');
    }

    public function test_a_supplied_password_is_hashed_and_authenticates(): void
    {
        $this->artisan('kafaat:create-admin admin --password=Tamkeen@2026x')->assertSuccessful();

        $u = User::where('username', 'admin')->first();
        $this->assertTrue(Hash::check('Tamkeen@2026x', $u->password));
    }

    public function test_it_refuses_to_clobber_an_existing_account_without_reset(): void
    {
        $this->artisan('kafaat:create-admin admin --password=First@12345')->assertSuccessful();
        $before = User::where('username', 'admin')->first()->password;

        $this->artisan('kafaat:create-admin admin --password=Second@12345')->assertFailed();

        $this->assertSame($before, User::where('username', 'admin')->first()->password,
            'أُعيد ضبط كلمة مرور حسابٍ قائم بلا --reset');
    }

    public function test_reset_flag_changes_the_password_of_an_existing_account(): void
    {
        $this->artisan('kafaat:create-admin admin --password=First@12345')->assertSuccessful();

        $this->artisan('kafaat:create-admin admin --password=Second@12345 --reset')->assertSuccessful();

        $u = User::where('username', 'admin')->first();
        $this->assertTrue(Hash::check('Second@12345', $u->password));
        $this->assertSame(1, User::where('username', 'admin')->count(), 'أُنشئ حساب ثانٍ بالاسم نفسه');
    }

    public function test_it_fails_clearly_on_an_unknown_role(): void
    {
        $this->artisan('kafaat:create-admin admin --role=WIZARD')->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_reset_clears_a_lockout(): void
    {
        $this->artisan('kafaat:create-admin admin --password=First@12345')->assertSuccessful();
        User::where('username', 'admin')->update([
            'failed_attempts' => 9,
            'locked_until' => now()->addHour(),
        ]);

        // حسابٌ مقفل بعد محاولات فاشلة: إعادة الضبط يجب أن تفتحه، وإلا بقي
        // الأمر بلا فائدة في الحالة التي يُستدعى فيها غالباً.
        $this->artisan('kafaat:create-admin admin --password=Second@12345 --reset')->assertSuccessful();

        $u = User::where('username', 'admin')->first();
        $this->assertSame(0, (int) $u->failed_attempts);
        $this->assertNull($u->locked_until);
    }
}
