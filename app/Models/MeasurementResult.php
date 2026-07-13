<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// نتائج أدوات القياس لدورة تقييم (شخصية/تحليلي/إنجليزي)
class MeasurementResult extends Model
{
    protected $fillable = [
        'candidate_id', 'assessment_id',
        'personality_score', 'analytical_score', 'english_score', 'uploaded_by',
    ];

    protected $casts = [
        'personality_score' => 'float',
        'analytical_score' => 'float',
        'english_score' => 'float',
    ];

    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function assessment(): BelongsTo { return $this->belongsTo(Assessment::class); }
}
