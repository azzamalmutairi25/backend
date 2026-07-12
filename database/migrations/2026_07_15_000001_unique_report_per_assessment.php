<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تقرير نهائي واحد لكل دورة تقييم — فهرس فريد يمنع سباق الإنشاء المتزامن
// (Postgres يسمح بتعدّد NULL، فلا يتأثّر أي سجلّ بلا assessment_id)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            $table->unique('assessment_id', 'final_reports_assessment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            $table->dropUnique('final_reports_assessment_unique');
        });
    }
};
