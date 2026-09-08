<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// خلايا شبكة جدولة المستشارين: (فترة × يوم × مستشار) ← عددٌ مخطَّط.
//
// الشبكة هي جدول المركز المعتمد: صفوفها أيام العمل، وأعمدتها المستشارون
// برموزهم، وخليتها **عدد المقابلات المخطَّطة** لذلك المستشار في ذلك اليوم.
// ومجموع الصفّ يُقارَن بالطاقة اليومية، ومجموع العمود حصّة المستشار.
//
// ── لماذا جدولٌ مستقلّ لا حسابٌ من الجلسات ──
// الشبكة **خطّةٌ تسبق الجلسات**: تُبنى قبل أن يُختار مشاركٌ واحد، ومنها
// يُعرف كم مقعداً يُملأ. وحسابُها من الجلسات يجعلها مرآةً لما وقع لا خطّةً
// لما سيقع — فلا يبقى ما يُقارَن به التنفيذ.
//
// ── وما لا يُخزَّن هنا ──
// الإجازة والتدريب وحلقة النقاش لا صفَّ لها في هذا الجدول: مصدرها
// `assessor_absences` بمداها وسببها. وخليةٌ بلا صفٍّ هنا وبلا غيابٍ هناك
// تعني «صفر» لا «غير معلوم» — والفرق بينهما لا يهمّ في خطّة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_grid_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('scheduling_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('cell_date');
            // العدد المخطَّط — صفرٌ يعني «لا يُسنَد إليه اليوم» صراحةً
            $table->unsignedSmallInteger('planned')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_id', 'user_id', 'cell_date']);
            $table->index(['period_id', 'cell_date']);
        });

        // ── الاقتران التدريبي ──
        // مستشارٌ تحت التدريب يُرافق مضيفاً ولا يُحتسب له مشاركون. مكانه
        // `assessor_absences` لأنه غيابٌ عن مقاعد المقابلات لا عن العمل —
        // والسبب `training` قائمٌ فيها، وينقصه **بمن** يتدرّب.
        Schema::table('assessor_absences', function (Blueprint $table) {
            $table->foreignId('host_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessor_absences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_user_id');
        });

        Schema::dropIfExists('period_grid_cells');
    }
};
