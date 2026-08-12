<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

// وصول مرشّح إلى المركز في يومٍ بعينه — نقطة بداية مسار «استقبال الموظفين»
class ReceptionVisit extends Model
{
    protected $fillable = [
        'candidate_id', 'assessment_id', 'visit_date', 'arrived_at',
        'signed_at', 'attested', 'received_by', 'status', 'approved_at', 'approved_by',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'arrived_at' => 'datetime',
        'signed_at' => 'datetime',
        'approved_at' => 'datetime',
        'attested' => 'boolean',
    ];

    // signature_enc خارج $fillable عمداً: يُكتب عبر هذه الخاصية وحدها فيُشفَّر
    // دائماً. لو كان قابلاً للتعبئة الجماعية لأمكن حفظ توقيعٍ خام بلا تشفير.
    protected $hidden = ['signature_enc'];

    public const ARRIVED = 'arrived';
    public const DISTRIBUTED = 'distributed';
    public const APPROVED = 'approved';

    protected function signature(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->signature_enc ? Crypt::decryptString($this->signature_enc) : null,
            set: fn ($value) => ['signature_enc' => $value ? Crypt::encryptString($value) : null],
        );
    }

    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function assessment(): BelongsTo { return $this->belongsTo(Assessment::class); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function assignments(): HasMany { return $this->hasMany(ReceptionAssignment::class, 'visit_id'); }

    // هل وقّع المرشّح وأقرّ بصحّة بياناته؟ الشرطان معاً — توقيعٌ بلا إقرار
    // ليس إقراراً، وإقرارٌ بلا توقيع لا يُلزِم أحداً.
    public function isSigned(): bool
    {
        return $this->signature_enc !== null && $this->attested;
    }

    // الإسناد الفعّال لنشاطٍ بعينه (غير المردود) — المردود يبقى للسجلّ
    public function activeAssignment(string $activity): ?ReceptionAssignment
    {
        return $this->assignments
            ->where('activity', $activity)
            ->whereIn('status', [ReceptionAssignment::PENDING, ReceptionAssignment::ACCEPTED])
            ->first();
    }
}
