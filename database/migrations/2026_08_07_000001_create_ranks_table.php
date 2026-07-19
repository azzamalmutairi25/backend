<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// قائمة الرتب/المراتب القابلة للإدارة — كل رتبة تُصنَّف عسكرية/مدنية وفئة قيادية
// (عليا/وسطى). تقود تصنيف تير المرشّح (Candidate::classifyTier) مع بقاء المنطق
// القديم احتياطاً للرتب غير المُدرَجة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->enum('category', ['military', 'civilian']);
            $table->enum('tier', ['upper', 'middle']);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['category', 'label']);
            $table->index(['category', 'is_active']);
        });

        // تُنشأ فارغة عمداً (تجاوز تدريجي): ما دامت فارغة يبقى تصنيف الفئة على المنطق
        // القائم (قائمة الإعدادات + عتبة الدرجة المدنية) بلا أي تغيير. وعند إضافة
        // المدير رتبةً مُدارة، تفوز على المنطق القديم لتلك الرتبة وحدها.
    }

    public function down(): void
    {
        Schema::dropIfExists('ranks');
    }
};
