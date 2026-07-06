<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendance';

    protected $fillable = [
        'schedule_id', 'status', 'check_in_time',
        'absence_reason', 'recorded_by',
    ];

    protected $casts = [
        'check_in_time' => 'datetime',
    ];

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }
}
