<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// إسناد مشارك لمجموعة يومٍ بعينه (A أو B).
// المجموعة تحدّد ترتيب فترتَي المقابلة وجلسة النقاش — راجع migration الجدول.
class RosterGroup extends Model
{
    protected $fillable = [
        'candidate_id', 'assessment_id', 'roster_date', 'group_letter', 'assigned_by',
    ];

    protected $casts = [
        'roster_date' => 'date',
    ];

    // الحروف المسموحة — مجموعتان اثنتان، كما في النموذج المطبوع
    public const LETTERS = ['A', 'B'];

    // العرض العربي للحرف المخزَّن لاتينياً
    public const LETTER_LABEL = ['A' => 'أ', 'B' => 'ب'];

    public static function label(?string $letter): string
    {
        return self::LETTER_LABEL[$letter] ?? '—';
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
