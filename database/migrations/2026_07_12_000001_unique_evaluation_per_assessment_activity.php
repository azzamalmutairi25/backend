<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// قيد فريد: تقييم واحد لكل (دورة + نشاط) — يمنع التكرار على مستوى قاعدة البيانات
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->unique(['assessment_id', 'activity'], 'evals_assessment_activity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropUnique('evals_assessment_activity_unique');
        });
    }
};
