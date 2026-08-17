<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\DiscussionCircle;
use App\Models\PeriodAssessor;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  حلقات النقاش — جلسةُ مجموعةٍ بسعةٍ ومستشار
// ════════════════════════════════════════════════════════════
//
// إسناد المشارك إلى حلقة **يُنشئ صفَّ `schedules` عادياً** (نشاطه `discussion`،
// وتاريخه ووقته ومستشاره من الحلقة، و`circle_id` مضبوط). فالحضور وكشف اليوم
// والتقييم والتقارير تلتقطه بلا تعديل حرفٍ فيها — الحلقة طبقةٌ فوق الجلسات لا
// بديلٌ عنها.
class DiscussionCircleController extends Controller
{
    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'discussion_circle',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function denyView(Request $request): ?\Illuminate\Http\JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
    }

    private function denyManage(Request $request): ?\Illuminate\Http\JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
    }

    /** حلقةٌ ضمن نطاق المستخدم: المحصور قطاعياً لا يمسّ حلقة قطاع آخر */
    private function circleInScope(Request $request, int $id): ?DiscussionCircle
    {
        $user = $request->user();
        return DiscussionCircle::with(['sector', 'evaluator', 'assistant'])
            ->when($user->isSectorBound(), fn ($q) => $q->where('sector_id', $user->sector_id))
            ->find($id);
    }

    private function row(DiscussionCircle $c, bool $canSeeNames): array
    {
        $taken = $c->seatsTaken();
        return [
            'id' => $c->id,
            'periodId' => $c->period_id,
            'sectorId' => $c->sector_id,
            'sectorName' => optional($c->sector)->name_ar,
            'date' => $c->circle_date?->toDateString(),
            'time' => $c->timeLabel(),
            'location' => $c->location,
            'evaluatorId' => $c->evaluator_id,
            'evaluatorName' => optional($c->evaluator)->full_name,
            'assistantId' => $c->assistant_id,
            'assistantName' => optional($c->assistant)->full_name,
            'capacity' => $c->capacity,
            'seatsTaken' => $taken,
            'seatsLeft' => max(0, $c->capacity - $taken),
            'groupLetter' => $c->group_letter,
            'participants' => $c->schedules->map(fn ($s) => [
                'scheduleId' => $s->id,
                'candidateId' => $s->candidate_id,
                'participantCode' => optional($s->candidate)->participant_code,
                'candidateName' => $canSeeNames ? optional($s->candidate)->full_name : null,
                'locked' => $s->attendance !== null,
            ])->values(),
        ];
    }

    // GET /discussion-circles — حلقات يومٍ أو موجة
    public function index(Request $request)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'periodId' => 'nullable|integer',
            'sectorId' => 'nullable|integer',
        ]);

        $user = $request->user();
        $query = DiscussionCircle::with([
            'sector', 'evaluator', 'assistant',
            'schedules.candidate', 'schedules.attendance',
        ]);

        if (!empty($validated['date']))     { $query->whereDate('circle_date', $validated['date']); }
        if (!empty($validated['periodId'])) { $query->where('period_id', $validated['periodId']); }
        if (!empty($validated['sectorId'])) { $query->where('sector_id', $validated['sectorId']); }
        if ($user->isSectorBound())         { $query->where('sector_id', $user->sector_id); }

        // بلا مُرشِّح حاصر: نافذة متدحرجة كنظيرتها في الجدولة، لا كل تاريخ المركز
        if (empty($validated['date']) && empty($validated['periodId'])) {
            $query->whereDate('circle_date', '>=', now()->subDays(60)->toDateString());
        }

        $canSeeNames = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);

        return response()->json([
            'circles' => $query->orderBy('circle_date')->orderBy('circle_time')->limit(500)->get()
                ->map(fn ($c) => $this->row($c, $canSeeNames))->values(),
            'defaultCapacity' => DiscussionCircle::defaultCapacity(),
            'canManage' => $user->hasPermission(Permissions::SCHEDULE_MANAGE),
        ]);
    }

    private function rules(bool $creating): array
    {
        return [
            'sectorId' => ($creating ? 'required|' : 'sometimes|required|') . 'integer|exists:sectors,id',
            'date' => ($creating ? 'required|' : 'sometimes|required|') . 'date_format:Y-m-d|after_or_equal:today',
            'time' => ($creating ? 'required|' : 'sometimes|required|') . 'date_format:H:i',
            'location' => 'nullable|string|max:200',
            'evaluatorId' => 'nullable|integer|exists:users,id',
            'assistantId' => 'nullable|integer|exists:users,id',
            'capacity' => 'nullable|integer|min:1|max:50',
            'groupLetter' => 'nullable|string|in:A,B',
            'periodId' => 'nullable|integer|exists:scheduling_periods,id',
        ];
    }

    /** المستشار مؤهّل لحلقة النقاش وفي قطاعها؟ يرجع رسالة الخطأ أو null */
    private function seatError(?int $userId, string $seat, int $sectorId): ?string
    {
        if (!$userId) {
            return null;
        }
        $u = User::with('role')->find($userId);
        if (!$u || !$u->is_active) {
            return 'المستخدم غير فعّال';
        }
        $roles = PeriodAssessor::eligibleRoles('discussion', $seat);
        if (!$u->role || !in_array($u->role->code, $roles, true)) {
            return '«' . $u->full_name . '» لا يؤهّله دوره ل' . ($seat === 'assistant' ? 'مساعدة' : 'إدارة') . ' حلقة النقاش';
        }
        // حدّ القطاع — نفس قاعدة الجدولة، بلا استثناء صامت
        if (!$u->coversSector($sectorId)) {
            return '«' . $u->full_name . '» من قطاع آخر — حلقة النقاش لقطاعها';
        }
        return null;
    }

    private function periodError(?int $periodId, string $date): ?string
    {
        if (!$periodId) {
            return null;
        }
        $p = SchedulingPeriod::find($periodId);
        if (!$p) {
            return 'موجة الجدولة غير موجودة';
        }
        if (!$p->isEditable()) {
            return 'موجة «' . $p->name . '» ' . SchedulingPeriod::label($p->status) . ' — لا تُعدَّل';
        }
        if ($date < $p->start_date->toDateString() || $date > $p->end_date->toDateString()) {
            return 'التاريخ خارج مدى موجة «' . $p->name . '»';
        }
        return null;
    }

    // POST /discussion-circles
    public function store(Request $request)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $validated = $request->validate($this->rules(true));

        $user = $request->user();
        if ($user->isSectorBound() && (int) $validated['sectorId'] !== $user->sector_id) {
            return response()->json(['error' => 'لا تُنشئ حلقةً لقطاع غير قطاعك'], 403);
        }
        if ($err = $this->periodError($validated['periodId'] ?? null, $validated['date'])) {
            return response()->json(['error' => $err], 422);
        }
        foreach (['evaluatorId' => 'evaluator', 'assistantId' => 'assistant'] as $key => $seat) {
            if ($err = $this->seatError($validated[$key] ?? null, $seat, (int) $validated['sectorId'])) {
                return response()->json(['error' => $err], 422);
            }
        }

        // الإدراج داخل معاملته الخاصة: في postgres يُجهض انتهاكُ قيدٍ **المعاملة
        // المحيطة كلها**، فيسقط كل استعلامٍ بعده بـ25P02. المعاملة الداخلية تجعل
        // الفشل يعود إلى نقطة حفظ فيبقى الطلب (والاختبار) قابلاً للمتابعة.
        try {
            $circle = DB::transaction(fn () => DiscussionCircle::create([
                'period_id' => $validated['periodId'] ?? null,
                'sector_id' => $validated['sectorId'],
                'circle_date' => $validated['date'],
                'circle_time' => $validated['time'],
                'location' => $validated['location'] ?? null,
                'evaluator_id' => $validated['evaluatorId'] ?? null,
                'assistant_id' => $validated['assistantId'] ?? null,
                'capacity' => $validated['capacity'] ?? DiscussionCircle::defaultCapacity(),
                'group_letter' => $validated['groupLetter'] ?? null,
                'created_by' => $user->id,
            ]));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // القيد الجزئي: المستشار نفسه في اللحظة نفسها
            return response()->json(['error' => 'المستشار مرتبط بحلقة أخرى في هذا الوقت'], 409);
        }

        $this->log($request, 'CREATE_CIRCLE', $circle->id, [
            'date' => $validated['date'], 'time' => $validated['time'],
            'capacity' => $circle->capacity,
        ]);

        return response()->json([
            'message' => 'أُنشئت حلقة النقاش',
            'circle' => $this->row(
                $circle->load(['sector', 'evaluator', 'assistant', 'schedules.candidate', 'schedules.attendance']),
                $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES)
            ),
        ], 201);
    }

    // PUT /discussion-circles/{id}
    public function update(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $circle = $this->circleInScope($request, $id);
        if (!$circle) {
            return response()->json(['error' => 'الحلقة غير موجودة'], 404);
        }

        $validated = $request->validate($this->rules(false));
        $sectorId = (int) ($validated['sectorId'] ?? $circle->sector_id);
        $date = $validated['date'] ?? $circle->circle_date->toDateString();

        // تصغير السعة تحت عدد المُسنَدين يترك حلقةً «ممتلئة فوق طاقتها» بلا أن
        // يقرّر أحد من يخرج — يُرفض ويُطلب سحبُ مشاركٍ أولاً
        $taken = $circle->seatsTaken();
        if (array_key_exists('capacity', $validated) && $validated['capacity'] !== null
            && $validated['capacity'] < $taken) {
            return response()->json([
                'error' => 'السعة الجديدة أقل من عدد المُسنَدين (' . $taken . ') — اسحب مشاركاً أولاً',
            ], 422);
        }

        if ($err = $this->periodError($validated['periodId'] ?? $circle->period_id, $date)) {
            return response()->json(['error' => $err], 422);
        }
        foreach (['evaluatorId' => 'evaluator', 'assistantId' => 'assistant'] as $key => $seat) {
            if (array_key_exists($key, $validated)
                && ($err = $this->seatError($validated[$key], $seat, $sectorId))) {
                return response()->json(['error' => $err], 422);
            }
        }

        $moved = (isset($validated['date']) && $validated['date'] !== $circle->circle_date->toDateString())
            || (isset($validated['time']) && $validated['time'] !== $circle->timeLabel());

        foreach ([
            'sectorId' => 'sector_id', 'date' => 'circle_date', 'time' => 'circle_time',
            'location' => 'location', 'evaluatorId' => 'evaluator_id',
            'assistantId' => 'assistant_id', 'capacity' => 'capacity',
            'groupLetter' => 'group_letter', 'periodId' => 'period_id',
        ] as $in => $col) {
            if (array_key_exists($in, $validated)) {
                $circle->{$col} = $validated[$in] ?? ($in === 'capacity' ? $circle->capacity : null);
            }
        }

        try {
            DB::transaction(function () use ($circle) {
                $circle->save();
                // جلسات الحلقة تتبعها: موعدُها ومستشارُها من الحلقة لا من الصفّ.
                // بلا هذا يبقى المشارك على موعدٍ قديم بينما الحلقة انتقلت.
                Schedule::where('circle_id', $circle->id)->update([
                    'schedule_date' => $circle->circle_date,
                    'schedule_time' => $circle->circle_time,
                    'evaluator_id' => $circle->evaluator_id,
                    'assistant_id' => $circle->assistant_id,
                    'location' => $circle->location,
                    'period_id' => $circle->period_id,
                ]);
                foreach (Schedule::where('circle_id', $circle->id)->pluck('assessment_id')->unique() as $aid) {
                    \App\Models\Assessment::refreshDatesFor($aid);
                }
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json(['error' => 'المستشار مرتبط بحلقة أخرى في هذا الوقت'], 409);
        }

        $this->log($request, 'UPDATE_CIRCLE', $circle->id, ['moved' => $moved, 'seats' => $taken]);

        return response()->json([
            'message' => $moved && $taken > 0
                ? 'حُدّثت الحلقة، ونُقلت ' . $taken . ' جلسة معها'
                : 'حُدّثت الحلقة',
            'circle' => $this->row(
                $circle->fresh(['sector', 'evaluator', 'assistant', 'schedules.candidate', 'schedules.attendance']),
                $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_NAMES)
            ),
        ]);
    }

    // DELETE /discussion-circles/{id} — حلقةٌ فارغة فقط
    public function destroy(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $circle = $this->circleInScope($request, $id);
        if (!$circle) {
            return response()->json(['error' => 'الحلقة غير موجودة'], 404);
        }
        if ($circle->seatsTaken() > 0) {
            return response()->json(['error' => 'اسحب المشاركين قبل حذف الحلقة'], 422);
        }

        $circle->delete();
        $this->log($request, 'DELETE_CIRCLE', $id);

        return response()->json(['message' => 'حُذفت الحلقة']);
    }

    // POST /discussion-circles/{id}/attach — إسناد مشاركين
    public function attach(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $circle = $this->circleInScope($request, $id);
        if (!$circle) {
            return response()->json(['error' => 'الحلقة غير موجودة'], 404);
        }
        if (!$circle->evaluator_id) {
            return response()->json(['error' => 'عيّن مستشار الحلقة قبل إسناد المشاركين'], 422);
        }
        if ($err = $this->periodError($circle->period_id, $circle->circle_date->toDateString())) {
            return response()->json(['error' => $err], 422);
        }

        $validated = $request->validate([
            'candidateIds' => 'required|array|min:1|max:50',
            'candidateIds.*' => 'required|integer',
        ], ['candidateIds.required' => 'اختر مشاركاً واحداً على الأقل']);

        // النطاق أولاً — خارجه يسقط صامتاً فلا يصير المعرّف أداةَ كشف
        $query = Candidate::query()->whereIn('id', $validated['candidateIds'])
            ->where('sector_id', $circle->sector_id);
        $this->scopeCandidateQuery($request, $query);
        $candidates = $query->with(['assessments' => fn ($q) => $q->where('status', '!=', 'completed')->orderByDesc('id')])->get();

        if ($candidates->isEmpty()) {
            return response()->json(['error' => 'لا مشاركين ضمن نطاقك وقطاع الحلقة في هذا الاختيار'], 422);
        }

        $skipped = [];
        $created = 0;

        // القفل ثم إعادة العدّ داخل المعاملة: فحصُ السعة قبلها يسمح لضغطتين
        // متزامنتين بتجاوزها معاً
        $result = DB::transaction(function () use ($circle, $candidates, &$skipped, &$created) {
            $locked = DiscussionCircle::whereKey($circle->id)->lockForUpdate()->first();
            $left = $locked->capacity - Schedule::where('circle_id', $locked->id)->count();

            foreach ($candidates as $c) {
                if (!in_array($c->status, ['scheduled', 'assessed'], true)) {
                    $skipped[] = ['code' => $c->participant_code, 'reason' => 'غير معتمد للتقييم'];
                    continue;
                }
                $assessment = $c->assessments->first();
                if (!$assessment) {
                    $skipped[] = ['code' => $c->participant_code, 'reason' => 'لا دورة تقييم نشطة'];
                    continue;
                }
                if (Schedule::where('circle_id', $locked->id)->where('candidate_id', $c->id)->exists()) {
                    $skipped[] = ['code' => $c->participant_code, 'reason' => 'مُسنَد لهذه الحلقة أصلاً'];
                    continue;
                }
                if ($left <= 0) {
                    $skipped[] = ['code' => $c->participant_code, 'reason' => 'تجاوز سعة الحلقة'];
                    continue;
                }

                Schedule::create([
                    'candidate_id' => $c->id,
                    'assessment_id' => $assessment->id,
                    'period_id' => $locked->period_id,
                    'circle_id' => $locked->id,
                    'schedule_date' => $locked->circle_date,
                    'schedule_time' => $locked->circle_time,
                    'activity' => 'discussion',
                    'evaluator_id' => $locked->evaluator_id,
                    'assistant_id' => $locked->assistant_id,
                    'location' => $locked->location,
                ]);
                $assessment->refreshSessionDates();
                $created++;
                $left--;
            }
            return $created;
        });

        $this->log($request, 'ATTACH_CIRCLE', $circle->id, [
            'attached' => $result, 'skipped' => count($skipped),
        ]);

        return response()->json([
            'message' => 'أُسنِد ' . $result . ' مشاركاً للحلقة',
            'attached' => $result,
            'skipped' => $skipped,
            'circle' => $this->row(
                $circle->fresh(['sector', 'evaluator', 'assistant', 'schedules.candidate', 'schedules.attendance']),
                $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_NAMES)
            ),
        ]);
    }

    // DELETE /discussion-circles/{id}/detach — سحب مشارك
    public function detach(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $circle = $this->circleInScope($request, $id);
        if (!$circle) {
            return response()->json(['error' => 'الحلقة غير موجودة'], 404);
        }

        $validated = $request->validate(['candidateId' => 'required|integer']);

        $schedule = Schedule::with('attendance')
            ->where('circle_id', $circle->id)
            ->where('candidate_id', $validated['candidateId'])
            ->first();
        if (!$schedule) {
            return response()->json(['error' => 'المشارك ليس في هذه الحلقة'], 404);
        }
        // نفس قاعدة حذف الجلسة: ما سُجّل حضوره لا يُمحى
        if ($schedule->attendance) {
            return response()->json(['error' => 'لا يُسحب مشارك سُجّل حضوره'], 422);
        }

        $code = optional($schedule->candidate)->participant_code;
        $assessmentId = $schedule->assessment_id;
        $schedule->delete();
        \App\Models\Assessment::refreshDatesFor($assessmentId);
        $this->log($request, 'DETACH_CIRCLE', $circle->id, ['candidate' => $code]);

        return response()->json(['message' => 'سُحب المشارك من الحلقة']);
    }
}
