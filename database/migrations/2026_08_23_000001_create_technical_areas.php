<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// المجالات الفنية — الأساس الذي يُرشَّح عليه المشارك.
//
// جدولٌ مرجعي مُدار كـ`ranks` و`expertise_areas`، لا أربعة أعمدة منطقية على
// `candidates`. السبب أنّ التصنيف قرارُ مركزٍ لا ثابتُ شيفرة: مجالٌ خامس يعني
// صفّاً يُضاف من الإعدادات، لا هجرةً تُكتب وتُنشر ويُعاد بها بناء قيد.
//
// وهو **غير** `expertise_areas`: تلك تخصّصات مجال (أمن المنشآت، المرور،
// الأدلة الجنائية) تُطابَق بها خبرة المقيّم بموضوع المشارك. وهذه مستوى أعلى —
// أيّ جانبٍ من الكفاءة يُقاس فيه أصلاً. اسمان مختلفان لأنّهما شيئان مختلفان.
return new class extends Migration
{
    // القائمة الأولى كما وردت في نموذج المركز. تُحرَّر بعدها من الإعدادات.
    private const SEED = [
        'القيادة',
        'تمكين الموظفين',
        'التميز في أداء العمل',
        'مهارات فنية تخصصية',
    ];

    public function up(): void
    {
        Schema::create('technical_areas', function (Blueprint $table) {
            $table->id();
            $table->string('label_ar', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('label_ar');
            $table->index(['is_active', 'sort_order']);
        });

        // المشارك ↔ مجالاته. الحذف يتتالى من الطرفين: مجالٌ حُذف لم يعد وصفاً،
        // ومشاركٌ حُذف لا مجال له.
        Schema::create('candidate_technical_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('technical_area_id')->constrained('technical_areas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['candidate_id', 'technical_area_id']);
            // شاشة الترشيح تسأل «من في هذا المجال؟» لا «ما مجالات هذا المشارك؟»،
            // فالفهرس على الطرف الذي يُبحث به.
            $table->index('technical_area_id');
        });

        $now = now();
        DB::table('technical_areas')->insert(array_map(fn ($label, $i) => [
            'label_ar' => $label,
            'sort_order' => ($i + 1) * 10,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::SEED, array_keys(self::SEED)));
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_technical_areas');
        Schema::dropIfExists('technical_areas');
    }
};
