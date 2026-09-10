<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// غياب مستشارٍ عن مدىً من الأيام — إجازةً أو تدريباً أو حلقةَ نقاشٍ يديرها.
//
// حلّت محلّ `period_assessors.is_available`: بوليانٌ واحد للفترة كلّها كان
// يعني «إمّا يعمل فيها أو لا»، فيُشطب الاسم من فترةٍ كاملة لأجل ثلاثة أيام.
//
// والسبب يُكتب لأنه يُقرأ في شبكة المستشارين: خانةٌ فارغة لا تقول أهو في
// إجازةٍ أم تدريبٍ أم يدير حلقة — وهي ثلاثة أحوالٍ مختلفة في التخطيط، اثنان
// منها يخرجانه من مقاعد المقابلات والثالث يشغله بنشاطٍ آخر.
class AssessorAbsence extends Model
{
    public const REASONS = ['leave', 'training', 'discussion', 'other'];

    public const REASON_LABEL = [
        'leave' => 'إجازة',
        'training' => 'تدريب',
        'discussion' => 'حلقة نقاش',
        'other' => 'أخرى',
    ];

    protected $fillable = [
        'period_id', 'user_id', 'host_user_id', 'from_date', 'to_date', 'reason', 'note', 'created_by',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
    ];

    public static function reasonLabel(?string $reason): string
    {
        return self::REASON_LABEL[$reason] ?? self::REASON_LABEL['other'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // المستشار المضيف — لمن تحت التدريب: يرافقه ولا يُحتسب له مشاركون
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SchedulingPeriod::class, 'period_id');
    }

    /**
     * هل هذا المستشار غائبٌ في هذا اليوم؟
     *
     * تُستدعى من قوائم الإسناد ومن شبكة المستشارين، فتُقرأ دفعةً واحدة لا
     * مرّةً لكل اسم — انظر `absentUserIdsOn`.
     */
    public static function absentUserIdsOn(string $date): array
    {
        return static::whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }
}
