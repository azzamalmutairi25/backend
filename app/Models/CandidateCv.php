<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

// سيرة ذاتية واحدة لكل مشارك. الوثيقة كلها مشفّرة في cv_data_enc، وتُقرأ/تُكتب
// عبر السمة المنطقية data (نفس نمط Candidate::fullName). النصّ الحرّ قد يحمل
// اسم المشارك، فيُنقّى قبل الحفظ ويُطمَس عند العرض للمقيّم (عبر CvGuard).
class CandidateCv extends Model
{
    protected $fillable = ['candidate_id', 'data', 'version', 'source', 'updated_by'];

    protected $hidden = ['cv_data_enc'];

    // data المنطقية ← → cv_data_enc المشفّرة
    protected function data(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cv_data_enc
                ? json_decode(Crypt::decryptString($this->cv_data_enc), true)
                : self::emptyDoc(),
            set: fn ($value) => ['cv_data_enc' => Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE))],
        );
    }

    // وثيقة فارغة موحّدة — تُعاد حين لا سيرة بعد
    public static function emptyDoc(): array
    {
        return [
            // ── البيانات الوظيفية (نموذج المركز المعتمد) ──
            // التاريخان ميلاديان بصيغة Y-m-d — يُنتقيان من مُنتقي تاريخ لا يُكتبان،
            // فلا يقع خلط بين هجري وميلادي ولا صيغ يوم/شهر مقلوبة.
            'birthDate' => null,        // يُشتقّ منه العمر عند العرض، فلا يقادم
            'appointmentDate' => null,
            // إقرار المشارك برتبته وإدارته — لا يستبدل candidates.rank_label الرسمي.
            // الرتبة تقود تصنيف الفئة القيادية، فتغييرها من بوّابة عامة يعبث بالتصنيف.
            'rankLabel' => null,
            'department' => null,
            'region' => null,

            // ── زيادات نموذج المركز (ملفّ الاستيراد) ──
            // لقب الرتبة يُطبع على البطاقة والتصريح، وتاريخ الترقية يُقرأ مع
            // سنوات الخبرة. كلاهما يُعرض ولا يُفلتَر به، فمكانهما الوثيقة.
            'rankTitle' => null,
            'rankPromotedAt' => null,
            'generalDepartment' => null,
            // مدينة العمل تُذكر صراحةً في النموذج، و`region` تبقى للصفوف السابقة:
            // إعادةُ استعمال مفتاحٍ لمعنىً آخر تجعل بياناتٍ قديمة تكذب بصمت.
            'workCity' => null,
            'currentPositionYears' => null,

            'currentPosition' => null,
            'totalYearsExperience' => 0,
            'briefBio' => null,
            'qualifications' => [],
            'experiences' => [],
            'certifications' => [],
        ];
    }

    /**
     * الدرجة العلمية من نصّها العربي — «بكالوريوس» ← `bachelor`.
     *
     * نموذج المركز يكتب المؤهل نصّاً حرّاً، والوثيقة تخزّنه قيمةً من قائمة
     * مغلقة (تُبنى عليها الفلترة والعرض). فلا بدّ من جسر بينهما.
     *
     * **لا يُخمَّن ولا يُقاس بالتشابه.** مطابقةٌ صريحة على صيغٍ معروفة، وما
     * لا يُعرف يُرجِع null فيُردّ الصفّ برسالة تقول القيم المقبولة. تخمينُ
     * درجةٍ علمية يكتب في سجلٍّ رسمي ما لم يقله صاحبه.
     */
    public const DEGREE_ALIASES = [
        'highschool' => ['ثانوية عامة', 'الثانوية العامة', 'ثانوية', 'الثانوية', 'ثانوي', 'شهادة الثانوية'],
        'diploma' => ['دبلوم', 'دبلوما', 'دبلوم عالي', 'الدبلوم'],
        'bachelor' => ['بكالوريوس', 'بكالريوس', 'بكالوريس', 'ليسانس', 'الليسانس', 'البكالوريوس'],
        'master' => ['ماجستير', 'ماجيستير', 'الماجستير'],
        'doctorate' => ['دكتوراه', 'دكتوراة', 'الدكتوراه', 'دكتورا'],
        'fellowship' => ['زمالة', 'الزمالة', 'زماله'],
    ];

    public static function degreeFromArabic(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        // القيمة قد تصل مُحوَّلةً سلفاً من الواجهة — تُقبل كما هي
        if (isset(self::DEGREE_ALIASES[$raw])) {
            return $raw;
        }

        $norm = self::normalizeAr($raw);
        foreach (self::DEGREE_ALIASES as $key => $forms) {
            foreach ($forms as $form) {
                if (self::normalizeAr($form) === $norm) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** الدرجات المقبولة بنصّها العربي — تُعرض في رسالة الرفض */
    public static function degreeChoices(): string
    {
        return implode(' · ', array_map(fn ($f) => $f[0], self::DEGREE_ALIASES));
    }

    // تطبيع عربي: الهمزات والتاء المربوطة والتشكيل والمسافات — كي لا يسقط
    // صفٌّ صحيح لأنّ كاتبه كتب «بكالوريوس ‏» بمسافةٍ زائدة أو «بكالوريوس» بألفٍ ممدودة
    private static function normalizeAr(string $s): string
    {
        $s = preg_replace('/[\x{0640}\x{064B}-\x{0652}]/u', '', $s) ?? $s;
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    // العمر من تاريخ الميلاد — يُحسب عند العرض ولا يُخزَّن
    public static function ageFrom(?string $birthDate): ?int
    {
        if (! $birthDate) {
            return null;
        }
        try {
            return Carbon::parse($birthDate)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    // هل الوثيقة فارغة فعلاً؟ (يميّز «لا سيرة» عن «تعذّر التحميل» في الواجهات)
    // فحص صريح للفراغ — empty() يعتبر النصّ "0" فارغاً وهو محتوى صحيح
    public static function isEmptyDoc(array $d): bool
    {
        $blank = fn ($v) => $v === null || $v === '';

        return $blank($d['currentPosition'] ?? null) && $blank($d['briefBio'] ?? null)
            // البيانات الوظيفية تُحسب هنا أيضاً: وثيقة لا تحمل غير الإدارة ليست فارغة
            && $blank($d['birthDate'] ?? null) && $blank($d['appointmentDate'] ?? null)
            && $blank($d['rankLabel'] ?? null) && $blank($d['department'] ?? null)
            && $blank($d['region'] ?? null)
            && count($d['qualifications'] ?? []) === 0
            && count($d['experiences'] ?? []) === 0
            && count($d['certifications'] ?? []) === 0
            && (int) ($d['totalYearsExperience'] ?? 0) === 0;
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
