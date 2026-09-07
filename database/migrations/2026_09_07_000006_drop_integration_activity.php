<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// نزع «التمرين التكاملي» من مفردات الأنشطة.
//
// نشاطٌ وُضع في المفردات ولم يُستعمل قطّ: صفر جلسة، وصفر تقييم، وصفر مقعدٍ
// في لوحة أي موجة. فحذفه نزعُ لفظٍ لا ترحيلُ بيانات — ولا صفَّ واحداً يتأثّر.
//
// وبقاؤه ليس محايداً: كل قائمةٍ تعرضه تُغري بجدولته، وأول جلسةٍ تُبنى عليه
// تصل يوم التنفيذ بلا شاشة تقييمٍ تقبلها — فـ`evaluations.activity` لا يعرف
// إلا المقابلة وحلقة النقاش. كان النشاط باباً يفتح على جدار.
//
// والقيد وحده هنا: أسماء العرض في المتحكّمات تُنزع معه في التغيير نفسه.
return new class extends Migration
{
    private const WITHOUT = ['interview', 'discussion', 'measurement'];

    private const WITH = ['interview', 'discussion', 'measurement', 'integration'];

    public function up(): void
    {
        // حارسٌ قبل الحذف: لو وُجد صفٌّ في تنصيبٍ آخر، نقف بدل أن نكسر قيده
        $count = DB::table('schedules')->where('activity', 'integration')->count();
        if ($count > 0) {
            throw new RuntimeException(
                "تعذّر نزع «التمرين التكاملي»: توجد {$count} جلسة عليه. رحّلها أو احذفها أولاً."
            );
        }

        $this->setCheck(self::WITHOUT);
    }

    public function down(): void
    {
        $this->setCheck(self::WITH);
    }

    private function setCheck(array $values): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement('ALTER TABLE schedules DROP CONSTRAINT IF EXISTS schedules_activity_check');
        DB::statement("ALTER TABLE schedules ADD CONSTRAINT schedules_activity_check CHECK (activity::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
