<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// سيرة المرشح الذاتية — وثيقة واحدة لكل مرشح (شخص، لا دورة). تُخزَّن مشفّرة
// كلها ككتلة JSON واحدة (نفس نهج تشفير بيانات المرشح الحساسة)، لأن النصّ
// الحرّ قد يحمل بيانات تعريفية. القيد الفريد على candidate_id يمنع سباق الإنشاء.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_cvs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->unique('candidate_id'); // سيرة واحدة لكل مرشح + يحسم سباق الإنشاء
            $table->text('cv_data_enc')->nullable(); // Crypt(JSON) للوثيقة كاملة
            $table->unsignedInteger('version')->default(0); // رمز قفل تفاؤلي متزايد
            $table->string('source', 10)->default('portal'); // 'portal' | 'admin' — مصدر آخر تعديل
            // مؤشّر الفاعل فقط (لا المصدر): حذف الموظّف يُفرّغه لكن source يبقى
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_cvs');
    }
};
