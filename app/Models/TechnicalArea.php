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

    // القطاعات التي يُعرَض فيها هذا المجال — واحدٌ على الأقلّ.
    // مجالٌ بلا قطاع لا يظهر في أي نموذج، فيصير سجلاًّ ميتاً لا يُوسَم به أحد.
    public function sectors()
    {
        return $this->belongsToMany(Sector::class, 'technical_area_sectors', 'technical_area_id', 'sector_id')
            ->withTimestamps();
    }

    // المستشارون الموسومون به — طرفُ المطابقة الآخر
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_technical_areas', 'technical_area_id', 'user_id')
            ->withTimestamps();
    }

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
