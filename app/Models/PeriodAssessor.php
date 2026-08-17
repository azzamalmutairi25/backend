<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// اسمٌ في لوحة الموجة: مقيّم أو مساعد، لنشاطٍ بعينه، بنصابه.
class PeriodAssessor extends Model
{
    public const SEATS = ['evaluator', 'assistant'];

    public const SEAT_LABEL = [
        'evaluator' => 'مقيّم',
        'assistant' => 'مساعد',
    ];

    // النشاط ⇐ أدوار من يجلس على مقعد المقيّم فيه. مأخوذة من
    // ReceptionAssignment::ACTIVITY_ROLES كي لا تفترق خريطتان لنفس الحقيقة.
    // المساعد دورٌ واحد لكل الأنشطة، فلا مكان له في تلك الخريطة.
    public const ASSISTANT_ROLES = ['ASSISTANT'];

    protected $fillable = [
        'period_id', 'user_id', 'activity', 'seat',
        'daily_quota', 'period_quota', 'is_available', 'assigned_by',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'daily_quota' => 'integer',
        'period_quota' => 'integer',
    ];

    public function period()
    {
        return $this->belongsTo(SchedulingPeriod::class, 'period_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * النصاب اليومي الفعّال: قيمة الصفّ، وإلا الإعداد العام، وإلا ٥.
     *
     * سلسلة سقوطٍ لا شرط: مركزٌ لم يضبط نصاباً لأحد يعمل بالإعداد العام كما
     * كان يعمل قبل هذا الجدول، ولا يظهر «٠» في الشاشة فيُقرأ كمنعٍ للإسناد.
     */
    public function dailyQuota(): int
    {
        if ($this->daily_quota !== null) {
            return max(0, $this->daily_quota);
        }

        return max(1, (int) (Setting::find('distribution.daily_cap_per_evaluator')?->value ?? 5));
    }

    /** أدوار المستخدمين المؤهّلين لهذا (النشاط، المقعد) */
    public static function eligibleRoles(string $activity, string $seat): array
    {
        if ($seat === 'assistant') {
            return self::ASSISTANT_ROLES;
        }

        // التمرين التكاملي لا صفّ له في تلك الخريطة (الاستقبال لا يوزّع عليه)،
        // وقائمةٌ فارغة تعني «لا أحد مؤهّل» فتُفرِغ الشاشة بلا سبب ظاهر.
        // يقع على مقيّم المقابلة — وهو من يديره فعلاً في المركز.
        return ReceptionAssignment::ACTIVITY_ROLES[$activity] ?? ['EVALUATOR'];
    }

    public static function seatLabel(string $seat): string
    {
        return self::SEAT_LABEL[$seat] ?? $seat;
    }
}
