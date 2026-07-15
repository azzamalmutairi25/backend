<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// قطاع المستخدم: كل مقيّم ومساعد ومستشار حلقة نقاش مخصَّص لقطاع، ولا يُقيّم غير مرشحيه.
//
// nullable على مستوى المخطّط لا لأن الحقل اختياري، بل لأن الأدوار غير المحصورة
// بقطاع (مدير النظام، الجدولة، الاستقبال…) لا قطاع لها أصلاً — والإلزام يُفرض
// في UserController على الأدوار المحصورة وحدها. جعله NOT NULL كان سيمنع
// إنشاء مدير نظام.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sector_id')->nullable()->after('role_id')->constrained('sectors');
            $table->index('sector_id');
        });

        // المقيّمون القائمون لا قطاع لهم بعد إضافة العمود، والقاعدة تمنع المحصور
        // بلا قطاع من تقييم أحد — أي أن الترقية وحدها تُعطّل كل مقيّم بصمت.
        // نُسندهم لأول قطاع مؤقتاً ليبقى النظام عاملاً، ويُصحَّح من إدارة المستخدمين.
        $first = DB::table('sectors')->orderBy('id')->value('id');
        if ($first) {
            $bound = DB::table('roles')
                ->whereIn('code', ['EVALUATOR', 'DISCUSSION_EVAL', 'ASSISTANT'])
                ->pluck('id');

            DB::table('users')
                ->whereIn('role_id', $bound)
                ->whereNull('sector_id')
                ->update(['sector_id' => $first]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['sector_id']);
            $table->dropIndex(['sector_id']);
            $table->dropColumn('sector_id');
        });
    }
};
