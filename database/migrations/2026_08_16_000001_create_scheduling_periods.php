<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// موجة الجدولة — «الدورة» التي يعلن المستخدم تواريخها ثم يبني جدولها بيده.
//
// قبلها كانت التواريخ ثابتة في الشيفرة: `now()->next(SUNDAY)` وخمسة أيام عمل
// في ثابتٍ خاص داخل DistributionService. فلم يكن يمكن التعبير عن دورةٍ من
// ثلاثة أيام ولا من عشرة، ولا عن دورتين في شهر — والتوزيع الآلي وحده من
// يملك تحديد المدى. الموجة تنقل القرار إلى المستخدم وتترك المحرّك الآلي
// خياراً مساعداً لا مساراً وحيداً.
//
// وهي أيضاً **الشيء الذي يُعتمد**: ما دامت الجلسات تُبنى يدوياً، فليس هناك
// «مقترح توزيع» يعتمده مدير المركز — الذي يُعتمد هو الموجة بكل جلساتها.
// لذلك الحالة هنا لا في distribution_proposals: draft ← pending_center ←
// approved ← closed، والرفض يعيدها مسودّةً بسببٍ مكتوب.
//
// لا قيد فريد على التاريخ: موجتان قد تتداخلان عمداً (قطاعٌ في الصباح وآخر
// في المساء، أو دورة تعويضية داخل دورة). الفريد هو الاسم — وهو المُعرِّف
// الذي يُكتب على المستند المطبوع ويُرسل للجهة، فتكراره خطأ إدخال لا حالة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');

            // أوقات جلسات خاصة بهذه الموجة (H:i مفصولة بفاصلة). فارغة ⇒ تُقرأ
            // الأوقات العامة من الإعداد schedule.session_times، فلا تُنسخ قيمة
            // تتقادم بصمت حين يغيّر المركز أوقاته.
            $table->string('session_times', 120)->nullable();

            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 300)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('name');
            $table->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_periods');
    }
};
