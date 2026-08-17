<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// مجال خبرة — مرجعٌ يُدار من الإعدادات، تُوسَم به حسابات المقيّمين.
class ExpertiseArea extends Model
{
    protected $fillable = ['label_ar', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_expertise', 'expertise_area_id', 'user_id')
            ->withTimestamps();
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
