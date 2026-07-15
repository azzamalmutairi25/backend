<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

// ════════════════════════════════════════════════════════════
//  مرحلة في سلسلة اعتماد — تُقرأ من القاعدة لا من الكود، فتُعدَّل من الشاشة.
//
//  السلسلة = المراحل المفعّلة مرتّبةً بـposition. «التالية» تُحسب ولا تُخزَّن:
//  لو خُزِّنت لتعارضت مع الترتيب عند أول إعادة ترتيب.
// ════════════════════════════════════════════════════════════

class WorkflowStage extends Model
{
    public const REPORT = 'report';

    protected $fillable = [
        'workflow', 'position', 'status_key', 'role_code', 'permission', 'label', 'is_active',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    public const FINAL_STATUS = 'approved';
    public const DRAFT_STATUS = 'draft';
    public const RETURNED_STATUS = 'returned';

    // المفردات الثابتة: يفرضها قيد final_reports_status_check، فلا تُخترع حالة من الشاشة
    public const ALLOWED_STATUS_KEYS = [
        'pending_evaluator', 'pending_manager', 'pending_dev_approval', 'pending_center',
    ];

    // ── السلسلة المفعّلة مرتّبة ──
    public static function chain(string $workflow = self::REPORT): Collection
    {
        return static::where('workflow', $workflow)
            ->where('is_active', true)
            ->orderBy('position')
            ->get();
    }

    // ── أول مرحلة: إليها يذهب التقرير عند الإرسال ──
    public static function firstStage(string $workflow = self::REPORT): ?self
    {
        return static::chain($workflow)->first();
    }

    // ── المرحلة التي تمثّلها هذه الحالة (ولو عُطّلت — تقارير عالقة فيها تبقى قابلة للتحريك) ──
    public static function forStatus(string $status, string $workflow = self::REPORT): ?self
    {
        return static::where('workflow', $workflow)->where('status_key', $status)->first();
    }

    // ── التالية بعد هذه الحالة، أو 'approved' إن كانت الأخيرة ──
    // تتخطّى المعطّلة: تعطيل مرحلة يعني إخراجها من السلسلة لا تجميد ما بعدها.
    public static function nextAfter(string $status, string $workflow = self::REPORT): string
    {
        $chain = static::chain($workflow);
        $i = $chain->search(fn ($s) => $s->status_key === $status);

        if ($i === false) {
            // حالة خارج السلسلة المفعّلة (عُطّلت مرحلتها وتقرير عالق فيها):
            // ادفعه لأول مرحلة مفعّلة بعد موضعها الأصلي، وإلا فالاعتماد
            $stage = static::forStatus($status, $workflow);
            $after = $stage
                ? $chain->first(fn ($s) => $s->position > $stage->position)
                : null;
            return $after?->status_key ?? self::FINAL_STATUS;
        }

        return $chain->get($i + 1)?->status_key ?? self::FINAL_STATUS;
    }

    // ── السابقة قبل هذه الحالة، أو null إن كانت الأولى ──
    public static function previousBefore(string $status, string $workflow = self::REPORT): ?self
    {
        $chain = static::chain($workflow);
        $i = $chain->search(fn ($s) => $s->status_key === $status);

        return $i === false || $i === 0 ? null : $chain->get($i - 1);
    }

    // ── كل الحالات التي تعني «قيد الاعتماد» ──
    // تشمل المعطّلة التي فيها تقارير — وإلا اختفت من الإحصاء والفلاتر وهي قائمة
    public static function pendingStatuses(string $workflow = self::REPORT): array
    {
        return static::where('workflow', $workflow)->pluck('status_key')->all();
    }
}
