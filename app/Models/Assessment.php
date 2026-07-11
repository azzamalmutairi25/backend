<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// دورة تقييم واحدة للمرشح (رمز + حالة + تقييمات + تقرير)
class Assessment extends Model
{
    protected $fillable = [
        'candidate_id', 'participant_code', 'assessment_type', 'status', 'created_by',
    ];

    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function evaluations(): HasMany { return $this->hasMany(Evaluation::class); }
    public function schedules(): HasMany { return $this->hasMany(Schedule::class); }
    public function report(): HasOne { return $this->hasOne(FinalReport::class); }

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
