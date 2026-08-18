<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// صفٌّ في الجدول الذهبي: تاريخٌ ورمز مشارك، منسوخان لا مجلوبان.
class GoldenScheduleEntry extends Model
{
    public const SOURCES = ['sync', 'manual'];

    protected $fillable = [
        'period_id', 'entry_date', 'participant_code', 'assessment_id',
        'schedule_id', 'sector_id', 'source', 'note', 'added_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
    ];

    public function period()
    {
        return $this->belongsTo(SchedulingPeriod::class, 'period_id');
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }

    public function adder()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function scopeManual($query)
    {
        return $query->where('source', 'manual');
    }
}
