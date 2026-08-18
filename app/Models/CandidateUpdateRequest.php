<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

// ════════════════════════════════════════════════════════════
//  طلب تحديث بيانات مشارك — يرفعه المستخدم الخارجي، ويبتّ فيه صاحب صلاحية.
//
//  الطلب اقتراحٌ لا تعديل: السجلّ لا يتغيّر إلا عند الاعتماد. الوثيقتان
//  (payload المقترح، snapshot الحالي لحظة الرفع) مشفّرتان ككتلة واحدة
//  على نمط CandidateCv::data — النصّ الحرّ فيهما يحمل هوية المشارك.
// ════════════════════════════════════════════════════════════
class CandidateUpdateRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'candidate_id', 'requested_by', 'status', 'payload', 'snapshot',
        'note', 'review_note', 'reviewed_by', 'reviewed_at',
    ];

    protected $hidden = ['payload_enc', 'snapshot_enc'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    // payload المنطقية ← → payload_enc المشفّرة
    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn () => self::decode($this->payload_enc),
            set: fn ($value) => ['payload_enc' => Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE))],
        );
    }

    protected function snapshot(): Attribute
    {
        return Attribute::make(
            get: fn () => self::decode($this->snapshot_enc),
            set: fn ($value) => ['snapshot_enc' => Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE))],
        );
    }

    // فكّ آمن: صفٌّ تالف (مفتاح تغيّر، تشفير مقطوع) يرجع وثيقة فارغة لا استثناءً
    // يُسقط الشاشة كلها — الطلب حينها يظهر ولا يُعتمد، وهو المسلك الصحيح.
    private static function decode(?string $enc): array
    {
        if (!$enc) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($enc), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }
}
