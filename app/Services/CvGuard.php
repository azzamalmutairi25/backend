<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Validation\ValidationException;
use Normalizer;

// ════════════════════════════════════════════════════════════
//  حارس السيرة الذاتية — نظافة يونيكود + منع تسرّب هوية المرشح.
//
//  السيرة نصّ حرّ يكتبه المرشح، والمقيّم يراها دون اسم. الخطر: أن يكتب
//  المرشح اسمه في «نبذة» أو «ملخّص الخبرة» فتنهار السرّية أول ما يقرؤها المقيّم.
//  دفاعان متكاملان:
//   • directIdentifierHit — يُرفض الحفظ (لأي كاتب: مرشح أو إدارة) إن حوى نصٌّ
//     حرّ اسمَ المرشح أو هويته أو جواله أو بريده. يرجع مفتاح الحقل لا محتواه.
//   • scrub — الضابط الحاسم: يطمس أي معرّف عند كل حدود عرض للمقيّم، فلا تعتمد
//     السرّية على طريقة الكتابة (يمسك تعديلات الإدارة والسجلّات القديمة والتحايل).
//
//  الكشف: أي مقطع اسم مخزَّن طوله ≥3 (الأول أو اللقب)، يُطابَق بالعربية المطبَّعة
//  وبنقحرة لاتينية تقريبية على كل حقل. الأرقام ≥9 والبريد والروابط أيضاً.
//
//  الخطر المتبقّي (معرّفات شبه — لقب+جهة+سنوات في فئة صغيرة، أو اسم لاتيني
//  قصير يفوت النقحرة) غير قابل للإزالة في سيرة صادقة، ومقايضة مقبولة.
// ════════════════════════════════════════════════════════════

class CvGuard
{
    private const REDACT = '«•••»';
    private const MESSAGE = 'لا تُدرج اسمك أو رقم هويتك أو جوالك أو بريدك في السيرة — التقييم يتم دون معرفة اسمك.';

    // نقحرة عربي → لاتيني تقريبية لبناء هيكل مطابقة
    private const TRANSLIT = [
        'ا' => 'a', 'أ' => 'a', 'إ' => 'a', 'آ' => 'a', 'ٱ' => 'a', 'ب' => 'b',
        'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd',
        'ذ' => 'th', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
        'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f',
        'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h',
        'ة' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'y', 'ء' => '', 'ؤ' => 'w', 'ئ' => 'y',
    ];

    // مفاتيح النصّ الحرّ داخل الوثيقة (مسار منقّط → قيمة) — كل ورقة نصّية
    private static function leaves(array $doc): array
    {
        $out = [];
        $add = function (string $path, $val) use (&$out) {
            if (is_string($val) && $val !== '') $out[$path] = $val;
        };
        $add('currentPosition', $doc['currentPosition'] ?? null);
        $add('briefBio', $doc['briefBio'] ?? null);
        foreach (($doc['qualifications'] ?? []) as $i => $q) {
            $add("qualifications.$i.major", $q['major'] ?? null);
            $add("qualifications.$i.institution", $q['institution'] ?? null);
        }
        foreach (($doc['experiences'] ?? []) as $i => $e) {
            $add("experiences.$i.position", $e['position'] ?? null);
            $add("experiences.$i.organization", $e['organization'] ?? null);
            $add("experiences.$i.summary", $e['summary'] ?? null);
        }
        foreach (($doc['certifications'] ?? []) as $i => $c) {
            $add("certifications.$i.name", $c['name'] ?? null);
            $add("certifications.$i.issuer", $c['issuer'] ?? null);
        }
        return $out;
    }

    // ── تنظيف نصّ واحد: HTML، محارف تحكّم، عرض صفري، تجاوز اتجاه، توحيد NFC ──
    public static function sanitize(?string $s): ?string
    {
        if ($s === null) return null;
        $s = strip_tags($s);
        $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', '', $s);
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s);
        if (class_exists(Normalizer::class)) {
            $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
        }
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s) === '' ? null : trim($s);
    }

    // توحيد عربي للمطابقة: تصغير، حذف تشكيل وتطويل، طيّ حروف، أرقام لاتينية
    public static function normalizeAr(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي',
            'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', 'ء' => '',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    // هيكل لاتيني: أحرف فقط، حذف الحركات، طيّ التكرار — لمطابقة النقحرة
    private static function latinSkeleton(string $latin): string
    {
        $latin = mb_strtolower($latin, 'UTF-8');
        $latin = preg_replace('/[^a-z]/', '', $latin);
        $latin = preg_replace('/[aeiou]/', '', $latin);
        return preg_replace('/(.)\1+/', '$1', $latin); // طيّ الأحرف المكرّرة
    }

    // سياق مطابقة من بيانات المرشح (يفكّ التشفير مرة واحدة)
    public static function context(Candidate $c): array
    {
        $connectors = array_map([self::class, 'normalizeAr'], ['بن', 'بنت', 'ابن', 'ابو', 'ال']);

        $name = (string) ($c->full_name ?? '');
        $raw = $name === '' ? [] : explode(' ', self::normalizeAr($name));
        // مقاطع دالّة طولها ≥3 (تُسقَط الوصلات والمقاطع القصيرة المبهمة)
        $tokens = array_values(array_unique(array_filter($raw, fn ($t) => mb_strlen($t) >= 3 && !in_array($t, $connectors, true))));

        $skeletons = [];
        foreach ($tokens as $t) {
            $sk = self::latinSkeleton(strtr($t, self::TRANSLIT));
            if (mb_strlen($sk) >= 3) $skeletons[] = $sk;
        }

        return [
            'tokens' => $tokens,                        // مقاطع عربية مطبَّعة
            'skeletons' => array_values(array_unique($skeletons)), // هياكل لاتينية للنقحرة
            'id' => preg_replace('/\D/', '', (string) ($c->national_id ?? '')),
            'mobile' => preg_replace('/\D/', '', (string) ($c->mobile ?? '')),
        ];
    }

    // ── رفض الحفظ إن حوى نصٌّ حرّ معرّفاً مباشراً (أي كاتب) ──
    public static function assertClean(array $doc, Candidate $c): void
    {
        $hit = self::directIdentifierHit($doc, $c);
        if ($hit !== null) {
            throw ValidationException::withMessages([$hit => [self::MESSAGE]]);
        }
    }

    // يرجع مفتاح أول حقل يحوي معرّفاً، أو null. لا يرجع محتوى أبداً.
    public static function directIdentifierHit(array $doc, Candidate $c): ?string
    {
        $ctx = self::context($c);
        foreach (self::leaves($doc) as $path => $val) {
            if (self::hasIdentifier($val, $ctx)) {
                return $path;
            }
        }
        return null;
    }

    // هل يحوي النصّ اسم المرشح أو هويته أو جواله أو بريده أو رقماً طويلاً؟
    private static function hasIdentifier(string $val, array $ctx): bool
    {
        // أرقام: هوية/جوال المرشح، أو أي سلسلة ≥9 (السنوات حقول منفصلة)
        $digits = preg_replace('/\D/', '', strtr($val, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));
        if ($ctx['id'] !== '' && str_contains($digits, $ctx['id'])) return true;
        if ($ctx['mobile'] !== '' && mb_strlen($ctx['mobile']) >= 9 && str_contains($digits, $ctx['mobile'])) return true;
        if (preg_match('/[0-9\x{0660}-\x{0669}]{9,}/u', $val)) return true;
        // بريد أو رابط
        if (preg_match('/[\w.%+\-]+@[\w.\-]+\.[a-z]{2,}/iu', $val)) return true;
        if (preg_match('#https?://|www\.#iu', $val)) return true;

        // الاسم بالعربية: أي مقطع مخزَّن (≥3) يظهر كلمةً في النصّ المطبَّع
        $norm = ' ' . self::normalizeAr($val) . ' ';
        foreach ($ctx['tokens'] as $tok) {
            if (str_contains($norm, ' ' . $tok . ' ')) return true;
        }
        // الاسم باللاتينية: هيكل أي كلمة لاتينية يطابق هيكل مقطع اسم
        if ($ctx['skeletons'] && preg_match_all('/[A-Za-z]+/', $val, $m)) {
            foreach ($m[0] as $word) {
                $sk = self::latinSkeleton($word);
                if (mb_strlen($sk) < 3) continue;
                foreach ($ctx['skeletons'] as $ns) {
                    if ($sk === $ns || (mb_strlen($ns) >= 4 && str_contains($sk, $ns))) return true;
                }
            }
        }
        return false;
    }

    // ── الضابط الحاسم: طمس المعرّفات عند العرض للمقيّم ──
    public static function scrub(array $doc, Candidate $c): array
    {
        $ctx = self::context($c);
        $f = fn ($v) => is_string($v) && $v !== '' ? self::scrubText($v, $ctx) : $v;

        $doc['currentPosition'] = $f($doc['currentPosition'] ?? null);
        $doc['briefBio'] = $f($doc['briefBio'] ?? null);
        foreach (($doc['qualifications'] ?? []) as $i => $q) {
            $doc['qualifications'][$i]['major'] = $f($q['major'] ?? null);
            $doc['qualifications'][$i]['institution'] = $f($q['institution'] ?? null);
        }
        foreach (($doc['experiences'] ?? []) as $i => $e) {
            $doc['experiences'][$i]['position'] = $f($e['position'] ?? null);
            $doc['experiences'][$i]['organization'] = $f($e['organization'] ?? null);
            $doc['experiences'][$i]['summary'] = $f($e['summary'] ?? null);
        }
        foreach (($doc['certifications'] ?? []) as $i => $ct) {
            $doc['certifications'][$i]['name'] = $f($ct['name'] ?? null);
            $doc['certifications'][$i]['issuer'] = $f($ct['issuer'] ?? null);
        }
        return $doc;
    }

    private static function scrubText(string $val, array $ctx): string
    {
        // بريد وروابط وأرقام طويلة
        $val = preg_replace('/[\w.%+\-]+@[\w.\-]+\.[a-z]{2,}/iu', self::REDACT, $val);
        $val = preg_replace('#https?://\S+|www\.\S+#iu', self::REDACT, $val);
        $val = preg_replace('/[0-9\x{0660}-\x{0669}]{9,}/u', self::REDACT, $val);

        // طمس الكلمات التي تطابق مقاطع الاسم (عربي أو لاتيني)، مع إبقاء الفواصل
        $parts = preg_split('/(\s+)/u', $val, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $k => $tok) {
            if (trim($tok) === '') continue;
            if (self::wordIsName($tok, $ctx)) $parts[$k] = self::REDACT;
        }
        return implode('', $parts);
    }

    private static function wordIsName(string $word, array $ctx): bool
    {
        $ar = self::normalizeAr($word);
        if ($ar !== '' && in_array($ar, $ctx['tokens'], true)) return true;
        if ($ctx['skeletons'] && preg_match('/[A-Za-z]/', $word)) {
            $sk = self::latinSkeleton($word);
            if (mb_strlen($sk) >= 3) {
                foreach ($ctx['skeletons'] as $ns) {
                    if ($sk === $ns || (mb_strlen($ns) >= 4 && str_contains($sk, $ns))) return true;
                }
            }
        }
        return false;
    }
}
