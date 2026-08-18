<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// خطوة في سير عمل الجدولة — بيانات تُحرَّر من الإعدادات لا ثابتٌ في الشيفرة.
class SchedulingWorkflowStep extends Model
{
    protected $fillable = [
        'position', 'title_ar', 'description', 'auto_key', 'is_required', 'is_active',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function progress()
    {
        return $this->hasMany(PeriodStepProgress::class, 'step_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** خطوة يتحقّق منها النظام بنفسه؟ */
    public function isAutomatic(): bool
    {
        return $this->auto_key !== null && $this->auto_key !== '';
    }
}
