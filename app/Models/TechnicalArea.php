<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// مجال فنّي — مرجعٌ يُدار من الإعدادات، يُوسَم به المشارك ويُرشَّح عليه.
//
// نظيرُ ExpertiseArea شكلاً، ونقيضه معنىً: تلك تصف **المقيّم** بتخصّصه
// (أمن المنشآت، المرور)، وهذه تصف **المشارك** بالجانب الذي يُقاس فيه.
class TechnicalArea extends Model
{
    protected $fillable = ['label_ar', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function candidates()
    {
        return $this->belongsToMany(
            Candidate::class,
            'candidate_technical_areas',
            'technical_area_id',
            'candidate_id'
        )->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label_ar');
    }
}
