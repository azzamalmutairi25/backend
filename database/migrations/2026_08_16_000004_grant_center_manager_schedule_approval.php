<?php

use App\Security\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  منح «مدير المركز» اعتماد موجة الجدولة — وله وحده
// ════════════════════════════════════════════════════════════
//
// فصل مهام لا صلاحية تجميلية: قبلها كان مسؤول الجدولة ومدير المركز يحملان
// schedule.distribute نفسها، فمن يبني الجدول هو من يعتمده — وخطوة «إرسال
// الجدولة إلى مدير المركز للاعتماد» لم يكن لها معنى تقني. الصلاحية الجديدة
// لا تُمنح لـSCHEDULER عمداً.
//
// وهي قابلة للتفويض لشخصٍ بعينه من شاشة المستخدمين (ليست في NON_DELEGABLE)،
// كي لا يقف الاعتماد حين يغيب مدير المركز.
//
// إضافةٌ لا استبدال: صفٌّ واحد، ولا يُمسّ شيء ممّا حُرِّر من الشاشة.
return new class extends Migration
{
    private const ROLE = 'CENTER_MANAGER';

    private const PERMISSION = 'schedule.approve_center';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');
        if (! $roleId) {
            return;   // منصّة لم تُبذَر بعد — المصفوفة تكفيها
        }

        $rows = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission');

        // دورٌ جُرّد عمداً من الشاشة — لا يُنقض قراره هنا
        if ($rows->count() === 1 && $rows->first() === Permissions::PLACEHOLDER) {
            return;
        }
        // دورٌ بلا صفوف يقع على المصفوفة، وقد أُضيفت فيها — فلا حاجة لصفّ هنا
        if ($rows->isEmpty() || $rows->contains(self::PERMISSION)) {
            return;
        }

        DB::table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission' => self::PERMISSION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');
        if (! $roleId) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission', self::PERMISSION)
            ->delete();
    }
};
