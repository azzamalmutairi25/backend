<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Data\MoiSectors;
use App\Models\Role;
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
        // ── الأدوار ──
        // updateOrCreate لا Role::create: البذر قابل لإعادة التشغيل (roles.code فريد،
        // فإعادة db:seed كانت تفشل)، ويضيف EXTERNAL_ADD للقواعد التي بُذرت قبله.
        $roles = [
            ['code' => 'ADMIN', 'name_ar' => 'مدير النظام'],
            ['code' => 'CENTER_MANAGER', 'name_ar' => 'مدير المركز'],
            ['code' => 'SCHEDULER', 'name_ar' => 'مسؤول الجدولة'],
            ['code' => 'RECEPTIONIST', 'name_ar' => 'مسؤول استقبال الموظفين'],
            ['code' => 'OPERATIONS', 'name_ar' => 'مسؤول العمليات'],
            ['code' => 'ASSESS_MANAGER', 'name_ar' => 'مدير إدارة التقييم'],
            ['code' => 'EVALUATOR', 'name_ar' => 'مستشار المقابلة'],
            ['code' => 'DISCUSSION_EVAL', 'name_ar' => 'مستشار حلقة النقاش'],
            ['code' => 'ASSISTANT', 'name_ar' => 'مساعد التقييم'],
            ['code' => 'DEV_MANAGER', 'name_ar' => 'إدارة تطوير الكفاءات'],
            ['code' => 'MEASURE_SUPER', 'name_ar' => 'مشرف أدوات القياس'],
            ['code' => 'EXTERNAL_ADD', 'name_ar' => 'مستخدم خارجي (إضافة مرشحين)'],
        ];
        foreach ($roles as $r) Role::updateOrCreate(['code' => $r['code']], $r);

        // ── صلاحيات الأدوار: تُبذَر من المصفوفة مرّة، ثم يملكها المدير ──
        // البذر لدورٍ **لا صفوف له** فقط. دورٌ حُرِّرت صلاحياته من الشاشة لا
        // يُعاد إلى الافتراضي بإعادة تشغيل البذر — وإلا محا كلُّ بذرٍ ضبطاً
        // اختاره صاحب المنصّة بلا إنذار، وهو نفس عطل حساب admin.
        if (\Illuminate\Support\Facades\Schema::hasTable('role_permissions')) {
            $seeded = 0;
            foreach (\App\Security\Permissions::matrix() as $code => $perms) {
                $role = Role::where('code', $code)->first();
                if (!$role) continue;
                if (\App\Models\RolePermission::where('role_id', $role->id)->exists()) continue;

                foreach ($perms as $p) {
                    \App\Models\RolePermission::create(['role_id' => $role->id, 'permission' => $p]);
                }
                $seeded++;
            }
            \App\Security\Permissions::forgetCache();
            if ($seeded > 0) echo "✓ بُذرت صلاحيات {$seeded} دور\n";
        }

        // ── قطاعات الوزارة المعتمدة ──
        // القائمة ومنطق عدم الدهس في App\Data\MoiSectors — تقرؤها الهجرة والبذر معاً
        MoiSectors::sync();

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
        foreach ($competencies as $c) Competency::updateOrCreate(['name_ar' => $c['name_ar']], $c);

        // ── حسابات المستخدمين ──
        // في الإنتاج: لا تُنشأ حسابات تجريبية بكلمة مرور منشورة إطلاقاً. يُنشأ مدير
        // واحد فقط بكلمة مرور من البيئة (ADMIN_INITIAL_PASSWORD) مع إلزام تغييرها؛
        // وإن غابت المتغيّرة يُتخطّى الإنشاء بتحذير صريح. أمّا خارج الإنتاج
        // (local/testing) فتُنشأ حسابات العرض كما هي — تعتمد عليها الاختبارات.
        $adminRole = Role::where('code', 'ADMIN')->first();

        if (app()->environment('production')) {
            $adminPassword = (string) env('ADMIN_INITIAL_PASSWORD', '');
            // حسابٌ قائم لا يُمَسّ. كان updateOrCreate يكتب فوقه، فإعادةُ تشغيل
            // البذر لسببٍ آخر — إضافة دور أو قطاع — تُعيد كلمة مرور مدير النظام
            // إلى قيمة البيئة وتُلزمه بالتغيير. أي أنّ أمراً يبدو مرجعياً بحتاً
            // كان ينتزع من صاحب المنصّة كلمةَ مروره التي اختارها، بلا إنذار.
            // الإنشاء لأول مرة فقط؛ وإعادة الضبط لها أمرها الصريح:
            //     php artisan kafaat:create-admin admin --reset
            if (User::where('username', 'admin')->exists()) {
                echo "• حساب admin قائم — لم يُمَسّ (استعمل kafaat:create-admin --reset لإعادة الضبط)\n";
            } elseif ($adminPassword !== '') {
                User::updateOrCreate(
                    ['username' => 'admin'],
                    [
                        'full_name' => 'مدير النظام',
                        'email' => (string) env('ADMIN_INITIAL_EMAIL', 'admin@kafaat.local'),
                        'password' => $adminPassword,
                        'role_id' => $adminRole->id,
                        'is_active' => true,
                        'must_change_password' => true, // إلزام التغيير عند أول دخول
                    ]
                );
                echo "✓ أُنشئ حساب admin (يجب تغيير كلمة المرور عند أول دخول)\n";
            } else {
                echo "⚠ لم يُنشأ أي حساب: اضبط ADMIN_INITIAL_PASSWORD ثم أعد التشغيل، أو أنشئ المدير يدوياً.\n";
            }
        } else {
            // بيئات غير إنتاجية فقط — كلمة مرور موحّدة معروفة للتطوير والاختبار
            $evalRole = Role::where('code', 'EVALUATOR')->first();
            $devRole = Role::where('code', 'DEV_MANAGER')->first();
            $demo = [
                ['username' => 'admin', 'full_name' => 'مدير النظام', 'email' => 'admin@kafaat.local', 'role_id' => $adminRole->id],
                ['username' => 'evaluator', 'full_name' => 'مستشار تجريبي', 'email' => 'eval@kafaat.local', 'role_id' => $evalRole->id],
                ['username' => 'devmanager', 'full_name' => 'مدير تطوير الكفاءات', 'email' => 'dev@kafaat.local', 'role_id' => $devRole->id],
            ];
            foreach ($demo as $u) {
                User::updateOrCreate(
                    ['username' => $u['username']],
                    $u + ['password' => 'Kafaat@2026', 'is_active' => true, 'must_change_password' => false]
                );
            }
        }

        // ── الإعدادات (قوالب البريد والرسائل) — idempotent ──
        DB::table('settings')->updateOrInsert(
            ['key' => 'email.invitation.subject'],
            ['value' => 'دعوة لجلسة تقييم الكفاءات القيادية', 'description' => 'عنوان بريد الدعوة', 'updated_at' => now()]
        );
        DB::table('settings')->updateOrInsert(
            ['key' => 'sms.invitation.template'],
            ['value' => 'مركز تمكين الكفاءات: لديك جلسة تقييم بتاريخ {date} الساعة {time} في {location}', 'description' => 'قالب رسالة الدعوة', 'updated_at' => now()]
        );

        echo "✓ تمت تعبئة البيانات الأولية\n";
    }
}
