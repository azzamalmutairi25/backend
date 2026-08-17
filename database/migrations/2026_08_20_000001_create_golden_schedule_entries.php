<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// «الجدول الذهبي» — التاريخ ورمز المشارك، مجمّعين بالقطاع.
//
// ── لماذا جدولٌ لا عرضٌ محسوب ──
// لو كان إسقاطاً محسوباً من `schedules` لتغيّر أثراً رجعياً كلما حُذفت جلسة أو
// نُقلت. والخطوة السادسة في المخطّط تصف **سجلّاً يُكتب**: ما أُثبت في الجدول
// الذهبي لهذه الدورة يبقى كما أُثبت. لذلك الرمز والتاريخ **يُنسخان** ولا
// يُجلبان بانضمام.
//
// و`source` يفصل ما جاء من مزامنة الجدول عمّا كتبه الموظّف بيده: إعادة
// المزامنة تُحدّث الأول ولا تمسّ الثاني — صفٌّ أُضيف يدوياً لسببٍ إداري لا
// يمحوه زرٌّ يُضغط بعد أسبوع.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('golden_schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('scheduling_periods')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('participant_code', 20);

            // أثرُ المنشأ — قابلان للإفراغ فيبقى الصفّ اليدوي قائماً بذاته
            $table->foreignId('assessment_id')->nullable()->constrained('assessments')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();

            $table->string('source', 10)->default('sync');   // sync | manual
            $table->string('note', 200)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // مشاركٌ واحد في يومٍ واحد من الموجة صفٌّ واحد — والقيد هو الحارس،
            // فمزامنةٌ تُشغَّل مرّتين لا تضاعف الجدول
            $table->unique(['period_id', 'entry_date', 'participant_code']);
            $table->index(['period_id', 'sector_id']);
        });

        // ── تاريخ التقييم حقلاً لا انضماماً ──
        // «إضافة رموز المشاركين والتاريخ بقاعدة البيانات الأساسية» (الخطوة ١٠):
        // التاريخ كان علاقةً تُجلب بانضمام، فلا يُفلتَر ولا يُصدَّر ولا يُقرأ في
        // بطاقة المرشّح. عمودان محسوبان من جلسات الدورة يجعلانه حقلاً أوّل درجة.
        Schema::table('assessments', function (Blueprint $table) {
            $table->date('first_session_date')->nullable()->after('status');
            $table->date('last_session_date')->nullable()->after('first_session_date');
            $table->index('first_session_date');
        });

        // تعبئة رجعية على دفعات — الجدول قد يحمل عشرات الآلاف من الصفوف
        DB::table('assessments')->orderBy('id')->chunkById(5000, function ($rows) {
            $ids = collect($rows)->pluck('id')->all();
            $bounds = DB::table('schedules')
                ->whereIn('assessment_id', $ids)
                ->groupBy('assessment_id')
                ->selectRaw('assessment_id, MIN(schedule_date) as first_d, MAX(schedule_date) as last_d')
                ->get();
            foreach ($bounds as $b) {
                DB::table('assessments')->where('id', $b->assessment_id)->update([
                    'first_session_date' => $b->first_d,
                    'last_session_date' => $b->last_d,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropIndex(['first_session_date']);
            $table->dropColumn(['first_session_date', 'last_session_date']);
        });
        Schema::dropIfExists('golden_schedule_entries');
    }
};
