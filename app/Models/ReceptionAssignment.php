<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// إسناد مشارك لنشاطٍ ومقيّم، وقرار المقيّم فيه.
// الصفّ سجلٌّ لا يُكتب فوقه: الردّ يُبقي صفّه بسببه، وإعادة الإسناد صفٌّ جديد.
class ReceptionAssignment extends Model
{
    protected $fillable = [
        'visit_id', 'activity', 'evaluator_id', 'status',
        'reject_reason', 'decided_at', 'assigned_by', 'schedule_id',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    // نفس مفردات schedules.activity — الاعتماد يُرحّل الإسناد إلى جلسة،
    // فاختلاف المفردات هنا يعني ترجمةً صامتة تنكسر عند أول نشاط يُضاف.
    public const ACTIVITIES = ['interview', 'discussion', 'measurement'];

    public const ACTIVITY_LABEL = [
        'interview' => 'المقابلة الشخصية',
        'discussion' => 'حلقة النقاش',
        'measurement' => 'أدوات القياس',
    ];

    // الأدوار المؤهّلة لكل نشاط — أساس قائمة المقيّمين المعروضة للاستقبال،
    // وأساس رفض إسنادٍ لمن لا يمارس النشاط أصلاً.
    //
    // كلّ نشاط ودورُ مستشارِه وحده. أُدرج مدير إدارة التقييم في المقابلة أوّلاً
    // ثم أُخرِج: هو معتمِدُ المرحلة الأولى لا مُجريها، ولو أُسنِد إليه مشارك
    // لصار مَن يقابل هو مَن يعتمد. والقائمة تُصفّى فوق ذلك بصلاحية
    // reception.decide — كي لا تعرض أبداً مَن سيردّه الخادم عند الإسناد.
    public const ACTIVITY_ROLES = [
        'interview' => ['EVALUATOR'],
        'discussion' => ['DISCUSSION_EVAL'],
        'measurement' => ['MEASURE_SUPER'],
    ];

    public static function label(string $activity): string
    {
        return self::ACTIVITY_LABEL[$activity] ?? $activity;
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ReceptionVisit::class, 'visit_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::ACCEPTED], true);
    }
}
