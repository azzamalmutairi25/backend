<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    private function allowedClassifications(Request $request): array
    {
        $canSeeClassified = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED);
        return $canSeeClassified ? ['normal', 'secret', 'top_secret'] : ['normal'];
    }

    // ── من يستقبل المرشح يسجّل حضوره ──
    // المقيّم/المساعد يسجّلان الجلسات المُسنَدة لهما وحدها؛ والاستقبال ومشرف
    // القياس يسجّلان أي جلسة (ATTENDANCE_RECORD_ANY) لأنهما يستقبلان من لا
    // إسناد لهما فيه. بلا هذا التمييز إمّا عجز المقيّم عن تسجيل جلسته، أو
    // سجّل أيُّ مقيّم حضور مرشّح لم يره.
    private function canRecordFor(Request $request, Schedule $schedule): bool
    {
        $user = $request->user();
        if ($user->hasPermission(Permissions::ATTENDANCE_RECORD_ANY)) {
            return true;
        }
        return $schedule->evaluator_id === $user->id || $schedule->assistant_id === $user->id;
    }

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'attendance',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    public function today(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::ATTENDANCE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الحضور'], 403);
        }

        $today = now()->toDateString();
        $allowed = $this->allowedClassifications($request);

        $canRecord = $request->user()->hasPermission(Permissions::ATTENDANCE_RECORD);

        $user = $request->user();

        $rows = Schedule::with(['candidate.sector', 'attendance'])
            ->whereDate('schedule_date', $today)
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            // المحصور بقطاع لا يرى حضور قطاع آخر
            ->when($user->isSectorBound(), fn ($q) => $q->whereHas('candidate',
                fn ($c) => $c->where('sector_id', $user->sector_id)))
            ->get()
            ->map(function ($sch) use ($request, $canRecord) {
                $att = $sch->attendance; // eager-loaded — لا N+1
                return [
                    'id' => $sch->id,
                    'participantCode' => $sch->candidate->participant_code,
                    'sectorName' => $sch->candidate->sector->name_ar,
                    'activity' => $sch->activity,
                    'status' => $att?->status ?? 'pending',
                    'checkInTime' => $att?->check_in_time?->format('H:i'),
                    // الواجهة تُظهر الأزرار على هذا — الخادم يفرضه على أي حال
                    'canRecord' => $canRecord && $this->canRecordFor($request, $sch),
                ];
            });

        return response()->json(['attendance' => $rows]);
    }

    public function stats(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::ATTENDANCE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الحضور'], 403);
        }

        $today = now()->toDateString();
        $allowed = $this->allowedClassifications($request);
        $user = $request->user();
        // نفس حصر القطاع في today() — وإلا عدّ المؤشّر ما لا تعرضه القائمة
        $scheduleIds = Schedule::whereDate('schedule_date', $today)
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->when($user->isSectorBound(), fn ($q) => $q->whereHas('candidate',
                fn ($c) => $c->where('sector_id', $user->sector_id)))
            ->pluck('id');
        $total = $scheduleIds->count();
        $present = Attendance::whereIn('schedule_id', $scheduleIds)->where('status', 'present')->count();
        $absent = Attendance::whereIn('schedule_id', $scheduleIds)
            ->whereIn('status', ['absent_excused', 'absent_unexcused'])->count();

        return response()->json(['stats' => [
            'total' => $total,
            'present' => $present,
            'absent' => $absent,
            'pending' => $total - $present - $absent,
        ]]);
    }

    public function checkIn(Request $request, int $scheduleId)
    {
        if (!$request->user()->hasPermission(Permissions::ATTENDANCE_RECORD)) {
            return response()->json(['error' => 'ليس لديك صلاحية تسجيل الحضور'], 403);
        }

        $schedule = Schedule::with('candidate')->find($scheduleId);
        if (!$schedule) {
            return response()->json(['error' => 'الجدول غير موجود'], 404);
        }

        if (!in_array($schedule->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_ATTENDANCE_CLASSIFIED', $scheduleId);
            return response()->json(['error' => 'الجدول غير موجود'], 404);
        }

        if (!$this->canRecordFor($request, $schedule)) {
            $this->log($request, 'DENIED_ATTENDANCE_NOT_ASSIGNED', $scheduleId);
            return response()->json(['error' => 'هذه الجلسة ليست مُسنَدة لك'], 403);
        }

        if ($schedule->schedule_date->toDateString() !== now()->toDateString()) {
            return response()->json(['error' => 'لا يمكن تسجيل الحضور إلا لجلسات اليوم'], 422);
        }
        if (Attendance::where('schedule_id', $scheduleId)->exists()) {
            return response()->json(['error' => 'تم تسجيل حالة هذه الجلسة مسبقاً'], 422);
        }

        try {
            Attendance::create([
                'schedule_id' => $scheduleId,
                'status' => 'present',
                'check_in_time' => now(),
                'recorded_by' => $request->user()->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // فقط انتهاك الفهرس الفريد (23505) يعني «سُجّلت مسبقاً»؛ أي خطأ آخر يُصعّد بدل ابتلاع فشل حقيقي
            if ($e->getCode() === '23505') {
                return response()->json(['error' => 'تم تسجيل حالة هذه الجلسة مسبقاً'], 422);
            }
            throw $e;
        }

        $this->log($request, 'RECORD_ATTENDANCE', $scheduleId, [
            'candidate' => $schedule->candidate->participant_code,
        ]);

        return response()->json(['message' => 'تم تسجيل الحضور']);
    }

    public function recordAbsence(Request $request, int $scheduleId)
    {
        if (!$request->user()->hasPermission(Permissions::ATTENDANCE_RECORD)) {
            return response()->json(['error' => 'ليس لديك صلاحية التسجيل'], 403);
        }

        $schedule = Schedule::with('candidate')->find($scheduleId);
        if (!$schedule) {
            return response()->json(['error' => 'الجدول غير موجود'], 404);
        }

        if (!in_array($schedule->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_ATTENDANCE_CLASSIFIED', $scheduleId);
            return response()->json(['error' => 'الجدول غير موجود'], 404);
        }

        if (!$this->canRecordFor($request, $schedule)) {
            $this->log($request, 'DENIED_ATTENDANCE_NOT_ASSIGNED', $scheduleId);
            return response()->json(['error' => 'هذه الجلسة ليست مُسنَدة لك'], 403);
        }

        if ($schedule->schedule_date->toDateString() !== now()->toDateString()) {
            return response()->json(['error' => 'لا يمكن تسجيل الغياب إلا لجلسات اليوم'], 422);
        }
        if (Attendance::where('schedule_id', $scheduleId)->exists()) {
            return response()->json(['error' => 'تم تسجيل حالة هذه الجلسة مسبقاً'], 422);
        }

        $validated = $request->validate([
            'excused' => 'required|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            Attendance::create([
                'schedule_id' => $scheduleId,
                'status' => $validated['excused'] ? 'absent_excused' : 'absent_unexcused',
                'absence_reason' => $validated['reason'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json(['error' => 'تم تسجيل حالة هذه الجلسة مسبقاً'], 422);
            }
            throw $e;
        }

        $this->log($request, 'RECORD_ABSENCE', $scheduleId, [
            'candidate' => $schedule->candidate->participant_code,
            'excused' => $validated['excused'],
        ]);

        return response()->json(['message' => 'تم تسجيل الغياب']);
    }
}
