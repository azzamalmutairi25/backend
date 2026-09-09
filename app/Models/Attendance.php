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

    // حالات الغياب — تُقرأ من مكانٍ واحد: قائمةُ الغائبين والشبكةُ والتقريرُ
    // اليومي تسأل السؤال نفسه، ونسخُ السلسلتين في كلٍّ منها يجعل إضافة حالةٍ
    // ثالثةً يوماً تحريراً في مواضع يُنسى أحدها.
    public const ABSENT_STATUSES = ['absent_excused', 'absent_unexcused'];

    public function isAbsent(): bool
    {
        return in_array($this->status, self::ABSENT_STATUSES, true);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    // من سجّل الحالة — يُقرأ في قائمة الغائبين وفي المراجعة بعد شهور
    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
