<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ربط خطوة «إرسال الجدولة للوزارة» بفحصها الآلي بعد بناء قدرتها.
//
// الخطوتان الأخريان الباقيتان — طباعة المستندات (٩) وملفّ كل قطاع (١٢) — تبقيان
// **يدويتين عمداً**: خروجُ الورق من الطابعة وحفظُ الملفّ فعلان خارج المنصّة لا
// تراهما، وإعلانُ اكتمالهما من سجلّ قراءةٍ ادّعاءٌ لا قياس.
return new class extends Migration
{
    private const TITLE = 'إرسال الجدولة للوزارة (العسكريين/وكالة الشؤون العسكرية)(المدنيين/الموارد البشرية)';
    private const KEY = 'period.dispatched';

    public function up(): void
    {
        DB::table('scheduling_workflow_steps')
            ->where('title_ar', self::TITLE)
            ->whereNull('auto_key')
            ->update(['auto_key' => self::KEY, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('scheduling_workflow_steps')
            ->where('title_ar', self::TITLE)
            ->where('auto_key', self::KEY)
            ->update(['auto_key' => null, 'updated_at' => now()]);
    }
};
