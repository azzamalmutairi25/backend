<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// توزيع أسبوعي للمرشحين على المقيّمين: يُقترَح ثم يُعتمَد.
//
// اقتراح واحد لكل أسبوع (قيد فريد على week_start) — نقطة التسلسل الوحيدة
// التي تصمد أمام ضغطتين متزامنتين: الثاني يصطدم بـ23505.
// بنوده تُخزَّن، وعند الاعتماد يُعاد التحقق من كل صف حيّاً قبل أن يصير جلسة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_proposals', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');                 // الأحد الذي يبدأ به الأسبوع الموزَّع
            $table->date('week_end');
            $table->unsignedSmallInteger('daily_cap');  // لقطة الحدّ وقت الاقتراح (للعرض)
            $table->string('status', 12)->default('draft'); // draft | approved | rejected
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedSmallInteger('placed')->default(0);   // كم بنداً صار جلسة عند الاعتماد
            $table->unsignedSmallInteger('dropped')->default(0);  // كم سقط في إعادة التحقق
            $table->timestamps();

            // اقتراح واحد لكل أسبوع — القيد هو حارس التسابق الوحيد الموثوق
            $table->unique('week_start');
            $table->index(['status', 'week_start']);
        });

        Schema::create('distribution_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('distribution_proposals')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates');
            $table->foreignId('evaluator_id')->constrained('users');
            $table->foreignId('sector_id')->constrained('sectors');
            $table->date('scheduled_date');             // اليوم المقترَح ضمن الأسبوع
            $table->string('activity', 20)->default('interview');
            $table->foreignId('schedule_id')->nullable()->constrained('schedules'); // يُملأ عند الاعتماد
            $table->string('drop_reason', 100)->nullable(); // إن سقط في إعادة التحقق
            $table->timestamps();

            // مرشّح لا يُقترَح مرتين في الاقتراح نفسه — المرشّح يُوزَّع مرة واحدة
            $table->unique(['proposal_id', 'candidate_id']);
            $table->index('evaluator_id');
        });

        // الحدّ اليومي لكل مقيّم — إعداد قابل للتغيير من الشاشة
        DB::table('settings')->updateOrInsert(
            ['key' => 'distribution.daily_cap_per_evaluator'],
            ['value' => '5', 'description' => 'عدد المرشحين لكل مقيّم في اليوم', 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_items');
        Schema::dropIfExists('distribution_proposals');
        DB::table('settings')->where('key', 'distribution.daily_cap_per_evaluator')->delete();
    }
};
