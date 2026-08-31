<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// صيانة يومية: كشف الغياب، تذكير جلسات الغد، تصعيد التقارير المتأخرة
Schedule::command('kafaat:daily')->dailyAt('06:00');

// ── بحيرة التقارير ────────────────────────────────────────────────────
// الأوامر الثلاثة تخرج فوراً حين تكون البحيرة معطّلة، فوجودُها في المجدول
// بلا تفعيلٍ لا يكلّف شيئاً.

// الشحن كل دقيقة: هذا هو سقفُ طزاجة البحيرة وحدُّها المُعلَن —
// دقيقةٌ واحدة بين اعتماد التقرير وظهوره للمستهلك. لا يُوعَد بأسرع منها
// ما دام المجدول هو المُشغِّل. withoutOverlapping يمنع تراكبَ تشغيلتين
// على الصندوق نفسه لو طالت واحدة.
Schedule::command('kafaat:lake:ship')->everyMinute()->withoutOverlapping();

// اللقطات في آخر اليوم لا في أوّل التالي: التقرير اليومي يُحسب من حالةٍ
// راهنة، فلقطتُه بعد منتصف الليل تصف يوماً انتهى بأرقامٍ تغيّرت.
Schedule::command('kafaat:lake:snapshot')->dailyAt('23:45');

// المطابقة ليلاً: تكشف ما لم يصل، وتُصلحه. تعمل بعد الصيانة اليومية
// لا قبلها، فتُطابق حالةً استقرّت.
Schedule::command('kafaat:lake:reconcile --repair')->dailyAt('03:30');

// الصيانة: الأقسام مُقدَّماً وتقليم الصندوق المشحون.
Schedule::command('kafaat:lake:maintain')->dailyAt('04:00');
