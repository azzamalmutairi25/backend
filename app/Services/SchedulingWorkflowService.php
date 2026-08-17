<?php

namespace App\Services;

use App\Models\PeriodAssessor;
use App\Models\PeriodStepProgress;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\SchedulingWorkflowStep;

// ════════════════════════════════════════════════════════════
//  سير عمل الجدولة — قياس موجةٍ على الخطوات المعرَّفة في الإعدادات
// ════════════════════════════════════════════════════════════
//
// خطوتان لا واحدة: خطوةٌ **آلية** يتحقّق منها النظام من حالة الموجة نفسها،
// وخطوةٌ **يدوية** يؤشّرها صاحبها. الفرق ليس تصنيفاً بل ضماناً: الآلية لا
// تُؤشَّر كذباً ولا تبقى مؤشَّرةً بعد نقض شرطها — تُحسب في كل قراءة.
//
// المفاتيح هنا هي **العقد** بين الإعدادات والشيفرة: من كتب مفتاحاً غير معروف
// في شاشة الإعدادات يُرفض عند الحفظ، فلا تظهر خطوةٌ آليةٌ لا أحد يحسبها.
// وإضافة قدرةٍ جديدة لاحقاً (الجدول الذهبي، تصاريح الدخول، تسليم الوزارة)
// تعني سطراً هنا ثم كتابة المفتاح في الشاشة — بلا هجرة ولا نشر.
class SchedulingWorkflowService
{
    /** المفاتيح المعروفة ووصفها العربي — تُعرض في شاشة الإعدادات للاختيار */
    public const CHECKS = [
        'period.dates' => 'مدى الموجة محدَّد (تاريخا البداية والنهاية)',
        'period.assessors' => 'أُدرِج اسمٌ واحد على الأقل في لوحة الموجة',
        'period.activities' => 'أُسنِد مقيّم لكل نشاطٍ في اللوحة',
        'period.participants' => 'جُدولت جلسةٌ واحدة على الأقل في الموجة',
        'period.evaluators_linked' => 'كل جلسة في الموجة لها مقيّم',
        'period.daily_spread' => 'كل يومٍ من أيام الموجة فيه جلسة',
        'period.submitted' => 'أُرسِلت الجدولة لمدير المركز',
        'period.approved' => 'اعتمد مدير المركز الموجة',
        'period.golden_synced' => 'رُحّلت رموز الموجة وتواريخها إلى الجدول الذهبي',
        'period.dates_written' => 'كُتب تاريخ التقييم في سجلّ كل دورة في الموجة',
        'period.dispatched' => 'سُلّمت الجدولة لكل جهةٍ لها مشاركون في الموجة',
    ];

    public static function checkOptions(): array
    {
        $out = [];
        foreach (self::CHECKS as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }
        return $out;
    }

    public static function isKnownCheck(?string $key): bool
    {
        return $key === null || $key === '' || array_key_exists($key, self::CHECKS);
    }

    /**
     * حالة مفتاحٍ آلي على موجة.
     *
     * مفتاحٌ غير معروف (بقي في القاعدة بعد حذفه من الشيفرة) يُقرأ **غير مكتمل**
     * لا مكتملاً: الافتراض المتساهل هنا يعني خطوةً تُعلَن منجَزةً بلا أن يفعلها أحد.
     */
    public function checkPasses(string $key, SchedulingPeriod $period): bool
    {
        return match ($key) {
            'period.dates' => $period->start_date !== null && $period->end_date !== null,

            'period.assessors' => PeriodAssessor::where('period_id', $period->id)->exists(),

            // لكل نشاطٍ مذكور في اللوحة مقعدُ مقيّمٍ متاح — لوحةٌ فيها مساعدون
            // وحدهم ليست إسناداً للنشاط
            'period.activities' => PeriodAssessor::where('period_id', $period->id)
                ->where('seat', 'evaluator')->where('is_available', true)->exists(),

            'period.participants' => Schedule::where('period_id', $period->id)->exists(),

            'period.evaluators_linked' => Schedule::where('period_id', $period->id)->exists()
                && !Schedule::where('period_id', $period->id)->whereNull('evaluator_id')->exists(),

            'period.daily_spread' => $this->everyDayHasSession($period),

            'period.submitted' => in_array($period->status, ['pending_center', 'approved', 'closed'], true),

            'period.approved' => in_array($period->status, ['approved', 'closed'], true),

            // الجدول الذهبي: كل جلسة في الموجة لها صفّ (تاريخ × رمز)
            'period.golden_synced' => $this->goldenCoversPeriod($period),

            // التسليم: لكل جهةٍ لها مشاركون في الموجة سجلُّ تسليمٍ فيها.
            // الجهةُ بلا مشاركين لا تُنتظر — دورةٌ بلا عسكريين لا تقف على وكالة
            // الشؤون العسكرية.
            'period.dispatched' => $this->dispatchesCoverPeriod($period),

            // ترحيل التواريخ: لا دورةَ في الموجة بلا تاريخ تقييمٍ مكتوب
            'period.dates_written' => Schedule::where('period_id', $period->id)->exists()
                && !\App\Models\Assessment::whereIn(
                        'id', Schedule::where('period_id', $period->id)->select('assessment_id')
                    )->whereNull('first_session_date')->exists(),

            default => false,
        };
    }

    /**
     * الجدول الذهبي يغطّي الموجة؟ لكل (تاريخ، رمز) في جلساتها صفٌّ فيه.
     *
     * المقارنة على الأزواج لا على العدد: جدولٌ فيه صفوفٌ يدوية بعدد الناقص
     * كان سيُقرأ «مكتملاً» وهو يغفل جلساتٍ حقيقية.
     */
    private function goldenCoversPeriod(SchedulingPeriod $period): bool
    {
        $sessions = Schedule::with('assessment')->where('period_id', $period->id)->get();
        if ($sessions->isEmpty()) {
            return false;
        }

        $needed = $sessions->map(fn ($s) => substr((string) $s->schedule_date, 0, 10)
            . '|' . ($s->assessment?->participant_code ?? ''))->unique()->filter(fn ($k) => !str_ends_with($k, '|'));

        $have = \App\Models\GoldenScheduleEntry::where('period_id', $period->id)
            ->get()
            ->map(fn ($e) => $e->entry_date->toDateString() . '|' . $e->participant_code)
            ->all();

        return $needed->every(fn ($k) => in_array($k, $have, true));
    }

    /** لكل جهةٍ لها مشاركون في الموجة سجلُّ تسليمٍ فيها؟ */
    private function dispatchesCoverPeriod(SchedulingPeriod $period): bool
    {
        $categories = \App\Models\Candidate::whereIn(
                'id', Schedule::where('period_id', $period->id)->select('candidate_id')
            )->distinct()->pluck('personnel_category')->filter()->all();

        if (!$categories) {
            return false;   // لا مشاركين ⇒ لا شيء سُلّم
        }

        $sent = \App\Models\ScheduleDispatch::where('period_id', $period->id)
            ->pluck('authority_id')->unique();

        foreach (\App\Models\DispatchAuthority::active()->get() as $a) {
            $owes = array_intersect($a->categoryList(), $categories);
            if ($owes && !$sent->contains($a->id)) {
                return false;
            }
        }
        return true;
    }

    private function everyDayHasSession(SchedulingPeriod $period): bool
    {
        $days = $period->days();
        if ($days === []) {
            return false;
        }
        $covered = Schedule::where('period_id', $period->id)
            ->selectRaw('DISTINCT schedule_date::date as d')
            ->pluck('d')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();

        foreach ($days as $day) {
            if (!in_array($day->toDateString(), $covered, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * الخطوات النشطة وحالة كلٍّ منها على هذه الموجة، ومعها ملخّص التقدّم.
     *
     * غير النشطة تُستثنى: إطفاء خطوةٍ من الإعدادات يعني «ليست من إجرائنا»،
     * فبقاؤها في القائمة يُبقي الموجة ناقصةً إلى الأبد.
     */
    public function forPeriod(SchedulingPeriod $period): array
    {
        $steps = SchedulingWorkflowStep::active()->ordered()->get();

        $progress = PeriodStepProgress::with('doer')
            ->where('period_id', $period->id)
            ->get()
            ->keyBy('step_id');

        $rows = [];
        $doneRequired = 0;
        $totalRequired = 0;

        foreach ($steps as $step) {
            $row = $progress->get($step->id);

            if ($step->isAutomatic()) {
                $status = $this->checkPasses($step->auto_key, $period) ? 'done' : 'pending';
                $note = null;
                $byName = null;
                $at = null;
            } else {
                $status = $row?->status ?? 'pending';
                $note = $row?->note;
                $byName = optional($row?->doer)->full_name;
                $at = optional($row?->done_at)?->toDateTimeString();
            }

            if ($step->is_required && $status !== 'skipped') {
                $totalRequired++;
                if ($status === 'done') {
                    $doneRequired++;
                }
            }

            $rows[] = [
                'id' => $step->id,
                'position' => $step->position,
                'title' => $step->title_ar,
                'description' => $step->description,
                'autoKey' => $step->auto_key,
                'autoLabel' => $step->auto_key ? (self::CHECKS[$step->auto_key] ?? 'فحص غير معروف') : null,
                'isAutomatic' => $step->isAutomatic(),
                'isRequired' => $step->is_required,
                'status' => $status,
                'note' => $note,
                'doneByName' => $byName,
                'doneAt' => $at,
            ];
        }

        return [
            'steps' => $rows,
            'summary' => [
                'total' => count($rows),
                'required' => $totalRequired,
                'done' => $doneRequired,
                'percent' => $totalRequired > 0 ? (int) round($doneRequired * 100 / $totalRequired) : 0,
            ],
        ];
    }
}
