<?php

use App\Security\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ثلاثة أدوارٍ تفصل من يُدخل عمّن يعتمد، واعتماد الفترة ينتقل لمسؤول الجدولة.
//
// ── لماذا الأدوار الثلاثة ──
// كان «مسؤول الجدولة» يفعل كل شيء: يضيف المشارك، ويعتمده، ويبني الفترة،
// ويُسند المستشارين، ويُرسل، ثم يعتمد ما أرسل. سلسلةٌ كاملة بيدٍ واحدة.
//
// فانقسم العمل: **موظّف إدخال البيانات** يملأ ولا يبتّ، و**موظّف إعداد
// الجدولة** يبني ويُرسل ولا يعتمد، و**مسؤول الجدولة** يعتمد الاثنين.
// و**موظّف التقييم** طبقةُ مراجعةٍ بين المستشار ومدير التقييم.
//
// ── ولماذا انتقل اعتماد الفترة ──
// كان لمدير المركز. ونُقل بقرار صاحب المنصّة: الفترة شأن إدارة الجدولة،
// ومدير المركز يطّلع. وفصلُ المهامّ لم يسقط — انتقل داخل الإدارة نفسها.
//
// والصلاحية مستقلّة لا مدموجة في `schedule.manage`: من يبني لا يعتمد، ولو
// صارتا واحدة لتعذّر الفصل غداً إلا بتعديلٍ برمجي.
return new class extends Migration
{
    private const ROLES = [
        ['code' => 'DATA_ENTRY', 'name_ar' => 'موظّف إدخال بيانات المشاركين'],
        ['code' => 'SCHEDULE_CLERK', 'name_ar' => 'موظّف إعداد الجدولة'],
        ['code' => 'ASSESS_CLERK', 'name_ar' => 'موظّف التقييم'],
    ];

    public function up(): void
    {
        $now = now();

        // ── الأدوار الثلاثة ──
        foreach (self::ROLES as $r) {
            $exists = DB::table('roles')->where('code', $r['code'])->exists();
            if (! $exists) {
                DB::table('roles')->insert($r + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        // ── صلاحياتها من المصفوفة ──
        // تُقرأ من الشيفرة لا تُكتب هنا: نسخةٌ في الهجرة تتفرّع عن المصفوفة
        // عند أول تعديل، فيصير للدور صلاحيتان مختلفتان في موضعين.
        $matrix = Permissions::matrix();
        foreach (self::ROLES as $r) {
            $roleId = DB::table('roles')->where('code', $r['code'])->value('id');
            if (! $roleId || empty($matrix[$r['code']])) {
                continue;
            }

            $rows = [];
            foreach ($matrix[$r['code']] as $perm) {
                $rows[] = [
                    'role_id' => $roleId,
                    'permission' => $perm,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            // موجودةٌ من قبل؟ لا تُكرَّر — القيد الفريد يحسمها، والتخطّي أوضح
            $held = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission')->all();
            $rows = array_values(array_filter($rows, fn ($x) => ! in_array($x['permission'], $held, true)));
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        }

        // ── اعتماد الفترة: يُمنح لمسؤول الجدولة ──
        $scheduler = DB::table('roles')->where('code', 'SCHEDULER')->value('id');
        if ($scheduler) {
            $has = DB::table('role_permissions')
                ->where('role_id', $scheduler)
                ->where('permission', Permissions::SCHEDULE_APPROVE)
                ->exists();
            if (! $has) {
                DB::table('role_permissions')->insert([
                    'role_id' => $scheduler,
                    'permission' => Permissions::SCHEDULE_APPROVE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // ── ويُسحب القديم من كل حامل ──
        // `schedule.approve_center` لم تعد تُقرأ في أي مسار. وتركُها ممنوحةً
        // يجعلها تظهر في شاشة الأدوار كصلاحيةٍ حيّة لا تفعل شيئاً.
        DB::table('role_permissions')
            ->where('permission', Permissions::SCHEDULE_APPROVE_CENTER)->delete();
        DB::table('user_permission_overrides')
            ->where('permission', Permissions::SCHEDULE_APPROVE_CENTER)->delete();
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', Permissions::SCHEDULE_APPROVE)->delete();

        $ids = DB::table('roles')->whereIn('code', array_column(self::ROLES, 'code'))->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $ids)->delete();
        // الحسابات المسندة لهذه الأدوار تمنع حذفها — تُترك، ويُترك الدور معها
        $free = DB::table('users')->whereIn('role_id', $ids)->doesntExist();
        if ($free) {
            DB::table('roles')->whereIn('id', $ids)->delete();
        }
    }
};
