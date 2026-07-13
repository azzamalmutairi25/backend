<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ربط نتائج القياس بدورة التقييم (كبقية الكيانات) — نتيجة واحدة لكل دورة
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurement_results', function (Blueprint $table) {
            $table->foreignId('assessment_id')->nullable()->after('candidate_id')
                ->constrained('assessments')->cascadeOnDelete();
        });

        // تعبئة الصفوف القائمة بأحدث دورة للمرشّح (لا شيء يُكتب إن كان الجدول فارغاً)
        DB::statement("
            UPDATE measurement_results mr
            SET assessment_id = (
                SELECT id FROM assessments a WHERE a.candidate_id = mr.candidate_id ORDER BY id DESC LIMIT 1
            )
            WHERE assessment_id IS NULL
        ");

        Schema::table('measurement_results', function (Blueprint $table) {
            $table->unique('assessment_id', 'measurement_assessment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('measurement_results', function (Blueprint $table) {
            $table->dropUnique('measurement_assessment_unique');
            $table->dropConstrainedForeignId('assessment_id');
        });
    }
};
