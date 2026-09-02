<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GoldenScheduleEntry;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Security\Permissions;
use App\Services\GoldenScheduleService;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  الجدول الذهبي — سجلُّ (التاريخ × رمز المشارك) لكل موجة
// ════════════════════════════════════════════════════════════
//
// العرض والطباعة بـ`schedule.view`، والكتابة والمزامنة بـ`schedule.manage`.
// والحصر القطاعي والتصنيفي يُطبَّق كما في كل قائمة: المحصور بقطاع لا يرى
// رموز غيره، ومن لا يملك تصريح المصنّفين لا يرى رمز مصنّف — ولا في مستندٍ
// مطبوع.
class GoldenScheduleController extends Controller
{
    public function __construct(private GoldenScheduleService $golden) {}

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'golden_schedule',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /** القطاع الذي يُقرأ به: المحصور يُشدّ إلى قطاعه مهما طلب */
    private function scopeSector(Request $request, ?int $asked): ?int
    {
        $user = $request->user();

        return $user->isSectorBound() ? $user->sector_id : $asked;
    }

    private function period(int $id): ?SchedulingPeriod
    {
        return SchedulingPeriod::find($id);
    }

    // GET /golden-schedule — شبكة الموجة
    public function index(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $validated = $request->validate([
            'periodId' => 'required|integer',
            'sectorId' => 'nullable|integer',
        ]);

        $period = $this->period($validated['periodId']);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }

        $data = $this->golden->gather(
            $period,
            $this->allowedClassifications($request),
            $this->scopeSector($request, $validated['sectorId'] ?? null)
        );

        return response()->json(array_merge($data, [
            'canManage' => $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE),
            'sectors' => $data['sectors'],
            'sectorOptions' => $request->user()->isSectorBound()
                ? Sector::whereKey($request->user()->sector_id)->get(['id', 'name_ar'])
                : Sector::orderBy('name_ar')->get(['id', 'name_ar']),
        ]));
    }

    // POST /golden-schedule/{periodId}/sync — ترحيل جلسات الموجة إلى الجدول
    public function sync(Request $request, int $periodId)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $period = $this->period($periodId);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }

        $res = $this->golden->sync($period, $request->user()->id);
        $this->log($request, 'SYNC_GOLDEN_SCHEDULE', $period->id, $res);

        return response()->json(array_merge($res, [
            'message' => 'تمت المزامنة: '.$res['created'].' صفّاً جديداً'
                .($res['keptManual'] ? '، وبقيت '.$res['keptManual'].' صفّاً يدوياً كما هي' : ''),
        ]));
    }

    // POST /golden-schedule — صفّ يدوي
    public function store(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $validated = $request->validate([
            'periodId' => 'required|integer|exists:scheduling_periods,id',
            'date' => 'required|date_format:Y-m-d',
            'participantCode' => 'required|string|max:20',
            'sectorId' => 'required|integer|exists:sectors,id',
            'note' => 'nullable|string|max:200',
        ]);

        $period = $this->period($validated['periodId']);
        if ($validated['date'] < $period->start_date->toDateString()
            || $validated['date'] > $period->end_date->toDateString()) {
            return response()->json(['error' => 'التاريخ خارج مدى الموجة'], 422);
        }

        $user = $request->user();
        if ($user->isSectorBound() && (int) $validated['sectorId'] !== $user->sector_id) {
            return response()->json(['error' => 'لا تُضيف صفّاً لقطاع غير قطاعك'], 403);
        }

        $exists = GoldenScheduleEntry::where('period_id', $period->id)
            ->whereDate('entry_date', $validated['date'])
            ->where('participant_code', $validated['participantCode'])
            ->exists();
        if ($exists) {
            return response()->json(['error' => 'هذا الرمز مسجَّل في هذا اليوم'], 409);
        }

        $entry = GoldenScheduleEntry::create([
            'period_id' => $period->id,
            'entry_date' => $validated['date'],
            'participant_code' => $validated['participantCode'],
            'sector_id' => $validated['sectorId'],
            'source' => 'manual',
            'note' => $validated['note'] ?? null,
            'added_by' => $user->id,
        ]);

        $this->log($request, 'ADD_GOLDEN_ROW', $entry->id, [
            'code' => $entry->participant_code, 'date' => $validated['date'],
        ]);

        return response()->json(['message' => 'أُضيف الصفّ', 'entryId' => $entry->id], 201);
    }

    // DELETE /golden-schedule/{id}
    public function destroy(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $user = $request->user();
        $entry = GoldenScheduleEntry::when($user->isSectorBound(),
            fn ($q) => $q->where('sector_id', $user->sector_id))->find($id);
        if (! $entry) {
            return response()->json(['error' => 'الصفّ غير موجود'], 404);
        }

        $code = $entry->participant_code;
        $entry->delete();
        $this->log($request, 'DELETE_GOLDEN_ROW', $id, ['code' => $code]);

        return response()->json(['message' => 'حُذف الصفّ']);
    }

    // GET /golden-schedule/document — المستند المطبوع
    public function document(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $validated = $request->validate([
            'periodId' => 'required|integer',
            'sectorId' => 'nullable|integer',
        ]);

        $period = $this->period($validated['periodId']);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }

        $sectorId = $this->scopeSector($request, $validated['sectorId'] ?? null);
        $data = $this->golden->gather($period, $this->allowedClassifications($request), $sectorId);

        $this->log($request, 'EXPORT_GOLDEN_SCHEDULE', $period->id, [
            'rows' => $data['total'],
            'sector' => $sectorId,
        ]);

        return response($this->golden->renderHtml($data))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
