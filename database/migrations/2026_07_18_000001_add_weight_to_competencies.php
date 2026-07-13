<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// وزن الكفاءة في احتساب التوافق الآلي (متوسط موزون) — الافتراضي 1 (متساوٍ)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->decimal('weight', 4, 2)->default(1.00)->after('max_level');
        });
    }

    public function down(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
