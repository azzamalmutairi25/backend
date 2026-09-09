<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// بيان تصاريح الدخول — بيانٌ يوميّ جماعيّ يُعتمَد قبل أن يُطبع.
class GateManifest extends Model
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const STATUS_LABELS = [
        self::DRAFT => 'مسوّدة',
        self::PENDING => 'بانتظار اعتماد مدير المركز',
        self::APPROVED => 'معتمَد',
    ];

    protected $fillable = [
        'manifest_date', 'gate_time', 'location', 'status', 'note',
        'prepared_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'manifest_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    // المسوّدة والمعلّقة تُحرَّران، والمعتمَد يُقرأ ويُطبع — كبقية ما يُعتمَد
    public function isEditable(): bool
    {
        return $this->status !== self::APPROVED;
    }

    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(Candidate::class, 'gate_manifest_entries', 'manifest_id', 'candidate_id')
            ->withTimestamps();
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * ما يُطبع لكل اسم: الاسم · رقم الهوية · الرتبة أو المرتبة أو «متعاقد».
     *
     * **ولا رمز.** الرمز أداةٌ داخلية لا يعرفها الحارس، والمطابقة عنده باسمٍ
     * وهوية. وطبعُه يُخرج مُعرِّفاً داخلياً إلى ورقةٍ تُقدَّم عند البوّابة.
     */
    public static function rowFor(Candidate $c): array
    {
        return [
            'candidateId' => $c->id,
            'name' => $c->full_name,
            'nationalId' => $c->national_id,
            // «الرتبة» للعسكري و«المرتبة» للمدني و«متعاقد» لمن لا رتبة له —
            // تسميةُ الحقل تتبع الفئة كما في كل مخرَجٍ آخر
            'rank' => $c->rank_label ?: Candidate::categoryLabel($c->personnel_category ?? 'civilian'),
            'sector' => $c->sector?->name_ar,
        ];
    }
}
