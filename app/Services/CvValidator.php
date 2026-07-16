<?php

namespace App\Services;

use App\Exceptions\CvTooLargeException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// ════════════════════════════════════════════════════════════
//  التحقّق من وثيقة السيرة الذاتية وإعادة بنائها من قائمة بيضاء.
//  لا نحفظ JSON العميل كما هو أبداً: كل حقل يُعاد بناؤه من مفاتيح معروفة
//  فقط (يمنع التلويث الشامل mass-assignment ونفخ المصفوفات array-bomb)،
//  وكل نصّ يمرّ عبر CvGuard::sanitize (تنظيف يونيكود واتجاه ثنائي).
//  فحص تسرّب الاسم يتم في CvController بعد هذا (يحتاج بيانات المرشح).
// ════════════════════════════════════════════════════════════

class CvValidator
{
    // حدود العدد — تمنع نفخ الحمولة على بوّابة عامة
    public const CAP = [
        'qualifications' => 15, 'experiences' => 20, 'certifications' => 20,
    ];

    // سقف الوثيقة المعاد ترميزها — يغطّي أسوأ مجموع لحدود الحقول بالبايت (عربي
    // متعدّد البايتات) مع هامش. كان 24576 أصغر من مجموع الحدود فيُرفض ما يمرّ التحقّق.
    public const MAX_BYTES = 131072;

    private const DEGREES = ['diploma', 'bachelor', 'master', 'doctorate', 'fellowship'];

    // يرجع الوثيقة النظيفة أو يرمي CvTooLargeException (413) / ValidationException (422)
    public function clean(array $in): array
    {
        // ١) حارس بنيوي قبل مُحقّق Laravel — يمنع فَرْد قيدٍ عبر آلاف العناصر (نفخ مصفوفة)
        foreach (self::CAP as $key => $max) {
            if (isset($in[$key]) && (!is_array($in[$key]) || count($in[$key]) > $max)) {
                throw new CvTooLargeException($key);
            }
        }

        $yMin = 1950;
        $yMax = (int) date('Y');

        $v = Validator::make($in, [
            'currentPosition' => 'nullable|string|max:150',
            'totalYearsExperience' => 'nullable|integer|min:0|max:60',
            'briefBio' => 'nullable|string|max:600',

            'qualifications' => 'nullable|array|max:15',
            'qualifications.*.degree' => 'required|in:' . implode(',', self::DEGREES),
            'qualifications.*.major' => 'nullable|string|max:120',
            'qualifications.*.institution' => 'required|string|max:150',
            'qualifications.*.gradYear' => "required|integer|min:$yMin|max:" . ($yMax + 1),

            'experiences' => 'nullable|array|max:20',
            'experiences.*.position' => 'required|string|max:120',
            'experiences.*.organization' => 'required|string|max:150',
            'experiences.*.fromYear' => "required|integer|min:$yMin|max:$yMax",
            'experiences.*.toYear' => "nullable|integer|min:$yMin|max:$yMax",
            'experiences.*.current' => 'required|boolean',
            'experiences.*.summary' => 'nullable|string|max:600',

            'certifications' => 'nullable|array|max:20',
            'certifications.*.name' => 'required|string|max:150',
            'certifications.*.issuer' => 'nullable|string|max:150',
            'certifications.*.year' => "required|integer|min:$yMin|max:$yMax",
        ], [
            'qualifications.*.degree.in' => 'الدرجة العلمية غير معروفة',
            'qualifications.max' => 'عدد المؤهلات أكثر من المسموح',
            'experiences.max' => 'عدد الخبرات أكثر من المسموح',
            'certifications.max' => 'عدد الشهادات أكثر من المسموح',
        ])->validate();

        // النصّ السردي (النبذة وملخّص الخبرة) بالعربية فقط: يُرفض تتابع حرفين لاتينيين
        // فأكثر. النصّ اللاتيني يفلت من مطابِق الاسم العربي، فنُلزم العربية هنا حيث
        // النصّ حرّ طويل (الحقول المنظّمة تسمح باللاتيني وتُطابَق بالنقحرة).
        if (self::hasLatinRun($v['briefBio'] ?? null)) {
            $this->fail('briefBio', 'اكتب النبذة بالعربية دون حروف لاتينية');
        }
        foreach (($v['experiences'] ?? []) as $i => $e) {
            if (self::hasLatinRun($e['summary'] ?? null)) {
                $this->fail("experiences.$i.summary", 'اكتب الملخّص بالعربية دون حروف لاتينية');
            }
        }

        // تحقّق متقاطع: الخبرة الحالية بلا سنة انتهاء، وإلا سنة الانتهاء ≥ البداية
        foreach (($v['experiences'] ?? []) as $i => $e) {
            $cur = (bool) ($e['current'] ?? false);
            if ($cur && !empty($e['toYear'])) {
                $this->fail("experiences.$i.toYear", 'خبرة حالية لا تحمل سنة انتهاء');
            }
            if (!$cur && empty($e['toYear'])) {
                $this->fail("experiences.$i.toYear", 'أدخل سنة الانتهاء أو علّم «حتى الآن»');
            }
            if (!$cur && !empty($e['toYear']) && (int) $e['toYear'] < (int) $e['fromYear']) {
                $this->fail("experiences.$i.toYear", 'سنة الانتهاء قبل سنة البداية');
            }
        }

        $doc = $this->rebuild($v);

        // ٦) سقف بايت احتياطي على الوثيقة النظيفة المعاد ترميزها
        if (strlen(json_encode($doc, JSON_UNESCAPED_UNICODE)) > self::MAX_BYTES) {
            throw new CvTooLargeException('document');
        }

        return $doc;
    }

    // أي حرف لاتيني مرفوض في النصّ السردي — لا مجرّد تتابع حرفين. أحرف مفردة
    // متباعدة (m o h a m m e d) كانت تفلت من قيد «حرفين متتاليين» فتنقل الاسم.
    private static function hasLatinRun(?string $s): bool
    {
        return $s !== null && preg_match('/[A-Za-z]/', $s) === 1;
    }

    // إعادة بناء الوثيقة من مفاتيح معروفة فقط، وتنظيف كل نصّ
    private function rebuild(array $v): array
    {
        return [
            'currentPosition' => CvGuard::sanitize($v['currentPosition'] ?? null),
            'totalYearsExperience' => (int) ($v['totalYearsExperience'] ?? 0),
            'briefBio' => CvGuard::sanitize($v['briefBio'] ?? null),

            'qualifications' => array_values(array_map(fn ($q) => [
                'degree' => $q['degree'],
                'major' => CvGuard::sanitize($q['major'] ?? null),
                'institution' => CvGuard::sanitize($q['institution']),
                'gradYear' => (int) $q['gradYear'],
            ], $v['qualifications'] ?? [])),

            'experiences' => array_values(array_map(fn ($e) => [
                'position' => CvGuard::sanitize($e['position']),
                'organization' => CvGuard::sanitize($e['organization']),
                'fromYear' => (int) $e['fromYear'],
                'toYear' => !empty($e['toYear']) ? (int) $e['toYear'] : null,
                'current' => (bool) ($e['current'] ?? false),
                'summary' => CvGuard::sanitize($e['summary'] ?? null),
            ], $v['experiences'] ?? [])),

            'certifications' => array_values(array_map(fn ($c) => [
                'name' => CvGuard::sanitize($c['name']),
                'issuer' => CvGuard::sanitize($c['issuer'] ?? null),
                'year' => (int) $c['year'],
            ], $v['certifications'] ?? [])),
        ];
    }

    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
