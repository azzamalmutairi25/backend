<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  البذر لا يكتب فوق حساب مدير النظام القائم.
//
//  البذر يُعاد تشغيله لأسباب مرجعية بحتة — إضافة دور أو قطاع أو كفاءة. وكان
//  يمرّ على حساب admin بـupdateOrCreate، فيُعيد كلمة مروره إلى قيمة البيئة
//  ويُلزمه بالتغيير. أي أنّ أمراً يبدو آمناً كان ينتزع من صاحب المنصّة كلمة
//  مروره بلا إنذار — ولا يظهر في أي اختبار يسأل «هل بُذرت الأدوار؟».
// ════════════════════════════════════════════════════════════
class SeederDoesNotOverwriteAdminTest extends TestCase
{
    use RefreshDatabase;

    // المتغيّرة تعيش مع العملية لا مع الاختبار — تركُها مضبوطة يُسرّب بيئة
    // إنتاجٍ وهمية إلى ما بعده من اختبارات
    protected function tearDown(): void
    {
        putenv('ADMIN_INITIAL_PASSWORD');
        parent::tearDown();
    }

    public function test_reseeding_leaves_an_existing_admin_password_untouched(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);
        putenv('ADMIN_INITIAL_PASSWORD=FromEnv@12345');

        $role = Role::firstOrCreate(['code' => 'ADMIN'], ['code' => 'ADMIN', 'name_ar' => 'مدير النظام']);
        $chosen = 'ChosenByOwner@99';
        // updateOrCreate لا create: الحزمة كاملةً تعمل على قاعدة مبذورة مرّة
        // خارج معاملة الاختبار، فحساب admin قائمٌ فيها — وcreate يسقط على
        // القيد الفريد فيفشل الاختبار لسببٍ لا علاقة له بما يقيسه
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'full_name' => 'مدير النظام',
                'email' => 'admin@kafaat.local',
                'password' => $chosen,
                'role_id' => $role->id,
                'is_active' => true,
                'must_change_password' => false,
            ]
        );

        (new DatabaseSeeder)->setContainer($this->app)->run();

        $admin = User::where('username', 'admin')->first();
        $this->assertTrue(Hash::check($chosen, $admin->password),
            'البذر أعاد ضبط كلمة مرور مدير النظام القائم');
        $this->assertFalse($admin->must_change_password,
            'البذر أعاد فرض تغيير كلمة المرور على حساب قائم');
    }

    public function test_the_operations_role_is_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $role = Role::where('code', 'OPERATIONS')->first();
        $this->assertNotNull($role, 'دور مسؤول العمليات غير مبذور');
        $this->assertSame('مسؤول العمليات', $role->name_ar);
    }
}
