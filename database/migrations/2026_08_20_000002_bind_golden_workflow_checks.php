<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ربط خطوتَي «الجدول الذهبي» و«ترحيل الرموز والتواريخ» بفحصيهما الآليَّين.
//
// هذا ما وعد به تصميم سير العمل: الخطوة اليدوية تصير آليةً **بكتابة مفتاحها**
// حين تُبنى قدرتها — بلا تغيير في الشيفرة ولا في الشاشة. المنصّة التي بُذرت
// قبل اليوم تُرقّى هنا، والجديدة تجدهما مربوطين في البذرة نفسها.
//
// التحديث بالعنوان لا بالمعرّف: الترتيب يتغيّر من الشاشة، والمعرّف يختلف بين
// التنصيبات. ومشروطٌ بأن يكون المفتاح فارغاً — فمن غيّر الخطوة بيده لا يُنقض
// قراره.
return new class extends Migration
{
    private const BINDINGS = [
        'إضافة التاريخ ورموز المشاركين في الجدول الذهبي' => 'period.golden_synced',
        'إضافة رموز المشاركين والتاريخ بقاعدة البيانات الأساسية' => 'period.dates_written',
    ];

    public function up(): void
    {
        foreach (self::BINDINGS as $title => $key) {
            DB::table('scheduling_workflow_steps')
                ->where('title_ar', $title)
                ->whereNull('auto_key')
                ->update(['auto_key' => $key, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::BINDINGS as $title => $key) {
            DB::table('scheduling_workflow_steps')
                ->where('title_ar', $title)
                ->where('auto_key', $key)
                ->update(['auto_key' => null, 'updated_at' => now()]);
        }
    }
};
