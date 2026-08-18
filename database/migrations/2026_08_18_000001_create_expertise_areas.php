<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مجالات الخبرة — «ربط كل مشارك مع المستشار حسب الخبرات».
//
// الخطوة السابعة في المخطّط كانت تجري بالعين المجرّدة: المُجدوِل يقرأ سيرة
// المشارك ثم يختار مستشاراً يظنّه قريباً منها. و`users` لم يكن فيها حقل خبرة
// من أي نوع، فلا شيء يُطابَق أصلاً.
//
// جدول مرجعي مُدار من الإعدادات كـ`ranks`: المركز يكتب مجالاته بنفسه (أمن
// المنشآت، المرور، الأدلة الجنائية…) ولا تُفرض عليه قائمة من الشيفرة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expertise_areas', function (Blueprint $table) {
            $table->id();
            $table->string('label_ar', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('label_ar');
            $table->index(['is_active', 'sort_order']);
        });

        // المستخدم ↔ مجالاته. الحذف يتتالى من الطرفين: مجالٌ حُذف لم يعد وصفاً،
        // ومستخدمٌ حُذف لا خبرة له.
        Schema::create('user_expertise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('expertise_area_id')->constrained('expertise_areas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'expertise_area_id']);
            $table->index('expertise_area_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_expertise');
        Schema::dropIfExists('expertise_areas');
    }
};
