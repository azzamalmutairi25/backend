<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

class Candidate extends Model
{
    protected $fillable = [
        'participant_code', 'national_id_enc', 'national_id_hash',
        'full_name_enc', 'mobile_enc', 'email_enc',
        'sector_id', 'rank_label', 'tier', 'assessment_type', 'status',
        'classification',
    ];

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

    public static function classifyTier(string $rankLabel, bool $isMilitary): string
    {
        if ($isMilitary) {
            $upperRanks = ['عميد', 'لواء', 'فريق', 'مشير'];
            foreach ($upperRanks as $r) {
                if (str_contains($rankLabel, $r)) return 'upper';
            }
            return 'middle';
        } else {
            if (preg_match('/م-?(\d+)/u', $rankLabel, $m)) {
                $grade = (int) $m[1];
                return $grade >= 13 ? 'upper' : 'middle';
            }
            return 'middle';
        }
    }

    public static function generateParticipantCode(Sector $sector): string
    {
        // مصدر الحقيقة الموحّد لتسلسل الرموز هو جدول الدورات (assessments)
        return Assessment::generateParticipantCode($sector);
    }
}
