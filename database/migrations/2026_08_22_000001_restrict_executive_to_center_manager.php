<?php

use App\Security\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// حصرُ «القيادة التنفيذية للمركز» في مدير المركز.
//
// المصفوفة في Permissions بذرةُ التنصيب الأولى لا المرجع: المرجع صفوفُ
// role_permissions. فتضييقُ المصفوفة وحده لا يسحب شيئاً من تنصيبٍ قائم —
// مدير إدارة التقييم وإدارة تطوير الكفاءات يبقيان يفتحان الشاشة إلى الأبد.
// هذه الهجرة تسحب الصفّ منهما.
//
// وسبب الحصر: الشاشة صارت نظرةً شاملة على أبواب المنصّة كلها — الجدولة
// والاستقبال والحضور والفريق وسجل التدقيق — وتلك صورةُ المركز لا صورةُ
// إدارةٍ فيه. والتحليلات العامّة والتقرير اليومي باقيان لهما.
return new class extends Migration
{
    private const PERMISSION = 'analytics.executive';

    private const ROLES = ['ASSESS_MANAGER', 'DEV_MANAGER'];

    public function up(): void
    {
        foreach (self::ROLES as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            if (! $roleId) {
                continue;
            }

            $rows = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission');

            // دورٌ بلا صفوف يقع على المصفوفة، وقد ضُيّقت فيها أصلاً — ولو
            // أدرجنا هنا لأنشأنا له مجموعةً صريحة تُجمّد بقيّة صلاحياته عند
            // لحظتها فلا يصله ما يُضاف للدور لاحقاً.
            if ($rows->isEmpty()) {
                continue;
            }

            // مجموعةٌ خُلّي منها عمداً (صفٌّ واحد هو الحارس) — لا تُمسّ
            if ($rows->count() === 1 && $rows->first() === Permissions::PLACEHOLDER) {
                continue;
            }

            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission', self::PERMISSION)
                ->delete();

            // سحبُ آخر صفٍّ يترك الدور بلا صفوف فيرتدّ إلى المصفوفة كاملةً —
            // ترقيةٌ صامتة عكس مقصد الهجرة. الحارس يُبقي المجموعة صريحة.
            if (DB::table('role_permissions')->where('role_id', $roleId)->doesntExist()) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission' => Permissions::PLACEHOLDER,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Permissions::forgetCache();
    }

    public function down(): void
    {
        foreach (self::ROLES as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            if (! $roleId) {
                continue;
            }
            $rows = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission');
            if ($rows->isEmpty() || $rows->contains(self::PERMISSION)) {
                continue;
            }
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission' => self::PERMISSION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Permissions::forgetCache();
    }
};
