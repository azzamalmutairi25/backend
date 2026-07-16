<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

// دورة تقييم واحدة للمرشح (رمز + حالة + تقييمات + تقرير)
class Assessment extends Model
{
    protected $fillable = [
        'candidate_id', 'participant_code', 'assessment_type', 'status', 'created_by',
        'confirm_token', 'confirmed_at', 'arrived_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'cv_snapshotted_at' => 'datetime',
    ];

    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function evaluations(): HasMany { return $this->hasMany(Evaluation::class); }
    public function schedules(): HasMany { return $this->hasMany(Schedule::class); }
    public function report(): HasOne { return $this->hasOne(FinalReport::class); }

    // قراءة منطقية للوثيقة المجمَّدة (السيرة كما كانت لحظة بدء التقييم)
    protected function cvSnapshot(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cv_snapshot_enc
                ? json_decode(Crypt::decryptString($this->cv_snapshot_enc), true)
                : null,
        );
    }

    // مجمَّدة = بدأ المقيّم أو تجاوزت الدورة مرحلة الرصد (لا الوصول — الوصول لا يقفل)
    public function cvFrozen(): bool
    {
        return in_array($this->status, ['assessed', 'approved', 'completed'], true)
            || $this->evaluations()->exists();
    }

    // التقاط السيرة الحيّة في هذه الدورة مرة واحدة عند التجميد
    public function freezeCvSnapshot(): void
    {
        if ($this->cv_snapshot_enc !== null) return; // مجمَّدة مسبقاً — لا تُكتب فوقها أبداً
        $cv = $this->candidate->cv;
        $doc = $cv?->data ?? CandidateCv::emptyDoc();
        $this->cv_snapshot_enc = Crypt::encryptString(json_encode($doc, JSON_UNESCAPED_UNICODE));
        $this->cv_snapshot_version = $cv?->version ?? 0;
        $this->cv_snapshotted_at = now();
        $this->save();
    }

    // رمز تأكيد فريد يوضَع في رابط الرسالة النصية (غير قابل للتخمين)
    public static function generateConfirmToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('confirm_token', $token)->exists());
        return $token;
    }

    // توليد رمز مشارك جديد فريد عالميًا للقطاع (يقرأ من كل الدورات)
    // نحسب أكبر رقم عدديًّا لا معجميًّا — وإلا اعتُبر 'DA-999' > 'DA-1000' فتكرّر الرمز بعد 999
    public static function generateParticipantCode(Sector $sector): string
    {
        // البادئة قابلة للتحديد من الإعدادات؛ الرجوع لأول حرفين يبقي التنصيبات
        // القديمة عاملة قبل تشغيل هجرة البادئة
        $prefix = strtoupper($sector->participant_prefix ?: substr($sector->code, 0, 2));
        $codes = self::where('participant_code', 'like', "$prefix-%")
            ->pluck('participant_code');

        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/-(\d+)$/', $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return sprintf('%s-%03d', $prefix, $max + 1);
    }
}
