<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// لوحة المقيّمين والمساعدين في الموجة — «اختيار الأسماء وتحديد نصاب كل مقيّم».
//
// النصاب كان قيمةً واحدة للجميع (الإعداد distribution.daily_cap_per_evaluator)،
// وهو ما لا يصف مركزاً فيه مقيّمٌ متفرّغ وآخر يحضر يومين. صار لكل اسمٍ في
// الموجة نصابه، ومن تُرك نصابه فارغاً يقع على الإعداد العام — فلا ينكسر
// مركزٌ لم يضبط النصابات بعد.
//
// و«المقعد» (seat) هو ما يجعل المساعد قابلاً للإسناد أصلاً: العمود
// schedules.assistant_id موجود منذ البداية ويُطبع في كشف الحضور، لكن لم
// يكن في المنصّة أي مسارٍ يُرجع قائمة المساعدين المؤهّلين — فكان الحقل
// يُكتب بنداء API يدوي أو لا يُكتب.
//
// الصفّ يحمل النشاط أيضاً، فالشخص الواحد قد يكون مقيّم مقابلات ومستشار
// حلقة نقاش في الموجة نفسها بنصابين مختلفين. لذلك الفريد رباعيّ:
// (الموجة، الشخص، النشاط، المقعد).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_assessors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('scheduling_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // نفس مفردات schedules.activity — لا مفردات ثانية توازيها فتنحرف عنها
            $table->string('activity', 20);
            $table->string('seat', 10)->default('evaluator');   // evaluator | assistant

            // النصاب: فارغ ⇒ الإعداد العام. صفر ليس فراغاً — صفرٌ يعني «مُدرَج
            // ولا يُسنَد إليه اليوم»، وهو حالةٌ مقصودة يعبّر عنها is_available.
            $table->unsignedSmallInteger('daily_quota')->nullable();
            $table->unsignedSmallInteger('period_quota')->nullable();

            $table->boolean('is_available')->default(true);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_id', 'user_id', 'activity', 'seat']);
            $table->index(['period_id', 'activity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_assessors');
    }
};
