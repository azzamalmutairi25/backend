<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    ];

    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function evaluations(): HasMany { return $this->hasMany(Evaluation::class); }
    public function schedules(): HasMany { return $this->hasMany(Schedule::class); }
    public function report(): HasOne { return $this->hasOne(FinalReport::class); }

    // رمز تأكيد فريد يوضَع في رابط الرسالة النصية (غير قابل للتخمين)
    public static function generateConfirmToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('confirm_token', $token)->exists());
        return $token;
    }

    // توليد رمز مشارك جديد فريد عالميًا للقطاع (يقرأ من كل الدورات)
    public static function generateParticipantCode(Sector $sector): string
    {
        $prefix = strtoupper(substr($sector->code, 0, 2));
        $lastCode = self::where('participant_code', 'like', "$prefix-%")
            ->orderBy('participant_code', 'desc')
            ->value('participant_code');

        $nextNum = 1;
        if ($lastCode && preg_match('/-(\d+)$/', $lastCode, $m)) {
            $nextNum = (int) $m[1] + 1;
        }
        return sprintf('%s-%03d', $prefix, $nextNum);
    }
}
