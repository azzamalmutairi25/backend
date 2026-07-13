<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\DevelopmentPlanItem;
use App\Models\FinalReport;
use App\Models\AuditLog;
use App\Security\Permissions;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  خطة التطوير الفردية — بنود متابعة مشتقّة من مجالات التطوير
// ════════════════════════════════════════════════════════════

class DevelopmentPlanController extends Controller
{
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
            'entity_type' => 'development_plan',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    // يحلّ المرشّح ضمن التصنيف المسموح (مصنّف خارج الصلاحية = «غير موجود»)
    private function resolveCandidate(Request $request, int $candidateId): ?Candidate
    {
        return Candidate::whereIn('classification', $this->allowedClassifications($request))->find($candidateId);
    }

    private function present(DevelopmentPlanItem $i): array
    {
        return [
            'id' => $i->id,
            'area' => $i->area,
            'action' => $i->action,
            'targetDate' => $i->target_date ? substr((string) $i->target_date, 0, 10) : null,
            'status' => $i->status,
            'completedAt' => optional($i->completed_at)->toIso8601String(),
        ];
    }

    // GET /development-plans/{candidateId} — بنود الدورة الحالية
    public function index(Request $request, int $candidateId)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض خطة التطوير'], 403);
        }
        $candidate = $this->resolveCandidate($request, $candidateId);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $assessment = $candidate->assessments()->orderByDesc('id')->first();
        $items = $assessment
            ? DevelopmentPlanItem::where('candidate_id', $candidateId)
                ->where('assessment_id', $assessment->id)->orderBy('id')->get()
            : collect();

        return response()->json(['items' => $items->map(fn ($i) => $this->present($i))->values()]);
    }

    // POST /development-plans — إضافة بند
    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة خطة التطوير'], 403);
        }
        $validated = $request->validate([
            'candidateId' => 'required|integer',
            'area' => 'required|string|max:500',
            'action' => 'nullable|string|max:1000',
            'targetDate' => 'nullable|date',
        ]);
        $candidate = $this->resolveCandidate($request, $validated['candidateId']);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $assessment = $candidate->assessments()->orderByDesc('id')->first();
        if (!$assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم لهذا المرشح'], 422);
        }

        $item = DevelopmentPlanItem::create([
            'candidate_id' => $candidate->id,
            'assessment_id' => $assessment->id,
            'area' => $validated['area'],
            'action' => $validated['action'] ?? null,
            'target_date' => $validated['targetDate'] ?? null,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        $this->log($request, 'CREATE_DEV_ITEM', $item->id, ['candidate' => $candidate->participant_code]);

        return response()->json(['message' => 'تمت إضافة البند', 'item' => $this->present($item)], 201);
    }

    // PUT /development-plan-items/{id} — تحديث الإجراء/الموعد/الحالة (متابعة)
    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة خطة التطوير'], 403);
        }
        $item = DevelopmentPlanItem::with('candidate')->find($id);
        if (!$item || !in_array($item->candidate->classification, $this->allowedClassifications($request), true)) {
            return response()->json(['error' => 'البند غير موجود'], 404);
        }
        $validated = $request->validate([
            'action' => 'nullable|string|max:1000',
            'targetDate' => 'nullable|date',
            'status' => 'nullable|in:pending,in_progress,done',
        ]);

        if (array_key_exists('action', $validated))     { $item->action = $validated['action']; }
        if (array_key_exists('targetDate', $validated))  { $item->target_date = $validated['targetDate']; }
        if (!empty($validated['status'])) {
            $item->status = $validated['status'];
            $item->completed_at = $validated['status'] === 'done' ? now() : null;
        }
        $item->save();

        $this->log($request, 'UPDATE_DEV_ITEM', $item->id, ['status' => $item->status]);

        return response()->json(['message' => 'تم تحديث البند', 'item' => $this->present($item)]);
    }

    // DELETE /development-plan-items/{id}
    public function destroy(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة خطة التطوير'], 403);
        }
        $item = DevelopmentPlanItem::with('candidate')->find($id);
        if (!$item || !in_array($item->candidate->classification, $this->allowedClassifications($request), true)) {
            return response()->json(['error' => 'البند غير موجود'], 404);
        }
        $item->delete();
        $this->log($request, 'DELETE_DEV_ITEM', $id);

        return response()->json(['message' => 'تم حذف البند']);
    }

    // POST /development-plans/seed — توليد بنود من «مجالات التطوير» في تقرير الدورة
    public function seed(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::REPORT_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة خطة التطوير'], 403);
        }
        $validated = $request->validate(['candidateId' => 'required|integer']);
        $candidate = $this->resolveCandidate($request, $validated['candidateId']);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        $assessment = $candidate->assessments()->orderByDesc('id')->first();
        $report = $assessment ? FinalReport::where('assessment_id', $assessment->id)->first() : null;
        $areas = $report ? ($report->development_areas ?? []) : [];
        if (empty($areas)) {
            return response()->json(['error' => 'لا توجد مجالات تطوير في تقرير هذه الدورة'], 422);
        }

        // لا نُكرّر بنداً موجوداً لنفس المجال في هذه الدورة
        $existing = DevelopmentPlanItem::where('candidate_id', $candidate->id)
            ->where('assessment_id', $assessment->id)->pluck('area')->all();
        $created = 0;
        // array_unique يمنع تكرار المجال المتطابق داخل نفس التشغيل، والإلحاق بـ $existing تحصينٌ إضافي
        foreach (array_unique($areas) as $area) {
            if (in_array($area, $existing, true)) continue;
            DevelopmentPlanItem::create([
                'candidate_id' => $candidate->id,
                'assessment_id' => $assessment->id,
                'area' => $area,
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);
            $existing[] = $area;
            $created++;
        }

        $this->log($request, 'SEED_DEV_PLAN', $candidate->id, ['created' => $created]);

        return response()->json(['message' => "تمت إضافة {$created} بنداً", 'created' => $created]);
    }
}
