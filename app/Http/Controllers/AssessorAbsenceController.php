<?php

namespace App\Http\Controllers;

use App\Models\AssessorAbsence;
use App\Models\AuditLog;
use App\Models\SchedulingPeriod;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  إجازات المستشارين — مدىً وسبباً.
//
//  من في إجازةٍ يسقط اسمه من قوائم الإسناد في تلك الأيام وحدها، ولا يُشطب
//  من الفترة كلّها. **ولا بديل يُعيَّن**: مسؤول الجدولة يعيد توزيع الأعداد
//  في الشبكة بنفسه — تعيينُ بديلٍ آليّاً يفترض أن حصّةً تنتقل كما هي، وهي
//  في الواقع تُقسَّم أو تُؤجَّل أو تسقط.
// ════════════════════════════════════════════════════════════

class AssessorAbsenceController extends Controller
{
    private function deny(Request $request): ?JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
    }

    private function audit(Request $request, string $action, int $id, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'assessor_absence',
            'entity_id' => (string) $id,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    // GET /assessor-absences?periodId=&userId=
    public function index(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'periodId' => 'nullable|integer',
            'userId' => 'nullable|integer',
        ]);

        $rows = AssessorAbsence::with(['user:id,full_name,code', 'host:id,full_name,code'])
            // الفترة تُفلتَر بالتداخل لا بالمساواة: إجازةٌ سُجّلت بلا فترة
            // تنطبق على كل فترةٍ يقع مداها فيها
            ->when(! empty($validated['periodId']), function ($q) use ($validated) {
                $period = SchedulingPeriod::find($validated['periodId']);
                if (! $period) {
                    return;
                }
                $q->whereDate('from_date', '<=', $period->end_date)
                    ->whereDate('to_date', '>=', $period->start_date);
            })
            ->when(! empty($validated['userId']), fn ($q) => $q->where('user_id', $validated['userId']))
            ->orderBy('from_date')
            ->limit(500)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'userId' => $a->user_id,
                'userName' => $a->user?->full_name,
                'userCode' => $a->user?->code,
                'fromDate' => $a->from_date?->toDateString(),
                'toDate' => $a->to_date?->toDateString(),
                'reason' => $a->reason,
                'reasonLabel' => AssessorAbsence::reasonLabel($a->reason),
                'hostCode' => $a->host?->code,
                'hostName' => $a->host?->full_name,
                'note' => $a->note,
            ]);

        return response()->json(['absences' => $rows]);
    }

    // POST /assessor-absences
    public function store(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'userId' => 'required|integer|exists:users,id',
            'periodId' => 'nullable|integer|exists:scheduling_periods,id',
            'fromDate' => 'required|date_format:Y-m-d',
            'toDate' => 'required|date_format:Y-m-d|after_or_equal:fromDate',
            'reason' => 'required|in:'.implode(',', AssessorAbsence::REASONS),
            // المضيف للتدريب وحده: «يرافق فلاناً» لا معنى لها في إجازة
            'hostUserId' => 'nullable|integer|exists:users,id',
            'note' => 'nullable|string|max:300',
        ], [
            'toDate.after_or_equal' => 'تاريخ النهاية قبل تاريخ البداية',
        ]);

        // تداخلٌ مع غيابٍ مسجَّل: مدىً يغطّي مدىً يجعل الشبكة تقول شيئين عن
        // اليوم نفسه، ولا يُعرف أيّهما السبب المعروض
        $overlap = AssessorAbsence::where('user_id', $validated['userId'])
            ->whereDate('from_date', '<=', $validated['toDate'])
            ->whereDate('to_date', '>=', $validated['fromDate'])
            ->first();
        if ($overlap) {
            return response()->json([
                'error' => 'يتداخل مع غيابٍ مسجَّل ('
                    .$overlap->from_date->toDateString().' — '.$overlap->to_date->toDateString().')',
            ], 422);
        }

        $absence = AssessorAbsence::create([
            'user_id' => $validated['userId'],
            'period_id' => $validated['periodId'] ?? null,
            'from_date' => $validated['fromDate'],
            'to_date' => $validated['toDate'],
            'reason' => $validated['reason'],
            'host_user_id' => $validated['reason'] === 'training'
                ? ($validated['hostUserId'] ?? null) : null,
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $this->audit($request, 'CREATE_ASSESSOR_ABSENCE', $absence->id, [
            'user' => User::find($validated['userId'])?->full_name,
            'from' => $validated['fromDate'],
            'to' => $validated['toDate'],
            'reason' => $validated['reason'],
        ]);

        return response()->json(['message' => 'سُجّل الغياب', 'id' => $absence->id], 201);
    }

    // DELETE /assessor-absences/{id}
    public function destroy(Request $request, int $id)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $absence = AssessorAbsence::find($id);
        if (! $absence) {
            return response()->json(['error' => 'السجلّ غير موجود'], 404);
        }

        $this->audit($request, 'DELETE_ASSESSOR_ABSENCE', $id, [
            'from' => $absence->from_date?->toDateString(),
            'to' => $absence->to_date?->toDateString(),
        ]);
        $absence->delete();

        return response()->json(['message' => 'حُذف السجلّ']);
    }
}
