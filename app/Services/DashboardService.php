<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Competency;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  لوحة البداية — حمولة واحدة لكل الأدوار.
//
//  الفرق الجوهري عن ExecutiveAnalyticsService: هذه الخدمة لا تبني استعلاماتها
//  بنفسها من الصفر، بل تستقبل «مغلّفات نطاق» (closures) جاهزة الحصر من المتحكّم
//  عبر حرّاس Controller المشتركة (scopeCandidateQuery / scopeViaCandidate / scopeReports).
//  فما تعدّه اللوحة لا يتجاوز أبداً ما تعرضه شاشة القائمة لنفس المستخدم:
//  حدّ القطاع وحدّ التصنيف مفروضان في كل استعلام بلا استثناء.
//
//  استعلامات مجمّعة (group by) لا حلقات — الكلفة ثابتة مع نموّ البيانات.
// ════════════════════════════════════════════════════════════

class DashboardService
{
    // أسماء الأشهر الميلادية بالعربية (مفتاحها رقم الشهر ١..١٢)
    private const MONTHS_AR = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];

    // ترتيب أيام الأسبوع كما يرجعها postgres: extract(dow) → 0 = الأحد
    private const DAYS_AR = [
        0 => 'أحد', 1 => 'اثنين', 2 => 'ثلاثاء', 3 => 'أربعاء',
        4 => 'خميس', 5 => 'جمعة', 6 => 'سبت',
    ];

    private const ACTIVITY_AR = [
        'interview' => 'مقابلة شخصية',
        'discussion' => 'حلقة نقاش',
        'measurement' => 'أدوات القياس',
        'integration' => 'جلسة تكاملية',
    ];

    private const TIER_AR = [
        'upper' => 'القيادة العليا',
        'middle' => 'القيادة الوسطى',
    ];

    // نغمات بطاقات جدول اليوم — تدور بالترتيب
    private const TONES = ['accent', 'info', 'purple', 'warn'];

    private const ABSENT_STATUSES = ['absent_excused', 'absent_unexcused'];
    private const COUNTED_EVAL_STATUSES = ['submitted', 'approved'];

    public function __construct(private ExecutiveAnalyticsService $executive)
    {
    }

    /**
     * الحمولة الكاملة.
     *
     * @param array $scope مغلّفات النطاق: candidates/reports/evaluations/schedules
     *                     (كل واحدة closure ترجع استعلاماً جديداً محصوراً)،
     *                     + classifications و sectorId و sectorBound.
     * @param array $can   الصلاحيات المحسوبة مسبقاً: candidate/attendance/evaluation/report/analytics/schedule
     */
    public function overview(array $scope, array $can): array
    {
        $now = now();

        return [
            'generatedAt' => $now->toIso8601String(),
            'kpis' => $this->kpis($scope, $can, $now),
            'readiness' => $can['analytics'] ? $this->readiness($scope, $now) : null,
            'trend' => $can['analytics'] ? ['months' => $this->trend($scope, $now)] : null,
            'attendanceToday' => $can['attendance'] ? $this->attendanceToday($scope, $now) : null,
            'weekHeatmap' => $can['analytics'] ? $this->weekHeatmap($scope) : null,
            'todaySchedule' => $can['schedule'] ? $this->todaySchedule($scope, $now) : null,
            'insights' => $can['analytics'] ? $this->insights($scope) : null,
        ];
    }

    // ═══════════════ المؤشرات الرئيسية ═══════════════
    // كل مؤشّر null إن لم يملك صاحبه صلاحيته — والغلاف نفسه حاضر دائماً،
    // فاللوحة صفحة الهبوط لكل دور ولا ترجع 403 ككل.
    private function kpis(array $scope, array $can, $now): array
    {
        $out = [
            'candidates' => null, 'attendance' => null, 'completion' => null,
            'evaluations' => null, 'approvals' => null, 'pending' => null,
        ];

        $p1 = $now->copy()->subDays(30);   // الفترة الحالية: آخر ٣٠ يوماً
        $p2 = $now->copy()->subDays(60);   // الفترة السابقة: ٣٠ يوماً قبلها

        // ── المرشحون + نسبة الإتمام (candidate.view) ──
        if ($can['candidate']) {
            $cand = $scope['candidates'];

            $total = $cand()->count();
            $out['candidates'] = [
                'value' => $total,
                'unit' => null,
                'delta' => $this->delta(
                    $cand()->where('created_at', '>=', $p1)->count(),
                    $cand()->whereBetween('created_at', [$p2, $p1])->count()
                ),
            ];

            $completed = $cand()->where('status', 'completed')->count();
            // بلا مرشحين أصلاً النسبة مجهولة لا صفر
            $rate = $total > 0 ? (int) round($completed / $total * 100) : null;

            $currTotal = $cand()->where('created_at', '>=', $p1)->count();
            $prevTotal = $cand()->whereBetween('created_at', [$p2, $p1])->count();
            $completionDelta = null;
            if ($currTotal > 0 && $prevTotal > 0) {
                $currDone = $cand()->where('created_at', '>=', $p1)->where('status', 'completed')->count();
                $prevDone = $cand()->whereBetween('created_at', [$p2, $p1])->where('status', 'completed')->count();
                $completionDelta = $this->delta(
                    (int) round($currDone / $currTotal * 100),
                    (int) round($prevDone / $prevTotal * 100)
                );
            }

            $out['completion'] = ['value' => $rate, 'unit' => '%', 'delta' => $completionDelta];
        }

        // ── نسبة الحضور اليوم مقابل الأيام السبعة السابقة (attendance.view) ──
        if ($can['attendance']) {
            $today = $now->toDateString();
            $todayAgg = $this->attendanceAgg(
                $scope['schedules']()->whereDate('schedule_date', $today)
            );
            // يومٌ بلا جلسات نسبتُه مجهولة لا صفر — كالإكمال بلا مرشحين أعلاه.
            // الصفر هنا كان يُقرأ «لم يحضر أحد»، فيصير كلُّ يوم عطلة إنذارَ
            // انهيارِ حضورٍ أحمر (٠٪ مقابل ٩٢٪ للأسبوع = ▼١٠٠٪) بلا واقعة.
            $todayRate = $todayAgg['total'] > 0
                ? (int) round($todayAgg['present'] / $todayAgg['total'] * 100)
                : null;

            $weekAgg = $this->attendanceAgg(
                $scope['schedules']()
                    ->whereDate('schedule_date', '>=', $now->copy()->subDays(7)->toDateString())
                    ->whereDate('schedule_date', '<', $today)
            );
            // لا مقارنةَ إلا بطرفين معلومين: نسبةُ اليوم وأسبوعٌ سابق بجلسات
            $attendanceDelta = ($todayRate !== null && $weekAgg['total'] > 0)
                ? $this->delta($todayRate, (int) round($weekAgg['present'] / $weekAgg['total'] * 100))
                : null;

            $out['attendance'] = ['value' => $todayRate, 'unit' => '%', 'delta' => $attendanceDelta];
        }

        // ── التقييمات المُسلَّمة/المعتمدة (evaluation.view) ──
        if ($can['evaluation']) {
            $ev = fn () => $scope['evaluations']()->whereIn('status', self::COUNTED_EVAL_STATUSES);
            $out['evaluations'] = [
                'value' => $ev()->count(),
                'unit' => null,
                'delta' => $this->delta(
                    $ev()->where('submitted_at', '>=', $p1)->count(),
                    $ev()->whereBetween('submitted_at', [$p2, $p1])->count()
                ),
            ];
        }

        // ── التقارير: المعتمدة والمعلّقة (report.view) ──
        if ($can['report']) {
            $rep = $scope['reports'];

            $approved = fn () => $rep()->where('status', 'approved');
            $out['approvals'] = [
                'value' => $approved()->count(),
                'unit' => null,
                'delta' => $this->delta(
                    $approved()->where('updated_at', '>=', $p1)->count(),
                    $approved()->whereBetween('updated_at', [$p2, $p1])->count()
                ),
            ];

            // «معلّق» = السلسلة كاملة لا مرحلتها الأخيرة (يطابق ReportController::stats)
            $pendingStatuses = WorkflowStage::pendingStatuses();
            $pending = fn () => $rep()->whereIn('status', $pendingStatuses);
            $out['pending'] = [
                'value' => $pending()->count(),
                'unit' => null,
                // ارتفاع الطابور سيّئ لا حسن — الواجهة تلوّنه تحذيراً
                'delta' => $this->delta(
                    $pending()->where('updated_at', '>=', $p1)->count(),
                    $pending()->whereBetween('updated_at', [$p2, $p1])->count(),
                    0,
                    true
                ),
            ];
        }

        return $out;
    }

    // ═══════════════ الجاهزية: الربع الحالي مقابل السابق (بالنقاط) ═══════════════
    private function readiness(array $scope, $now): array
    {
        $approved = fn () => $scope['reports']()->where('status', 'approved');

        $qStart = $now->copy()->startOfQuarter();
        $prevStart = $now->copy()->startOfQuarter()->subMonths(3);

        $curr = $this->avgReadiness($approved()->where('updated_at', '>=', $qStart));
        $prev = $this->avgReadiness($approved()->whereBetween('updated_at', [$prevStart, $qStart]));

        return [
            'value' => $this->avgReadiness($approved()),
            // فرقٌ بالنقاط المئوية لا نسبة تغيّر — ولا يُحسب بلا ربعٍ سابقٍ مقارَن
            'deltaPoints' => ($curr !== null && $prev !== null) ? round($curr - $prev, 1) : null,
        ];
    }

    // ═══════════════ اتجاه ١٢ شهراً (الأقدم أولاً، الفجوات مملوءة بصفر) ═══════════════
    private function trend(array $scope, $now): array
    {
        $since = $now->copy()->startOfMonth()->subMonths(11);

        $evals = $scope['evaluations']()
            ->whereIn('status', self::COUNTED_EVAL_STATUSES)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $since)
            ->selectRaw("to_char(submitted_at, 'YYYY-MM') ym, count(*) c")
            ->groupBy('ym')->pluck('c', 'ym');

        $reports = $scope['reports']()
            ->where('status', 'approved')
            ->where('updated_at', '>=', $since)
            ->selectRaw("to_char(updated_at, 'YYYY-MM') ym, count(*) c")
            ->groupBy('ym')->pluck('c', 'ym');

        $out = [];
        for ($m = 0; $m < 12; $m++) {
            $month = $since->copy()->addMonths($m);
            $key = $month->format('Y-m');
            $out[] = [
                'month' => $key,
                'label' => self::MONTHS_AR[(int) $month->format('n')],
                'evaluations' => (int) ($evals[$key] ?? 0),
                'approvedReports' => (int) ($reports[$key] ?? 0),
            ];
        }

        return $out;
    }

    // ═══════════════ حضور اليوم (يطابق AttendanceController::stats) ═══════════════
    private function attendanceToday(array $scope, $now): array
    {
        $agg = $this->attendanceAgg(
            $scope['schedules']()->whereDate('schedule_date', $now->toDateString())
        );

        return [
            'total' => $agg['total'],
            'present' => $agg['present'],
            'absent' => $agg['absent'],
            'pending' => max(0, $agg['total'] - $agg['present'] - $agg['absent']),
        ];
    }

    // ═══════════════ خريطة الأسبوع: كفاءة × يوم (استعلام مجمّع واحد) ═══════════════
    private function weekHeatmap(array $scope): array
    {
        $days = [];
        foreach (self::DAYS_AR as $key => $label) {
            $days[] = ['key' => $key, 'label' => $label];
        }

        $rows = DB::table('evaluation_scores as es')
            ->join('evaluations as e', 'es.evaluation_id', '=', 'e.id')
            ->join('candidates as c', 'e.candidate_id', '=', 'c.id')
            // حصرٌ مزدوج مقصود: النطاق الجاهز من المتحكّم، وفوقه التصنيف/القطاع صراحةً
            // على جدول المرشحين — فلا يتسرّب صفٌّ لو تغيّرت دلالة المغلّف يوماً.
            ->whereIn('e.id', $scope['evaluations']()->select('id'))
            ->whereIn('c.classification', $scope['classifications'])
            ->when($scope['sectorId'] !== null, fn ($q) => $q->where('c.sector_id', $scope['sectorId']))
            ->whereIn('e.status', self::COUNTED_EVAL_STATUSES)
            ->whereNotNull('e.submitted_at')
            ->groupByRaw('es.competency_id, extract(dow from e.submitted_at)')
            ->selectRaw('es.competency_id, extract(dow from e.submitted_at) as dow,
                         avg(es.score) as avg_score, count(*) as n')
            ->get();

        $comps = Competency::orderBy('sort_order')->orderBy('id')->get();

        // خلايا مفهرسة: كفاءة → يوم
        $cells = [];
        foreach ($rows as $r) {
            $cells[(int) $r->competency_id][(int) $r->dow] = $r;
        }

        $out = [];
        foreach ($comps as $comp) {
            if (!isset($cells[$comp->id])) {
                continue;   // كفاءة بلا عيّنة لا صفّ لها
            }
            $max = (int) ($comp->max_level ?: 5);
            $row = ['competencyId' => $comp->id, 'competency' => $comp->name_ar, 'cells' => []];

            for ($d = 0; $d <= 6; $d++) {
                $cell = $cells[$comp->id][$d] ?? null;
                // خليّة بلا عيّنة = null لا صفر: «لا نعلم» ليست «صفر إتقان»
                $row['cells'][] = $cell === null ? null : [
                    'pct' => $max > 0 ? min(100.0, round((float) $cell->avg_score / $max * 100, 1)) : 0.0,
                    'samples' => (int) $cell->n,
                ];
            }

            $out[] = $row;
            if (count($out) === 6) {
                break;
            }
        }

        return ['days' => $days, 'rows' => $out];
    }

    // ═══════════════ جدول اليوم — بلا أي بيان يعرّف الشخص ═══════════════
    // العنوان نشاطٌ وفئةٌ قيادية فقط. الاسم يلزمه candidate.view_names،
    // وهذه البطاقة تُعرض لكل من يملك schedule.view — فلا اسم فيها أصلاً.
    private function todaySchedule(array $scope, $now): array
    {
        $today = $scope['schedules']()->whereDate('schedule_date', $now->toDateString());

        // العدّ قبل القصّ: البطاقة تعرض ستّاً، لكن «كم جلسة اليوم؟» سؤالٌ عن
        // اليوم لا عن البطاقة. بلا هذا العدد تقرأ الواجهةُ طولَ المصفوفة فتثبت
        // على ٦ مهما كثرت الجلسات — رقمٌ خاطئ يبدو صحيحاً.
        $total = (clone $today)->count();

        $rows = $today
            ->with('candidate:id,tier')
            ->orderBy('schedule_time')
            ->orderBy('id')
            ->limit(6)
            ->get();

        $out = [];
        foreach ($rows as $i => $s) {
            $activity = self::ACTIVITY_AR[$s->activity] ?? $s->activity;
            $tier = self::TIER_AR[optional($s->candidate)->tier] ?? null;

            $out[] = [
                'scheduleId' => $s->id,
                'time' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null,
                'title' => $tier ? "{$activity} — {$tier}" : $activity,
                'tone' => self::TONES[$i % count(self::TONES)],
            ];
        }

        return ['total' => $total, 'items' => $out];
    }

    // ═══════════════ الرؤى ═══════════════
    // نُعيد استعمال مولّد النصوص في ExecutiveAnalyticsService كما هو — لكنه يقارن
    // القطاعات ببعضها ولا يحصر بقطاع. فالمحصور بقطاع لا يُعطى رؤى البتّة:
    // «القطاع الأعلى جاهزية» جملةٌ تفشي ما وراء حدّه. قائمة فارغة لا null،
    // فالفراغ هنا «لا رؤى لك» لا «ممنوع».
    private function insights(array $scope): array
    {
        if (!empty($scope['sectorBound'])) {
            return [];
        }

        return array_slice($this->executive->insights($scope['classifications']), 0, 4);
    }

    // ─────────────── مساعدات ───────────────

    // إجماليات الحضور على استعلام جداول محصور مسبقاً
    private function attendanceAgg($scheduleQuery): array
    {
        $total = (clone $scheduleQuery)->count();
        if ($total === 0) {
            return ['total' => 0, 'present' => 0, 'absent' => 0];
        }

        $absent = self::ABSENT_STATUSES;
        $agg = Attendance::whereIn('schedule_id', (clone $scheduleQuery)->select('id'))
            ->selectRaw("
                count(*) filter (where status = 'present') as present,
                count(*) filter (where status in ('" . implode("','", $absent) . "')) as absent
            ")->first();

        return [
            'total' => $total,
            'present' => (int) ($agg->present ?? 0),
            'absent' => (int) ($agg->absent ?? 0),
        ];
    }

    // متوسط الجاهزية = متوسط (السلوكي + الفنّي) / ٢ على استعلام تقارير معتمدة محصور
    private function avgReadiness($approvedQuery): ?float
    {
        $v = $approvedQuery
            ->selectRaw('avg((coalesce(behavioral_fit,0) + coalesce(technical_fit,0)) / 2) r')
            ->value('r');

        return $v === null ? null : round((float) $v, 1);
    }

    // فرق فترتين: {value, prev, pct, dir, inverse}
    // inverse = «الارتفاع سيّئ» (طابور الاعتماد) — الواجهة تعكس اللون لا الحساب.
    private function delta($curr, $prev, int $decimals = 0, bool $inverse = false): array
    {
        $curr = (float) ($curr ?? 0);
        $prev = (float) ($prev ?? 0);
        $pct = $prev > 0 ? round(($curr - $prev) / $prev * 100, 1) : ($curr > 0 ? 100.0 : 0.0);

        return [
            'value' => $decimals > 0 ? round($curr, $decimals) : (int) $curr,
            'prev' => $decimals > 0 ? round($prev, $decimals) : (int) $prev,
            'pct' => $pct,
            'dir' => $curr > $prev ? 'up' : ($curr < $prev ? 'down' : 'flat'),
            'inverse' => $inverse,
        ];
    }
}
