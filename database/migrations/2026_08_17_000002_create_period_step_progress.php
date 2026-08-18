<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تقدّم الموجة في سير العمل — صفٌّ لكل (موجة، خطوة) **يدوية**.
//
// الخطوة الآلية لا صفّ لها: حالتها تُحسب حيّاً من الموجة نفسها، فلا تنحرف
// نسخةٌ محفوظة عن الواقع حين تُحذف جلسة أو يُسحب اسم من اللوحة. تخزينها كان
// سيعني «مكتملة» بجانب خطوةٍ نُقض شرطها.
//
// و`skipped` حالةٌ ثالثة مقصودة لا تجميل: خطوةٌ لا تنطبق على هذه الدورة
// (لا عسكريين فيها فلا إرسال لوكالة الشؤون العسكرية) تُستثنى بسببٍ مكتوب،
// وتخرج من حساب النسبة — لا تبقى معلّقةً إلى الأبد ولا تُؤشَّر كذباً.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_step_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('scheduling_periods')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('scheduling_workflow_steps')->cascadeOnDelete();
            $table->string('status', 10)->default('done');   // done | skipped
            $table->string('note', 300)->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'step_id']);
            $table->index('period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_step_progress');
    }
};
