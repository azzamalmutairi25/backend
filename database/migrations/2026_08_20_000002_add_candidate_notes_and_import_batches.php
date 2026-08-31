<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  ملاحظاتُ المشارك، ودفعاتُ الاستيراد.
//
//  ١) `candidates.notes` — حقلٌ حرّ اختياري. ما كان له موضع فكان يُكتب في
//     حقولٍ ليست له (الإدارة، المسمّى) فيُفسدها للتصفية والتقارير.
//
//  ٢) `import_batches` — الاستيراد صار يقبل عشرة آلاف صفّ، وهذا لا يُعالَج
//     في نداءٍ واحد: المتصفّح ينقطع قبل أن ينتهي، والمُدخِل لا يعرف أوقف
//     العمل أم لا يزال يجري. فصار الملفّ يُقيَّد دفعةً لها حالةٌ وعدّاد،
//     وتُعالَج صفوفها في الخلفية، وتُقرأ الحالة من هنا لا من طول الانتظار.
//
//     والصفوف تُخزَّن في العمود `payload` لا في جدولٍ مستقل: الدفعة تُقرأ
//     كاملةً أو لا تُقرأ، ولا يُستعلَم عن صفٍّ بعينه — فجدولٌ بعشرة آلاف
//     صفٍّ لكل رفعة يُثقل القاعدة بلا فائدة تُقابل ثقله.
// ════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('classification');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 255)->nullable();
            // queued → processing → completed | failed
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            // الصفوف الواردة، والمرفوضُ منها بسببه — يُقرأ في شاشة الأخطاء
            $table->json('payload')->nullable();
            $table->json('failures')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // شاشة «رفعاتي» تقرأ آخر دفعات صاحبها مرتّبةً — فهرسٌ لهذا بعينه
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
