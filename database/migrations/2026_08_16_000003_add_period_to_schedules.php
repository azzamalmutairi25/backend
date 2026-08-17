<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ربط الجلسة بموجتها — قابل للإفراغ عمداً.
//
// كل صفٍّ قائم يبقى بـnull، فالجلسات المجدولة قبل اليوم لا تنتمي لموجة ولا
// يُطلب منها ذلك، وشاشة الجدولة تعمل كما هي بلا موجة. الموجة تُصبح **مِرشَحاً**
// للجلسات ومَعلَقاً لما بعدها (الجدول الذهبي، تسليم الجهة، ملف كل قطاع)،
// لا شرطاً لإنشاء جلسة.
//
// nullOnDelete لا cascade: حذف موجةٍ أُنشئت خطأً يجب ألا يمحو جلساتٍ حقيقية
// جرى فيها حضورٌ وتقييم — تفقد انتماءها لا وجودها.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignId('period_id')->nullable()->after('assessment_id')
                ->constrained('scheduling_periods')->nullOnDelete();
            $table->index(['period_id', 'schedule_date']);
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex(['period_id', 'schedule_date']);
            $table->dropConstrainedForeignId('period_id');
        });
    }
};
