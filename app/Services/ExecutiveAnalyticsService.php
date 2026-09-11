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
use App\Models\GateManifest;
use App\Models\MeasurementResult;
use App\Models\PostponementRequest;
use App\Models\ReceptionAssignment;
use App\Models\ReceptionVisit;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleDispatch;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  تحليلات تنفيذية مجمّعة لشاشة القيادة التنفيذية للمركز.
//  استعلامات مُجمّعة (group by) لا حلقات — أداءٌ ثابت مع نموّ البيانات.
//  كل الدوال تستقبل قائمة التصنيفات المسموحة (fail-closed) وتحصر عليها.
// ════════════════════════════════════════════════════════════

class ExecutiveAnalyticsService
{
    // مراحل سلسلة اعتماد التقرير بترتيبها، ومنها مرحلة مدير المركز. كانت ثلاثاً
    // هنا، فالتقرير الواقف عند مدير المركز يُحسب في «الإجمالي» ويختفي من خطّ
    // الاعتماد وأطول انتظار و«في سلسلة الاعتماد».
    private const REPORT_CHAIN = ['pending_evaluator', 'pending_manager', 'pending_dev_approval', 'pending_center'];

    // الجاهزية من الدرجة الموجودة لا بصفرٍ مكان الناقصة: تقريرٌ سلوكيّه ٨٠ وفنّيه
    // لم يُرصد كان يدخل المتوسط بـ٤٠. وحين تغيب الدرجتان معاً يُستبعد التقرير.
    private const READINESS_SQL = '(coalesce(behavioral_fit, technical_fit) + coalesce(technical_fit, behavioral_fit)) / 2';

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
        $r = self::READINESS_SQL;
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
            if (! $comp) {
                continue;
            }
            $max = (int) ($comp->max_level ?: 5);
            $pct = $max > 0 ? min(100.0, round((float) $r->avg_score / $max * 100, 1)) : 0.0;
            $cells[$r->competency_id.'-'.$r->sector_id] = ['pct' => $pct, 'samples' => (int) $r->n];
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
                         avg(".self::READINESS_SQL.') readiness')
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
                    : 'انخفضت جاهزية التقارير المعتمدة بمقدار '.abs($diff).' نقطة عن الشهر السابق.',
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

    // ── المشاركون ──
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
            'label' => 'المشاركون',
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
                // اعتماد الموجة لمسؤول الجدولة (schedule.approve) منذ فُصل من يبني عمّن
                // يعتمد. كانت تُسمّى «بانتظار اعتمادك» فتَعِد مدير المركز بقرارٍ ليس له.
                ['label' => 'بانتظار اعتماد مسؤول الجدولة', 'value' => $pending, 'tone' => $pending > 0 ? 'info' : 'neutral'],
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

        // إسنادٌ لم يبتّ فيه المقيّم بعد — المشارك واقفٌ في البهو حتى يُقبل أو يُردّ
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
        $chain = self::REPORT_CHAIN;
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
        // بصاحب المرحلة لا بموقعها: «النهائي» لم يعد تطوير الكفاءات حين تُفعَّل مرحلة المركز
        'pending_dev_approval' => 'بانتظار تطوير الكفاءات',
        'pending_center' => 'بانتظار مدير المركز',
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

        $chain = self::REPORT_CHAIN;
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

        // معدّل الإعادة: من التقارير التي دخلت السلسلة، كم أُعيد مرّةً على الأقل.
        // مؤشّرُ جودة الكتابة لا بطء الاعتماد — يفرّق بين مشكلة الكاتب والمعتمِد.
        $entered = (clone $scoped())->whereNotIn('status', ['draft', 'cancelled']);
        $reworkBase = (clone $entered)->count();
        $reworked = (clone $entered)->where('return_count', '>', 0)->count();

        $recent = (clone $scoped())->with(['candidate.sector', 'assessment'])
            ->orderByDesc('updated_at')->limit($limit)->get()
            ->map(function ($r) {
                $behavioral = $r->behavioral_fit;
                $technical = $r->technical_fit;
                // كـREADINESS_SQL: الناقصة تأخذ قيمة الموجودة لا الصفر
                $readiness = ($behavioral === null && $technical === null)
                    ? null
                    : round(((float) ($behavioral ?? $technical) + (float) ($technical ?? $behavioral)) / 2, 1);

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
                'reworked' => $reworked,
                'reworkBase' => $reworkBase,
                'reworkRate' => $reworkBase > 0 ? round($reworked / $reworkBase * 100, 1) : null,
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
            'pendingExecSummary' => $this->pendingExecSummary($allowed),
            'cycleTime' => $this->cycleTime($allowed),
        ];
    }

    // المعتمَد بلا ملخّص تنفيذي، الأقدم أولاً — قائمةٌ يُعمل عليها لا عدّادٌ في حاشية
    private function pendingExecSummary(array $allowed, int $limit = 10): array
    {
        $q = $this->inScope(FinalReport::where('status', 'approved')->whereNull('executive_summary'), $allowed);

        return [
            'count' => (clone $q)->count(),
            'rows' => (clone $q)->with(['candidate.sector', 'assessment'])->orderBy('updated_at')->limit($limit)->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'code' => $r->assessment?->participant_code ?? $r->candidate?->participant_code ?? '—',
                    'sector' => $r->candidate?->sector?->name_ar ?? '—',
                    'recommendation' => $r->recommendation ?: '—',
                    'days' => $this->ageDays($r->updated_at),
                ])->all(),
        ];
    }

    // ── زمن الدورة: من الترشيح إلى الاعتماد النهائي، وأين يذهب الوقت ──
    //
    // تقريبيٌّ بصدق: لا سجلّ انتقالاتٍ صريحاً على التقرير. الترشيح تاريخُ إنشاء
    // الدورة، وأوّل جلسة من assessments.first_session_date، والاعتماد النهائي آخرُ
    // اعتمادٍ انتقل إلى «معتمد» في سجل التدقيق (وإلا updated_at).
    // الوسيط لا المتوسط: تقريرٌ منسيٌّ شهراً لا يسحب رقمَ تسعةٍ مرّت في أيام.
    private function cycleTime(array $allowed): array
    {
        $since = now()->subMonths(12);
        $rows = DB::table('final_reports as fr')
            ->join('candidates as c', 'fr.candidate_id', '=', 'c.id')
            ->leftJoin('assessments as a', 'fr.assessment_id', '=', 'a.id')
            ->where('fr.status', 'approved')
            ->whereIn('c.classification', $allowed)
            ->selectRaw("a.created_at as nominated_at, a.first_session_date, fr.created_at as report_at,
                coalesce((select max(al.created_at) from audit_logs al
                    where al.entity_type = 'report' and al.entity_id = fr.id::text
                      and al.action in ('APPROVE_REPORT', 'APPROVE_REPORT_SKIPPED_EVALUATOR')
                      and al.details->>'to' = 'approved'), fr.updated_at) as approved_at")
            ->get()
            ->filter(fn ($r) => $r->approved_at && Carbon::parse($r->approved_at)->gte($since));

        $span = function ($from, $to): ?int {
            if (! $from || ! $to) {
                return null;
            }
            $d = Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay(), false);

            // تاريخان مقلوبان خطأُ إدخالٍ لا زمنٌ سالب — يُستبعد من المقياس
            return $d < 0 ? null : (int) $d;
        };

        $hops = [
            'to_session' => ['label' => 'من الترشيح إلى أوّل جلسة', 'from' => 'nominated_at', 'to' => 'first_session_date'],
            'to_report' => ['label' => 'من أوّل جلسة إلى كتابة التقرير', 'from' => 'first_session_date', 'to' => 'report_at'],
            'to_approval' => ['label' => 'من كتابة التقرير إلى الاعتماد النهائي', 'from' => 'report_at', 'to' => 'approved_at'],
        ];

        $out = [];
        foreach ($hops as $key => $h) {
            $values = $rows->map(fn ($r) => $span($r->{$h['from']}, $r->{$h['to']}))
                ->filter(fn ($v) => $v !== null)->values()->all();
            $out[] = ['key' => $key, 'label' => $h['label']] + $this->spread($values);
        }

        $total = $rows->map(fn ($r) => $span($r->nominated_at, $r->approved_at))
            ->filter(fn ($v) => $v !== null)->values()->all();

        return [
            'windowMonths' => 12,
            'hops' => $out,
            'total' => $this->spread($total),
        ];
    }

    // وسيطٌ ومئينٌ تسعون وعدد — على قيمٍ بالأيام
    private function spread(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['medianDays' => null, 'p90Days' => null, 'n' => 0];
        }
        sort($values);
        $median = $n % 2 ? $values[intdiv($n, 2)] : ($values[intdiv($n, 2) - 1] + $values[intdiv($n, 2)]) / 2;

        return [
            'medianDays' => round($median, 1),
            'p90Days' => $values[(int) ceil(0.9 * $n) - 1],
            'n' => $n,
        ];
    }

    // ════════════════════════════════════════════════════════
    //  بانتظار قرارك — ما يبتّ فيه القارئ، وما توقّف عند غيره، ويومُ المركز
    // ════════════════════════════════════════════════════════
    //
    // النظرة الشاملة عدّاداتٌ لا يقول أيٌّ منها «هذا أنت من يبتّ فيه». هنا يُفصل
    // ما يوقّعه القارئ (الطابور) عمّا وقف عند غيره ولا يرفعه إليه أحد (المتوقّف)،
    // والعمرُ هو الخبر لا العدد: طلبان من أمس ليسا كطلبين من أسبوعين.
    //
    // صفوف الطابور تُبنى بصلاحية البتّ لا بالدور: من سُحبت منه صلاحيةٌ في شاشة
    // الأدوار يختفي صفُّها، فلا يُعرض عليه قرارٌ سيرفضه الخادم.
    public function decisionDesk(array $allowed, User $user): array
    {
        $today = now()->toDateString();

        return [
            'date' => $today,
            'today' => $this->centerToday($allowed, $today),
            'queue' => $this->decisionQueue($allowed, $user, $today),
            'blockers' => $this->blockers($allowed, $today),
        ];
    }

    // ── اليوم في المركز: الجلسات وحضورها، والبهو، وبيان البوّابة ──
    private function centerToday(array $allowed, string $today): array
    {
        $sessionIds = $this->inScope(Schedule::whereDate('schedule_date', $today), $allowed)->pluck('id');
        $att = Attendance::whereIn('schedule_id', $sessionIds)
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $present = (int) ($att['present'] ?? 0);
        $absent = (int) ($att['absent_excused'] ?? 0) + (int) ($att['absent_unexcused'] ?? 0);

        $visits = $this->inScope(ReceptionVisit::whereDate('visit_date', $today), $allowed)
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        $manifest = GateManifest::whereDate('manifest_date', $today)->withCount('candidates')->first();

        return [
            'sessions' => $sessionIds->count(),
            'present' => $present,
            'absent' => $absent,
            // جلسةٌ لم يُرصد حضورها بعد ليست غياباً
            'notRecorded' => max(0, $sessionIds->count() - $present - $absent),
            'visits' => (int) $visits->sum(),
            'arrived' => (int) ($visits[ReceptionVisit::ARRIVED] ?? 0),
            'distributed' => (int) ($visits[ReceptionVisit::DISTRIBUTED] ?? 0),
            'approvedVisits' => (int) ($visits[ReceptionVisit::APPROVED] ?? 0),
            'waitingOnEvaluator' => $this->waitingOnEvaluator($allowed, $today)->count(),
            'gateManifest' => $manifest ? [
                'status' => $manifest->status,
                'label' => GateManifest::statusLabel($manifest->status),
                'names' => (int) $manifest->candidates_count,
            ] : null,
        ];
    }

    // ── الطابور: ما يحمل توقيع القارئ ──
    private function decisionQueue(array $allowed, User $user, string $today): array
    {
        $rows = [];

        if ($user->hasPermission(Permissions::GATE_MANIFEST_APPROVE)) {
            $pending = GateManifest::where('status', GateManifest::PENDING);
            $next = (clone $pending)->orderBy('manifest_date')->withCount('candidates')->first();
            $rows[] = $this->deskRow('gate_manifest', 'بيان تصاريح دخول بانتظار اعتمادك', $pending, 'updated_at',
                '/gate-manifest', null, Permissions::GATE_MANIFEST_APPROVE, [
                    'hint' => $next ? 'أقربها ليوم '.$next->manifest_date->toDateString().' — الأسماء: '.$next->candidates_count : null,
                ]);
        }

        if ($user->hasPermission(Permissions::REPORT_APPROVE_CENTER)) {
            $rows[] = $this->deskRow('reports_center', 'تقارير بانتظار اعتمادك النهائي',
                $this->inScope(FinalReport::where('status', 'pending_center'), $allowed), 'updated_at',
                '/reports', ['status' => 'pending_center'], Permissions::REPORT_VIEW);
        }

        if ($user->hasPermission(Permissions::REPORT_EXEC_SUMMARY)) {
            // آخر ما يُكتب في التقرير قبل أن يخرج من المركز — ويكتبه القارئ نفسه
            $rows[] = $this->deskRow('exec_summary', 'تقارير معتمدة بلا ملخّص تنفيذي',
                $this->inScope(FinalReport::where('status', 'approved')->whereNull('executive_summary'), $allowed),
                'updated_at', '/reports', ['status' => 'approved'], Permissions::REPORT_VIEW);
        }

        if ($user->hasPermission(Permissions::CANDIDATE_APPROVE)) {
            $rows[] = $this->deskRow('candidates_draft', 'مشاركون بمسودّة بانتظار الاعتماد',
                Candidate::where('status', 'draft')->whereIn('classification', $allowed), 'created_at',
                '/candidates', ['status' => 'draft'], Permissions::CANDIDATE_VIEW);
        }

        if ($user->hasPermission(Permissions::RECEPTION_APPROVE)) {
            // جاهزةٌ للاعتماد بشروط الخادم نفسها: موقَّعة ومُقَرّة، وسيرتها معتمدة،
            // وفيها إسنادٌ مستلَم — لا كلُّ زيارةٍ لم تُعتمد بعد
            $ready = $this->inScope(ReceptionVisit::whereDate('visit_date', $today)
                ->where('status', '!=', ReceptionVisit::APPROVED)
                ->whereNotNull('signature_enc')->where('attested', true)
                ->whereNotNull('cv_approved_at')
                ->whereHas('assignments', fn ($a) => $a->where('status', ReceptionAssignment::ACCEPTED)), $allowed);
            $rows[] = $this->deskRow('reception_ready', 'زيارات اليوم جاهزة للاعتماد والترحيل', $ready, 'updated_at',
                '/reception', null, Permissions::RECEPTION_VIEW);
        }

        if ($user->hasPermission(Permissions::EVALUATION_APPROVE)) {
            $rows[] = $this->deskRow('evaluations_submitted', 'تقييمات مُرسلة بانتظار الاعتماد',
                $this->inScope(Evaluation::where('status', 'submitted'), $allowed), DB::raw('coalesce(submitted_at, updated_at)'),
                '/assessment', null, Permissions::EVALUATION_VIEW);
        }

        return $rows;
    }

    // ── المتوقّف: ليس قرار القارئ، لكنه وقف ولا أحد يرفعه إليه ──
    private function blockers(array $allowed, string $today): array
    {
        $beforeCenter = array_values(array_diff(self::REPORT_CHAIN, ['pending_center']));

        return [
            // حالة الموجة لا مرحلة التقرير — مفرداتٌ أخرى بالاسم نفسه
            $this->deskRow('waves_pending', 'موجات جدولة مُرسلة لم تُعتمد',
                SchedulingPeriod::where('status', 'pending_center'), DB::raw('coalesce(submitted_at, updated_at)'),
                '/scheduling-periods', null, Permissions::SCHEDULE_VIEW, ['owner' => 'مسؤول الجدولة']),
            $this->deskRow('postponements', 'طلبات تأجيل لم يُبتّ فيها',
                $this->inScope(PostponementRequest::where('status', PostponementRequest::PENDING), $allowed), 'created_at',
                '/absentees', null, Permissions::SCHEDULE_VIEW, ['owner' => 'مسؤول الجدولة']),
            $this->waitingRow($allowed, $today),
            $this->deskRow('returned_reports', 'تقارير مُعادة للتعديل لم تُرسل بعد',
                $this->inScope(FinalReport::where('status', 'returned'), $allowed), DB::raw('coalesce(last_returned_at, updated_at)'),
                '/reports', ['status' => 'returned'], Permissions::REPORT_VIEW, ['owner' => 'كاتب التقرير']),
            $this->deskRow('stalled_reports', 'تقارير متوقّفة في سلسلة الاعتماد أكثر من أسبوع',
                $this->inScope(FinalReport::whereIn('status', $beforeCenter)->where('updated_at', '<', now()->subDays(7)), $allowed),
                'updated_at', '/reports', ['status' => 'pending'], Permissions::REPORT_VIEW, ['owner' => 'أصحاب مراحل الاعتماد']),
            $this->deskRow('stale_evaluation_drafts', 'مسودّات تقييم لم تُرسل منذ أكثر من ثلاثة أيام',
                $this->inScope(Evaluation::where('status', 'draft')->where('updated_at', '<', now()->subDays(3)), $allowed),
                'updated_at', '/assessment', null, Permissions::EVALUATION_VIEW, ['owner' => 'المستشارون']),
            $this->deskRow('overdue_plans', 'بنود خطط تطوير تجاوزت موعدها',
                $this->inScope(DevelopmentPlanItem::where('status', '!=', 'done')->whereNotNull('target_date')
                    ->whereDate('target_date', '<', $today), $allowed),
                'target_date', '/development-plans', null, Permissions::DEVELOPMENT_PLAN_VIEW, ['owner' => 'إدارة تطوير الكفاءات']),
        ];
    }

    // إسنادٌ لم يبتّ فيه المستشار لزيارة اليوم — مشاركٌ واقفٌ في البهو
    private function waitingOnEvaluator(array $allowed, string $today)
    {
        return ReceptionAssignment::where('status', ReceptionAssignment::PENDING)
            ->whereHas('visit', fn ($q) => $q->whereDate('visit_date', $today)
                ->whereHas('candidate', fn ($c) => $c->whereIn('classification', $allowed)));
    }

    private function waitingRow(array $allowed, string $today): array
    {
        $q = $this->waitingOnEvaluator($allowed, $today);
        $oldest = (clone $q)->min('reception_assignments.created_at');

        return [
            'key' => 'waiting_evaluator',
            'label' => 'مشاركون في البهو بانتظار قرار المستشار',
            'count' => (clone $q)->count(),
            'oldestDays' => null,
            // البهو يُقاس بالدقائق لا بالأيام
            'oldestMinutes' => $oldest ? (int) Carbon::parse($oldest)->diffInMinutes(now(), true) : null,
            'route' => '/reception',
            'query' => null,
            'linkPerm' => Permissions::RECEPTION_VIEW,
            'owner' => 'المستشار المُسنَد',
        ];
    }

    // ─────────────── مساعدات ───────────────

    // عمرٌ بالأيام الصحيحة — diffInDays في Carbon 3 عشريٌّ بإشارة
    private function ageDays($ts): ?int
    {
        return $ts ? (int) Carbon::parse($ts)->diffInDays(now(), true) : null;
    }

    // حصرُ صفوفٍ مربوطة بمشارك على التصنيفات المسموحة (fail-closed)
    private function inScope($query, array $allowed)
    {
        return $query->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
    }

    // صفٌّ موحّد في «بانتظار قرارك»: العدد، وعمر أقدمه، ووجهة فتحه وصلاحيتها
    private function deskRow(string $key, string $label, $query, $ageColumn, ?string $route, ?array $routeQuery, ?string $linkPerm, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'count' => (clone $query)->count(),
            'oldestDays' => $this->ageDays((clone $query)->min($ageColumn)),
            'route' => $route,
            'query' => $routeQuery,
            'linkPerm' => $linkPerm,
        ], $extra);
    }

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

    // متوسط الجاهزية = متوسط (السلوكي + الفنّي) / ٢ على استعلام تقارير معتمدة،
    // والدرجة الناقصة تأخذ قيمة الموجودة (READINESS_SQL) لا الصفر
    private function avgReadiness($approvedQuery): ?float
    {
        $v = $approvedQuery->selectRaw('avg('.self::READINESS_SQL.') r')->value('r');

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
            if (! $comp) {
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
            'pending_center' => 'اعتماد مدير المركز',
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
