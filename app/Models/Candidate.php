<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

class Candidate extends Model
{
    protected $fillable = [
        'participant_code', 'national_id_enc', 'national_id_hash',
        'full_name_enc', 'mobile_enc', 'email_enc',
        'sector_id', 'rank_label', 'personnel_category', 'tier', 'assessment_type', 'status',
        'classification',
    ];

    // فئة المنسوب — صفةُ الشخص لا صفةُ قطاعه
    public const CATEGORIES = ['civilian', 'military', 'contractor'];

    // المتعاقد بلا قائمة رتب: مسمّاه الوظيفي حرّ وطبقته تُختار صراحةً
    public const CATEGORY_CONTRACTOR = 'contractor';

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'military' => 'عسكري',
            'contractor' => 'متعاقد',
            default => 'مدني',
        };
    }

    // «الرتبة» للعسكري، و«المرتبة» للمدني، و«المسمّى الوظيفي» للمتعاقد —
    // تسميةُ الحقل تتبع الفئة في كل رسالة خطأ وكل عمود مستورَد
    public static function rankWord(string $category): string
    {
        return match ($category) {
            'military' => 'الرتبة',
            'contractor' => 'المسمّى الوظيفي',
            default => 'المرتبة',
        };
    }

    protected $hidden = [
        'national_id_enc', 'national_id_hash', 'full_name_enc',
        'mobile_enc', 'email_enc',
    ];

    protected function nationalId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->national_id_enc ? Crypt::decryptString($this->national_id_enc) : null,
            set: fn ($value) => [
                'national_id_enc' => Crypt::encryptString($value),
                'national_id_hash' => hash('sha256', $value),
            ],
        );
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->full_name_enc ? Crypt::decryptString($this->full_name_enc) : null,
            set: fn ($value) => ['full_name_enc' => Crypt::encryptString($value)],
        );
    }

    protected function mobile(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->mobile_enc ? Crypt::decryptString($this->mobile_enc) : null,
            set: fn ($value) => ['mobile_enc' => $value ? Crypt::encryptString($value) : null],
        );
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->email_enc ? Crypt::decryptString($this->email_enc) : null,
            set: fn ($value) => ['email_enc' => $value ? Crypt::encryptString($value) : null],
        );
    }

    // حذف المرشح يزيل سجلّات مراسلاته أولاً — وإلا منعت قيود FK (RESTRICT) الحذف فترمي 500
    // (assessments/schedules/evaluations/reports تُحذف تلقائياً عبر cascade، لكن sms/email لا)
    protected static function booted(): void
    {
        static::deleting(function (Candidate $candidate) {
            SmsLog::where('candidate_id', $candidate->id)->delete();
            EmailLog::where('candidate_id', $candidate->id)->delete();
        });
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    // دورات التقييم لهذا الشخص (شخص واحد ← عدة دورات/رموز)
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    // السيرة الذاتية — وثيقة واحدة لكل مرشح (يدخلها المرشح عبر البوّابة)
    public function cv(): HasOne
    {
        return $this->hasOne(CandidateCv::class);
    }

    // تحديث حالة الشخص + مزامنتها على دورته الحالية (الأحدث)
    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->save();
        $latest = $this->assessments()->latest('id')->first();
        if ($latest && $latest->status !== $status) {
            $latest->update(['status' => $status]);
        }
    }

    public static function nationalIdExists(string $nationalId, ?int $exceptId = null): bool
    {
        $q = self::where('national_id_hash', hash('sha256', $nationalId));
        if ($exceptId) $q->where('id', '!=', $exceptId);
        return $q->exists();
    }

    // القواعد قابلة للضبط من الإعدادات (رتب عسكرية عليا + عتبة الرتبة المدنية)،
    // مع رجوع لقيم افتراضية إن لم تُضبط بعد.
    public const DEFAULT_UPPER_RANKS = ['عميد', 'لواء', 'فريق', 'مشير'];
    public const DEFAULT_UPPER_GRADE = 13;

    // الطبقة لأي فئة — نقطةٌ واحدة تستعملها كلُّ مسارات الكتابة (الإضافة
    // والتعديل والاستيراد وطلب التحديث)، فلا يسهو مسارٌ عن حالة المتعاقد
    public static function resolveTier(string $category, string $rankLabel, ?string $explicitTier): string
    {
        if ($category === self::CATEGORY_CONTRACTOR) {
            return in_array($explicitTier, ['upper', 'middle'], true) ? $explicitTier : 'middle';
        }
        return self::classifyTier($rankLabel, $category);
    }

    // الطبقة من الرتبة وفئةِ المرشّح. المتعاقد لا يمرّ من هنا: مسمّاه حرّ
    // وطبقته تُختار صراحةً، فاستنتاجُها من نصٍّ حرّ تخمينٌ يُقيَّم به إنسان.
    public static function classifyTier(string $rankLabel, string $category): string
    {
        if ($category === self::CATEGORY_CONTRACTOR) {
            throw new \InvalidArgumentException('طبقة المتعاقد تُختار صراحةً لا تُستنتج');
        }

        $isMilitary = $category === 'military';

        // القائمة المُدارة (جدول ranks) أولاً: مطابقة صريحة تحسم الفئة.
        // غير المُدرَج يسقط للمنطق القديم (قائمة الإعدادات + عتبة المرتبة المدنية).
        $managed = Rank::tierFor($rankLabel, $category);
        if ($managed !== null) {
            return $managed;
        }

        if ($isMilitary) {
            foreach (self::tierUpperRanks() as $r) {
                if ($r !== '' && str_contains($rankLabel, $r)) return 'upper';
            }
            return 'middle';
        }
        if (preg_match('/م-?(\d+)/u', $rankLabel, $m)) {
            return (int) $m[1] >= self::tierUpperGrade() ? 'upper' : 'middle';
        }
        return 'middle';
    }

    public static function tierUpperRanks(): array
    {
        $raw = Setting::find('tier.military_upper_ranks')?->value;
        if ($raw === null || trim($raw) === '') return self::DEFAULT_UPPER_RANKS;
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($r) => $r !== ''));
    }

    public static function tierUpperGrade(): int
    {
        // قيمة غير رقمية (تلف) ترجع للافتراضي 13 لا إلى 1
        $v = Setting::find('tier.civilian_upper_grade')?->value;
        return is_numeric($v) ? max(1, (int) $v) : self::DEFAULT_UPPER_GRADE;
    }

    public static function generateParticipantCode(Sector $sector): string
    {
        // مصدر الحقيقة الموحّد لتسلسل الرموز هو جدول الدورات (assessments)
        return Assessment::generateParticipantCode($sector);
    }
}
