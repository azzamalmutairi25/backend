<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تجميع الكفاءات: «مجموعة» للكفاءات السلوكية (سلوكية/تميز/إحساس) و«مجال»
// للكفاءات الفنية (مجالات التقييم). كلاهما نصّ حرّ اختياري يُسمّى من الإعدادات.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->string('group', 60)->nullable()->after('type');   // تجميع سلوكي
            $table->string('domain', 60)->nullable()->after('group'); // مجال فنّي
        });
    }

    public function down(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->dropColumn(['group', 'domain']);
        });
    }
};
