<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\PostponementRequest;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Security\Permissions;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  طلبات التأجيل — الاستقبال يرفع، ومسؤول الجدولة يبتّ.
//
//  ── لماذا انقسم الفعل ──
//  الفترة المعتمَدة مقفلةٌ على الاستقبال، ومن يفكّها صاحبها. والاستقبال هو
//  من يرى المانع بعينه — مشاركٌ أمامه لا يستطيع إتمام محطّته — فيرفعه بسببه،
//  ولا يؤجّل بيده. ولو أجّل لصار كلُّ ازدحامٍ في يومٍ تأجيلاً، وضاعت الموجة
//  التي بُنيت واعتُمدت.
//
//  ── والقرارات أربعة لا اثنان ──
//  القبولُ وحده يترك المشارك معلّقاً بلا موعد. وإعادةُ الجدولة تُنشئ له
//  موعداً في القرار نفسه. والإرجاعُ يعيده إلى محطّته الناقصة بلا تاريخ —
//  يُجدوَل في موجةٍ قادمة. والرفضُ يُبقيه على موعده.
// ════════════════════════════════════════════════════════════

class PostponementController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    private function audit(Request $request, string $action, int $id, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'postponement',
            'entity_id' => (string) $id,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function denyView(Request $request): ?JsonResponse
    {
        $u = $request->user();

        return $u->hasPermission(Permissions::POSTPONE_REQUEST)
            || $u->hasPermission(Permissions::POSTPONE_DECIDE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية طلبات التأجيل'], 403);
    }

    // GET /postponements?status=
    public function index(Request $request)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'status' => 'nullable|in:'.implode(',', array_merge(
                [PostponementRequest::PENDING], PostponementRequest::DECISIONS
            )),
            'candidateId' => 'nullable|integer',
        ]);

        $user = $request->user();

        $rows = PostponementRequest::with([
            'candidate.sector', 'assessment', 'schedule',
            'requestedBy:id,full_name', 'decidedBy:id,full_name',
        ])
            // المعلّقة أوّلاً افتراضاً — الشاشة أداةُ بتٍّ لا سجلّ
            ->when(! empty($validated['status']),
                fn ($q) => $q->where('status', $validated['status']),
                fn ($q) => $q->where('status', PostponementRequest::PENDING))
            ->when(! empty($validated['candidateId']),
                fn ($q) => $q->where('candidate_id', $validated['candidateId']))
            ->whereHas('candidate', function ($c) use ($request, $user) {
                $c->whereIn('classification', $this->allowedClassifications($request));
                if ($user->isSectorBound()) {
                    $c->whereIn('sector_id', $user->sectorIds());
                }
            })
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (PostponementRequest $r) => $this->payload($r));

        return response()->json([
            'requests' => $rows,
            'canDecide' => $user->hasPermission(Permissions::POSTPONE_DECIDE),
            'pendingCount' => PostponementRequest::where('status', PostponementRequest::PENDING)->count(),
        ]);
    }

    private function payload(PostponementRequest $r): array
    {
        return [
            'id' => $r->id,
            'candidateId' => $r->candidate_id,
            'participantCode' => $r->assessment?->participant_code ?? $r->candidate?->participant_code,
            'sector' => $r->candidate?->sector?->name_ar,
            'scheduleId' => $r->schedule_id,
            'sessionDate' => $r->schedule?->schedule_date?->toDateString(),
            'station' => $r->station,
            'stationLabel' => $r->station ? Assessment::stationLabel($r->station) : null,
            'reason' => $r->reason,
            'status' => $r->status,
            'statusLabel' => PostponementRequest::statusLabel($r->status),
            'decisionNote' => $r->decision_note,
            'newDate' => $r->new_date?->toDateString(),
            'requestedBy' => $r->requestedBy?->full_name,
            'requestedAt' => $r->created_at?->toIso8601String(),
            'decidedBy' => $r->decidedBy?->full_name,
            'decidedAt' => $r->decided_at?->toIso8601String(),
        ];
    }

    // POST /postponements — الاستقبال يرفع
    public function store(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::POSTPONE_REQUEST)) {
            return response()->json(['error' => 'ليس لديك صلاحية رفع طلب تأجيل'], 403);
        }

        $validated = $request->validate([
            'scheduleId' => 'required|integer|exists:schedules,id',
            // السبب إلزاميّ: الطلب كلُّه سببٌ يُقرأ عند البتّ، وطلبٌ بلا سبب
            // يُحيل القرار إلى تخمين
            'reason' => 'required|string|min:3|max:500',
        ], [
            'reason.required' => 'اكتب سبب التأجيل — عليه يُبنى قرار مسؤول الجدولة',
        ]);

        $schedule = Schedule::with('candidate', 'assessment')->find($validated['scheduleId']);
        if (! $schedule || ! $this->resolveCandidateInScope($request, $schedule->candidate_id)) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }

        // طلبٌ معلّقٌ قائم: رفعُ ثانٍ يجعل البتّ في أحدهما لا يعني شيئاً للآخر
        $open = PostponementRequest::where('schedule_id', $schedule->id)
            ->where('status', PostponementRequest::PENDING)->first();
        if ($open) {
            return response()->json([
                'error' => 'يوجد طلب تأجيل معلّق لهذه الجلسة — انتظر البتّ فيه',
            ], 422);
        }

        $req = PostponementRequest::create([
            'candidate_id' => $schedule->candidate_id,
            'assessment_id' => $schedule->assessment_id,
            'schedule_id' => $schedule->id,
            'station' => $schedule->activity,
            'reason' => $validated['reason'],
            'status' => PostponementRequest::PENDING,
            'requested_by' => $request->user()->id,
        ]);

        // ── ويصل صاحبَ القرار ──
        // طلبٌ لا يعلم به من يبتّ فيه ينتظر إلى أن يُفتَح السجلّ صدفةً
        $this->notify->notifyPermission(
            Permissions::POSTPONE_DECIDE,
            'approval',
            'طلب تأجيل جديد',
            'رُفع طلب تأجيل لـ'.Assessment::stationLabel($schedule->activity)
                .' — '.($schedule->assessment?->participant_code ?? 'مشارك'),
            'postponement',
            (string) $req->id,
            $request->user()->id,
        );

        $this->audit($request, 'CREATE_POSTPONEMENT', $req->id, [
            'code' => $schedule->assessment?->participant_code,
            'station' => $schedule->activity,
        ]);

        return response()->json([
            'message' => 'رُفع طلب التأجيل',
            'request' => $this->payload($req->fresh(['candidate.sector', 'assessment', 'schedule', 'requestedBy'])),
        ], 201);
    }

    // POST /postponements/{id}/decide — مسؤول الجدولة يبتّ
    public function decide(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::POSTPONE_DECIDE)) {
            return response()->json(['error' => 'ليس لديك صلاحية البتّ في طلبات التأجيل'], 403);
        }

        $req = PostponementRequest::with('schedule', 'candidate', 'assessment')->find($id);
        if (! $req || ! $this->resolveCandidateInScope($request, $req->candidate_id)) {
            return response()->json(['error' => 'الطلب غير موجود'], 404);
        }
        if (! $req->isPending()) {
            return response()->json([
                'error' => 'بُتَّ في هذا الطلب: '.PostponementRequest::statusLabel($req->status),
            ], 422);
        }

        $validated = $request->validate([
            'decision' => 'required|in:'.implode(',', PostponementRequest::DECISIONS),
            // التاريخ لازمٌ لإعادة الجدولة وحدها — «أُعيدت جدولتها» بلا تاريخ
            // لا تعني شيئاً، والمشارك يبقى معلّقاً وقد كُتب أنه جُدول
            'newDate' => 'required_if:decision,rescheduled|nullable|date_format:Y-m-d|after_or_equal:today',
            // الرفض يلزمه تعليل: من رُفض طلبُه يسأل لماذا، ومن رفعه يعتذر
            // للمشارك — وبلا سببٍ مكتوب يُعتذَر بلا شيء
            'note' => 'required_if:decision,rejected|nullable|string|max:500',
        ], [
            'newDate.required_if' => 'اكتب التاريخ الجديد — إعادة جدولةٍ بلا تاريخ لا تعني شيئاً',
            'newDate.after_or_equal' => 'التاريخ الجديد في الماضي',
            'note.required_if' => 'اكتب سبب الرفض — من رفع الطلب يعتذر به للمشارك',
        ]);

        $decision = $validated['decision'];
        $newScheduleId = null;

        DB::transaction(function () use ($req, $decision, $validated, $request, &$newScheduleId) {
            // إعادة الجدولة تُنشئ الموعد في القرار نفسه — لا تترك المشارك
            // معلّقاً ينتظر خطوةً ثانية قد تُنسى
            if ($decision === PostponementRequest::RESCHEDULED && $req->schedule) {
                $old = $req->schedule;
                $fresh = Schedule::create([
                    'candidate_id' => $old->candidate_id,
                    'assessment_id' => $old->assessment_id,
                    'period_id' => SchedulingPeriod::coveringDate($validated['newDate'])?->id,
                    'schedule_date' => $validated['newDate'],
                    'schedule_time' => $old->schedule_time,
                    'activity' => $old->activity,
                    'evaluator_id' => $old->evaluator_id,
                    'location' => $old->location,
                ]);
                $newScheduleId = $fresh->id;
                Assessment::refreshDatesFor($old->assessment_id);
            }

            $req->update([
                'status' => $decision,
                'decision_note' => $validated['note'] ?? null,
                'new_date' => $validated['newDate'] ?? null,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);
        });

        // ويصل الطالبَ خبرُ قراره — هو من يواجه المشارك
        if ($req->requested_by) {
            $this->notify->notify(
                $req->requested_by,
                // 'return' لا 'warning': المفردات محروسةٌ في الخدمة، والرفضُ
                // إرجاعٌ لصاحب الطلب يعتذر به — وهو معنى النوع نفسه
                $decision === PostponementRequest::REJECTED ? 'return' : 'info',
                'بُتَّ في طلب التأجيل: '.PostponementRequest::statusLabel($decision),
                ($req->assessment?->participant_code ?? 'المشارك').' — '
                    .PostponementRequest::statusLabel($decision)
                    .($validated['newDate'] ?? null ? ' إلى '.$validated['newDate'] : '')
                    .($validated['note'] ?? null ? '. '.$validated['note'] : ''),
                'postponement',
                (string) $req->id,
                $request->user()->id,
            );
        }

        $this->audit($request, 'DECIDE_POSTPONEMENT', $req->id, [
            'decision' => $decision,
            'newDate' => $validated['newDate'] ?? null,
            'newScheduleId' => $newScheduleId,
        ]);

        return response()->json([
            'message' => PostponementRequest::statusLabel($decision),
            'newScheduleId' => $newScheduleId,
            'request' => $this->payload($req->fresh([
                'candidate.sector', 'assessment', 'schedule', 'requestedBy', 'decidedBy',
            ])),
        ]);
    }
}
