<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\FinalReport;
use App\Models\Schedule;
use App\Models\Attendance;
use App\Models\EvaluationScore;
use App\Models\Sector;
use App\Security\Permissions;
use App\Services\ExecutiveAnalyticsService;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  التحليلات — نظرة تنفيذية مجمّعة (تحترم تصنيف من يعرض: fail-closed)
// ════════════════════════════════════════════════════════════

class AnalyticsController extends Controller
{

    private function gate(Request $request): bool
    {
        return $request->user()->hasPermission(Permissions::ANALYTICS_VIEW);
    }

    // GET /analytics/dashboard — النظرة التنفيذية الموحّدة
    public function dashboard(Request $request)
    {
        if (!$this->gate($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التحليلات'], 403);
        }
        $allowed = $this->allowedClassifications($request);
        $cand = fn () => Candidate::whereIn('classification', $allowed);

        $byStatus = (clone $cand())->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $byTier = (clone $cand())->selectRaw('tier, count(*) c')->groupBy('tier')->pluck('c', 'tier');
        $byClass = (clone $cand())->selectRaw('classification, count(*) c')->groupBy('classification')->pluck('c', 'classification');

        $reports = FinalReport::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
        $reportsByStatus = (clone $reports)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $approved = (clone $reports)->where('status', 'approved');

        $today = now()->toDateString();
        $todaySchedules = Schedule::whereDate('schedule_date', $today)
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));
        $todayIds = (clone $todaySchedules)->pluck('id');
        $present = Attendance::whereIn('schedule_id', $todayIds)->where('status', 'present')->count();
        $absent = Attendance::whereIn('schedule_id', $todayIds)
            ->whereIn('status', ['absent_excused', 'absent_unexcused'])->count();
        $totalToday = $todayIds->count();

        $upcoming = Schedule::whereDate('schedule_date', '>=', $today)
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))->count();

        // ── سطرُ المقارنة تحت كل مؤشّر ──
        // البطاقة تعرض رقماً مجرّداً لا يقول أصاعدٌ هو أم هابط. هذه أرقامُ
        // سياقه، محسوبةٌ من البيانات نفسها لا مستهدفاتٌ مُفترضة: المنصّة لا
        // تعرّف «مستهدفاً» للتوافق، فاختراعُ سبعين بالمئة هنا حكمٌ إداريّ لا
        // يملكه هذا الكود. المقارنة بالربع السابق كما في مقياس الجاهزية،
        // و‎null‎ حين لا ربعَ سابقاً يُقارَن — لا صفراً يُقرأ ثباتاً.
        $qStart = now()->startOfQuarter();
        $prevQStart = now()->startOfQuarter()->subMonths(3);

        $newThisQuarter = (clone $cand())->where('created_at', '>=', $qStart)->count();

        $fitDelta = function (string $column) use ($reports, $qStart, $prevQStart): ?float {
            $curr = $this->round1((clone $reports)->where('status', 'approved')
                ->where('updated_at', '>=', $qStart)->avg($column));
            $prev = $this->round1((clone $reports)->where('status', 'approved')
                ->whereBetween('updated_at', [$prevQStart, $qStart])->avg($column));
            return ($curr !== null && $prev !== null) ? round($curr - $prev, 1) : null;
        };

        $horizonDays = 14;
        $upcomingSoon = Schedule::whereBetween('schedule_date', [$today, now()->addDays($horizonDays)->toDateString()])
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))->count();

        return response()->json([
            'candidates' => [
                'total' => (clone $cand())->count(),
                'byStatus' => $this->fill($byStatus, ['draft', 'scheduled', 'assessed', 'approved', 'completed']),
                'byTier' => $this->fill($byTier, ['upper', 'middle']),
                'byClassification' => $this->fill($byClass, ['normal', 'secret', 'top_secret']),
            ],
            'reports' => [
                'byStatus' => $this->fill($reportsByStatus, [
                    'draft', 'pending_evaluator', 'pending_manager', 'pending_dev_approval', 'returned', 'approved',
                ]),
                'avgBehavioralFit' => $this->round1((clone $approved)->avg('behavioral_fit')),
                'avgTechnicalFit' => $this->round1((clone $approved)->avg('technical_fit')),
            ],
            'today' => [
                'sessions' => $totalToday,
                'present' => $present,
                'absent' => $absent,
                'pending' => max(0, $totalToday - $present - $absent),
            ],
            'upcomingSessions' => $upcoming,
            // سياقُ كل بطاقة — الواجهة تصوغه نصّاً، والخادم يعطي الرقم وحده
            'context' => [
                'candidatesThisQuarter' => $newThisQuarter,
                'behavioralFitDeltaPoints' => $fitDelta('behavioral_fit'),
                'technicalFitDeltaPoints' => $fitDelta('technical_fit'),
                'upcomingHorizonDays' => $horizonDays,
                'upcomingWithinHorizon' => $upcomingSoon,
            ],
        ]);
    }

    // GET /analytics/executive — مؤشرات القيادة التنفيذية (KPIs/خريطة/مقارنات/اتجاهات/رؤى)
    //
    // بصلاحيتها المستقلّة لا بصلاحية التحليلات العامّة: القيادة التنفيذية شاشة
    // قائمة بذاتها في الشريط الجانبي، وسحبُها من دورٍ في شاشة الأدوار يجب أن
    // يُغلق مسارها لا أن يُخفي رابطها وحده.
    public function executive(Request $request, ExecutiveAnalyticsService $svc)
    {
        if (!$this->executiveGate($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض القيادة التنفيذية'], 403);
        }
        $months = (int) ($request->input('months') ?: 6);
        return response()->json($svc->executive($this->allowedClassifications($request), $months));
    }

    // GET /analytics/executive/overview — نظرة شاملة على كل أبواب المنصّة إلا الإعدادات
    //
    // نداءٌ مستقل عن /executive لا حقلٌ فيه: التبويبان يُفتحان منفصلين، وضمُّ
    // ثلاثة عشر قسماً إلى حمولة المؤشرات يُثقل الفتحة الأولى بما لا يُقرأ فيها.
    public function executiveOverview(Request $request, ExecutiveAnalyticsService $svc)
    {
        if (!$this->executiveGate($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض القيادة التنفيذية'], 403);
        }
        return response()->json($svc->platformOverview($this->allowedClassifications($request)));
    }

    // GET /analytics/executive/reports — لوحة التقارير التنفيذية (حالة السلسلة، لا تحرير)
    //
    // بصلاحية القيادة التنفيذية لا بـREPORT_VIEW: هذه نظرةٌ مجمّعة بالرمز على
    // خطّ الاعتماد، لا فتحُ تقريرٍ ولا اعتمادُه — وشاشة التقارير التشغيلية
    // تبقى محروسة بصلاحيتها كما هي.
    public function executiveReports(Request $request, ExecutiveAnalyticsService $svc)
    {
        if (!$this->executiveGate($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض القيادة التنفيذية'], 403);
        }
        $limit = (int) ($request->input('limit') ?: 25);
        return response()->json($svc->reportsBoard($this->allowedClassifications($request), $limit));
    }

    private function executiveGate(Request $request): bool
    {
        return $request->user()->hasPermission(Permissions::ANALYTICS_EXECUTIVE);
    }

    // GET /analytics/by-sector — تجميع حسب القطاع (عدد، مكتمل، متوسط توافق)
    public function bySector(Request $request)
    {
        if (!$this->gate($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التحليلات'], 403);
        }
        $allowed = $this->allowedClassifications($request);

        $rows = Sector::orderBy('name_ar')->get()->map(function ($sector) use ($allowed) {
            $base = Candidate::where('sector_id', $sector->id)->whereIn('classification', $allowed);
            $total = (clone $base)->count();
            $completed = (clone $base)->where('status', 'completed')->count();
            $approved = FinalReport::where('status', 'approved')
                ->whereHas('candidate', fn ($q) => $q->where('sector_id', $sector->id)->whereIn('classification', $allowed));
            return [
                'sectorId' => $sector->id,
                'sectorName' => $sector->name_ar,
                'total' => $total,
                'completed' => $completed,
                'completionRate' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
                'avgBehavioralFit' => $this->round1((clone $approved)->avg('behavioral_fit')),
                'avgTechnicalFit' => $this->round1((clone $approved)->avg('technical_fit')),
            ];
        })->filter(fn ($r) => $r['total'] > 0)->values();

        return response()->json(['sectors' => $rows]);
    }

    // GET /analytics/competency-gaps — متوسط النسبة لكل كفاءة (الأضعف أولاً) للتطوير المؤسسي
    public function competencyGaps(Request $request)
    {
        if (!$this->gate($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التحليلات'], 403);
        }
        $allowed = $this->allowedClassifications($request);

        $scores = EvaluationScore::whereHas('evaluation', fn ($q) => $q
            ->whereIn('status', ['submitted', 'approved'])
            ->whereHas('candidate', fn ($c) => $c->whereIn('classification', $allowed)))
            ->with('competency')->get();

        $gaps = $scores->groupBy('competency_id')->map(function ($rows) {
            $c = $rows->first()->competency;
            if (!$c) return null;
            $max = (int) ($c->max_level ?: 5);
            $avg = (float) $rows->avg('score');
            return [
                'competency' => $c->name_ar,
                'type' => $c->type,
                'avgPct' => $max > 0 ? round($avg / $max * 100, 1) : 0.0,
                'samples' => $rows->count(),
            ];
        })->filter()->sortBy('avgPct')->values();

        return response()->json(['gaps' => $gaps->all()]);
    }

    // GET /analytics/trends — التقارير المعتمدة شهرياً (اتجاه الإنجاز)
    public function trends(Request $request)
    {
        if (!$this->gate($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التحليلات'], 403);
        }
        $allowed = $this->allowedClassifications($request);

        $rows = FinalReport::where('status', 'approved')
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->selectRaw("to_char(updated_at, 'YYYY-MM') ym, count(*) c")
            ->groupBy('ym')->orderBy('ym')->get()
            ->map(fn ($r) => ['month' => $r->ym, 'approvedReports' => (int) $r->c]);

        return response()->json(['trends' => $rows]);
    }

    private function fill($pluck, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (int) ($pluck[$k] ?? 0);
        }
        return $out;
    }

    private function round1($v): ?float
    {
        return $v === null ? null : round((float) $v, 1);
    }
}
