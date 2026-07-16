<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FinalReport;
use App\Models\Role;
use App\Models\WorkflowStage;
use App\Security\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  إعدادات سير العمل — ترتيب مراحل الاعتماد وتفعيلها.
//
//  المشرف يعيد الترتيب ويُفعّل/يُعطّل. لا يخترع مراحل: المفردات يفرضها
//  قيد القاعدة على final_reports.status.
// ════════════════════════════════════════════════════════════

class WorkflowController extends Controller
{
    private function log(Request $request, string $action, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'workflow',
            'entity_id' => '0',
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    // GET /workflow/report — السلسلة الحالية + عدد التقارير العالقة في كل مرحلة
    public function show(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $counts = FinalReport::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $roles = Role::pluck('name_ar', 'code');

        $stages = WorkflowStage::where('workflow', WorkflowStage::REPORT)
            ->orderBy('position')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'position' => $s->position,
                'statusKey' => $s->status_key,
                'roleCode' => $s->role_code,
                'roleName' => $roles[$s->role_code] ?? $s->role_code,
                'permission' => $s->permission,
                'label' => $s->label,
                'isActive' => $s->is_active,
                // قواعد المرحلة على كاتب التقرير — تُعرض ليعرف المشرف لماذا يُمنع اعتماد
                'blocksSelfAuthored' => $s->blocks_self_authored,
                'requiresTeamAuthorship' => $s->requires_team_authorship,
                // تقارير عالقة هنا — تمنع تعطيل المرحلة
                'reportsHere' => (int) ($counts[$s->status_key] ?? 0),
            ]);

        return response()->json([
            'workflow' => 'report',
            'stages' => $stages,
            // المسار الفعلي كما يراه المحرّك — يُقرأ من نفس الدالّة التي تعتمد
            'activeChain' => WorkflowStage::chain()->pluck('label'),
        ]);
    }

    // PUT /workflow/report — إعادة ترتيب/تفعيل
    public function update(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $validated = $request->validate([
            'stages' => 'required|array|min:1',
            'stages.*.id' => 'required|integer|exists:workflow_stages,id',
            'stages.*.position' => 'required|integer|min:1',
            'stages.*.isActive' => 'required|boolean',
        ]);

        $incoming = collect($validated['stages'])->keyBy('id');
        $existing = WorkflowStage::where('workflow', WorkflowStage::REPORT)->get();

        // الطلب يشمل السلسلة كاملة — تعديل جزئي يترك مواضع متضاربة
        if ($incoming->count() !== $existing->count()) {
            return response()->json(['error' => 'يجب إرسال المراحل كاملة'], 422);
        }

        // مرحلة واحدة مفعّلة على الأقل — سلسلة فارغة تعني تقريراً لا يُعتمد أبداً
        if (!$incoming->contains(fn ($s) => $s['isActive'])) {
            return response()->json([
                'errors' => ['stages' => ['يجب تفعيل مرحلة واحدة على الأقل — وإلا تعذّر اعتماد أي تقرير']],
            ], 422);
        }

        // المواضع فريدة — تكرارها يجعل الترتيب غير محدّد
        $positions = $incoming->pluck('position');
        if ($positions->unique()->count() !== $positions->count()) {
            return response()->json(['errors' => ['stages' => ['ترتيب المراحل مكرّر']]], 422);
        }

        // تعطيل مرحلة فيها تقارير يعلّقها: حالتها تبقى في القاعدة بلا مرحلة
        // مفعّلة تعتمدها. نمنعه بدل تحريك تقارير أحدٍ من تحته.
        $blocked = [];
        foreach ($existing as $s) {
            $want = $incoming[$s->id];
            $turningOff = $s->is_active && !$want['isActive'];
            if (!$turningOff) {
                continue;
            }
            $here = FinalReport::where('status', $s->status_key)->count();
            if ($here > 0) {
                $blocked[] = "«{$s->label}» فيها {$here} تقرير";
            }
        }
        if ($blocked) {
            return response()->json([
                'errors' => ['stages' => [
                    'لا يمكن تعطيل مرحلة فيها تقارير: ' . implode('، ', $blocked)
                    . '. حرّكها أولاً ثم عطّل المرحلة.',
                ]],
            ], 422);
        }

        $before = WorkflowStage::chain()->pluck('status_key')->all();

        DB::transaction(function () use ($existing, $incoming) {
            foreach ($existing as $s) {
                $want = $incoming[$s->id];
                $s->position = $want['position'];
                $s->is_active = $want['isActive'];
                $s->save();
            }
        });

        $after = WorkflowStage::chain()->pluck('status_key')->all();

        // تغيير سلسلة الاعتماد قرار حوكمي — يُدوَّن بحالتيه قبل وبعد
        $this->log($request, 'UPDATE_WORKFLOW', ['before' => $before, 'after' => $after]);

        return response()->json([
            'message' => 'تم حفظ سير العمل',
            'activeChain' => WorkflowStage::chain()->pluck('label'),
        ]);
    }
}
