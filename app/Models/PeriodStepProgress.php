<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// تأشير خطوةٍ يدوية على موجة بعينها.
class PeriodStepProgress extends Model
{
    protected $table = 'period_step_progress';

    public const STATUSES = ['done', 'skipped'];

    protected $fillable = [
        'period_id', 'step_id', 'status', 'note', 'done_by', 'done_at',
    ];

    protected $casts = [
        'done_at' => 'datetime',
    ];

    public function period()
    {
        return $this->belongsTo(SchedulingPeriod::class, 'period_id');
    }

    public function step()
    {
        return $this->belongsTo(SchedulingWorkflowStep::class, 'step_id');
    }

    public function doer()
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
