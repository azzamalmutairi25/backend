<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

// مستخدمو اختبار — واحد لكل دور، لتجربة صلاحيات ومسارات النظام
// التشغيل:  php artisan db:seed --class=TestUsersSeeder   (آمن ومتكرّر — updateOrCreate)
class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = 'Kafaat@2026';

        // اسم المستخدم => رمز الدور
        $map = [
            'admin'      => 'ADMIN',            // كل الصلاحيات
            'center'     => 'CENTER_MANAGER',   // إشراف عام (عرض) + تحليلات + تدقيق
            'scheduler'  => 'SCHEDULER',        // إضافة/اعتماد مرشّح + جدولة + دعوات
            'reception'  => 'RECEPTIONIST',     // تسجيل حضور + دعوات
            'assess'     => 'ASSESS_MANAGER',   // اعتماد التقييم + إنشاء/تعديل التقارير + تحليلات
            'evaluator'  => 'EVALUATOR',        // إدخال تقييم المقابلة
            'discussion' => 'DISCUSSION_EVAL',  // إدخال تقييم حلقة النقاش
            'assistant'  => 'ASSISTANT',        // مساعدة التقييم (رصد)
            'devmanager' => 'DEV_MANAGER',      // الاعتماد النهائي للتقارير + إدارة الكفاءات + تحليلات
            'measure'    => 'MEASURE_SUPER',    // رفع أدوات القياس + تسجيل حضور
            'external'   => 'EXTERNAL_ADD',     // إضافة مرشّح فقط (صلاحية دنيا)
        ];

        $created = 0;
        foreach ($map as $username => $roleCode) {
            $role = Role::where('code', $roleCode)->first();
            if (!$role) {
                $this->command->warn("تخطّي {$username}: الدور {$roleCode} غير موجود");
                continue;
            }
            User::updateOrCreate(
                ['username' => $username],
                [
                    'full_name' => "اختبار — {$roleCode}",
                    'email' => "{$username}@kafaat.test",
                    'password' => $password,      // يُشفَّر تلقائياً عبر الموديل
                    'role_id' => $role->id,
                    'user_type' => 'external',    // كلمة مرور محلية (لا AD)
                    'is_active' => true,
                    'must_change_password' => false,
                    'failed_attempts' => 0,
                    'locked_until' => null,
                ]
            );
            $created++;
        }

        $this->command->info("✅ تم تجهيز {$created} مستخدم اختبار — كلمة المرور للجميع: {$password}");
    }
}
