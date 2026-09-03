<?php

use App\Security\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// منح «مدير المركز» تسليم الجدولة للجهات.
//
// فعلٌ يخرج من المركز إلى خارجه — فصلاحيته مستقلّة عن بناء الجدول واعتماده،
// ولا تُمنح لمسؤول الجدولة. وهي قابلة للتفويض بالاستثناء الفردي حين يغيب.
return new class extends Migration
{
    private const ROLE = 'CENTER_MANAGER';

    private const PERMISSION = 'schedule.dispatch';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');
        if (! $roleId) {
            return;
        }
        $rows = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission');
        if ($rows->count() === 1 && $rows->first() === Permissions::PLACEHOLDER) {
            return;
        }
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
        DB::table('role_permissions')->where('role_id', $roleId)
            ->where('permission', self::PERMISSION)->delete();
    }
};
