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
//  فحص تسرّب الاسم يتم في CvController بعد هذا (يحتاج بيانات المشارك).
// ════════════════════════════════════════════════════════════

class CvValidator
{
    // حدود العدد — تمنع نفخ الحمولة على بوّابة عامة
    // نموذج المركز فيه ٢٦ خانة دورة — والسقف عشرون كان يردّ من ملأها كلها
    // بخطأ ٤١٣ قبل أن يصل التحقّق أصلاً. الحدّ هنا هو الحارس البنيوي، فلا بدّ
    // أن يوافق سقف القاعدة في clean() وإلا سبقه فرمى.
    public const CAP = [
        'qualifications' => 15, 'experiences' => 20, 'certifications' => 30,
    ];

    // سقف الوثيقة المعاد ترميزها — يغطّي أسوأ مجموع لحدود الحقول بالبايت (عربي
    // متعدّد البايتات) مع هامش. كان 24576 أصغر من مجموع الحدود فيُرفض ما يمرّ التحقّق.
    public const MAX_BYTES = 131072;

    private const DEGREES = ['highschool', 'diploma', 'bachelor', 'master', 'doctorate', 'fellowship'];

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

        // نافذة تاريخ الميلاد: من ٧٠ سنة إلى ١٨ سنة. تمنع الخطأ الشائع في
        // مُنتقي التاريخ (سنة اليوم بدل سنة الميلاد) وتمنع تاريخاً مستحيلاً.
        $bornAfter = date('Y-m-d', strtotime('-70 years'));
        $bornBefore = date('Y-m-d', strtotime('-18 years'));
        $today = date('Y-m-d');

        $v = Validator::make($in, [
            // ── البيانات الوظيفية — إلزامية في نموذج المركز ──
            'birthDate' => "required|date_format:Y-m-d|after_or_equal:$bornAfter|before_or_equal:$bornBefore",
            'appointmentDate' => "required|date_format:Y-m-d|before_or_equal:$today",
            'rankLabel' => 'required|string|max:50',
            'department' => 'required|string|max:150',
            'region' => 'required|string|max:100',

            // ── زيادات نموذج المركز ── تُعرض ولا يُفلتَر بها، فلا عمود لها
            'rankTitle' => 'nullable|string|max:60',
            'rankPromotedAt' => "nullable|date_format:Y-m-d|before_or_equal:$today",
            'generalDepartment' => 'nullable|string|max:150',
            'workCity' => 'nullable|string|max:100',
            'currentPositionYears' => 'nullable|string|max:40',

            'currentPosition' => 'nullable|string|max:150',
            'totalYearsExperience' => 'nullable|integer|min:0|max:60',
            'briefBio' => 'nullable|string|max:600',

            // مؤهلٌ واحد على الأقل — حدّ «السيرة المكتملة». ومن عُيّن حديثاً قد
            // لا تكون له وظيفة سابقة ولا دورة، فتبقى الكتلتان الأخريان اختياريتين.
            'qualifications' => 'required|array|min:1|max:15',
            'qualifications.*.degree' => 'required|in:' . implode(',', self::DEGREES),
            'qualifications.*.major' => 'nullable|string|max:120',
            'qualifications.*.institution' => 'required|string|max:150',
            // يبقى إلزامياً: قرارٌ قائم يثبته اختبار، ولا يُخفَّف لأجل خطأ
            // ترقيمٍ في قالب المركز (يكرّر «(4)» ويُسقط الرابع). القالب الذي
            // تُصدره المنصّة يرقّم الأربعة صحيحةً — فالإصلاح في الملفّ لا في القاعدة.
            'qualifications.*.studyPlace' => 'required|string|max:120',
            // سنة التخرّج لا عمود لها في نموذج المركز. اشتراطُها يردّ كل ملفّ
            // وارد، فصارت اختياريةً هنا ومطلوبةً في نموذج الإضافة اليدوي وحده.
            'qualifications.*.gradYear' => "nullable|integer|min:$yMin|max:" . ($yMax + 1),

            'experiences' => 'nullable|array|max:20',
            'experiences.*.position' => 'required|string|max:120',
            'experiences.*.organization' => 'required|string|max:150',
            // النموذج يعطي **مدّة خدمة** نصّاً («٣ سنوات») لا سنتَي بداية ونهاية.
            // تُحفظ كما وردت، وتبقى السنتان لمن يُدخل يدوياً فيعرفهما بدقّة.
            'experiences.*.years' => 'nullable|string|max:40',
            'experiences.*.section' => 'nullable|string|max:150',
            'experiences.*.fromYear' => "nullable|integer|min:$yMin|max:$yMax",
            'experiences.*.toYear' => "nullable|integer|min:$yMin|max:$yMax",
            'experiences.*.current' => 'nullable|boolean',
            'experiences.*.summary' => 'nullable|string|max:600',

            // ٢٦ خانة دورة في النموذج — والسقف عشرون كان يردّ من ملأها كلها
            'certifications' => 'nullable|array|max:30',
            'certifications.*.name' => 'required|string|max:150',
            'certifications.*.issuer' => 'nullable|string|max:150',
            'certifications.*.year' => "nullable|integer|min:$yMin|max:$yMax",
        ], [
            'birthDate.required' => 'أدخل تاريخ الميلاد',
            'birthDate.date_format' => 'تاريخ الميلاد بالميلادي (سنة-شهر-يوم)',
            'birthDate.after_or_equal' => 'تاريخ الميلاد غير منطقي — راجِعه',
            'birthDate.before_or_equal' => 'تاريخ الميلاد غير منطقي — راجِعه',
            'appointmentDate.required' => 'أدخل تاريخ التعيين',
            'appointmentDate.date_format' => 'تاريخ التعيين بالميلادي (سنة-شهر-يوم)',
            'appointmentDate.before_or_equal' => 'تاريخ التعيين لا يكون في المستقبل',
            'rankLabel.required' => 'أدخل الرتبة أو المرتبة',
            'department.required' => 'أدخل الإدارة',
            'region.required' => 'أدخل المنطقة',
            'qualifications.*.studyPlace.required' => 'أدخل مقر/دولة الدراسة',
            'qualifications.*.degree.in' => 'الدرجة العلمية غير معروفة',
            'qualifications.required' => 'السيرة الذاتية إلزامية — أضِف مؤهلاً علمياً واحداً على الأقل',
            'qualifications.min' => 'السيرة الذاتية إلزامية — أضِف مؤهلاً علمياً واحداً على الأقل',
            'qualifications.max' => 'عدد المؤهلات أكثر من المسموح',
            'experiences.max' => 'عدد الخبرات أكثر من المسموح',
            'certifications.max' => 'عدد الشهادات أكثر من المسموح',
        ])->validate();

        // التعيين بعد الميلاد بثمانية عشر عاماً على الأقل — يكشف خلط الحقلين
        if (!empty($v['birthDate']) && !empty($v['appointmentDate'])
            && $v['appointmentDate'] < date('Y-m-d', strtotime($v['birthDate'] . ' +18 years'))) {
            $this->fail('appointmentDate', 'تاريخ التعيين قبل بلوغ الثامنة عشرة — راجِع التاريخين');
        }

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

        // تحقّق متقاطع على السنتين — يُطبَّق على من أدخلهما وحده.
        //
        // الخبرة الآن تُوصف بطريقتين: سنتَي بداية ونهاية (الإدخال اليدوي)، أو
        // مدّةً نصّية (ملفّ المركز). فالقيد يسري حين تُذكر سنة البداية فقط —
        // وإلزامُه على صفٍّ لا سنة فيه كان يردّ كل ملفّ وارد بخطأ لا حيلة فيه.
        foreach (($v['experiences'] ?? []) as $i => $e) {
            if (empty($e['fromYear'])) {
                continue;
            }
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
            'birthDate' => $v['birthDate'] ?? null,
            'appointmentDate' => $v['appointmentDate'] ?? null,
            'rankLabel' => CvGuard::sanitize($v['rankLabel'] ?? null),
            'department' => CvGuard::sanitize($v['department'] ?? null),
            'region' => CvGuard::sanitize($v['region'] ?? null),

            'rankTitle' => CvGuard::sanitize($v['rankTitle'] ?? null),
            'rankPromotedAt' => $v['rankPromotedAt'] ?? null,
            'generalDepartment' => CvGuard::sanitize($v['generalDepartment'] ?? null),
            'workCity' => CvGuard::sanitize($v['workCity'] ?? null),
            'currentPositionYears' => CvGuard::sanitize($v['currentPositionYears'] ?? null),

            'currentPosition' => CvGuard::sanitize($v['currentPosition'] ?? null),
            'totalYearsExperience' => (int) ($v['totalYearsExperience'] ?? 0),
            'briefBio' => CvGuard::sanitize($v['briefBio'] ?? null),

            'qualifications' => array_values(array_map(fn ($q) => [
                'degree' => $q['degree'],
                'major' => CvGuard::sanitize($q['major'] ?? null),
                'institution' => CvGuard::sanitize($q['institution']),
                'studyPlace' => CvGuard::sanitize($q['studyPlace']),
                // القيم الغائبة تبقى null لا صفراً: «٠» سنةَ تخرّجٍ تُطبع على
                // الوثيقة فتُقرأ بياناً، والفراغ يُقرأ فراغاً.
                'gradYear' => isset($q['gradYear']) ? (int) $q['gradYear'] : null,
            ], $v['qualifications'] ?? [])),

            'experiences' => array_values(array_map(fn ($e) => [
                'position' => CvGuard::sanitize($e['position']),
                'organization' => CvGuard::sanitize($e['organization']),
                'section' => CvGuard::sanitize($e['section'] ?? null),
                'years' => CvGuard::sanitize($e['years'] ?? null),
                'fromYear' => isset($e['fromYear']) ? (int) $e['fromYear'] : null,
                'toYear' => !empty($e['toYear']) ? (int) $e['toYear'] : null,
                'current' => (bool) ($e['current'] ?? false),
                'summary' => CvGuard::sanitize($e['summary'] ?? null),
            ], $v['experiences'] ?? [])),

            'certifications' => array_values(array_map(fn ($c) => [
                'name' => CvGuard::sanitize($c['name']),
                'issuer' => CvGuard::sanitize($c['issuer'] ?? null),
                'year' => isset($c['year']) ? (int) $c['year'] : null,
            ], $v['certifications'] ?? [])),
        ];
    }

    private function fail(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
