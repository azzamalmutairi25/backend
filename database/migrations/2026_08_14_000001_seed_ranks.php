<?php

use App\Models\Candidate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  زرع الرتب العسكرية والمراتب المدنية المعتمدة في الوزارة.
//
//  الجدول أُنشئ فارغاً عمداً (تجاوز تدريجي): ما دام فارغاً يبقى التصنيف على
//  المنطق القديم. وذاك المنطق يقرأ المرتبة المدنية بتعبير نمطي على صيغة
//  «م-13»، بينما المراتب تُكتب في ملفات الوزارة كلماتٍ («الثالثة عشرة»)،
//  فكانت كلّها تسقط إلى «وسطى» صامتةً — ومعها ينهار تصنيف القيادة العليا.
//
//  ⚠ الطبقة تُقرأ من إعداد البيئة نفسها لا من الثابت المكتوب في الصنف:
//    tierUpperRanks() و tierUpperGrade() ترجعان ما ضبطه المدير من شاشة
//    الإعدادات، والافتراضيَّ عند غيابه. والقائمة المُدارة **تفوز** على ذلك
//    الإعداد بعد الزرع، فلو زرعنا الثابت لانقلب تصنيف رتبةٍ ضبطها المدير
//    خلافه — على قاعدة التطوير مثلاً «عقيد» عليا بالإعداد ووسطى بالثابت،
//    فكان كل عقيد سينزل إلى الوسطى بهجرةٍ عنوانها «إضافة رتب».
//  فالزرع يُثبّت السلوك القائم ولا يغيّره، وتعديلُه بعد ذلك قرارٌ صريح
//  من شاشة الرتب.
//
//  و`updateOrInsert` لا `insert`: الهجرة تُعاد على قاعدةٍ فيها بعضُها فلا
//  تنكسر بمفتاحٍ مكرّر، ولا تدهس تسميةً عدّلها المدير من الشاشة.
// ════════════════════════════════════════════════════════════
return new class extends Migration
{
    // الرتب العسكرية بتسلسلها من الأدنى إلى الأعلى
    private const MILITARY = [
        'ملازم', 'ملازم أول', 'نقيب', 'رائد', 'مقدم', 'عقيد', 'عميد', 'لواء',
    ];

    // المراتب المدنية — السادسة إلى الخامسة عشرة.
    // التسميات كما تَرِد في مستندات الوزارة؛ ورقمُها هو ما يُقاس عليه.
    private const CIVILIAN = [
        6 => 'السادسة',
        7 => 'السابعة',
        8 => 'الثامنة',
        9 => 'التاسعة',
        10 => 'العاشرة',
        11 => 'الحادية عشرة',
        12 => 'الثانية عشرة',
        13 => 'الثالثة عشرة',
        14 => 'الرابعة عشرة',
        15 => 'الخامسة عشرة',
    ];

    public function up(): void
    {
        $now = now();
        // ما ضبطه المدير، أو الافتراضي عند غيابه — لا الثابت مباشرةً
        $upperRanks = Candidate::tierUpperRanks();
        $upperGrade = Candidate::tierUpperGrade();

        foreach (self::MILITARY as $i => $label) {
            DB::table('ranks')->updateOrInsert(
                ['category' => 'military', 'label' => $label],
                [
                    // الاحتواء لا المساواة: الإعداد قد يحمل «عميد» فتُطابقه
                    // «عميد» وحدها، وقد يحمل صيغةً أطول تحتوي التسمية
                    'tier' => array_reduce($upperRanks, fn ($c, $r) => $c || ($r !== '' && (str_contains($label, $r) || str_contains($r, $label))), false)
                        ? 'upper' : 'middle',
                    'sort_order' => ($i + 1) * 10,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        foreach (self::CIVILIAN as $grade => $label) {
            DB::table('ranks')->updateOrInsert(
                ['category' => 'civilian', 'label' => $label],
                [
                    'tier' => $grade >= $upperGrade ? 'upper' : 'middle',
                    'sort_order' => $grade * 10,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        // تُحذف المزروعة وحدها بالتسمية — رتبةٌ أضافها المدير بيده تبقى
        DB::table('ranks')->where('category', 'military')->whereIn('label', self::MILITARY)->delete();
        DB::table('ranks')->where('category', 'civilian')->whereIn('label', array_values(self::CIVILIAN))->delete();
    }
};
