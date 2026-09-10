<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  خدمة الاحتساب — تجميع درجات الكفاءات إلى توافق (متوسط موزون)
//  السلوكي = كفاءات سلوكية/قيادية، الفني = كفاءات فنية، العام = الكل.
//  كل درجة تُطبَّع (score / max_level × 100) ثم تُوزَن بوزن الكفاءة.
// ════════════════════════════════════════════════════════════

class ScoringService
{
    private const BEHAVIORAL_TYPES = ['behavioral', 'leadership'];

    private const TECHNICAL_TYPES = ['technical'];

    // يحسب توافق دورة من درجات تقييماتها المُرسلة/المعتمدة
    public function computeFit(Assessment $assessment): array
    {
        $evalIds = Evaluation::where('assessment_id', $assessment->id)
            ->whereIn('status', ['submitted', 'approved'])
            ->pluck('id');

        $scores = EvaluationScore::whereIn('evaluation_id', $evalIds)
            ->with('competency')
            ->get();

        // متوسط درجة كل كفاءة عبر الأنشطة (كفاءة قد تُرصد في أكثر من نشاط) — تفادي ازدواج الاحتساب
        $byComp = $scores->groupBy('competency_id')->map(function ($rows) {
            $c = $rows->first()->competency;
            if (! $c) {
                return null;
            }
            $avg = (float) $rows->avg('score');
            $max = (int) ($c->max_level ?: 5);

            return [
                'competencyId' => $c->id,
                'name' => $c->name_ar,
                'type' => $c->type,
                'group' => $c->group,   // تجميع سلوكي (سلوكية/تميز/إحساس)
                'domain' => $c->domain, // مجال فنّي
                'weight' => (float) ($c->weight ?? 1),
                'avgScore' => round($avg, 2),
                'maxLevel' => $max,
                // تُقصَر على 100: لو خُفّض max_level بعد الرصد لتجاوزت النسبة 100٪
                'pct' => $max > 0 ? round(min($avg, $max) / $max * 100, 2) : 0.0,
            ];
        })->filter()->values();

        return [
            'behavioralFit' => $this->weightedPct($byComp, self::BEHAVIORAL_TYPES),
            'technicalFit' => $this->weightedPct($byComp, self::TECHNICAL_TYPES),
            'overallFit' => $this->weightedPct($byComp, null),
            'competenciesScored' => $byComp->count(),
            'evaluationsCount' => $evalIds->count(),
            'breakdown' => $byComp->all(),
        ];
    }

    private const TIERS = ['upper', 'middle'];

    // تحليل الفجوة: المستوى المُحقَّق مقابل المطلوب لفئة المشارك، لكل كفاءة لها مستوى مطلوب
    public function computeGap(Assessment $assessment, string $tier): array
    {
        // فئة مجهولة كانت تقع صامتة على target_middle ثم تُعاد كما هي في الرد،
        // فينتج ردّ يقول tier=X بأرقام middle. الفئة تأتي من classifyTier (upper|middle)
        // فهذا خطأ برمجي لا مُدخَل مستخدم — يُرفض بدل أن يُخمَّن.
        if (! in_array($tier, self::TIERS, true)) {
            throw new \InvalidArgumentException(
                "فئة قيادية غير معروفة: '{$tier}'. المسموح: ".implode(', ', self::TIERS)
            );
        }

        $targetCol = $tier === 'upper' ? 'target_upper' : 'target_middle';

        // المستوى المطلوب حسب رتبة المشارك إن كانت مضبوطة، وإلّا حسب فئته.
        //
        // الفئة رقمان لا غير: كلّ من في «العليا» يُحاسَب بسقفٍ واحد، فيُقاس
        // اللواء بمعيار العقيد وهما درجتان متباعدتان في نموذج المركز. مصفوفةُ
        // الرتبة أدقّ، فتُقدَّم متى وُجدت؛ ويبقى عمودا الفئة ارتداداً لرتبةٍ
        // لم تُضبط بعد أو مشاركٍ بلا رتبة مُدارة.
        $rankId = $assessment->candidate?->rank_id;
        $byRank = $rankId
            ? DB::table('rank_competency_targets')->where('rank_id', $rankId)
                ->pluck('target', 'competency_id')
            : collect();

        $competencies = Competency::orderBy('sort_order')->get()
            ->filter(fn ($c) => $byRank->has($c->id) || $c->{$targetCol} !== null);

        $evalIds = Evaluation::where('assessment_id', $assessment->id)
            ->whereIn('status', ['submitted', 'approved'])->pluck('id');
        $achieved = EvaluationScore::whereIn('evaluation_id', $evalIds)->get()
            ->groupBy('competency_id')
            ->map(fn ($rows) => round((float) $rows->avg('score'), 2));

        $items = $competencies->map(function ($c) use ($achieved, $targetCol, $byRank) {
            $fromRank = $byRank->has($c->id);
            $target = (int) ($fromRank ? $byRank[$c->id] : $c->{$targetCol});
            $ach = $achieved->get($c->id); // null إن لم تُرصد بعد

            return [
                'competency' => $c->name_ar,
                'type' => $c->type,
                'maxLevel' => (int) $c->max_level,
                'target' => $target,
                // مصدرُ السقف يُعرَض لا يُخمَّن: مراجعُ التقرير يحتاج أن يعرف
                // أنّ هذه الكفاءة قيست بمعيار الرتبة وتلك بمعيار الفئة.
                'targetSource' => $fromRank ? 'rank' : 'tier',
                'achieved' => $ach,
                'gap' => $ach === null ? null : round($ach - $target, 2),
                'met' => $ach !== null && $ach >= $target,
            ];
        })->values();

        return [
            'tier' => $tier,
            'rankLabel' => $assessment->candidate?->rank?->label,
            'targetSource' => $byRank->isNotEmpty() ? 'rank' : 'tier',
            'total' => $items->count(),
            'met' => $items->where('met', true)->count(),
            'items' => $items->all(),
        ];
    }

    // متوسط موزون للنِّسب على كفاءات النوع المطلوب (null = كل الأنواع)
    private function weightedPct(Collection $byComp, ?array $types): ?float
    {
        $rows = $types === null
            ? $byComp
            : $byComp->filter(fn ($r) => in_array($r['type'], $types, true));

        if ($rows->isEmpty()) {
            return null;
        }

        $weightSum = $rows->sum('weight');
        if ($weightSum <= 0) {
            return null;
        }

        $acc = $rows->sum(fn ($r) => $r['pct'] * $r['weight']);

        return round($acc / $weightSum, 2);
    }
}
