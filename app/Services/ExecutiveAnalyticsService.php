<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateUpdateRequest;
use App\Models\Competency;
use App\Models\DevelopmentPlanItem;
use App\Models\DiscussionCircle;
use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\MeasurementResult;
use App\Models\ReceptionAssignment;
use App\Models\ReceptionVisit;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleDispatch;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  تحليلات تنفيذية مجمّعة لشاشة القيادة التنفيذية للمركز.
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

    // ════════════════════════════════════════════════════════
    //  نظرة شاملة على المنصّة — كل بابٍ من أبواب العمل إلا الإعدادات
    // ════════════════════════════════════════════════════════
    //
    // القيادة التنفيذية تطّلع ولا تُشغّل: هذه الحمولة عدّاداتٌ مجمّعة للقراءة،
    // لا صفوفَ عملٍ تُحرَّر. والإعداداتُ خارجها بقرارٍ صريح — ضبط النظام سلطةٌ
    // تُدار من حساب مدير النظام، فلا تظهر ولو عدداً في شاشة الاطّلاع.
    //
    // كل قسمٍ بالشكل نفسه {key,label,icon,route,metrics,bars} كي تعرضه الواجهة
    // بمُصيِّرٍ واحد: بابٌ يُضاف هنا يظهر هناك بلا لمس الواجهة.
    public function platformOverview(array $allowed): array
    {
        return [
            'sections' => [
                $this->ovCandidates($allowed),
                $this->ovWaves(),
                $this->ovSessions($allowed),
                $this->ovReception($allowed),
                $this->ovAttendance($allowed),
                $this->ovEvaluation($allowed),
                $this->ovMeasurement($allowed),
                $this->ovReports($allowed),
                $this->ovDevelopmentPlans($allowed),
                $this->ovCompetencies(),
                $this->ovUpdateRequests($allowed),
                $this->ovPeople(),
                $this->ovAudit(),
            ],
        ];
    }

    // ── المرشحون ──
    private function ovCandidates(array $allowed): array
    {
        $cand = fn () => Candidate::whereIn('classification', $allowed);
        $byStatus = (clone $cand())->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $labels = [
            'draft' => 'مسودّة', 'scheduled' => 'مجدول', 'assessed' => 'مُقيَّم',
            'approved' => 'معتمد', 'completed' => 'مكتمل',
        ];

        return [
            'key' => 'candidates',
            'label' => 'المرشحون',
            'icon' => 'candidates',
            'route' => '/candidates',
            'metrics' => [
                ['label' => 'الإجمالي', 'value' => (clone $cand())->count()],
                ['label' => 'قيد التقييم', 'value' => (clone $cand())->whereIn('status', ['scheduled', 'assessed'])->count(), 'tone' => 'info'],
                ['label' => 'مكتمل', 'value' => (int) ($byStatus['completed'] ?? 0), 'tone' => 'ok'],
                ['label' => 'جدد (٣٠ يوماً)', 'value' => (clone $cand())->where('created_at', '>=', now()->subDays(30))->count()],
            ],
            'bars' => $this->bars($labels, $byStatus),
        ];
    }

    // ── موجات الجدولة ──
    private function ovWaves(): array
    {
        $byStatus = SchedulingPeriod::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $pending = (int) ($byStatus['pending_center'] ?? 0);

        return [
            'key' => 'waves',
            'label' => 'موجات الجدولة',
            'icon' => 'calendar',
            'route' => '/scheduling-periods',
            'metrics' => [
                ['label' => 'الموجات', 'value' => (int) $byStatus->sum()],
                ['label' => 'معتمَدة', 'value' => (int) ($byStatus['approved'] ?? 0), 'tone' => 'ok'],
                // قرارٌ ينتظر مدير المركز نفسه — يُبرز لأنه الوحيد الذي يرفعه
                ['label' => 'بانتظار اعتمادك', 'value' => $pending, 'tone' => $pending > 0 ? 'warn' : 'neutral'],
                ['label' => 'مغلقة', 'value' => (int) ($byStatus['closed'] ?? 0)],
            ],
            'bars' => $this->bars(SchedulingPeriod::STATUS_LABEL, $byStatus),
        ];
    }

    // ── الجلسات والتسليم ──
    private function ovSessions(array $allowed): array
    {
        $today = now()->toDateString();
        $sched = fn () => Schedule::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
        $byActivity = (clone $sched())->selectRaw('activity, count(*) c')->groupBy('activity')->pluck('c', 'activity');

        return [
            'key' => 'sessions',
            'label' => 'الجلسات والتسليم',
            'icon' => 'calendar',
            'route' => '/schedules',
            'metrics' => [
                ['label' => 'جلسات اليوم', 'value' => (clone $sched())->whereDate('schedule_date', $today)->count(), 'tone' => 'info'],
                ['label' => 'قادمة', 'value' => (clone $sched())->whereDate('schedule_date', '>', $today)->count()],
                ['label' => 'حلقات النقاش', 'value' => DiscussionCircle::count()],
                ['label' => 'تسليمات للجهات', 'value' => ScheduleDispatch::count()],
            ],
            'bars' => $this->bars(ReceptionAssignment::ACTIVITY_LABEL, $byActivity),
        ];
    }

    // ── استقبال الموظفين (اليوم) ──
    private function ovReception(array $allowed): array
    {
        $today = now()->toDateString();
        $visits = fn () => ReceptionVisit::whereDate('visit_date', $today)
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
        $byStatus = (clone $visits())->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        // إسنادٌ لم يبتّ فيه المقيّم بعد — المرشّح واقفٌ في البهو حتى يُقبل أو يُردّ
        $pending = ReceptionAssignment::where('status', ReceptionAssignment::PENDING)
            ->whereHas('visit', fn ($q) => $q->whereDate('visit_date', $today))
            ->count();

        return [
            'key' => 'reception',
            'label' => 'استقبال اليوم',
            'icon' => 'user',
            'route' => '/reception',
            'metrics' => [
                ['label' => 'زيارات اليوم', 'value' => (int) $byStatus->sum()],
                ['label' => 'وصلوا', 'value' => (int) ($byStatus[ReceptionVisit::ARRIVED] ?? 0), 'tone' => 'info'],
                ['label' => 'وُزّعوا', 'value' => (int) ($byStatus[ReceptionVisit::DISTRIBUTED] ?? 0)],
                ['label' => 'بانتظار قرار المقيّم', 'value' => $pending, 'tone' => $pending > 0 ? 'warn' : 'neutral'],
            ],
            'bars' => $this->bars([
                ReceptionVisit::ARRIVED => 'وصل',
                ReceptionVisit::DISTRIBUTED => 'وُزّع',
                ReceptionVisit::APPROVED => 'اعتُمد',
            ], $byStatus),
        ];
    }

    // ── الحضور (آخر ٣٠ يوماً) ──
    private function ovAttendance(array $allowed): array
    {
        $ids = Schedule::whereDate('schedule_date', '>=', now()->subDays(30)->toDateString())
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->pluck('id');

        $byStatus = Attendance::whereIn('schedule_id', $ids)
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        $present = (int) ($byStatus['present'] ?? 0);
        $absent = (int) ($byStatus['absent_excused'] ?? 0) + (int) ($byStatus['absent_unexcused'] ?? 0);
        $recorded = $present + $absent;
        // النسبة على المرصود لا على المجدول: جلسةٌ لم تُرصد بعد ليست غياباً
        $rate = $recorded > 0 ? round($present / $recorded * 100, 1) : null;

        return [
            'key' => 'attendance',
            'label' => 'الحضور (٣٠ يوماً)',
            'icon' => 'attendance',
            'route' => '/attendance',
            'metrics' => [
                ['label' => 'حاضر', 'value' => $present, 'tone' => 'ok'],
                ['label' => 'غائب', 'value' => $absent, 'tone' => $absent > 0 ? 'warn' : 'neutral'],
                ['label' => 'لم يُرصد', 'value' => max(0, $ids->count() - $recorded)],
                ['label' => 'نسبة الحضور', 'value' => $rate, 'suffix' => '%', 'tone' => $rate === null ? 'neutral' : ($rate >= 85 ? 'ok' : 'warn')],
            ],
            'bars' => $this->bars([
                'present' => 'حاضر',
                'absent_excused' => 'غياب بعذر',
                'absent_unexcused' => 'غياب بلا عذر',
            ], $byStatus),
        ];
    }

    // ── التقييم ──
    private function ovEvaluation(array $allowed): array
    {
        $evals = fn () => Evaluation::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
        $byStatus = (clone $evals())->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return [
            'key' => 'evaluation',
            'label' => 'التقييم',
            'icon' => 'assessment',
            'route' => '/assessment',
            'metrics' => [
                ['label' => 'جلسات التقييم', 'value' => (int) $byStatus->sum()],
                ['label' => 'معتمدة', 'value' => (int) ($byStatus['approved'] ?? 0), 'tone' => 'ok'],
                ['label' => 'مُرسلة للاعتماد', 'value' => (int) ($byStatus['submitted'] ?? 0), 'tone' => 'info'],
                ['label' => 'مسودّات', 'value' => (int) ($byStatus['draft'] ?? 0)],
            ],
            'bars' => $this->bars([
                'draft' => 'مسودّة', 'submitted' => 'مُرسلة', 'approved' => 'معتمدة',
            ], $byStatus),
        ];
    }

    // ── أدوات القياس ──
    private function ovMeasurement(array $allowed): array
    {
        $scoped = fn ($q) => $q->whereHas('candidate', fn ($c) => $c->whereIn('classification', $allowed));

        $withResults = $scoped(MeasurementResult::query())->distinct('assessment_id')->count('assessment_id');
        $totalAssessments = $scoped(Assessment::query())->count();
        $avg = $scoped(MeasurementResult::query())
            ->selectRaw('avg(personality_score) p, avg(analytical_score) a, avg(english_score) e')->first();
        $r1 = fn ($v) => $v === null ? null : round((float) $v, 1);
        $missing = max(0, $totalAssessments - $withResults);

        return [
            'key' => 'measurement',
            'label' => 'أدوات القياس',
            'icon' => 'clipboard',
            'route' => '/measurements',
            'metrics' => [
                ['label' => 'دورات لها نتائج', 'value' => $withResults, 'tone' => 'ok'],
                ['label' => 'بلا نتائج', 'value' => $missing, 'tone' => $missing > 0 ? 'warn' : 'neutral'],
                ['label' => 'متوسط التحليلي', 'value' => $r1($avg->a ?? null)],
                ['label' => 'متوسط الإنجليزي', 'value' => $r1($avg->e ?? null)],
            ],
            'bars' => [],
        ];
    }

    // ── التقارير ──
    private function ovReports(array $allowed): array
    {
        $byStatus = $this->reportStatusCounts($allowed);
        $chain = ['pending_evaluator', 'pending_manager', 'pending_dev_approval'];
        $inChain = collect($chain)->sum(fn ($s) => (int) ($byStatus[$s] ?? 0));
        $returned = (int) ($byStatus['returned'] ?? 0);

        return [
            'key' => 'reports',
            'label' => 'التقارير',
            'icon' => 'reports',
            'route' => '/reports',
            'metrics' => [
                ['label' => 'الإجمالي', 'value' => (int) $byStatus->sum()],
                ['label' => 'معتمدة', 'value' => (int) ($byStatus['approved'] ?? 0), 'tone' => 'ok'],
                ['label' => 'في سلسلة الاعتماد', 'value' => $inChain, 'tone' => $inChain > 0 ? 'info' : 'neutral'],
                ['label' => 'مُعادة للتعديل', 'value' => $returned, 'tone' => $returned > 0 ? 'warn' : 'neutral'],
            ],
            'bars' => $this->bars(self::REPORT_STATUS_LABEL, $byStatus),
        ];
    }

    // ── خطط التطوير ──
    private function ovDevelopmentPlans(array $allowed): array
    {
        $items = fn () => DevelopmentPlanItem::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
        $byStatus = (clone $items())->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        // متأخّر = مضى موعده المستهدف وهو غير منجَز
        $overdue = (clone $items())->where('status', '!=', 'done')
            ->whereNotNull('target_date')->whereDate('target_date', '<', now()->toDateString())->count();

        return [
            'key' => 'development_plans',
            'label' => 'خطط التطوير',
            'icon' => 'bulb',
            'route' => '/development-plans',
            'metrics' => [
                ['label' => 'البنود', 'value' => (int) $byStatus->sum()],
                ['label' => 'منجَزة', 'value' => (int) ($byStatus['done'] ?? 0), 'tone' => 'ok'],
                ['label' => 'قيد التنفيذ', 'value' => (int) ($byStatus['in_progress'] ?? 0), 'tone' => 'info'],
                ['label' => 'متأخّرة', 'value' => $overdue, 'tone' => $overdue > 0 ? 'danger' : 'neutral'],
            ],
            'bars' => $this->bars([
                'pending' => 'لم تبدأ', 'in_progress' => 'قيد التنفيذ', 'done' => 'منجَزة',
            ], $byStatus),
        ];
    }

    // ── منظومة الكفاءات ──
    private function ovCompetencies(): array
    {
        $byType = Competency::selectRaw('type, count(*) c')->groupBy('type')->pluck('c', 'type');
        $linked = DB::table('activity_competency')->distinct('competency_id')->count('competency_id');

        return [
            'key' => 'competencies',
            'label' => 'منظومة الكفاءات',
            'icon' => 'competencyMap',
            'route' => '/competency-framework',
            'metrics' => [
                ['label' => 'الكفاءات', 'value' => (int) $byType->sum()],
                ['label' => 'مربوطة بالأنشطة', 'value' => $linked, 'tone' => 'ok'],
                ['label' => 'سلوكية', 'value' => (int) ($byType['behavioral'] ?? 0)],
                ['label' => 'فنّية', 'value' => (int) ($byType['technical'] ?? 0)],
            ],
            'bars' => $this->bars([
                'behavioral' => 'سلوكية', 'leadership' => 'قيادية', 'technical' => 'فنّية',
            ], $byType),
        ];
    }

    // ── طلبات تحديث البيانات ──
    private function ovUpdateRequests(array $allowed): array
    {
        $reqs = fn () => CandidateUpdateRequest::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
        $byStatus = (clone $reqs())->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $pending = (int) ($byStatus['pending'] ?? 0);

        return [
            'key' => 'update_requests',
            'label' => 'طلبات تحديث البيانات',
            'icon' => 'undo',
            'route' => '/update-requests',
            'metrics' => [
                ['label' => 'الإجمالي', 'value' => (int) $byStatus->sum()],
                ['label' => 'معلّقة', 'value' => $pending, 'tone' => $pending > 0 ? 'warn' : 'neutral'],
                ['label' => 'معتمدة', 'value' => (int) ($byStatus['approved'] ?? 0), 'tone' => 'ok'],
                ['label' => 'مرفوضة', 'value' => (int) ($byStatus['rejected'] ?? 0)],
            ],
            'bars' => $this->bars([
                'pending' => 'معلّقة', 'approved' => 'معتمدة', 'rejected' => 'مرفوضة',
            ], $byStatus),
        ];
    }

    // ── المستخدمون والأدوار ──
    // عدّاداتٌ للاطّلاع لا بابٌ للإدارة: القيادة التنفيذية ترى حجم الفريق
    // وتوزّعه على الأدوار، وإدارةُ الحسابات تبقى بيد مدير النظام.
    private function ovPeople(): array
    {
        $active = User::where('is_active', true)->count();
        $inactive = User::where('is_active', false)->count();
        $byRole = User::join('roles', 'roles.id', '=', 'users.role_id')
            ->selectRaw('roles.name_ar name, count(*) c')
            ->groupBy('roles.name_ar')->orderByDesc('c')->pluck('c', 'name');

        return [
            'key' => 'people',
            'label' => 'الفريق والأدوار',
            'icon' => 'users',
            'route' => null,
            'metrics' => [
                ['label' => 'المستخدمون', 'value' => $active + $inactive],
                ['label' => 'نشط', 'value' => $active, 'tone' => 'ok'],
                ['label' => 'معطّل', 'value' => $inactive, 'tone' => $inactive > 0 ? 'warn' : 'neutral'],
                ['label' => 'الأدوار', 'value' => Role::count()],
            ],
            'bars' => $byRole->take(6)->map(fn ($c, $name) => ['label' => $name, 'value' => (int) $c])->values()->all(),
        ];
    }

    // ── سجل التدقيق ──
    private function ovAudit(): array
    {
        $since = fn ($days) => AuditLog::where('created_at', '>=', now()->subDays($days))->count();
        $top = AuditLog::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('action, count(*) c')->groupBy('action')->orderByDesc('c')->limit(5)->pluck('c', 'action');

        return [
            'key' => 'audit',
            'label' => 'سجل التدقيق',
            'icon' => 'audit',
            'route' => '/audit',
            'metrics' => [
                ['label' => 'عمليات اليوم', 'value' => AuditLog::whereDate('created_at', now()->toDateString())->count(), 'tone' => 'info'],
                ['label' => 'آخر ٧ أيام', 'value' => $since(7)],
                ['label' => 'آخر ٣٠ يوماً', 'value' => $since(30)],
                ['label' => 'الإجمالي', 'value' => AuditLog::count()],
            ],
            'bars' => $top->map(fn ($c, $action) => ['label' => $action, 'value' => (int) $c])->values()->all(),
        ];
    }

    // ════════════════════════════════════════════════════════
    //  لوحة التقارير التنفيذية — حالة السلسلة لا تحرير التقارير
    // ════════════════════════════════════════════════════════
    //
    // تعمل بالرمز لا بالاسم: شاشة اطّلاعٍ لا تحتاج هوية المشارك، وحجبُ الاسم
    // هنا لا يُنقص القرار التنفيذي شيئاً (الاسم في التقرير نفسه لحامل صلاحيته).
    public const REPORT_STATUS_LABEL = [
        'draft' => 'مسودّة',
        'pending_evaluator' => 'بانتظار اعتماد المقيّم',
        'pending_manager' => 'بانتظار مدير التقييم',
        'pending_dev_approval' => 'بانتظار الاعتماد النهائي',
        'returned' => 'مُعاد للتعديل',
        'approved' => 'معتمد',
        'cancelled' => 'ملغى',
    ];

    public function reportsBoard(array $allowed, int $limit = 25): array
    {
        $limit = max(5, min(100, $limit));
        $scoped = fn () => FinalReport::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));

        $byStatus = $this->reportStatusCounts($allowed);
        $approved = (clone $scoped())->where('status', 'approved');

        $chain = ['pending_evaluator', 'pending_manager', 'pending_dev_approval'];
        $inChain = collect($chain)->sum(fn ($s) => (int) ($byStatus[$s] ?? 0));

        // عمر أقدم تقرير في كل مرحلة — الرقم الذي يقول أين يقف الخطّ فعلاً
        $aging = [];
        foreach (array_merge($chain, ['returned']) as $status) {
            $count = (int) ($byStatus[$status] ?? 0);
            if ($count === 0) {
                continue;
            }
            $oldest = (clone $scoped())->where('status', $status)->min('updated_at');
            $aging[] = [
                'status' => $status,
                'label' => self::REPORT_STATUS_LABEL[$status] ?? $status,
                'count' => $count,
                // عددٌ صحيح موجب: diffInDays في Carbon 3 يعيد عشرياً بإشارة،
                // فتقرأ الشاشة «‎-10.000008 يوماً» بدل «١٠ أيام»
                'oldestDays' => $oldest ? (int) Carbon::parse($oldest)->diffInDays(now(), true) : null,
            ];
        }
        usort($aging, fn ($a, $b) => ($b['oldestDays'] ?? -1) <=> ($a['oldestDays'] ?? -1));

        $byRecommendation = (clone $scoped())->whereNotNull('recommendation')
            ->selectRaw('recommendation, count(*) c')->groupBy('recommendation')
            ->orderByDesc('c')->get()
            ->map(fn ($r) => ['label' => $r->recommendation, 'value' => (int) $r->c])->all();

        $recent = (clone $scoped())->with(['candidate.sector', 'assessment'])
            ->orderByDesc('updated_at')->limit($limit)->get()
            ->map(function ($r) {
                $behavioral = $r->behavioral_fit;
                $technical = $r->technical_fit;
                $readiness = ($behavioral === null && $technical === null)
                    ? null
                    : round(((float) ($behavioral ?? 0) + (float) ($technical ?? 0)) / 2, 1);

                return [
                    'id' => $r->id,
                    'code' => $r->assessment?->participant_code ?? $r->candidate?->participant_code ?? '—',
                    'sector' => $r->candidate?->sector?->name_ar ?? '—',
                    'tier' => $r->candidate?->tier === 'upper' ? 'قيادة عليا' : ($r->candidate?->tier === 'middle' ? 'قيادة وسطى' : '—'),
                    'behavioral' => $behavioral === null ? null : round((float) $behavioral, 1),
                    'technical' => $technical === null ? null : round((float) $technical, 1),
                    'readiness' => $readiness,
                    'recommendation' => $r->recommendation ?: '—',
                    'status' => $r->status,
                    'statusLabel' => self::REPORT_STATUS_LABEL[$r->status] ?? $r->status,
                    'returnCount' => (int) $r->return_count,
                    'hasExecSummary' => filled($r->executive_summary),
                    'updatedAt' => $r->updated_at?->toIso8601String(),
                ];
            })->all();

        return [
            'kpis' => [
                'total' => (int) $byStatus->sum(),
                'approved' => (int) ($byStatus['approved'] ?? 0),
                'inChain' => $inChain,
                'returned' => (int) ($byStatus['returned'] ?? 0),
                'avgReadiness' => $this->avgReadiness(clone $approved),
                // الملخّص التنفيذي يكتبه مدير المركز — تغطيتُه مؤشّرُ عملِه هو
                'execSummaries' => (clone $approved)->whereNotNull('executive_summary')->count(),
            ],
            'pipeline' => collect(self::REPORT_STATUS_LABEL)
                ->map(fn ($label, $status) => [
                    'status' => $status,
                    'label' => $label,
                    'count' => (int) ($byStatus[$status] ?? 0),
                ])->values()->all(),
            'aging' => $aging,
            'byRecommendation' => $byRecommendation,
            'recent' => $recent,
        ];
    }

    // ─────────────── مساعدات ───────────────

    // عدّاد التقارير بالحالة ضمن التصنيفات المسموحة — يُقرأ في موضعين
    private function reportStatusCounts(array $allowed)
    {
        return FinalReport::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
    }

    // خريطة تسميات + عدّادات ← أشرطةُ عرض. الصفر يبقى ظاهراً: «لا شيء في هذه
    // الحالة» خبرٌ أيضاً، وحذفُه يجعل الشريط يبدو مكتملاً وهو ناقص.
    private function bars(array $labels, $counts): array
    {
        $out = [];
        foreach ($labels as $key => $label) {
            $out[] = ['label' => $label, 'value' => (int) ($counts[$key] ?? 0)];
        }
        return $out;
    }

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
