<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// لقطة سيرة مجمَّدة لكل دورة تقييم. المقيّم يقرأ اللقطة لا السيرة الحيّة،
// فتعديل السيرة لدورة لاحقة لا يمسّ أساس دورة سبق تقييمها (سلامة التقييم).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->text('cv_snapshot_enc')->nullable(); // Crypt(JSON) للوثيقة المجمَّدة
            $table->unsignedInteger('cv_snapshot_version')->nullable(); // نسخة السيرة الحيّة الملتقَطة
            $table->timestamp('cv_snapshotted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['cv_snapshot_enc', 'cv_snapshot_version', 'cv_snapshotted_at']);
        });
    }
};
