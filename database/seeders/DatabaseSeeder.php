<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\Sector;
use App\Models\Competency;
use App\Models\User;

// ════════════════════════════════════════════════════════════
//  البيانات الأولية (Seeder)
//  شغّله عبر: php artisan db:seed
// ════════════════════════════════════════════════════════════

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── الأدوار العشرة ──
        $roles = [
            ['code' => 'ADMIN', 'name_ar' => 'مدير النظام'],
            ['code' => 'CENTER_MANAGER', 'name_ar' => 'مدير المركز'],
            ['code' => 'SCHEDULER', 'name_ar' => 'مسؤول الجدولة'],
            ['code' => 'RECEPTIONIST', 'name_ar' => 'مسؤول الاستقبال'],
            ['code' => 'ASSESS_MANAGER', 'name_ar' => 'مدير إدارة التقييم'],
            ['code' => 'EVALUATOR', 'name_ar' => 'مستشار المقابلة'],
            ['code' => 'DISCUSSION_EVAL', 'name_ar' => 'مستشار حلقة النقاش'],
            ['code' => 'ASSISTANT', 'name_ar' => 'مساعد التقييم'],
            ['code' => 'DEV_MANAGER', 'name_ar' => 'إدارة تطوير الكفاءات'],
            ['code' => 'MEASURE_SUPER', 'name_ar' => 'مشرف أدوات القياس'],
            ['code' => 'EXTERNAL_ADD', 'name_ar' => 'مستخدم خارجي (إضافة مرشحين)'],
        ];
        foreach ($roles as $r) Role::create($r);

        // ── القطاعات الثمانية ──
        $sectors = [
            ['code' => 'DA', 'name_ar' => 'الدفاع', 'is_military' => true],
            ['code' => 'HI', 'name_ar' => 'الصحة', 'is_military' => false],
            ['code' => 'MA', 'name_ar' => 'المالية', 'is_military' => true],
            ['code' => 'TR', 'name_ar' => 'النقل', 'is_military' => false],
            ['code' => 'EN', 'name_ar' => 'الطاقة', 'is_military' => true],
            ['code' => 'ED', 'name_ar' => 'التعليم', 'is_military' => false],
            ['code' => 'HO', 'name_ar' => 'الإسكان', 'is_military' => false],
            ['code' => 'CO', 'name_ar' => 'الاتصالات', 'is_military' => false],
        ];
        foreach ($sectors as $s) Sector::create($s);

        // ── الكفاءات ──
        $competencies = [
            ['name_ar' => 'القيادة والتأثير', 'type' => 'leadership', 'max_level' => 5, 'sort_order' => 1],
            ['name_ar' => 'التواصل الفعّال', 'type' => 'behavioral', 'max_level' => 5, 'sort_order' => 2],
            ['name_ar' => 'التفكير الاستراتيجي', 'type' => 'behavioral', 'max_level' => 5, 'sort_order' => 3],
            ['name_ar' => 'صنع القرار', 'type' => 'leadership', 'max_level' => 5, 'sort_order' => 4],
            ['name_ar' => 'بناء الفرق', 'type' => 'leadership', 'max_level' => 5, 'sort_order' => 5],
            ['name_ar' => 'إدارة التغيير', 'type' => 'behavioral', 'max_level' => 5, 'sort_order' => 6],
            ['name_ar' => 'حل المشكلات', 'type' => 'technical', 'max_level' => 5, 'sort_order' => 7],
            ['name_ar' => 'المرونة والتكيّف', 'type' => 'behavioral', 'max_level' => 5, 'sort_order' => 8],
        ];
        foreach ($competencies as $c) Competency::create($c);

        // ── حسابات تجريبية (كلمة المرور: Kafaat@2026) ──
        $adminRole = Role::where('code', 'ADMIN')->first();
        $evalRole = Role::where('code', 'EVALUATOR')->first();
        $devRole = Role::where('code', 'DEV_MANAGER')->first();

        User::create([
            'username' => 'admin', 'full_name' => 'مدير النظام',
            'email' => 'admin@kafaat.local', 'password' => 'Kafaat@2026',
            'role_id' => $adminRole->id, 'is_active' => true, 'must_change_password' => false,
        ]);
        User::create([
            'username' => 'evaluator', 'full_name' => 'مستشار تجريبي',
            'email' => 'eval@kafaat.local', 'password' => 'Kafaat@2026',
            'role_id' => $evalRole->id, 'is_active' => true, 'must_change_password' => false,
        ]);
        User::create([
            'username' => 'devmanager', 'full_name' => 'مدير تطوير الكفاءات',
            'email' => 'dev@kafaat.local', 'password' => 'Kafaat@2026',
            'role_id' => $devRole->id, 'is_active' => true, 'must_change_password' => false,
        ]);

        // ── الإعدادات (قوالب البريد والرسائل) ──
        DB::table('settings')->insert([
            ['key' => 'email.invitation.subject', 'value' => 'دعوة لجلسة تقييم الكفاءات القيادية', 'description' => 'عنوان بريد الدعوة', 'updated_at' => now()],
            ['key' => 'sms.invitation.template', 'value' => 'مركز تمكين الكفاءات: لديك جلسة تقييم بتاريخ {date} الساعة {time} في {location}', 'description' => 'قالب رسالة الدعوة', 'updated_at' => now()],
        ]);

        echo "✓ تمت تعبئة البيانات الأولية\n";
    }
}
