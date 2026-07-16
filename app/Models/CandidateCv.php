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
            'currentPosition' => null,
            'totalYearsExperience' => 0,
            'briefBio' => null,
            'qualifications' => [],
            'experiences' => [],
            'certifications' => [],
        ];
    }

    // هل الوثيقة فارغة فعلاً؟ (يميّز «لا سيرة» عن «تعذّر التحميل» في الواجهات)
    public static function isEmptyDoc(array $d): bool
    {
        return empty($d['currentPosition']) && empty($d['briefBio'])
            && empty($d['qualifications']) && empty($d['experiences'])
            && empty($d['certifications']) && (int) ($d['totalYearsExperience'] ?? 0) === 0;
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
