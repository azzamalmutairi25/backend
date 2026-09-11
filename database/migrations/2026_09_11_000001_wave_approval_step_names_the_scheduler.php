<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// عنوانُ خطوة الاعتماد في «سير عمل الجدولة» ما زال يقول «مدير المركز».
//
// الاعتماد انتقل إلى مسؤول الجدولة في ٨ سبتمبر (2026_09_08_000004)، والخطوة
// مزروعةٌ بياناتٍ لا شيفرة، فلم تتبع. ويُحدَّث العنوان **فقط إن كان ما زال
// النصَّ المزروع حرفاً**: الإعدادات تحرّره، وتعديلٌ أجراه المالك لا يُمحى.
return new class extends Migration
{
    private const OLD = 'إرسال الجدولة إلى مدير المركز للاعتماد';

    private const NEW = 'إرسال الجدولة إلى مسؤول الجدولة للاعتماد';

    public function up(): void
    {
        DB::table('scheduling_workflow_steps')
            ->where('auto_key', 'period.approved')
            ->where('title_ar', self::OLD)
            ->update(['title_ar' => self::NEW, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('scheduling_workflow_steps')
            ->where('auto_key', 'period.approved')
            ->where('title_ar', self::NEW)
            ->update(['title_ar' => self::OLD, 'updated_at' => now()]);
    }
};
