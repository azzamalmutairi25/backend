<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

// موجة الجدولة — تواريخ الدورة، وأوقات جلساتها، وحالة اعتمادها.
class SchedulingPeriod extends Model
{
    // ── الحالات ──
    // draft: تُبنى. pending_center: أُرسلت لمدير المركز. approved: معتمَدة.
    // closed: انتهت وأُرشفت. الرفض يعيدها draft بسببٍ مكتوب لا حالةً ثالثة —
    // الحالة الميّتة تُخفي الموجة عن صاحبها بدل أن تعيدها إليه.
    public const STATUSES = ['draft', 'pending_center', 'approved', 'closed'];

    public const STATUS_LABEL = [
        'draft' => 'مسودّة',
        'pending_center' => 'بانتظار اعتماد مدير المركز',
        'approved' => 'معتمَدة',
        'closed' => 'مغلقة',
    ];

    // سقفٌ صلب على طول الموجة. بلا سقف، خطأُ إدخالٍ في السنة (2027 مكان 2026)
    // يولّد قائمة أيامٍ بالمئات تُحمَّل في كل شاشة تعرض الموجة.
    public const MAX_DAYS = 120;

    protected $fillable = [
        'name', 'start_date', 'end_date', 'session_times', 'status', 'notes',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'reject_reason',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function assessors()
    {
        return $this->hasMany(PeriodAssessor::class, 'period_id');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'period_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['draft', 'pending_center', 'approved']);
    }

    /**
     * أيام الموجة — قائمة Carbon من البداية إلى النهاية.
     *
     * كل الأيام لا أيام العمل: العطلة داخل الموجة قرارُ من يحدّد التواريخ، ولا
     * تقويم إجازاتٍ موثوق في المنصّة يُستأنس به. من لا يريد الجمعة يبني موجتين.
     */
    public function days(): array
    {
        $out = [];
        $cursor = $this->start_date->copy()->startOfDay();
        $end = $this->end_date->copy()->startOfDay();

        while ($cursor->lte($end) && count($out) < self::MAX_DAYS) {
            $out[] = $cursor->copy();
            $cursor->addDay();
        }

        return $out;
    }

    public function dayCount(): int
    {
        return count($this->days());
    }

    /**
     * أوقات جلسات الموجة — قيمتها الخاصة، وإلا الإعداد العام.
     *
     * تُقرأ من الإعداد لا تُنسخ عنه عند الإنشاء: نسخةٌ وقت الإنشاء تتقادم بصمت
     * حين يغيّر المركز أوقاته، فتظهر موجةٌ بأوقاتٍ لا وجود لها في أي شاشة أخرى.
     */
    public function sessionTimes(): array
    {
        $raw = trim((string) ($this->session_times ?? ''));
        if ($raw === '') {
            $raw = (string) (Setting::find('schedule.session_times')?->value ?? '10:15,12:30,14:30');
        }

        $times = array_values(array_filter(array_map('trim', explode(',', $raw))));
        sort($times);

        return $times;
    }

    /** هل الموجة ما زالت قابلة للتحرير؟ المعتمَدة والمغلقة تُقرأ ولا تُكتب. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'pending_center'], true);
    }

    public static function label(string $status): string
    {
        return self::STATUS_LABEL[$status] ?? $status;
    }
}
