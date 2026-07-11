<?php

namespace App\Http\Controllers;

use App\Models\FinalReport;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private NotificationService $notify) {}

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
            'entity_type' => 'report',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقارير'], 403);
        }

        $query = FinalReport::with('candidate.sector')
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $this->allowedClassifications($request)));

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $reports = $query->orderByDesc('created_at')->get()->map(fn ($r) => [
            'id' => $r->id,
            'participantCode' => $r->candidate->participant_code,
            'sectorName' => $r->candidate->sector->name_ar,
            'tier' => $r->candidate->tier,
            'behavioralFit' => $r->behavioral_fit,
            'technicalFit' => $r->technical_fit,
            'recommendation' => $r->recommendation,
            'status' => $r->status,
            'returnCount' => $r->return_count,
        ]);

        $this->log($request, 'VIEW_REPORTS', 0, ['count' => $reports->count()]);

        return response()->json(['reports' => $reports]);
    }

    public function stats(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض التقارير'], 403);
        }

        $allowed = $this->allowedClassifications($request);
        $base = FinalReport::whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));

        return response()->json(['stats' => [
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'pending' => (clone $base)->where('status', 'pending_dev_approval')->count(),
            'draft' => (clone $base)->where('status', 'draft')->count(),
            'returned' => (clone $base)->where('status', 'returned')->count(),
        ]]);
    }

    public function approve(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاعتماد النهائي'], 403);
        }

        $report = FinalReport::with('candidate')->findOrFail($id);

        if (!in_array($report->candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_REPORT_CLASSIFIED', $id);
            return response()->json(['error' => 'هذا التقرير لمرشح مصنّف'], 403);
        }

        if ($report->status !== 'pending_dev_approval') {
            return response()->json(['error' => 'لا يمكن اعتماد تقرير غير مُرسل للاعتماد'], 422);
        }

        $report->update(['status' => 'approved']);
        $report->candidate->setStatus('completed'); // يزامن حالة الدورة → تُتاح إعادة التقييم بدورة جديدة

        $this->log($request, 'APPROVE_REPORT', $id, ['candidate' => $report->candidate->participant_code]);

        return response()->json(['message' => 'تم اعتماد التقرير نهائياً']);
    }

    public function returnReport(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_RETURN)) {
            return response()->json(['error' => 'ليس لديك صلاحية الإرجاع'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'يجب ذكر سبب الإرجاع',
            'reason.min' => 'سبب الإرجاع قصير جداً',
        ]);

        $report = FinalReport::with('candidate')->findOrFail($id);

        if (!in_array($report->candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'هذا التقرير لمرشح مصنّف'], 403);
        }

        $report->update([
            'status' => 'returned',
            'return_reason' => $validated['reason'],
            'return_count' => $report->return_count + 1,
            'last_returned_by' => $request->user()->id,
            'last_returned_at' => now(),
        ]);

        if ($report->created_by) {
            $this->notify->notify($report->created_by, 'return',
                'أُعيد تقرير للتعديل',
                'سبب الإرجاع: ' . $validated['reason'],
                'report', (string) $id, $request->user()->id);
        }

        $thread = ChatThread::firstOrCreate(
            ['entity_type' => 'report', 'entity_id' => $id],
            ['title' => 'محادثة التقرير']
        );
        ChatMessage::create([
            'thread_id' => $thread->id,
            'sender_id' => $request->user()->id,
            'message' => 'أُعيد التقرير للتعديل. السبب: ' . $validated['reason'],
            'message_type' => 'action',
            'action_type' => 'return',
        ]);

        $this->log($request, 'RETURN_REPORT', $id, ['reason' => $validated['reason'], 'count' => $report->return_count]);

        return response()->json([
            'message' => 'تم إرجاع التقرير للتعديل',
            'returnCount' => $report->return_count,
        ]);
    }

    public function resubmit(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إعادة الإرسال'], 403);
        }

        $report = FinalReport::findOrFail($id);
        if ($report->status !== 'returned') {
            return response()->json(['error' => 'التقرير ليس في حالة إرجاع'], 400);
        }

        $report->update(['status' => 'pending_dev_approval']);

        $this->notify->notifyRole('DEV_MANAGER', 'approval',
            'تقرير معدّل بانتظار الاعتماد',
            'أُعيد إرسال تقرير بعد تعديله',
            'report', (string) $id, $request->user()->id);

        $this->log($request, 'RESUBMIT_REPORT', $id);

        return response()->json(['message' => 'تم إعادة إرسال التقرير']);
    }
}
