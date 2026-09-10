<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  فهرسٌ أعمى للبحث بالاسم — والاسم يبقى مشفَّراً.
//
//  المشكلة: `full_name_enc` مشفَّر، ولا يُبحث في مشفَّرٍ بجملة SQL. وبحثٌ
//  يفكّ تشفير كل الصفوف ثم يفلتر يعمل على مئات وينهار على عشرات الآلاف.
//
//  والبحث بجزء الكلمة يمنع الحلّ السهل: بصمةُ الاسم الكامل تُطابق تامّاً
//  ولا تجد «فلان» في «فلان بن فلان»، وبصمةُ كل كلمةٍ لا تجد «محم» في «محمد».
//
//  ── الحلّ: بصمةٌ لكل مقطعٍ ثلاثيّ ──
//  يُطبَّع الاسم (تُزال العلامات و«ال» التعريف، وتُوحَّد الهمزات والتاء
//  المربوطة والياء)، ثم يُقطَّع إلى مقاطع ثلاثية، وتُحفَظ **بصمةُ** كل مقطع.
//  فالبحث عن «فلان» يُبصم مقاطعه ويُطابقها، والاسم لا يُخزَّن صريحاً في أي
//  موضع.
//
//  ── ثم يُتحقَّق بفكّ التشفير ──
//  الفهرس **يضيّق** ولا يحسم: مقاطع مشتركة قد تجمع اسمين مختلفين. فتُفكّ
//  الأسماء المرشَّحة وحدها — عشرات لا عشرات آلاف — ويُتحقَّق من الاحتواء
//  فعلاً. فالنتيجة دقيقة والكلفة محتملة.
//
//  ── حدُّه ──
//  أقلّ ما يُبحث به **ثلاثة أحرف**: حرفان يطابقان نصف القاعدة فلا معنى لهما.
//
//  ── وثمنه الأمني معلَن ──
//  فهرس المقاطع يكشف عن الاسم أكثر ممّا يكشفه فهرس الكلمات الكاملة: من ملك
//  الجدول استطاع تخمين حروفٍ متجاورة. ويبقى أبعد ما يكون عن تخزينه صريحاً،
//  والجدول محميٌّ بصلاحيات القاعدة كبقية الجداول.
// ════════════════════════════════════════════════════════════

class NameIndex
{
    /** أقلّ طولٍ للمقطع — وهو أقلّ ما يُبحث به */
    public const GRAM = 3;

    /** تطبيعٌ عربي: يُعيد استعمال المُوحِّد نفسه الذي تعتمده بقية المسارات */
    public static function normalise(string $name): string
    {
        $n = CvGuard::normalizeAr($name);

        // «ال» التعريف تُزال من أوّل كل كلمة: «الفلاني» و«فلاني» اسمٌ واحد
        // في الاستعمال، والفرق بينهما إملائيٌّ لا هُوَويّ.
        $words = array_filter(explode(' ', $n), fn ($w) => $w !== '');
        $words = array_map(
            fn ($w) => mb_strlen($w) > 3 && str_starts_with($w, 'ال') ? mb_substr($w, 2) : $w,
            $words
        );

        return implode(' ', $words);
    }

    /**
     * مقاطع الاسم الثلاثية — مبصومة، بلا تكرار.
     *
     * التقطيع **داخل كل كلمة** لا عبر الاسم كلّه: مقطعٌ يعبر فراغاً («نبن»
     * من «فلان بن») يربط كلمتين لا علاقة بينهما، فيُطابق أسماءً لا تشبهه.
     */
    public static function grams(string $name): array
    {
        $out = [];
        foreach (explode(' ', self::normalise($name)) as $word) {
            $len = mb_strlen($word);
            if ($len < self::GRAM) {
                continue;
            }
            for ($i = 0; $i + self::GRAM <= $len; $i++) {
                $out[] = hash('sha256', mb_substr($word, $i, self::GRAM));
            }
        }

        return array_values(array_unique($out));
    }

    /** يعيد بناء فهرس مشاركٍ واحد — يُستدعى عند كل كتابةٍ للاسم */
    public static function reindex(Candidate $candidate): void
    {
        DB::table('candidate_name_grams')->where('candidate_id', $candidate->id)->delete();

        $name = $candidate->full_name;
        if (! $name) {
            return;
        }

        $rows = array_map(
            fn ($g) => ['candidate_id' => $candidate->id, 'gram' => $g],
            self::grams($name)
        );
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('candidate_name_grams')->insert($chunk);
        }
    }

    /**
     * معرّفات المشاركين الذين يطابق اسمُهم هذا النصّ.
     *
     * خطوتان: الفهرس يضيّق، وفكّ التشفير يؤكّد. ويُشترط أن تكون **كل** مقاطع
     * المبحوث عنه موجودة — لا بعضها: «فلان» مقاطعها ثلاثة، ومن يحمل واحداً
     * منها فقط ليس المطلوب.
     *
     * @param  int  $cap  سقف المرشَّحين قبل فكّ التشفير — حارسٌ ضدّ بحثٍ فضفاض
     */
    public static function search(string $query, int $cap = 500): array
    {
        $grams = self::grams($query);
        if (! $grams) {
            return [];
        }

        $candidateIds = DB::table('candidate_name_grams')
            ->whereIn('gram', $grams)
            ->groupBy('candidate_id')
            ->havingRaw('count(distinct gram) = ?', [count($grams)])
            ->limit($cap)
            ->pluck('candidate_id')
            ->all();

        if (! $candidateIds) {
            return [];
        }

        // التأكيد: الفهرس يجمع من اشترك في المقاطع، وقد تجتمع في اسمٍ لا
        // يحتوي المبحوث عنه أصلاً («فلا» و«لان» في كلمتين مختلفتين).
        $needle = self::normalise($query);

        return Candidate::whereIn('id', $candidateIds)
            ->get(['id', 'full_name_enc'])
            ->filter(fn ($c) => $c->full_name && str_contains(self::normalise($c->full_name), $needle))
            ->pluck('id')
            ->values()
            ->all();
    }
}
