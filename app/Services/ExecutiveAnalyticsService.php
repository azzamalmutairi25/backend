<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\Competency;
use App\Models\FinalReport;
use App\Models\Sector;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  تحليلات تنفيذية مجمّعة للوحة مدير المركز.
//  استعلامات مُجمّعة (group by) لا حلقات — أداءٌ ثابت مع نموّ البيانات.
//  كل الدوال تستقبل قائمة التصنيفات المسموحة (fail-closed) وتحصر عليها.
// ════════════════════════════════════════════════════════════

class ExecutiveAnalyticsService
{
    // الحمولة الكاملة للوحة التنفيذية في نداء واحد
    public function executive(array $allowed, int $trendMonths = 6): array
    {
        $heatmap = $this->competencyHeatmap($allowed);
        $sectors = $this->sectorComparison($allowed);
        $trends = $this->trends($allowed, $trendMonths);

        return [
            'kpis' => $this->kpis($allowed),
            'heatmap' => $heatmap,
            'sectorComparison' => $sectors,
            'tierComparison' => $this->tierComparison($allowed),
            'readinessDistribution' => $this->readinessDistribution($allowed),
            'trends' => $trends,
            'insights' => $this->insights($allowed, $heatmap, $sectors, $trends),
        ];
    }

    // ── مقارنة الفئتين القياديتين: العليا مقابل الوسطى ──
    public function tierComparison(array $allowed): array
    {
        $out = [];
        foreach (['upper' => 'القيادة العليا', 'middle' => 'القيادة الوسطى'] as $tier => $label) {
            $base = Candidate::where('tier', $tier)->whereIn('classification', $allowed);
            $approved = FinalReport::where('status', 'approved')
                ->whereHas('candidate', fn ($q) => $q->where('tier', $tier)->whereIn('classification', $allowed));
            $total = (clone $base)->count();
            $out[] = [
                'tier' => $tier,
                'label' => $label,
                'total' => $total,
                'completed' => (clone $base)->where('status', 'completed')->count(),
                'avgReadiness' => $this->avgReadiness($approved),
            ];
        }
        return $out;
    }

    // ── توزيع جاهزية التقارير المعتمدة على شرائح (صحّة خطّ الكفاءات) ──
    public function readinessDistribution(array $allowed): array
    {
        $r = "(coalesce(behavioral_fit,0) + coalesce(technical_fit,0)) / 2";
        $row = FinalReport::where('status', 'approved')
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->selectRaw("
                count(*) filter (where {$r} >= 85) as excellent,
                count(*) filter (where {$r} >= 70 and {$r} < 85) as good,
                count(*) filter (where {$r} >= 55 and {$r} < 70) as fair,
                count(*) filter (where {$r} < 55) as weak
            ")->first();

        return [
            ['label' => 'ممتاز (٨٥+)', 'count' => (int) ($row->excellent ?? 0), 'tone' => 'excellent'],
            ['label' => 'جيّد (٧٠–٨٥)', 'count' => (int) ($row->good ?? 0), 'tone' => 'good'],
            ['label' => 'متوسّط (٥٥–٧٠)', 'count' => (int) ($row->fair ?? 0), 'tone' => 'fair'],
            ['label' => 'يحتاج تطويراً (<٥٥)', 'count' => (int) ($row->weak ?? 0), 'tone' => 'weak'],
        ];
    }

    // ── مؤشرات رئيسية مع فرق الفترة (آخر ٣٠ يوماً مقابل التي قبلها) ──
    public function kpis(array $allowed): array
    {
        $cand = fn () => Candidate::whereIn('classification', $allowed);
        $approved = fn () => FinalReport::where('status', 'approved')
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));

        $now = now();
        $p1Start = $now->copy()->subDays(30);   // الفترة الحالية: آخر ٣٠ يوماً
        $p2Start = $now->copy()->subDays(60);   // الفترة السابقة: ٣٠ يوماً قبلها

        $newCandCurr = (clone $cand())->where('created_at', '>=', $p1Start)->count();
        $newCandPrev = (clone $cand())->whereBetween('created_at', [$p2Start, $p1Start])->count();

        $apprCurr = $approved()->where('updated_at', '>=', $p1Start)->count();
        $apprPrev = $approved()->whereBetween('updated_at', [$p2Start, $p1Start])->count();

        $readinessNow = $this->avgReadiness($approved());
        $readinessCurr = $this->avgReadiness((clone $approved())->where('updated_at', '>=', $p1Start));
        $readinessPrev = $this->avgReadiness((clone $approved())->whereBetween('updated_at', [$p2Start, $p1Start]));

        return [
            'totalCandidates' => (clone $cand())->count(),
            'activeAssessments' => (clone $cand())->whereIn('status', ['scheduled', 'assessed'])->count(),
            'approvedReports' => $approved()->count(),
            'avgReadiness' => $readinessNow,
            'deltas' => [
                'newCandidates' => $this->delta($newCandCurr, $newCandPrev),
                'approvedReports' => $this->delta($apprCurr, $apprPrev),
                'readiness' => $this->delta($readinessCurr, $readinessPrev, 1),
            ],
        ];
    }

    // ── خريطة حرارية: متوسط نسبة الإتقان لكل كفاءة × قطاع (استعلام واحد) ──
    public function competencyHeatmap(array $allowed): array
    {
        $rows = DB::table('evaluation_scores as es')
            ->join('evaluations as e', 'es.evaluation_id', '=', 'e.id')
            ->join('candidates as c', 'e.candidate_id', '=', 'c.id')
            ->whereIn('e.status', ['submitted', 'approved'])
            ->whereIn('c.classification', $allowed)
            ->groupBy('es.competency_id', 'c.sector_id')
            ->selectRaw('es.competency_id, c.sector_id, avg(es.score) as avg_score, count(*) as n')
            ->get();

        $comps = Competency::orderBy('sort_order')->get()->keyBy('id');
        $sectors = Sector::orderBy('name_ar')->get()->keyBy('id');

        $compIds = [];
        $sectorIds = [];
        $cells = [];
        foreach ($rows as $r) {
            $comp = $comps->get($r->competency_id);
            if (!$comp) {
                continue;
            }
            $max = (int) ($comp->max_level ?: 5);
            $pct = $max > 0 ? min(100.0, round((float) $r->avg_score / $max * 100, 1)) : 0.0;
            $cells[$r->competency_id . '-' . $r->sector_id] = ['pct' => $pct, 'samples' => (int) $r->n];
            $compIds[$r->competency_id] = true;
            $sectorIds[$r->sector_id] = true;
        }

        // نُبقي فقط الكفاءات/القطاعات الحاضرة في البيانات — بترتيبها المرجعي
        $competencies = $comps->filter(fn ($c) => isset($compIds[$c->id]))
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar, 'type' => $c->type])
            ->values();
        $sectorList = $sectors->filter(fn ($s) => isset($sectorIds[$s->id]))
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar])
            ->values();

        return [
            'competencies' => $competencies->all(),
            'sectors' => $sectorList->all(),
            'cells' => $cells,
        ];
    }

    // ── مقارنة القطاعات: العدد، نسبة الإتمام، الجاهزية، الترتيب ──
    public function sectorComparison(array $allowed): array
    {
        $rows = Sector::orderBy('name_ar')->get()->map(function ($sector) use ($allowed) {
            $base = Candidate::where('sector_id', $sector->id)->whereIn('classification', $allowed);
            $total = (clone $base)->count();
            if ($total === 0) {
                return null;
            }
            $completed = (clone $base)->where('status', 'completed')->count();
            $approved = FinalReport::where('status', 'approved')
                ->whereHas('candidate', fn ($q) => $q->where('sector_id', $sector->id)->whereIn('classification', $allowed));

            return [
                'sectorId' => $sector->id,
                'sectorName' => $sector->name_ar,
                'total' => $total,
                'completed' => $completed,
                'completionRate' => round($completed / $total * 100, 1),
                'avgReadiness' => $this->avgReadiness($approved),
            ];
        })->filter()->values();

        // ترتيب تنازلي بالجاهزية (الأعلى أولاً) — رتبة لكل قطاع
        $ranked = $rows->sortByDesc(fn ($r) => $r['avgReadiness'] ?? -1)->values();
        return $ranked->map(fn ($r, $i) => $r + ['rank' => $i + 1])->all();
    }

    // ── اتجاهات شهرية متعدّدة السلاسل: التقارير المعتمدة + متوسط الجاهزية ──
    public function trends(array $allowed, int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $since = now()->copy()->startOfMonth()->subMonths($months - 1);

        $rows = FinalReport::where('status', 'approved')
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->where('updated_at', '>=', $since)
            ->selectRaw("to_char(updated_at, 'YYYY-MM') ym, count(*) c,
                         avg((coalesce(behavioral_fit,0) + coalesce(technical_fit,0)) / 2) readiness")
            ->groupBy('ym')->orderBy('ym')->get()
            ->keyBy('ym');

        // نملأ كل شهر في المدى (حتى الأشهر بلا بيانات) — خطٌّ متّصل
        $out = [];
        for ($m = 0; $m < $months; $m++) {
            $key = $since->copy()->addMonths($m)->format('Y-m');
            $row = $rows->get($key);
            $out[] = [
                'month' => $key,
                'approvedReports' => $row ? (int) $row->c : 0,
                'avgReadiness' => $row && $row->readiness !== null ? round((float) $row->readiness, 1) : null,
            ];
        }
        return $out;
    }

    // ── رؤى تلقائية مشتقّة من التجميعات (نصوص عربية جاهزة للعرض) ──
    public function insights(array $allowed, ?array $heatmap = null, ?array $sectors = null, ?array $trends = null): array
    {
        $heatmap ??= $this->competencyHeatmap($allowed);
        $sectors ??= $this->sectorComparison($allowed);
        $trends ??= $this->trends($allowed);

        $out = [];

        // (١) أقوى/أضعف كفاءة مؤسسياً (متوسط عبر كل القطاعات، بعتبة عيّنة)
        $compAvg = $this->competencyAverages($allowed);
        if (count($compAvg) >= 2) {
            $strong = $compAvg[0];
            $weak = end($compAvg);
            $out[] = [
                'tone' => 'positive', 'icon' => 'trending-up',
                'title' => 'أقوى كفاءة مؤسسياً',
                'detail' => "«{$strong['name']}» بمتوسط إتقان {$strong['pct']}% عبر القطاعات.",
            ];
            $out[] = [
                'tone' => 'warning', 'icon' => 'target',
                'title' => 'أولوية التطوير',
                'detail' => "«{$weak['name']}» الأدنى بمتوسط {$weak['pct']}% — مرشّحة لبرنامج تطوير مؤسسي.",
            ];
        }

        // (٢) أعلى/أدنى قطاع بالجاهزية
        $withReadiness = array_values(array_filter($sectors, fn ($s) => $s['avgReadiness'] !== null));
        if (count($withReadiness) >= 2) {
            $top = $withReadiness[0]; // مُرتّبة تنازلياً
            $bottom = end($withReadiness);
            $out[] = [
                'tone' => 'positive', 'icon' => 'award',
                'title' => 'القطاع الأعلى جاهزية',
                'detail' => "«{$top['sectorName']}» بجاهزية {$top['avgReadiness']}% (إتمام {$top['completionRate']}%).",
            ];
            if ($bottom['sectorId'] !== $top['sectorId']) {
                $out[] = [
                    'tone' => 'warning', 'icon' => 'alert',
                    'title' => 'قطاع يحتاج متابعة',
                    'detail' => "«{$bottom['sectorName']}» الأدنى جاهزية بـ{$bottom['avgReadiness']}%.",
                ];
            }
        }

        // (٣) اتجاه الجاهزية (آخر شهرين بمعطيات)
        $ready = array_values(array_filter($trends, fn ($t) => $t['avgReadiness'] !== null));
        if (count($ready) >= 2) {
            $last = end($ready);
            $prev = $ready[count($ready) - 2];
            $diff = round($last['avgReadiness'] - $prev['avgReadiness'], 1);
            $out[] = [
                'tone' => $diff >= 0 ? 'positive' : 'warning',
                'icon' => $diff >= 0 ? 'trending-up' : 'trending-down',
                'title' => 'اتجاه الجاهزية',
                'detail' => $diff >= 0
                    ? "ارتفعت جاهزية التقارير المعتمدة بمقدار {$diff} نقطة عن الشهر السابق."
                    : "انخفضت جاهزية التقارير المعتمدة بمقدار " . abs($diff) . " نقطة عن الشهر السابق.",
            ];
        }

        // (٤) اختناق سلسلة الاعتماد (المرحلة الأكثر انتظاراً)
        $bottleneck = $this->reportBottleneck($allowed);
        if ($bottleneck) {
            $out[] = [
                'tone' => 'info', 'icon' => 'clock',
                'title' => 'اختناق الاعتماد',
                'detail' => "{$bottleneck['count']} تقرير بانتظار «{$bottleneck['label']}» — أطول طابور في السلسلة.",
            ];
        }

        return $out;
    }

    // ─────────────── مساعدات ───────────────

    // متوسط الجاهزية = متوسط (السلوكي + الفنّي) / ٢ على استعلام تقارير معتمدة
    private function avgReadiness($approvedQuery): ?float
    {
        $v = $approvedQuery->selectRaw('avg((coalesce(behavioral_fit,0) + coalesce(technical_fit,0)) / 2) r')->value('r');
        return $v === null ? null : round((float) $v, 1);
    }

    // متوسط نسبة الإتقان لكل كفاءة (تنازلي)، بعتبة عيّنة ≥٣ لتفادي الضجيج
    private function competencyAverages(array $allowed): array
    {
        $rows = DB::table('evaluation_scores as es')
            ->join('evaluations as e', 'es.evaluation_id', '=', 'e.id')
            ->join('candidates as c', 'e.candidate_id', '=', 'c.id')
            ->whereIn('e.status', ['submitted', 'approved'])
            ->whereIn('c.classification', $allowed)
            ->groupBy('es.competency_id')
            ->havingRaw('count(*) >= 3')
            ->selectRaw('es.competency_id, avg(es.score) avg_score, count(*) n')
            ->get();

        $comps = Competency::all()->keyBy('id');
        $list = [];
        foreach ($rows as $r) {
            $comp = $comps->get($r->competency_id);
            if (!$comp) {
                continue;
            }
            $max = (int) ($comp->max_level ?: 5);
            $list[] = [
                'name' => $comp->name_ar,
                'pct' => $max > 0 ? min(100.0, round((float) $r->avg_score / $max * 100, 1)) : 0.0,
            ];
        }
        usort($list, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        return $list;
    }

    private function reportBottleneck(array $allowed): ?array
    {
        $labels = [
            'pending_evaluator' => 'اعتماد المقيّم',
            'pending_manager' => 'اعتماد مدير التقييم',
            'pending_dev_approval' => 'اعتماد تطوير الكفاءات',
            'returned' => 'إعادة للتعديل',
        ];
        $counts = FinalReport::whereIn('status', array_keys($labels))
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        $topStatus = null;
        $topCount = 0;
        foreach ($labels as $status => $label) {
            $c = (int) ($counts[$status] ?? 0);
            if ($c > $topCount) {
                $topCount = $c;
                $topStatus = $status;
            }
        }
        return $topStatus ? ['status' => $topStatus, 'label' => $labels[$topStatus], 'count' => $topCount] : null;
    }

    // فرق نسبي بين فترتين: {value, prev, pct, dir}
    private function delta($curr, $prev, int $decimals = 0): array
    {
        $curr = (float) ($curr ?? 0);
        $prev = (float) ($prev ?? 0);
        $pct = $prev > 0 ? round(($curr - $prev) / $prev * 100, 1) : ($curr > 0 ? 100.0 : 0.0);
        return [
            'value' => $decimals > 0 ? round($curr, $decimals) : (int) $curr,
            'prev' => $decimals > 0 ? round($prev, $decimals) : (int) $prev,
            'pct' => $pct,
            'dir' => $curr > $prev ? 'up' : ($curr < $prev ? 'down' : 'flat'),
        ];
    }
}
