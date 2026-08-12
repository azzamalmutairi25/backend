<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مجموعتا كشف اليوم (A/B) — بها يتحدّد مَن يبدأ بالمقابلة ومَن يبدأ بجلسة النقاش،
// فالمجموعتان تتبادلان الفترتين بعد جلسة القياس المشتركة.
//
// المجموعة خاصية للمشارك في يومٍ بعينه، لا للجلسة: المشارك الواحد له عدة جلسات
// في اليوم (قياس + مقابلة + نقاش) وكلها في مجموعة واحدة. لذلك الجدول مستقل
// عن schedules، وإلا تكرّر الحرف على كل جلسة وانحرفت النسخ عن بعضها.
//
// الحرف يُخزَّن لاتينياً (A/B) ويُعرَض عربياً (أ/ب): حرف ASCII يقارَن ويُفهرَس
// بلا مفاجآت ترميز، والعرض شأن الواجهة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            // دورة التقييم التي يخصّها الإسناد — تُلتقط وقت الإسناد للتأريخ
            $table->foreignId('assessment_id')->nullable()->constrained('assessments')->nullOnDelete();
            $table->date('roster_date');
            $table->string('group_letter', 1);   // A | B
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // مشارك واحد لا يقع في مجموعتين في اليوم نفسه — القيد هو الحارس،
            // لا فحصٌ في الكود يسبقه ضغطتان متزامنتان
            $table->unique(['candidate_id', 'roster_date']);
            $table->index(['roster_date', 'group_letter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_groups');
    }
};
