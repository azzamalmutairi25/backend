<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// تسليم الجدولة للجهات — الخطوة الحادية عشرة.
//
// المخطّط يذكر جهتين: العسكريون ← وكالة الشؤون العسكرية، والمدنيون ← الموارد
// البشرية. لكنّ فئات المشاركين صارت **ثلاثاً** (مدني/عسكري/متعاقد)، والمخطّط لا
// يذكر المتعاقد. فبدل التخمين في الشيفرة صار **الربط بياناً**: لكل جهة عمود
// `categories` يُعدَّد الفئات التي تستقبلها، ويُحرَّر.
//
// البذرة تضع المتعاقد مع الموارد البشرية — وهو الافتراض الظاهر لا الصامت،
// ويُغيَّر بتعديل صفٍّ واحد إن كان للمركز رأيٌ آخر.
//
// و`checksum` ليس تزييناً: هو بصمة الملفّ الذي سُلّم فعلاً، فيُثبت بعد شهور أنّ
// ما بيد الجهة هو ما أخرجه النظام لا نسخةً عُدِّلت في الطريق.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_authorities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_ar', 120);
            // الفئات التي تستقبلها — مفصولة بفاصلة من: civilian, military, contractor
            $table->string('categories', 60);
            $table->string('email', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('dispatch_authorities')->insert([
            [
                'code' => 'MILITARY_AFFAIRS',
                'name_ar' => 'وكالة الشؤون العسكرية',
                'categories' => 'military',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'HR',
                'name_ar' => 'الموارد البشرية',
                'categories' => 'civilian,contractor',
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        Schema::create('schedule_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authority_id')->constrained('dispatch_authorities')->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('scheduling_periods')->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('rows_count');
            $table->string('channel', 10)->default('download');   // download | print
            $table->char('checksum', 64);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['period_id', 'authority_id']);
            $table->index('date_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_dispatches');
        Schema::dropIfExists('dispatch_authorities');
    }
};
