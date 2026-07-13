<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// بند خطة تطوير فردية (مجال + إجراء + موعد مستهدف + حالة متابعة)
class DevelopmentPlanItem extends Model
{
    protected $fillable = [
        'candidate_id', 'assessment_id', 'area', 'action',
        'target_date', 'status', 'created_by', 'completed_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function assessment(): BelongsTo { return $this->belongsTo(Assessment::class); }
}
