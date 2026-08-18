<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// الجنس — العمود الأول في نموذج المركز، وحقلٌ تُبنى عليه قرارات تشغيلية
// (فصل الجلسات، وقاعة الانتظار، ومن يستقبل).
//
// عمودٌ لا مفتاحٌ في وثيقة السيرة: الجدولة تفلتر به وتعدّه، والوثيقة المشفّرة
// لا تُفلتَر ولا تُفهرَس. وكل ما في النموذج ممّا يُقرأ ولا يُفلتَر به يبقى في
// الوثيقة كما هو.
//
// **قابلٌ للإفراغ في القاعدة ومطلوبٌ في التحقّق.** الصفوف القائمة سبقت العمود،
// و`NOT NULL` بلا قيمةٍ افتراضية يُسقط الهجرة عليها؛ ولا افتراضيَّ صحيحاً هنا —
// «ذكر» تخمينٌ يُكتب في سجلٍّ رسمي. فالمنعُ في طبقة الطلب حيث توجد رسالة تُقرأ.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('sector_id');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
