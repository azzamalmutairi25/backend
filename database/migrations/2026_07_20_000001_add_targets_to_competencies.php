<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المستوى المطلوب لكل كفاءة حسب الفئة القيادية (عليا/وسطى) — أساس تحليل الفجوة
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->unsignedTinyInteger('target_upper')->nullable()->after('weight');
            $table->unsignedTinyInteger('target_middle')->nullable()->after('target_upper');
        });
    }

    public function down(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->dropColumn(['target_upper', 'target_middle']);
        });
    }
};
