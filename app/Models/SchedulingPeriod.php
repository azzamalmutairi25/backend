<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

// موجة الجدولة — تواريخ الدورة، وأوقات جلساتها، وحالة اعتمادها.
class SchedulingPeriod extends Model
{
    // ── الحالات ──
    // draft: تُبنى. pending_center: أُرسلت لمسؤول الجدولة. approved: معتمَدة.
    // والقيمة تبقى pending_center من يوم كان الاعتماد لمدير المركز: تغييرُها يوجب
    // ترحيل الصفوف وكلِّ ما يقارنها ولا يكسب شيئاً — التسمية هي ما يُقرأ.
    // closed: انتهت وأُرشفت. الرفض يعيدها draft بسببٍ مكتوب لا حالةً ثالثة —
    // الحالة الميّتة تُخفي الموجة عن صاحبها بدل أن تعيدها إليه.
    public const STATUSES = ['draft', 'pending_center', 'approved', 'closed'];

    public const STATUS_LABEL = [
        'draft' => 'مسودّة',
        'pending_center' => 'بانتظار اعتماد مسؤول الجدولة',
        'approved' => 'معتمَدة',
        'closed' => 'مغلقة',
    ];

    // سقفٌ صلب على طول الموجة. بلا سقف، خطأُ إدخالٍ في السنة (2027 مكان 2026)
    // يولّد قائمة أيامٍ بالمئات تُحمَّل في كل شاشة تعرض الموجة.
    public const MAX_DAYS = 120;

    protected $fillable = [
        'name', 'start_date', 'end_date', 'session_times', 'status', 'notes',
        'work_days', 'daily_capacity', 'excluded_dates',
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
     * **كل** الأيام — بما فيها غير العاملة. لأيام العمل وحدها `workingDays()`.
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

    // ── أيام العمل ──
    // أرقام أيام الأسبوع العاملة (0=الأحد). الافتراض الأحد–الخميس، وهو جدول
    // المركز — والجمعة والسبت خارجه.
    public function workDayNumbers(): array
    {
        $raw = trim((string) ($this->work_days ?? ''));
        if ($raw === '') {
            return [0, 1, 2, 3, 4];
        }

        $nums = array_values(array_unique(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== '')
        )));
        sort($nums);

        return array_values(array_filter($nums, fn ($n) => $n >= 0 && $n <= 6));
    }

    /** التواريخ المستثناة صراحةً — عطلةٌ رسمية داخل المدى */
    public function excludedDates(): array
    {
        $raw = trim((string) ($this->excluded_dates ?? ''));

        return $raw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * أيام العمل الفعلية: داخل المدى، ويومُها من أيام العمل، وليست مستثناة.
     *
     * هي ما يُبنى عليه صفوف شبكة المستشارين — لا كل أيام المدى: عمودُ جمعةٍ
     * فارغ في الشبكة يُقرأ نقصاً في التخطيط لا يوماً غير عامل.
     */
    public function workingDays(): array
    {
        $work = $this->workDayNumbers();
        $skip = $this->excludedDates();

        return array_values(array_filter(
            $this->days(),
            fn ($d) => in_array((int) $d->dayOfWeek, $work, true)
                && ! in_array($d->toDateString(), $skip, true)
        ));
    }

    public function workingDayCount(): int
    {
        return count($this->workingDays());
    }

    /**
     * الإجمالي المستهدف: أيام العمل × الطاقة اليومية.
     *
     * يُحسب ولا يُخزَّن: قيمةٌ مخزَّنة تتقادم عند أول تعديلٍ للمدى أو للطاقة،
     * فتُقرأ هدفاً وهي أثرُ إعدادٍ سابق.
     */
    public function targetTotal(): ?int
    {
        return $this->daily_capacity
            ? $this->workingDayCount() * (int) $this->daily_capacity
            : null;
    }

    /**
     * الفترة التي يقع فيها هذا التاريخ.
     *
     * الترتيب: المعتمَدة أولاً، ثم **الأضيق مدىً**، ثم الأقدم.
     *
     * والأضيق أدقّ دلالةً: فترتان تشملان اليوم — واحدةٌ من ثلاثة أيام وأخرى
     * من ستّة أشهر — والمقصودة هي الأولى قطعاً. الثانيةُ مظلّةٌ عامّة، ونسبةُ
     * جلسةٍ إليها تُخرجها من مستندات الفترة التي تخصّها فعلاً.
     */
    public static function coveringDate(string $date): ?self
    {
        return static::whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'pending_center' THEN 1 ELSE 2 END")
            ->orderByRaw('(end_date - start_date) ASC')
            ->orderBy('id')
            ->first();
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
