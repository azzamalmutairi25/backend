<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// طلب تأجيل — الاستقبال يرفع، ومسؤول الجدولة يبتّ.
//
// «التأجيل قرارُ مسؤول الجدولة لا الاستقبال»: الفترة المعتمَدة مقفلةٌ على
// الاستقبال، ومن يفكّها صاحبها. والاستقبال هو من يرى المانع بعينه — مشاركٌ
// أمامه لا يستطيع إتمام محطّته — فيرفعه ولا يبتّ فيه.
class PostponementRequest extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const RETURNED = 'returned';

    public const RESCHEDULED = 'rescheduled';

    // القرارات أربعة لا اثنان. القبولُ وحده يترك المشارك معلّقاً بلا موعد،
    // وإعادةُ الجدولة تُنشئ له موعداً في القرار نفسه — والفرق بينهما ما يُدار.
    // و«الإرجاع» يعيده إلى محطّته الناقصة بلا تاريخ: يُجدوَل في موجةٍ قادمة.
    public const DECISIONS = [self::ACCEPTED, self::REJECTED, self::RETURNED, self::RESCHEDULED];

    public const STATUS_LABELS = [
        self::PENDING => 'بانتظار البتّ',
        self::ACCEPTED => 'قُبل التأجيل',
        self::REJECTED => 'رُفض الطلب',
        self::RETURNED => 'أُرجع للمرحلة الناقصة',
        self::RESCHEDULED => 'أُعيدت جدولتها',
    ];

    protected $fillable = [
        'candidate_id', 'assessment_id', 'schedule_id', 'station',
        'reason', 'status', 'decision_note', 'new_date',
        'requested_by', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'new_date' => 'date',
        'decided_at' => 'datetime',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
