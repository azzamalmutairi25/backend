<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\DistributionProposal;
use App\Security\Permissions;
use App\Services\DistributionService;
use Illuminate\Http\Request;

// التوزيع الأسبوعي — اقتراح واعتماد. لمسؤول الجدولة (إدارة المرشحين).
class DistributionController extends Controller
{
    public function __construct(private DistributionService $service) {}

    private function guard(Request $request): bool
    {
        return $request->user()->hasPermission(Permissions::DISTRIBUTION_MANAGE);
    }

    private function log(Request $request, string $action, int $id, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action,
            'entity_type' => 'distribution', 'entity_id' => (string) $id,
            'details' => $details ?: null, 'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }

    // GET /distribution — الاقتراح الحالي للأسبوع القادم (إن وُجد) + سياق
    public function index(Request $request)
    {
        if (!$this->guard($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية التوزيع'], 403);
        }

        $weekStart = $this->service->nextWeekStart()->toDateString();
        $proposal = DistributionProposal::with(['items.candidate.sector', 'items.evaluator'])
            ->where('week_start', $weekStart)->first();

        return response()->json([
            'weekStart' => $weekStart,
            'dailyCap' => $this->service->dailyCap(),
            'proposal' => $proposal ? $this->present($proposal) : null,
            'readyCount' => $this->readyCandidatesQuery()->count(),
        ]);
    }

    // POST /distribution/propose — يقترح توزيع الأسبوع القادم
    public function propose(Request $request)
    {
        if (!$this->guard($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية التوزيع'], 403);
        }

        $weekStart = $this->service->nextWeekStart()->toDateString();
        if (DistributionProposal::where('week_start', $weekStart)->exists()) {
            return response()->json(['error' => 'يوجد اقتراح لهذا الأسبوع — احذفه أولاً لإعادة التوزيع'], 422);
        }

        try {
            $proposal = $this->service->propose($request->user());
        } catch (\Illuminate\Database\QueryException $e) {
            // 23505: سبقنا اقتراحٌ متزامن — القيد الفريد على week_start
            if ($e->getCode() === '23505') {
                return response()->json(['error' => 'أُنشئ اقتراح لهذا الأسبوع للتو'], 422);
            }
            throw $e;
        }

        $this->log($request, 'PROPOSE_DISTRIBUTION', $proposal->id, [
            'weekStart' => $weekStart, 'items' => $proposal->items()->count(),
        ]);

        return response()->json([
            'message' => 'تم اقتراح التوزيع',
            'proposal' => $this->present($proposal->fresh(['items.candidate.sector', 'items.evaluator'])),
        ], 201);
    }

    // POST /distribution/{id}/approve — يعتمد الاقتراح ويصنع الجلسات
    public function approve(Request $request, int $id)
    {
        if (!$this->guard($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية التوزيع'], 403);
        }

        $proposal = DistributionProposal::find($id);
        if (!$proposal) {
            return response()->json(['error' => 'الاقتراح غير موجود'], 404);
        }
        if ($proposal->status !== 'draft') {
            return response()->json(['error' => 'الاقتراح مُعتمد أو مرفوض مسبقاً'], 422);
        }

        $result = $this->service->approve($proposal, $request->user());

        $this->log($request, 'APPROVE_DISTRIBUTION', $proposal->id, $result);

        return response()->json([
            'message' => $result['dropped'] > 0
                ? "تم الاعتماد — جُدوِل {$result['placed']}، وسقط {$result['dropped']} لتغيّر بياناتهم"
                : "تم اعتماد التوزيع — جُدوِل {$result['placed']} مرشّح",
            'placed' => $result['placed'],
            'dropped' => $result['dropped'],
        ]);
    }

    // DELETE /distribution/{id} — يحذف اقتراحاً مسودّة (لإعادة التوزيع)
    public function destroy(Request $request, int $id)
    {
        if (!$this->guard($request)) {
            return response()->json(['error' => 'ليس لديك صلاحية التوزيع'], 403);
        }
        $proposal = DistributionProposal::find($id);
        if (!$proposal) {
            return response()->json(['error' => 'الاقتراح غير موجود'], 404);
        }
        if ($proposal->status === 'approved') {
            return response()->json(['error' => 'لا يُحذف اقتراح مُعتمد — جلساته قائمة'], 422);
        }
        $proposal->delete();
        $this->log($request, 'DELETE_DISTRIBUTION', $id);

        return response()->json(['message' => 'تم حذف الاقتراح']);
    }

    // مرشحون جاهزون للتوزيع: معتمدون للتقييم بلا جلسة مقابلة
    private function readyCandidatesQuery()
    {
        return Candidate::where('status', 'scheduled')
            ->whereDoesntHave('assessments.schedules', fn ($q) => $q->where('activity', 'interview'));
    }

    private function present(DistributionProposal $p): array
    {
        // مجمّع لكل مقيّم — الأوضح للمراجعة قبل الاعتماد
        $byEvaluator = $p->items->groupBy('evaluator_id')->map(function ($items) {
            $ev = $items->first()->evaluator;
            return [
                'evaluatorName' => $ev?->full_name ?? '—',
                'sector' => $items->first()->sector?->name_ar,
                'count' => $items->count(),
                'items' => $items->sortBy('scheduled_date')->values()->map(fn ($i) => [
                    'code' => $i->candidate?->participant_code,
                    'date' => (string) $i->scheduled_date,
                    'dropped' => $i->drop_reason,
                ])->all(),
            ];
        })->values();

        return [
            'id' => $p->id,
            'weekStart' => (string) $p->week_start,
            'weekEnd' => (string) $p->week_end,
            'dailyCap' => $p->daily_cap,
            'status' => $p->status,
            'total' => $p->items->count(),
            'placed' => $p->placed,
            'dropped' => $p->dropped,
            'byEvaluator' => $byEvaluator,
        ];
    }
}
