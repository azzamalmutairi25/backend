<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = [
        'candidate_id', 'assessment_id', 'period_id', 'circle_id', 'schedule_date', 'schedule_time',
        'activity', 'evaluator_id', 'assistant_id', 'location', 'rescheduled_at',
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'rescheduled_at' => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    // موجة الجدولة — قابلة للإفراغ: كل جلسة أُنشئت قبل الموجات بلا انتماء
    public function period()
    {
        return $this->belongsTo(SchedulingPeriod::class, 'period_id');
    }

    // حلقة النقاش — جلسات الحلقة صفوفٌ عادية تحمل معرّفها
    public function circle()
    {
        return $this->belongsTo(DiscussionCircle::class, 'circle_id');
    }

    public function attendance()
    {
        return $this->hasOne(Attendance::class);
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function assistant()
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }
}
