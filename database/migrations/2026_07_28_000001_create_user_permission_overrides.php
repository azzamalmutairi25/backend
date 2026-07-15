<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تخصيص صلاحية لمستخدم بعينه فوق دوره — منحاً أو سحباً.
//
// الدور يبقى الأساس، وهذا استثناء موثّق لشخص. لا يُعدَّل الدور نفسه:
// تغييره يمسّ كل من يحمله، ويكسر بصمت حوكماتٍ تقفلها الاختبارات
// (مثل «من يكتب التقرير لا يعتمد مرحلته»).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission', 60);
            // true = امنح فوق الدور، false = اسحب رغم الدور
            $table->boolean('granted');
            $table->string('reason', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            // استثناء واحد لكل (مستخدم، صلاحية) — تكراره يجعل النتيجة تعتمد على الترتيب
            $table->unique(['user_id', 'permission']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
