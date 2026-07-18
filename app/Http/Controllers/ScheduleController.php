<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Schedule;
use App\Models\Attendance;
use App\Models\User;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  خدمة الجدولة — إنشاء/إدارة مواعيد جلسات التقييم
//  (المرشّح ← دورته الحالية ← جلسات بأنشطتها والمُقيّمين والقاعات)
// ════════════════════════════════════════════════════════════

class ScheduleController extends Controller
{
    private const ACTIVITY_LABEL = [
        'interview' => 'المقابلة الشخصية',
        'discussion' => 'حلقة النقاش',
        'measurement' => 'أدوات القياس',
        'integration' => 'التمرين التكاملي',
    ];


    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'schedule',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    // حدّ القطاع لجلسة محمّلة بالمعرّف: المحصور قطاعياً لا يمسّ جلسة قطاع آخر — كما
    // في القوائم (index/absences). التصنيف + القطاع، وخارج النطاق = «غير موجودة» (لا
    // كشف وجود). لازمٌ لأن schedule.manage/candidate.edit قابلتان للتفويض لدورٍ محصور.
    private function scheduleOutOfScope(Request $request, Schedule $schedule): bool
    {
        $user = $request->user();
        if (!in_array($schedule->candidate->classification, $this->allowedClassifications($request), true)) {
            return true;
        }
        return $user->isSectorBound() && $schedule->candidate->sector_id !== $user->sector_id;
    }

    // GET /schedules — قائمة الجلسات (فلترة بالتاريخ/النشاط/المرشّح/المُقيّم)
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $validated = $request->validate([
            'date' => 'nullable|date',
            'activity' => 'nullable|in:interview,discussion,measurement,integration',
            'candidateId' => 'nullable|integer',
            'evaluatorId' => 'nullable|integer',
        ]);

        $canSeeNames = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        $allowed = $this->allowedClassifications($request);

        $query = Schedule::with(['candidate.sector', 'attendance', 'evaluator', 'assistant'])
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));

        // المحصور بقطاع يرى جلسات قطاعه وحدها
        $user = $request->user();
        if ($user->isSectorBound()) {
            $query->whereHas('candidate', fn ($q) => $q->where('sector_id', $user->sector_id));
        }

        if (!empty($validated['date']))        { $query->whereDate('schedule_date', $validated['date']); }
        if (!empty($validated['activity']))    { $query->where('activity', $validated['activity']); }
        if (!empty($validated['candidateId'])) { $query->where('candidate_id', $validated['candidateId']); }
        if (!empty($validated['evaluatorId'])) { $query->where('evaluator_id', $validated['evaluatorId']); }

        // بلا أي مُرشِّح حاصر كانت القائمة تُحمّل كل جلسات كل الدورات (تنمو بلا حدّ مع
        // التاريخ). نافذة متدحرجة افتراضية (٦٠ يوماً للخلف فصاعداً) + سقف صلب.
        $unfiltered = empty($validated['date']) && empty($validated['candidateId']) && empty($validated['evaluatorId']);
        if ($unfiltered) {
            $query->whereDate('schedule_date', '>=', now()->subDays(60)->toDateString());
        }

        $rows = $query->orderBy('schedule_date')->orderBy('schedule_time')->limit(2000)->get()->map(fn ($s) => [
            'id' => $s->id,
            'candidateId' => $s->candidate_id,
            'participantCode' => $s->candidate->participant_code,
            'candidateName' => $canSeeNames ? $s->candidate->full_name : null,
            'sectorName' => optional($s->candidate->sector)->name_ar,
            'date' => substr((string) $s->schedule_date, 0, 10),
            'time' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null,
            'activity' => $s->activity,
            'activityLabel' => self::ACTIVITY_LABEL[$s->activity] ?? $s->activity,
            'location' => $s->location,
            'evaluatorId' => $s->evaluator_id,
            'evaluatorName' => optional($s->evaluator)->full_name,
            'assistantName' => optional($s->assistant)->full_name,
            'attendanceStatus' => optional($s->attendance)->status ?? 'pending',
        ]);

        return response()->json(['schedules' => $rows]);
    }

    // قواعد التحقّق المشتركة للإنشاء/التعديل
    private function rules(bool $creating): array
    {
        return [
            // النشاط إلزامي عند الإنشاء، واختياري عند التعديل الجزئي (يُطبَّق فقط إن أُرسل)
            'activity' => ($creating ? 'required|' : 'sometimes|') . 'in:interview,discussion,measurement,integration',
            'date' => ($creating ? 'required|' : 'nullable|') . 'date|after_or_equal:today',
            'time' => 'nullable|date_format:H:i',
            'location' => 'nullable|string|max:200',
            'evaluatorId' => 'nullable|integer|exists:users,id',
            'assistantId' => 'nullable|integer|exists:users,id',
        ];
    }

    // POST /schedules — جدولة جلسة لمرشّح ضمن دورته الحالية
    // ── حدّ القطاع عند التوزيع ──
    // كل مقيّم ومساعد مخصَّص لقطاع ولا يُقيّم غيره. الإسناد عبر القطاعات يُمنع،
    // ولا يمرّ إلا لحامل CROSS_SECTOR_ASSIGN وبعد تأكيد صريح (confirmCrossSector).
    // يرجع خطأً جاهزاً للرد، أو null إن كان الإسناد سليماً.
    private function crossSectorError(Request $request, Candidate $candidate, array $validated): ?array
    {
        $offenders = [];

        foreach (['evaluatorId' => 'المقيّم', 'assistantId' => 'المساعد'] as $key => $label) {
            $id = $validated[$key] ?? null;
            if (!$id) {
                continue;
            }
            $u = User::with(['role', 'sector'])->find($id);
            if (!$u || $u->coversSector($candidate->sector_id)) {
                continue;
            }
            $offenders[] = $label . ' «' . $u->full_name . '» ('
                . ($u->sector?->name_ar ?? 'بلا قطاع') . ')';
        }

        if (!$offenders) {
            return null;
        }

        $sector = $candidate->sector?->name_ar ?? '—';
        $warning = 'تنبيه: هذا المرشح ليس من نفس القطاع. المرشّح من قطاع «' . $sector
            . '» بينما ' . implode(' و', $offenders) . '.';

        if (!$request->user()->hasPermission(Permissions::CROSS_SECTOR_ASSIGN)) {
            return ['body' => ['error' => $warning . ' الإسناد عبر القطاعات يتطلّب صلاحية إدارة المرشحين.'], 'status' => 403];
        }

        // يملك الصلاحية لكنه لم يؤكّد بعد — أعِد التحذير ليُعرض قبل التوزيع
        if (!$request->boolean('confirmCrossSector')) {
            return ['body' => [
                'error' => $warning,
                'requiresConfirmation' => true,
                'confirmField' => 'confirmCrossSector',
            ], 'status' => 409];
        }

        return null; // أكّد وهو يملك الصلاحية — يمرّ، ويُدوَّن التجاوز عند الحفظ
    }

    private function isCrossSector(Request $request, Candidate $candidate, array $validated): bool
    {
        foreach (['evaluatorId', 'assistantId'] as $key) {
            $id = $validated[$key] ?? null;
            if ($id && ($u = User::with('role')->find($id)) && !$u->coversSector($candidate->sector_id)) {
                return true;
            }
        }
        return false;
    }

    // GET /candidates/{id}/interviewers — مستشارو المقابلة المؤهّلون لهذا المرشّح
    // (مقيّمو قطاعه الفعّالون)، لاختيار المستشار عند الجدولة بعد مراجعة السيرة.
    public function interviewers(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }
        $candidate = $this->resolveCandidateInScope($request, $id);
        if (!$candidate) {
            $this->log($request, 'DENIED_CANDIDATE_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        $interviewers = User::whereHas('role', fn ($q) => $q->where('code', 'EVALUATOR'))
            ->where('is_active', true)
            ->where('sector_id', $candidate->sector_id)
            ->orderBy('full_name')
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->full_name]);

        return response()->json([
            'interviewers' => $interviewers,
            'hasCv' => $candidate->cv()->exists(), // هل توجد سيرة للمراجعة قبل التعيين
        ]);
    }

    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $validated = $request->validate(array_merge(
            ['candidateId' => 'required|integer'],
            $this->rules(true)
        ));

        // النطاق كاملاً (التصنيف + القطاع). كان التصنيف وحده، فمن مُنح schedule.manage
        // بالاستثناء وهو محصور قطاعياً كان يجدول مرشّح قطاع آخر (خارج النطاق = «غير موجود»).
        $candidate = $this->resolveCandidateInScope($request, $validated['candidateId']);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        if (!in_array($candidate->status, ['scheduled', 'assessed'], true)) {
            return response()->json(['error' => 'لا يمكن جدولة مرشّح غير معتمد للتقييم'], 422);
        }
        // نربط الجلسة بالدورة الحالية غير المكتملة
        $assessment = $candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
        if (!$assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم نشطة للمرشّح'], 422);
        }

        if ($err = $this->crossSectorError($request, $candidate, $validated)) {
            return response()->json($err['body'], $err['status']);
        }
        $crossed = $this->isCrossSector($request, $candidate, $validated);

        $schedule = Schedule::create([
            'candidate_id' => $candidate->id,
            'assessment_id' => $assessment->id,
            'schedule_date' => $validated['date'],
            'schedule_time' => $validated['time'] ?? null,
            'activity' => $validated['activity'],
            'evaluator_id' => $validated['evaluatorId'] ?? null,
            'assistant_id' => $validated['assistantId'] ?? null,
            'location' => $validated['location'] ?? null,
        ]);

        // التجاوز يُدوَّن بفعل مستقل — تجاوز حدّ القطاع يجب أن يكون مرئياً في التدقيق
        $this->log($request, $crossed ? 'CREATE_SCHEDULE_CROSS_SECTOR' : 'CREATE_SCHEDULE', $schedule->id, [
            'candidate' => $candidate->participant_code,
            'activity' => $schedule->activity,
            'date' => $validated['date'],
            'candidateSector' => $candidate->sector?->code,
        ]);

        return response()->json([
            'message' => 'تمت جدولة الجلسة',
            'scheduleId' => $schedule->id,
            'crossSector' => $crossed,
        ], 201);
    }

    // PUT /schedules/{id} — تعديل جلسة (يُمنع بعد تسجيل الحضور تفادياً للتنافر)
    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $schedule = Schedule::with('candidate')->find($id);
        if (!$schedule) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        if ($this->scheduleOutOfScope($request, $schedule)) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        // القفل بعد تسجيل الحضور يبقى للجميع، إلا إدارة المرشحين (CANDIDATE_EDIT):
        // تعدّل مع تدوين التجاوز. القفل يمنع تنافر «حضورٌ لجلسة تغيّر تاريخها».
        $recorded = Attendance::where('schedule_id', $schedule->id)->exists();
        $canOverride = $request->user()->hasPermission(Permissions::CANDIDATE_EDIT);
        if ($recorded && !$canOverride) {
            return response()->json(['error' => 'لا يمكن تعديل جلسة سُجّل حضورها'], 422);
        }

        $validated = $request->validate($this->rules(false));

        // إعادة الإسناد تمرّ بنفس حدّ القطاع — وإلا التفّ التوزيع عبر التعديل
        if ($err = $this->crossSectorError($request, $schedule->candidate, $validated)) {
            return response()->json($err['body'], $err['status']);
        }
        $crossed = $this->isCrossSector($request, $schedule->candidate, $validated);

        // تغيّر التاريخ أو الوقت يُبطل الحضور المسجّل: حضورٌ لجلسة موعدها تبدّل
        // لم يعد صحيحاً. تغيير المكان أو المُقيّم لا يمسّ الحضور.
        // طبّع الجانبين: schedule_date مصبوب date فـ(string) يعطي «Y-m-d H:i:s»، فمقارنته
        // بـ«Y-m-d» الخام كانت غير متساوية دائماً — يحذف حضوراً سليماً عند تعديلٍ لا يمسّ الموعد.
        $timeChanged = (isset($validated['date']) && $validated['date'] !== $schedule->schedule_date->toDateString())
            || (array_key_exists('time', $validated) && $validated['time'] !== substr((string) $schedule->schedule_time, 0, 5));

        if (isset($validated['activity']))  { $schedule->activity = $validated['activity']; }
        if (isset($validated['date']))      { $schedule->schedule_date = $validated['date']; }
        if (array_key_exists('time', $validated))        { $schedule->schedule_time = $validated['time']; }
        if (array_key_exists('location', $validated))    { $schedule->location = $validated['location']; }
        if (array_key_exists('evaluatorId', $validated)) { $schedule->evaluator_id = $validated['evaluatorId']; }
        if (array_key_exists('assistantId', $validated)) { $schedule->assistant_id = $validated['assistantId']; }

        // الحفظ والإبطال في معاملة. الحذف مشروط بتغيّر الموعد فقط لا بقراءة $recorded
        // السابقة: إدخال حضور متزامن بعد تلك القراءة كان ينجو من الحذف فيبقى حضورٌ
        // لموعد تبدّل (TOCTOU).
        $attendanceCleared = false;
        DB::transaction(function () use ($schedule, $timeChanged, &$attendanceCleared) {
            $schedule->save();
            if ($timeChanged) {
                $attendanceCleared = Attendance::where('schedule_id', $schedule->id)->delete() > 0;
            }
        });

        $action = $recorded ? 'UPDATE_SCHEDULE_OVERRIDE' : ($crossed ? 'UPDATE_SCHEDULE_CROSS_SECTOR' : 'UPDATE_SCHEDULE');
        $this->log($request, $action, $schedule->id, [
            'activity' => $schedule->activity,
            'attendanceCleared' => $attendanceCleared,
        ]);

        return response()->json([
            'message' => $attendanceCleared
                ? 'تم تحديث الجلسة — أُلغي الحضور المسجّل لتغيّر الموعد'
                : 'تم تحديث الجلسة',
            'crossSector' => $crossed,
            'attendanceCleared' => $attendanceCleared,
        ]);
    }

    // DELETE /schedules/{id} — حذف جلسة (يُمنع بعد تسجيل الحضور)
    public function destroy(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $schedule = Schedule::with('candidate')->find($id);
        if (!$schedule) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        if ($this->scheduleOutOfScope($request, $schedule)) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        if (Attendance::where('schedule_id', $schedule->id)->exists()) {
            return response()->json(['error' => 'لا يمكن حذف جلسة سُجّل حضورها'], 422);
        }

        $code = $schedule->candidate->participant_code;
        $schedule->delete();
        $this->log($request, 'DELETE_SCHEDULE', $id, ['candidate' => $code]);

        return response()->json(['message' => 'تم حذف الجلسة']);
    }

    // GET /schedules/absences/{candidateId} — جلسات الغياب القابلة لإعادة الجدولة
    public function absences(Request $request, int $candidateId)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $candidate = Candidate::find($candidateId);
        if (!$candidate || !in_array($candidate->classification, $this->allowedClassifications($request), true)) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        // المحصور بقطاع لا يرى غياب قطاع آخر
        $user = $request->user();
        if ($user->isSectorBound() && $candidate->sector_id !== $user->sector_id) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        $rows = Schedule::with('attendance')
            ->where('candidate_id', $candidateId)
            ->whereNull('rescheduled_at') // الغياب المُستهلَك لا يُعرض للإعادة ثانيةً
            ->whereHas('attendance', fn ($q) => $q->whereIn('status', ['absent_excused', 'absent_unexcused']))
            ->orderByDesc('schedule_date')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'activity' => self::ACTIVITY_LABEL[$s->activity] ?? $s->activity,
                'date' => (string) $s->schedule_date,
                'status' => $s->attendance->status === 'absent_excused' ? 'غياب بعذر' : 'غياب',
                'reason' => $s->attendance->absence_reason,
            ]);

        return response()->json(['absences' => $rows]);
    }

    // POST /schedules/{id}/reschedule — إعادة جدولة جلسة غياب بتاريخ جديد.
    // إدارة المرشحين (CANDIDATE_EDIT) وحدها: إعادة الجدولة قرار إداري لا تسجيل.
    // تُنشئ جلسة جديدة بنفس النشاط والإسناد، وتُبقي جلسة الغياب للتدقيق.
    public function reschedule(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_EDIT)) {
            return response()->json(['error' => 'ليس لديك صلاحية إعادة الجدولة'], 403);
        }

        $old = Schedule::with(['candidate', 'attendance'])->find($id);
        if (!$old || $this->scheduleOutOfScope($request, $old)) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }

        // لا يُعاد جدولة إلا جلسة غياب — الحاضر والمعلّق لا يحتاجان
        $status = $old->attendance?->status;
        if (!in_array($status, ['absent_excused', 'absent_unexcused'], true)) {
            return response()->json(['error' => 'لا تُعاد جدولة إلا جلسة سُجّل فيها غياب'], 422);
        }

        // نفس حرّاس store: لا نُنشئ جلسة حيّة لمرشّح غير مؤهّل أو داخل دورة منتهية.
        // نربط بالدورة الحالية غير المكتملة لا بدورة القديمة (قد تكون أُغلقت).
        if (!in_array($old->candidate->status, ['scheduled', 'assessed'], true)) {
            return response()->json(['error' => 'لا يمكن إعادة جدولة مرشّح غير معتمد للتقييم'], 422);
        }
        $assessment = $old->candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
        if (!$assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم نشطة للمرشّح'], 422);
        }

        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'time' => 'nullable|date_format:H:i',
            'location' => 'nullable|string|max:150',
        ], [
            'date.after_or_equal' => 'تاريخ إعادة الجدولة يجب ألا يكون في الماضي',
        ]);

        // مرّة واحدة لكل غياب: نقفل الصف القديم ونضع rescheduled_at داخل معاملة.
        // نداءان متكرّران/متزامنان كانا يُنشئان جلسات مكرّرة (لا عمود يستهلك الغياب).
        $new = DB::transaction(function () use ($old, $assessment, $validated) {
            $locked = Schedule::whereKey($old->id)->lockForUpdate()->first();
            if ($locked->rescheduled_at !== null) {
                return null; // استُهلك الغياب مسبقاً
            }
            $created = Schedule::create([
                'candidate_id' => $old->candidate_id,
                'assessment_id' => $assessment->id,        // الدورة الحالية لا القديمة
                'schedule_date' => $validated['date'],
                'schedule_time' => $validated['time'] ?? $old->schedule_time,
                'activity' => $old->activity,              // نفس النشاط الذي تغيّب عنه
                'evaluator_id' => $old->evaluator_id,       // نفس الإسناد
                'assistant_id' => $old->assistant_id,
                'location' => $validated['location'] ?? $old->location,
            ]);
            $locked->rescheduled_at = now();
            $locked->save();
            return $created;
        });

        if ($new === null) {
            return response()->json(['error' => 'أُعيدت جدولة هذا الغياب مسبقاً'], 409);
        }

        $this->log($request, 'RESCHEDULE_SESSION', $new->id, [
            'candidate' => $old->candidate->participant_code,
            'fromSchedule' => $old->id,
            'activity' => $old->activity,
            'date' => $validated['date'],
        ]);

        return response()->json([
            'message' => 'تمت إعادة جدولة الجلسة',
            'scheduleId' => $new->id,
        ], 201);
    }
}
