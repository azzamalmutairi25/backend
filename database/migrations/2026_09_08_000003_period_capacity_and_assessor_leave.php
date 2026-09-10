<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// الفترة تعرف أيام عملها وطاقتها، والمستشار تُسجَّل إجازته.
//
// ── أيام العمل ──
// جدول المركز المعتمد يقع على الأحد–الخميس، والجمعة والسبت خارجه. وكانت
// الفترة مدىً من التواريخ بلا تمييز، فيُحسب «عدد أيامها» بالجمعة والسبت فيها
// — ويُقارَن به إجماليٌّ لا يشملهما.
//
// تُخزَّن أرقام أيام الأسبوع (0=الأحد … 6=السبت) نصّاً مفصولاً بفواصل: مجموعةٌ
// صغيرة ثابتة تُقرأ كاملةً في كل استعمال، ولا يُبحث فيها ولا يُنضَمّ عليها.
//
// ── الطاقة اليومية ──
// العدد المستهدف يومياً (اثنا عشر في جدول المركز). به يُقارَن مجموع كل يوم في
// شبكة المستشارين فيحمرّ عند الاختلاف — وبلا رقمٍ مرجعيّ لا معنى للمقارنة.
//
// ── الأيام المستثناة ──
// عطلةٌ رسمية داخل المدى. تُخزَّن قائمة تواريخ لا قاعدةً تُحسب: التقويم
// الرسمي لا يُشتقّ، ويُعلَن بأمرٍ فيُكتب.
//
// ── إجازات المستشارين ──
// كانت `period_assessors.is_available` بوليان واحد للفترة كلّها: إمّا يعمل
// فيها أو لا. والواقع مدىً — إجازةٌ من الأحد إلى الأربعاء، أو تدريبٌ أسبوعاً.
// وبلا تواريخ يُشطب الاسم من الفترة كلّها لأجل ثلاثة أيام.
//
// والسبب يُكتب لأنه يُقرأ في الشبكة: خانةٌ فارغة لا تقول أهو في إجازةٍ أم
// تدريبٍ أم يدير حلقة نقاش، وهذه ثلاثة أحوالٍ مختلفة في التخطيط.
return new class extends Migration
{
    private const REASONS = ['leave', 'training', 'discussion', 'other'];

    public function up(): void
    {
        Schema::table('scheduling_periods', function (Blueprint $table) {
            // أرقام أيام الأسبوع العاملة — 0=الأحد. الافتراض: الأحد–الخميس
            $table->string('work_days', 20)->default('0,1,2,3,4')->after('session_times');
            // العدد المستهدف يومياً — فارغٌ يعني «بلا هدف معلَن»
            $table->unsignedSmallInteger('daily_capacity')->nullable()->after('work_days');
            // تواريخ مستثناة داخل المدى (عطلة رسمية) — Y-m-d مفصولة بفواصل
            $table->text('excluded_dates')->nullable()->after('daily_capacity');
        });

        Schema::create('assessor_absences', function (Blueprint $table) {
            $table->id();
            // الفترة اختيارية: إجازةٌ قد تُسجَّل خارج أي فترة وتنطبق على ما
            // يقع في مداها من فترات
            $table->foreignId('period_id')->nullable()->constrained('scheduling_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 20)->default('leave');
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'from_date', 'to_date']);
            $table->index('period_id');
        });

        $this->setReasonCheck(self::REASONS);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assessor_absences DROP CONSTRAINT IF EXISTS assessor_absences_reason_check');
        }
        Schema::dropIfExists('assessor_absences');

        Schema::table('scheduling_periods', function (Blueprint $table) {
            $table->dropColumn(['work_days', 'daily_capacity', 'excluded_dates']);
        });
    }

    private function setReasonCheck(array $values): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement('ALTER TABLE assessor_absences DROP CONSTRAINT IF EXISTS assessor_absences_reason_check');
        DB::statement("ALTER TABLE assessor_absences ADD CONSTRAINT assessor_absences_reason_check CHECK (reason::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
