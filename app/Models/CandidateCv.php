<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

// سيرة ذاتية واحدة لكل مرشح. الوثيقة كلها مشفّرة في cv_data_enc، وتُقرأ/تُكتب
// عبر السمة المنطقية data (نفس نمط Candidate::fullName). النصّ الحرّ قد يحمل
// اسم المرشح، فيُنقّى قبل الحفظ ويُطمَس عند العرض للمقيّم (عبر CvGuard).
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
            // إقرار المرشّح برتبته وإدارته — لا يستبدل candidates.rank_label الرسمي.
            // الرتبة تقود تصنيف الفئة القيادية، فتغييرها من بوّابة عامة يعبث بالتصنيف.
            'rankLabel' => null,
            'department' => null,
            'region' => null,

            'currentPosition' => null,
            'totalYearsExperience' => 0,
            'briefBio' => null,
            'qualifications' => [],
            'experiences' => [],
            'certifications' => [],
        ];
    }

    // العمر من تاريخ الميلاد — يُحسب عند العرض ولا يُخزَّن
    public static function ageFrom(?string $birthDate): ?int
    {
        if (!$birthDate) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($birthDate)->age;
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
