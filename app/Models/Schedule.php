<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = [
        'candidate_id', 'schedule_date', 'schedule_time',
        'activity', 'evaluator_id', 'assistant_id', 'location',
    ];

    protected $casts = [
        'schedule_date' => 'date',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function attendance()
    {
        return $this->hasOne(Attendance::class);
    }
}
