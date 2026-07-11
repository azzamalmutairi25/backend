<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * الأساس: فصل الشخص (candidates) عن دورة التقييم (assessments).
 * كل مرشح (شخص) يمكن أن يكون له عدة دورات، لكل دورة رمز/حالة/تقييمات/تقرير.
 * غير كاسر: نُبقي أعمدة candidate + نضيف assessment_id مُعبّأ بالتوازي.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) جدول الدورات
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('participant_code')->unique();
            $table->enum('assessment_type', ['comprehensive', 'executive'])->default('comprehensive');
            $table->enum('status', ['draft', 'scheduled', 'assessed', 'approved', 'completed'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index('candidate_id');
            $table->index('status');
        });

        // 2) ربط assessment_id في الجداول التابعة (nullable حتى لا يكسر الصفوف القائمة)
        foreach (['evaluations', 'schedules', 'final_reports'] as $tbl) {
            if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl, 'assessment_id')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->foreignId('assessment_id')->nullable()->after('candidate_id')
                        ->constrained('assessments')->nullOnDelete();
                });
            }
        }

        // 3) التعبئة: دورة واحدة لكل مرشح حالي (تحمل رمزه/حالته/نوعه)، وربط التابعين بها
        $now = now();
        foreach (DB::table('candidates')->get() as $c) {
            $assessmentId = DB::table('assessments')->insertGetId([
                'candidate_id'    => $c->id,
                'participant_code'=> $c->participant_code,
                'assessment_type' => $c->assessment_type ?? 'comprehensive',
                'status'          => $c->status ?? 'draft',
                'created_at'      => $c->created_at ?? $now,
                'updated_at'      => $now,
            ]);
            DB::table('evaluations')->where('candidate_id', $c->id)->update(['assessment_id' => $assessmentId]);
            DB::table('schedules')->where('candidate_id', $c->id)->update(['assessment_id' => $assessmentId]);
            DB::table('final_reports')->where('candidate_id', $c->id)->update(['assessment_id' => $assessmentId]);
        }
    }

    public function down(): void
    {
        foreach (['evaluations', 'schedules', 'final_reports'] as $tbl) {
            if (Schema::hasColumn($tbl, 'assessment_id')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('assessment_id');
                });
            }
        }
        Schema::dropIfExists('assessments');
    }
};
