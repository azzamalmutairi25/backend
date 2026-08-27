<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\ExpertiseArea;

// ════════════════════════════════════════════════════════════
//  مطابقة خبرة المستشار بسيرة المشارك — «حسب الخبرات»
// ════════════════════════════════════════════════════════════
//
// المطابقة **اقتراحُ ترتيبٍ لا قرار**: تُقدَّم الأسماء الأقرب إلى سيرة المشارك،
// ويبقى الاختيار كاملاً بيد المُجدوِل — كما هو حال النصاب. لذلك لا تُحجب أسماء
// ولا يُمنع اختيار مَن درجته صفر.
//
// والمطابقة نصّية على نصٍّ مطبَّع: تُزال التشكيلات و«ال» التعريف، وتُوحَّد
// الهمزات والتاء المربوطة والياء — فيُطابَق «أمن المنشآت» و«الأمن المنشات».
// هذا التطبيع نظير ما يفعله ImportController مع أسماء القطاعات.
class ExpertiseMatcher
{
    /** تطبيع نصّ عربي للمطابقة — نظير ImportController::normalizeAr */
    public static function normalise(string $s): string
    {
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s);   // تشكيل وتطويل
        $s = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $s);
        $s = str_replace(['ة'], 'ه', $s);
        $s = str_replace(['ى'], 'ي', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * نصّ السيرة القابل للمطابقة: المنصب والنبذة والخبرات والشهادات.
     *
     * يُقرأ من `candidate_cvs` مباشرةً لا من مستندٍ مُنقّى: المطابقة تجري في
     * الخادم ولا يُعاد النصّ للعميل، فلا حاجة لكشفه ولا لتمريره عبر CvGuard.
     */
    public function candidateText(Candidate $candidate): string
    {
        $cv = $candidate->cv()->first();
        if (!$cv) {
            return '';
        }
        $doc = is_array($cv->data) ? $cv->data : (array) $cv->data;

        $parts = [
            $doc['currentPosition'] ?? '',
            $doc['briefBio'] ?? '',
            $doc['department'] ?? '',
            // «الإدارة العامة» تحمل أدلَّ ما في السيرة على المجال أحياناً
            // («الإدارة العامة للأدلة الجنائية»)، وكانت خارج المُطابَقة كلّها
            $doc['generalDepartment'] ?? '',
            $doc['rankTitle'] ?? '',
        ];
        foreach (($doc['experiences'] ?? []) as $x) {
            // و«القسم» كذلك («قسم مكافحة المخدرات») — نصٌّ دالٌّ كان يُهمَل
            $parts[] = ($x['position'] ?? '') . ' ' . ($x['organization'] ?? '') . ' ' . ($x['section'] ?? '');
        }
        foreach (($doc['certifications'] ?? []) as $x) {
            $parts[] = is_array($x) ? ($x['name'] ?? '') : (string) $x;
        }
        foreach (($doc['qualifications'] ?? []) as $x) {
            $parts[] = ($x['major'] ?? '') . ' ' . ($x['institution'] ?? '');
        }

        return self::normalise(implode(' ', array_filter($parts)));
    }

    /**
     * المجالات التي يذكرها نصّ السيرة.
     *
     * @return array<int,string> معرّف المجال ⇐ تسميته
     */
    public function areasInText(string $normalisedText): array
    {
        if ($normalisedText === '') {
            return [];
        }

        $hits = [];
        foreach (ExpertiseArea::active()->get() as $area) {
            $needle = self::normalise($area->label_ar);
            if ($needle !== '' && str_contains($normalisedText, $needle)) {
                $hits[$area->id] = $area->label_ar;
            }
        }
        return $hits;
    }
}
