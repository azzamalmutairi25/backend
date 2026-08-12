<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  منح «مدير المركز» رؤية أسماء المرشحين — قرار صاحب المنصّة
// ════════════════════════════════════════════════════════════
//
// تعديل المصفوفة وحده لا يكفي على منصّة عاملة: منذ أن صارت صلاحيات الأدوار
// بياناتٍ في role_permissions، تُقرأ من الجدول ولا يُنظَر إلى المصفوفة إلا
// لدورٍ **لا صفوف له**. فالمصفوفة تخدم التنصيب الجديد و«إعادة الافتراضي»،
// وهذه الهجرة تخدم القائم.
//
// إضافةٌ لا استبدال: صفٌّ واحد يُضاف، ولا يُمسّ شيء ممّا حُرِّر من الشاشة.
return new class extends Migration
{
    private const ROLE = 'CENTER_MANAGER';
    private const PERMISSION = 'candidate.view_names';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');
        if (!$roleId) {
            return;   // منصّة لم تُبذَر بعد — المصفوفة تكفيها
        }

        $rows = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission');

        // دورٌ جُرّد من كل صلاحياته عمداً (لا يبقى إلا العلامة البديلة): تجريدٌ
        // اختاره المدير من الشاشة، ومنحُه صلاحيةً هنا نقضٌ لقراره بلا إنذار.
        if ($rows->count() === 1 && $rows->first() === \App\Security\Permissions::PLACEHOLDER) {
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
        if (!$roleId) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission', self::PERMISSION)
            ->delete();
    }
};
