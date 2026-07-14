<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// تسمية المركز الجديدة في قالب رسالة الدعوة المخزّن.
// البذور تُدرج مرة واحدة على قاعدة جديدة فقط، فالقواعد القائمة تحتاج تحديثاً صريحاً.
return new class extends Migration
{
    private const OLD = 'مركز الكفاءات: لديك جلسة تقييم بتاريخ {date} الساعة {time} في {location}';
    private const NEW = 'مركز تمكين الكفاءات: لديك جلسة تقييم بتاريخ {date} الساعة {time} في {location}';

    public function up(): void
    {
        // مطابقة القيمة القديمة بالضبط — قالب عدّله المستخدم يدوياً يُترك كما هو
        DB::table('settings')
            ->where('key', 'sms.invitation.template')
            ->where('value', self::OLD)
            ->update(['value' => self::NEW, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'sms.invitation.template')
            ->where('value', self::NEW)
            ->update(['value' => self::OLD, 'updated_at' => now()]);
    }
};
