<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Schedule;
use App\Models\Attendance;
use App\Models\User;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;

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

    private function allowedClassifications(Request $request): array
    {
        return $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)
            ? ['normal', 'secret', 'top_secret'] : ['normal'];
    }

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

        if (!empty($validated['date']))        { $query->whereDate('schedule_date', $validated['date']); }
        if (!empty($validated['activity']))    { $query->where('activity', $validated['activity']); }
        if (!empty($validated['candidateId'])) { $query->where('candidate_id', $validated['candidateId']); }
        if (!empty($validated['evaluatorId'])) { $query->where('evaluator_id', $validated['evaluatorId']); }

        $rows = $query->orderBy('schedule_date')->orderBy('schedule_time')->get()->map(fn ($s) => [
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
            'activity' => 'required|in:interview,discussion,measurement,integration',
            'date' => ($creating ? 'required|' : 'nullable|') . 'date|after_or_equal:today',
            'time' => 'nullable|date_format:H:i',
            'location' => 'nullable|string|max:200',
            'evaluatorId' => 'nullable|integer|exists:users,id',
            'assistantId' => 'nullable|integer|exists:users,id',
        ];
    }

    // POST /schedules — جدولة جلسة لمرشّح ضمن دورته الحالية
    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $validated = $request->validate(array_merge(
            ['candidateId' => 'required|integer'],
            $this->rules(true)
        ));

        // حلّ المرشّح ضمن صلاحية التصنيف فقط (مصنّف خارج الصلاحية = «غير موجود»)
        $candidate = Candidate::whereIn('classification', $this->allowedClassifications($request))
            ->find($validated['candidateId']);
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

        $this->log($request, 'CREATE_SCHEDULE', $schedule->id, [
            'candidate' => $candidate->participant_code,
            'activity' => $schedule->activity,
            'date' => $validated['date'],
        ]);

        return response()->json(['message' => 'تمت جدولة الجلسة', 'scheduleId' => $schedule->id], 201);
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
        if (!in_array($schedule->candidate->classification, $this->allowedClassifications($request), true)) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        if (Attendance::where('schedule_id', $schedule->id)->exists()) {
            return response()->json(['error' => 'لا يمكن تعديل جلسة سُجّل حضورها'], 422);
        }

        $validated = $request->validate($this->rules(false));

        if (isset($validated['activity']))  { $schedule->activity = $validated['activity']; }
        if (isset($validated['date']))      { $schedule->schedule_date = $validated['date']; }
        if (array_key_exists('time', $validated))        { $schedule->schedule_time = $validated['time']; }
        if (array_key_exists('location', $validated))    { $schedule->location = $validated['location']; }
        if (array_key_exists('evaluatorId', $validated)) { $schedule->evaluator_id = $validated['evaluatorId']; }
        if (array_key_exists('assistantId', $validated)) { $schedule->assistant_id = $validated['assistantId']; }
        $schedule->save();

        $this->log($request, 'UPDATE_SCHEDULE', $schedule->id, ['activity' => $schedule->activity]);

        return response()->json(['message' => 'تم تحديث الجلسة']);
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
        if (!in_array($schedule->candidate->classification, $this->allowedClassifications($request), true)) {
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
}
