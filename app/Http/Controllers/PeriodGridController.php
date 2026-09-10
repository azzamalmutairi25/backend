<?php

namespace App\Http\Controllers;

use App\Models\AssessorAbsence;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\PeriodAssessor;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Security\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  شبكة جدولة المستشارين — جدول المركز المعتمد.
//
//  الصفوف أيام العمل، والأعمدة المستشارون برموزهم، والخلية ما يفعله المستشار
//  ذلك اليوم: عدداً من المقابلات، أو حلقةَ نقاشٍ يديرها، أو إجازة، أو تدريباً
//  يرافق فيه مضيفاً.
//
//  ── ثلاثة مجاميع تُقرأ بلمحة ──
//  مجموع الصفّ يُقارَن بالطاقة اليومية فيحمرّ عند الاختلاف. ومجموع العمود
//  حصّة المستشار في الفترة. والمجموع الكلّي هو إجمالي ما ستستقبله.
//
//  ── وهي خطّةٌ لا مرآة ──
//  تُبنى قبل أن يُختار مشاركٌ واحد. ولذلك تُعرض معها **الجلسات المُسنَدة
//  فعلاً** في خانةٍ ثانية: الخطّة تقول «مقعدان»، والتنفيذ يقول «واحد» —
//  والفرق بينهما هو ما يُدار.
// ════════════════════════════════════════════════════════════

class PeriodGridController extends Controller
{
    private function denyView(Request $request): ?JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
    }

    private function denyManage(Request $request): ?JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
    }

    // GET /scheduling-periods/{id}/grid
    public function show(Request $request, int $id)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الفترة غير موجودة'], 404);
        }

        $days = collect($period->workingDays())->map(fn ($d) => $d->toDateString())->all();
        $from = $days ? reset($days) : $period->start_date->toDateString();
        $to = $days ? end($days) : $period->end_date->toDateString();

        // ── الأعمدة: من في لوحة الفترة ──
        // اللوحة هي من يعمل فيها، والشبكة تُبنى عليها. ومن ليس فيها لا عمود
        // له: إضافتُه إليها هي الباب، لا تحريرُ خليةٍ في جدول.
        $panel = PeriodAssessor::with('user:id,full_name,code')
            ->where('period_id', $period->id)
            ->get();

        $consultants = $panel->groupBy('user_id')->map(function ($rows) {
            $u = $rows->first()->user;

            return [
                'id' => $u?->id,
                'code' => $u?->code,
                'name' => $u?->full_name,
                // النشاط قد يتعدّد للشخص الواحد (مقابلة وحلقة نقاش بنصابين)
                'activities' => $rows->pluck('activity')->unique()->values()->all(),
                'dailyQuota' => $rows->first()->dailyQuota(),
            ];
        })->filter(fn ($c) => $c['id'] !== null)
            ->sortBy(fn ($c) => [$c['code'] ?? 'zz', $c['name']])
            ->values();

        $userIds = $consultants->pluck('id')->all();

        // ── الخلايا المخطَّطة ──
        $planned = DB::table('period_grid_cells')
            ->where('period_id', $period->id)
            ->get(['user_id', 'cell_date', 'planned'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('planned', 'cell_date')->all())
            ->all();

        // ── الغياب: إجازةً أو تدريباً أو حلقةَ نقاش ──
        $absences = AssessorAbsence::with('host:id,code,full_name')
            ->whereIn('user_id', $userIds)
            ->whereDate('from_date', '<=', $to)
            ->whereDate('to_date', '>=', $from)
            ->get();

        // ── المُسنَد فعلاً — الخطّة تُقارَن بالتنفيذ ──
        // والغائب لا يُحتسب مقعداً مشغولاً: مقعدُه تُرك فارغاً فعلاً، وعدُّه
        // مشغولاً يجعل اليوم يبدو مكتملاً وهو ناقص — فلا يرى مسؤول الجدولة
        // النقص الذي عليه أن يعالجه. «مقعد المستشار يُترك فارغاً فيظهر اليوم
        // ناقصاً في الشبكة».
        $assigned = Schedule::where('period_id', $period->id)
            ->whereIn('evaluator_id', $userIds)
            ->whereDoesntHave('attendance', fn ($a) => $a->whereIn('status', Attendance::ABSENT_STATUSES))
            ->selectRaw('evaluator_id, schedule_date, count(*) c')
            ->groupBy('evaluator_id', 'schedule_date')
            ->get()
            ->groupBy('evaluator_id')
            // التاريخ من استعلامٍ خام لا يمرّ بتحويل النموذج، فقد يعود
            // بطابعٍ زمنيّ كامل — يُقصّ صراحةً كي يطابق مفاتيح الأيام
            ->map(fn ($rows) => $rows->mapWithKeys(
                fn ($r) => [substr((string) $r->schedule_date, 0, 10) => (int) $r->c]
            )->all())
            ->all();

        $capacity = $period->daily_capacity;
        $rows = [];
        foreach ($days as $day) {
            $cells = [];
            $dayTotal = 0;
            foreach ($consultants as $c) {
                $away = $absences->first(fn ($a) => $a->user_id === $c['id']
                    && $a->from_date->toDateString() <= $day
                    && $a->to_date->toDateString() >= $day);

                $count = (int) ($planned[$c['id']][$day] ?? 0);
                if (! $away) {
                    $dayTotal += $count;
                }

                $cells[] = [
                    'userId' => $c['id'],
                    'planned' => $away ? null : $count,
                    'assigned' => (int) ($assigned[$c['id']][$day] ?? 0),
                    // الحجب وسببُه — خانةٌ فارغة لا تقول أهو في إجازةٍ أم
                    // تدريبٍ أم يدير حلقة، وهي ثلاثة أحوالٍ مختلفة في التخطيط
                    'blocked' => $away ? $away->reason : null,
                    'blockedLabel' => $away ? AssessorAbsence::reasonLabel($away->reason) : null,
                    'hostCode' => $away?->host?->code,
                ];
            }

            $rows[] = [
                'date' => $day,
                'total' => $dayTotal,
                // يحمرّ عند الاختلاف عن الطاقة — وبلا طاقةٍ معلَنة لا مقارنة
                'matchesCapacity' => $capacity === null ? null : $dayTotal === (int) $capacity,
                'cells' => $cells,
            ];
        }

        // مجموع كل عمود — حصّة المستشار في الفترة
        $columnTotals = [];
        foreach ($consultants as $c) {
            $columnTotals[$c['id']] = array_sum(array_map(
                fn ($r) => collect($r['cells'])->firstWhere('userId', $c['id'])['planned'] ?? 0,
                $rows
            ));
        }

        return response()->json([
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'dailyCapacity' => $capacity,
                'workingDayCount' => count($days),
                'targetTotal' => $period->targetTotal(),
                'editable' => $period->isEditable(),
            ],
            'consultants' => $consultants,
            'days' => $rows,
            'columnTotals' => $columnTotals,
            'grandTotal' => array_sum($columnTotals),
            'canManage' => $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE),
        ]);
    }

    // PUT /scheduling-periods/{id}/grid — حفظ الخلايا دفعةً واحدة
    public function save(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الفترة غير موجودة'], 404);
        }
        // المعتمَدة تُقرأ ولا تُكتب — كبقية بنيتها
        if (! $period->isEditable()) {
            return response()->json([
                'error' => 'فترة '.SchedulingPeriod::label($period->status).' — لا تُعدَّل شبكتها',
            ], 422);
        }

        $validated = $request->validate([
            'cells' => 'present|array|max:4000',
            'cells.*.userId' => 'required|integer|exists:users,id',
            'cells.*.date' => 'required|date_format:Y-m-d',
            'cells.*.planned' => 'required|integer|min:0|max:99',
        ]);

        $workingDays = collect($period->workingDays())->map(fn ($d) => $d->toDateString())->all();
        $onPanel = PeriodAssessor::where('period_id', $period->id)->pluck('user_id')->unique()->all();

        $rejected = [];
        $rows = [];
        $now = now();
        foreach ($validated['cells'] as $cell) {
            // خارج أيام العمل: عمودُ جمعةٍ لا وجود له في الشبكة أصلاً
            if (! in_array($cell['date'], $workingDays, true)) {
                $rejected[] = $cell['date'].' ليس يوم عمل في هذه الفترة';

                continue;
            }
            // من ليس في اللوحة لا عمود له — بابُه إضافتُه إليها
            if (! in_array($cell['userId'], $onPanel, true)) {
                $rejected[] = 'مستشارٌ خارج لوحة الفترة';

                continue;
            }

            $rows[] = [
                'period_id' => $period->id,
                'user_id' => $cell['userId'],
                'cell_date' => $cell['date'],
                'planned' => $cell['planned'],
                'updated_by' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rejected) {
            return response()->json([
                'error' => 'خلايا مرفوضة: '.implode('، ', array_slice(array_unique($rejected), 0, 3)),
            ], 422);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('period_grid_cells')->upsert(
                $chunk,
                ['period_id', 'user_id', 'cell_date'],
                ['planned', 'updated_by', 'updated_at']
            );
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'SAVE_PERIOD_GRID',
            'entity_type' => 'scheduling_period',
            'entity_id' => (string) $period->id,
            'details' => ['cells' => count($rows)],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'حُفظت الشبكة',
            'saved' => count($rows),
        ]);
    }
}
