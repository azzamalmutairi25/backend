<?php

namespace App\Services;

use App\Models\SchedulingPeriod;

// ════════════════════════════════════════════════════════════
//  حارس الموجة — من يكتب فيها، ومتى يُمنع
// ════════════════════════════════════════════════════════════
//
// كان الحارس دالّةً خاصّة مكرّرة حرفياً في متحكّمَين (ScheduleController
// وDiscussionCircleController)، تُستدعى في خمسة مواضع من أحد عشر تكتب في موجة.
// والستّة الباقية تكتب في موجةٍ معتمَدة بلا رفض. التكرار هو العلّة لا السهو:
// من يكتب مساراً جديداً بعد شهر لا يخطر له أن عليه نسخ فحصٍ خاصٍّ من متحكّمٍ
// آخر — فالموضع الواحد يجعل النسيان مستحيلاً بدل أن يجعله نادراً.
//
// ── درجتا تجميد لا درجةٌ واحدة ──
//
// **BUILD** — ما تُبنى منه الموجة قبل عرضها: الجلسات والحلقات ولوحة المقيّمين.
// يُجمَّد عند الاعتماد، لأن المعتمَد هو الموجة بجلساتها؛ فزيادةُ جلسةٍ بعده
// تجعل المنفَّذ غيرَ الذي اعتمده مدير المركز.
//
// **FOLLOW_UP** — ما يُبنى **بعد** الاعتماد: الجدول الذهبي وتأشير خطوات الإجراء.
// لا يُجمَّد إلا عند الإغلاق. وهذا ليس تساهلاً بل قراءةٌ للإجراء نفسه: خطواته
// من السادسة إلى الثانية عشرة (ترحيل الرموز، طباعة البطاقات والتصاريح، تسليم
// الوزارة، ملفّ كل قطاع) كلّها تقع بعد اعتماد مدير المركز — وتجميدُها عنده
// يجعل قائمة الإجراء غير قابلة للإكمال أصلاً، فيتحوّل الحارس إلى عطل.
class WaveGuard
{
    /** بنية الموجة: جلسة أو حلقة أو اسمٌ في اللوحة — تُجمَّد عند الاعتماد */
    public const BUILD = 'build';

    /** ما يتفرّع عنها بعد الاعتماد — لا يُجمَّد إلا عند الإغلاق */
    public const FOLLOW_UP = 'follow_up';

    /**
     * هل تُمنع الكتابة في هذه الموجة؟ يرجع رسالة الرفض بالعربية، أو null إن جازت.
     *
     * الرسالة تُسمّي الموجة وحالتها: «لا تُعدَّل» وحدها تترك من يقرؤها يجرّب
     * الباب نفسه مرّة أخرى.
     */
    public function refuse(?int $periodId, ?string $date = null, string $grade = self::BUILD): ?string
    {
        if (!$periodId) {
            return null;   // جلسةٌ بلا موجة تُنشأ كما كانت تُنشأ دائماً
        }

        $period = SchedulingPeriod::find($periodId);
        if (!$period) {
            return 'موجة الجدولة غير موجودة';
        }

        if ($this->frozen($period, $grade)) {
            return 'موجة «' . $period->name . '» ' . SchedulingPeriod::label($period->status)
                . ' — ' . $this->verb($grade);
        }

        if ($date !== null && !$this->covers($period, $date)) {
            return 'التاريخ خارج مدى موجة «' . $period->name . '» ('
                . $period->start_date->toDateString() . ' — '
                . $period->end_date->toDateString() . ')';
        }

        return null;
    }

    /** نفس الفحص على كائنٍ محمّل — يوفّر استعلاماً حيث الموجة مقروءة أصلاً */
    public function frozen(SchedulingPeriod $period, string $grade = self::BUILD): bool
    {
        return $grade === self::FOLLOW_UP
            ? $period->status === 'closed'
            : !$period->isEditable();
    }

    /** هل يقع التاريخ داخل مدى الموجة؟ */
    public function covers(SchedulingPeriod $period, string $date): bool
    {
        $d = substr($date, 0, 10);

        return $d >= $period->start_date->toDateString()
            && $d <= $period->end_date->toDateString();
    }

    /**
     * موجة الجلسة المُعوِّضة عن غياب — تُختار بالتاريخ ولا تُورَّث عن الغياب.
     *
     * الغياب لا يقع إلا في موجةٍ تعمل، ومعنى «تعمل» أنها اعتُمدت. فوراثتها تزيد
     * جلسات موجةٍ ختمها مدير المركز، ويصير المنفَّذ غيرَ المعتمَد بلا أن يعلم.
     * والتعويض يذهب إلى الموجة التي تشمل تاريخه الجديد وما زالت تُبنى.
     *
     * والاختيار الآلي لا يقع إلا حين تكون الإجابة واحدة لا تحتمل غيرها: موجتان
     * مفتوحتان تتداخلان عمداً (قطاعٌ صباحاً وآخر مساءً) والقسمة بينهما قرارُ
     * المُجدوِل لا ترجيحُ استعلام — فإن تعدّدت تُترك الجلسة بلا موجة حتى يُسنِدها
     * بيده، كما كانت الحال قبل الموجات أصلاً.
     */
    public function openPeriodOn(string $date): ?int
    {
        $d = substr($date, 0, 10);

        $rows = SchedulingPeriod::whereIn('status', ['draft', 'pending_center'])
            ->whereDate('start_date', '<=', $d)
            ->whereDate('end_date', '>=', $d)
            ->limit(2)
            ->pluck('id');

        return $rows->count() === 1 ? (int) $rows->first() : null;
    }

    /**
     * ترجمة انتهاك قيد التعارض إلى رسالةٍ تُقرأ.
     *
     * القيد هو الحارس، وهذه الدالّة لسانه: بلا ترجمة يصل 23505 إلى الشاشة
     * باسم الفهرس الإنجليزي، فيقرأ المُجدوِل «duplicate key value violates
     * unique constraint» ولا يعرف أنّ المطلوب منه تغيير الوقت.
     */
    public function conflictMessage(\Throwable $e): ?string
    {
        $text = $e->getMessage();

        foreach (self::CONFLICTS as $index => $message) {
            if (str_contains($text, $index)) {
                return $message;
            }
        }

        return null;
    }

    /** اسم الفهرس ← ما يُقال لمن اصطدم به */
    private const CONFLICTS = [
        'schedules_evaluator_slot_unique' =>
            'المقيّم مرتبط بمقابلة أخرى في هذا الوقت — غيّر الوقت أو المقيّم',
        'schedules_assistant_slot_unique' =>
            'المساعد مرتبط بمقابلة أخرى في هذا الوقت — غيّر الوقت أو المساعد',
        'schedules_candidate_slot_unique' =>
            'للمشارك جلسة أخرى في هذا الوقت — غيّر الوقت',
        'discussion_circles_evaluator_slot_unique' =>
            'المستشار مرتبط بحلقة أخرى في هذا الوقت — غيّر الوقت أو المستشار',
        'discussion_circles_assistant_slot_unique' =>
            'المساعد مرتبط بحلقة أخرى في هذا الوقت — غيّر الوقت أو المساعد',
    ];

    /** الفعل المرفوض — يُذكر في الرسالة كي لا يُعاد الطرق من بابٍ آخر */
    private function verb(string $grade): string
    {
        return $grade === self::FOLLOW_UP
            ? 'لا تُعدَّل مخرجاتها'
            : 'لا تُعدَّل جلساتها';
    }
}
