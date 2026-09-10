<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\GateManifest;
use App\Models\Schedule;
use App\Security\Permissions;
use App\Services\GateManifestService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  بيان تصاريح الدخول — يومٌ واحد، وورقةٌ واحدة، واعتمادٌ قبل الطباعة.
//
//  ── من يُعدّ ليس من يأذن ──
//  البيان يفتح باب المركز لأسماءٍ بأرقام هوياتهم. فالاستقبال يجهّزه لأنه من
//  يعرف من سيحضر، ومدير المركز يعتمده لأنه من يأذن بإخراج الأسماء. ولا يخرج
//  بلا اعتماد.
//
//  ── وصفوفُه تُثبَّت لا تُشتقّ ──
//  لو قُرئت من الجلسات وقت الطباعة لتغيّر البيان بعد اعتماده: جلسةٌ تُضاف
//  فيدخل من لم يُعتمَد اسمُه. تُلتقَط حين يُجهَّز، والاعتماد يقع على ما التُقط.
// ════════════════════════════════════════════════════════════

class GateManifestController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    private function audit(Request $request, string $action, $id, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'gate_manifest',
            'entity_id' => (string) $id,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function denyView(Request $request): ?JsonResponse
    {
        $u = $request->user();

        return $u->hasPermission(Permissions::GATE_MANIFEST_MANAGE)
            || $u->hasPermission(Permissions::GATE_MANIFEST_APPROVE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية بيان تصاريح الدخول'], 403);
    }

    // GET /gate-manifests?date=
    public function show(Request $request)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $validated = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $validated['date'] ?? now()->toDateString();

        $manifest = GateManifest::with(['candidates.sector', 'preparedBy:id,full_name', 'approvedBy:id,full_name'])
            ->whereDate('manifest_date', $date)->first();

        return response()->json([
            'date' => $date,
            'manifest' => $manifest ? $this->payload($manifest) : null,
            // المرشَّحون لليوم — من له جلسةٌ فيه. يُعرَضون قبل الإعداد كي
            // يُعرَف عددُ من سيدخل قبل أن يُثبَّت
            'scheduledCount' => $this->scheduledFor($request, $date)->count(),
            'canManage' => $request->user()->hasPermission(Permissions::GATE_MANIFEST_MANAGE),
            'canApprove' => $request->user()->hasPermission(Permissions::GATE_MANIFEST_APPROVE),
        ]);
    }

    private function payload(GateManifest $m): array
    {
        return [
            'id' => $m->id,
            'date' => $m->manifest_date->toDateString(),
            'gateTime' => $m->gate_time,
            'location' => $m->location,
            'status' => $m->status,
            'statusLabel' => GateManifest::statusLabel($m->status),
            'note' => $m->note,
            'editable' => $m->isEditable(),
            'preparedBy' => $m->preparedBy?->full_name,
            'approvedBy' => $m->approvedBy?->full_name,
            'approvedAt' => $m->approved_at?->toIso8601String(),
            'count' => $m->candidates->count(),
            'rows' => $m->candidates->map(fn (Candidate $c) => GateManifest::rowFor($c))->values(),
        ];
    }

    /** من له جلسةٌ في هذا اليوم — ضمن نطاق الطالب */
    private function scheduledFor(Request $request, string $date)
    {
        $user = $request->user();

        return Candidate::whereIn('id', Schedule::whereDate('schedule_date', $date)->select('candidate_id'))
            ->whereIn('classification', $this->allowedClassifications($request))
            ->when($user->isSectorBound(), fn ($q) => $q->whereIn('sector_id', $user->sectorIds()))
            ->with('sector')
            ->get();
    }

    // POST /gate-manifests — الاستقبال يجهّز
    public function store(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::GATE_MANIFEST_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إعداد البيان'], 403);
        }

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            // الموعد يضعه الموظّف ليتّفق مع خطاب القطاع — لا يُشتقّ من أبكر
            // جلسة: الخطاب يقول «الثامنة والنصف» والجلسة ٠٩:٠٠
            'gateTime' => 'required|string|max:60',
            'location' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:300',
        ], [
            'gateTime.required' => 'اكتب موعد الحضور عند البوّابة — ليتّفق مع خطاب القطاع',
        ]);

        if (GateManifest::whereDate('manifest_date', $validated['date'])->exists()) {
            return response()->json(['error' => 'يوجد بيانٌ لهذا اليوم — عدّله أو احذفه'], 422);
        }

        $people = $this->scheduledFor($request, $validated['date']);
        if ($people->isEmpty()) {
            return response()->json([
                'error' => 'لا مجدولين في هذا اليوم — لا بيان بلا أسماء',
            ], 422);
        }

        $manifest = DB::transaction(function () use ($validated, $people, $request) {
            $m = GateManifest::create([
                'manifest_date' => $validated['date'],
                'gate_time' => $validated['gateTime'],
                'location' => $validated['location'] ?? null,
                'note' => $validated['note'] ?? null,
                'status' => GateManifest::DRAFT,
                'prepared_by' => $request->user()->id,
            ]);
            $m->candidates()->sync($people->pluck('id')->all());

            return $m;
        });

        $this->audit($request, 'CREATE_GATE_MANIFEST', $manifest->id, [
            'date' => $validated['date'], 'count' => $people->count(),
        ]);

        return response()->json([
            'message' => 'أُعدّ البيان — راجِعه ثم أرسِله للاعتماد',
            'manifest' => $this->payload($manifest->fresh(['candidates.sector', 'preparedBy'])),
        ], 201);
    }

    // POST /gate-manifests/{id}/submit — يُرسَل لمدير المركز
    public function submit(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::GATE_MANIFEST_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إعداد البيان'], 403);
        }

        $m = GateManifest::find($id);
        if (! $m) {
            return response()->json(['error' => 'البيان غير موجود'], 404);
        }
        if ($m->status !== GateManifest::DRAFT) {
            return response()->json([
                'error' => 'البيان '.GateManifest::statusLabel($m->status),
            ], 422);
        }

        $m->update(['status' => GateManifest::PENDING]);

        $this->notify->notifyPermission(
            Permissions::GATE_MANIFEST_APPROVE,
            'approval',
            'بيان تصاريح دخول بانتظار الاعتماد',
            'بيان يوم '.$m->manifest_date->toDateString().' — '.$m->candidates()->count().' اسماً',
            'gate_manifest',
            (string) $m->id,
            $request->user()->id,
        );

        $this->audit($request, 'SUBMIT_GATE_MANIFEST', $m->id);

        return response()->json(['message' => 'أُرسل البيان لمدير المركز', 'status' => $m->status]);
    }

    // POST /gate-manifests/{id}/approve — مدير المركز يعتمد
    public function approve(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::GATE_MANIFEST_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية اعتماد البيان'], 403);
        }

        $m = GateManifest::find($id);
        if (! $m) {
            return response()->json(['error' => 'البيان غير موجود'], 404);
        }
        if ($m->status !== GateManifest::PENDING) {
            return response()->json([
                'error' => 'لا يُعتمد إلا بيانٌ مُرسَل للاعتماد',
            ], 422);
        }

        $m->update([
            'status' => GateManifest::APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        if ($m->prepared_by) {
            $this->notify->notify(
                $m->prepared_by, 'approval',
                'اعتُمد بيان تصاريح الدخول',
                'بيان يوم '.$m->manifest_date->toDateString().' — يمكن طباعته الآن',
                'gate_manifest', (string) $m->id, $request->user()->id,
            );
        }

        $this->audit($request, 'APPROVE_GATE_MANIFEST', $m->id, [
            'date' => $m->manifest_date->toDateString(),
            'count' => $m->candidates()->count(),
        ]);

        return response()->json([
            'message' => 'اعتُمد البيان',
            'manifest' => $this->payload($m->fresh(['candidates.sector', 'preparedBy', 'approvedBy'])),
        ]);
    }

    // DELETE /gate-manifests/{id}
    public function destroy(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::GATE_MANIFEST_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إعداد البيان'], 403);
        }

        $m = GateManifest::find($id);
        if (! $m) {
            return response()->json(['error' => 'البيان غير موجود'], 404);
        }
        // المعتمَد لا يُحذف — أُذِن به، وأثرُ الإذن يبقى
        if ($m->isApproved()) {
            return response()->json(['error' => 'بيانٌ معتمَد لا يُحذف'], 422);
        }

        $this->audit($request, 'DELETE_GATE_MANIFEST', $m->id, [
            'date' => $m->manifest_date->toDateString(),
        ]);
        $m->delete();

        return response()->json(['message' => 'حُذف البيان']);
    }

    // GET /gate-manifests/{id}/document — الورقة نفسها
    public function document(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::GATE_MANIFEST_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية طباعة البيان'], 403);
        }

        $m = GateManifest::with(['candidates.sector', 'approvedBy:id,full_name'])->find($id);
        if (! $m) {
            return response()->json(['error' => 'البيان غير موجود'], 404);
        }
        // ── ولا يخرج بيانٌ غير معتمَد ──
        // الورقة تفتح باب المركز، والاعتماد هو الإذن. وطباعةُ مسوّدةٍ تجعل
        // البيان يخرج قبل أن يأذن به أحد.
        if (! $m->isApproved()) {
            return response()->json([
                'error' => 'لا يُطبع بيانٌ غير معتمَد — '.GateManifest::statusLabel($m->status),
            ], 422);
        }

        // إخراج الأسماء وأرقام الهويات يُدقَّق في كل مرّة — كنظيره في كشف الحضور
        $this->audit($request, 'PRINT_GATE_MANIFEST', $m->id, [
            'date' => $m->manifest_date->toDateString(),
            'count' => $m->candidates->count(),
        ]);

        return response((new GateManifestService)->renderHtml($m))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
