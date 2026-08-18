<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// حلقة نقاش — جلسةٌ لمجموعة: مستشارٌ ومساعدُه وعدّةُ مشاركين في وقتٍ واحد.
class DiscussionCircle extends Model
{
    protected $fillable = [
        'period_id', 'sector_id', 'circle_date', 'circle_time', 'location',
        'evaluator_id', 'assistant_id', 'capacity', 'group_letter', 'created_by',
    ];

    protected $casts = [
        'circle_date' => 'date',
        'capacity' => 'integer',
    ];

    /** السعة الافتراضية من الإعدادات — لا رقم محفور في الشيفرة */
    public static function defaultCapacity(): int
    {
        return max(1, (int) (Setting::find('discussion.default_circle_capacity')?->value ?? 6));
    }

    public function period()
    {
        return $this->belongsTo(SchedulingPeriod::class, 'period_id');
    }

    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function assistant()
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    /** جلسات الحلقة — صفوف `schedules` عادية، فلا تحتاج الشاشات الأخرى تعديلاً */
    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'circle_id');
    }

    public function seatsTaken(): int
    {
        return $this->schedules()->count();
    }

    public function seatsLeft(): int
    {
        return max(0, $this->capacity - $this->seatsTaken());
    }

    /** الوقت بصيغة H:i — العمود time يعود «HH:MM:SS» */
    public function timeLabel(): string
    {
        return substr((string) $this->circle_time, 0, 5);
    }
}
