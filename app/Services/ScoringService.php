<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use Illuminate\Support\Collection;

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
            if (!$c) return null;
            $avg = (float) $rows->avg('score');
            $max = (int) ($c->max_level ?: 5);
            return [
                'competencyId' => $c->id,
                'name' => $c->name_ar,
                'type' => $c->type,
                'weight' => (float) ($c->weight ?? 1),
                'avgScore' => round($avg, 2),
                'maxLevel' => $max,
                'pct' => $max > 0 ? round($avg / $max * 100, 2) : 0.0,
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

    // تحليل الفجوة: المستوى المُحقَّق مقابل المطلوب لفئة المرشّح، لكل كفاءة لها مستوى مطلوب
    public function computeGap(Assessment $assessment, string $tier): array
    {
        $targetCol = $tier === 'upper' ? 'target_upper' : 'target_middle';
        $competencies = Competency::whereNotNull($targetCol)->orderBy('sort_order')->get();

        $evalIds = Evaluation::where('assessment_id', $assessment->id)
            ->whereIn('status', ['submitted', 'approved'])->pluck('id');
        $achieved = EvaluationScore::whereIn('evaluation_id', $evalIds)->get()
            ->groupBy('competency_id')
            ->map(fn ($rows) => round((float) $rows->avg('score'), 2));

        $items = $competencies->map(function ($c) use ($achieved, $targetCol) {
            $target = (int) $c->{$targetCol};
            $ach = $achieved->get($c->id); // null إن لم تُرصد بعد
            return [
                'competency' => $c->name_ar,
                'type' => $c->type,
                'maxLevel' => (int) $c->max_level,
                'target' => $target,
                'achieved' => $ach,
                'gap' => $ach === null ? null : round($ach - $target, 2),
                'met' => $ach !== null && $ach >= $target,
            ];
        })->values();

        return [
            'tier' => $tier,
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

        if ($rows->isEmpty()) return null;

        $weightSum = $rows->sum('weight');
        if ($weightSum <= 0) return null;

        $acc = $rows->sum(fn ($r) => $r['pct'] * $r['weight']);
        return round($acc / $weightSum, 2);
    }
}
