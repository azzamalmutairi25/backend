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

        $rows = Schedule::with(['candidate.sector', 'attendance'])
            ->whereDate('schedule_date', $today)
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
            ->get()
            ->map(function ($sch) {
                $att = $sch->attendance; // eager-loaded — لا N+1
                return [
                    'id' => $sch->id,
                    'participantCode' => $sch->candidate->participant_code,
                    'sectorName' => $sch->candidate->sector->name_ar,
                    'activity' => $sch->activity,
                    'status' => $att?->status ?? 'pending',
                    'checkInTime' => $att?->check_in_time?->format('H:i'),
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
        $scheduleIds = Schedule::whereDate('schedule_date', $today)
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed))
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
            return response()->json(['error' => 'هذا المرشح مصنّف، وليس لديك صلاحية'], 403);
        }

        if ($schedule->schedule_date->toDateString() !== now()->toDateString()) {
            return response()->json(['error' => 'لا يمكن تسجيل الحضور إلا لجلسات اليوم'], 422);
        }
        if (Attendance::where('schedule_id', $scheduleId)->exists()) {
            return response()->json(['error' => 'تم تسجيل حالة هذه الجلسة مسبقاً'], 422);
        }

        Attendance::create([
            'schedule_id' => $scheduleId,
            'status' => 'present',
            'check_in_time' => now(),
            'recorded_by' => $request->user()->id,
        ]);

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
            return response()->json(['error' => 'هذا المرشح مصنّف، وليس لديك صلاحية'], 403);
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

        Attendance::create([
            'schedule_id' => $scheduleId,
            'status' => $validated['excused'] ? 'absent_excused' : 'absent_unexcused',
            'absence_reason' => $validated['reason'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        $this->log($request, 'RECORD_ABSENCE', $scheduleId, [
            'candidate' => $schedule->candidate->participant_code,
            'excused' => $validated['excused'],
        ]);

        return response()->json(['message' => 'تم تسجيل الغياب']);
    }
}
