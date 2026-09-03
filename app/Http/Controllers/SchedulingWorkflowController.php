<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PeriodStepProgress;
use App\Models\SchedulingPeriod;
use App\Models\SchedulingWorkflowStep;
use App\Security\Permissions;
use App\Services\SchedulingWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  سير عمل الجدولة — تعريفه في الإعدادات، وقياس الموجات عليه
// ════════════════════════════════════════════════════════════
//
// التعريف سلطةُ إعدادات (`settings.manage`): من يغيّر الإجراء يغيّره لكل موجة
// قادمة. والقراءة والتأشير سلطةُ جدولة — من يبني الموجة هو من يؤشّر خطواتها.
class SchedulingWorkflowController extends Controller
{
    public function __construct(private SchedulingWorkflowService $workflow) {}

    private function log(Request $request, string $action, ?int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'scheduling_workflow',
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function denyManage(Request $request): ?JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SETTINGS_MANAGE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
    }

    private function row(SchedulingWorkflowStep $s): array
    {
        return [
            'id' => $s->id,
            'position' => $s->position,
            'title' => $s->title_ar,
            'description' => $s->description,
            'autoKey' => $s->auto_key,
            'autoLabel' => $s->auto_key ? (SchedulingWorkflowService::CHECKS[$s->auto_key] ?? 'فحص غير معروف') : null,
            'isAutomatic' => $s->isAutomatic(),
            'isRequired' => $s->is_required,
            'isActive' => $s->is_active,
        ];
    }

    // ════════════════════════════════════════════════════════
    //  التعريف (الإعدادات)
    // ════════════════════════════════════════════════════════

    // GET /settings/scheduling-workflow — الخطوات كما هي معرَّفة
    //
    // القراءة بـschedule.view لا settings.manage: شاشة الموجة تعرض قائمة
    // الخطوات لمن يبني الجدولة، وهو لا يملك الإعدادات.
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission(Permissions::SCHEDULE_VIEW)
            && ! $user->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض سير عمل الجدولة'], 403);
        }

        $canManage = $user->hasPermission(Permissions::SETTINGS_MANAGE);
        $query = SchedulingWorkflowStep::ordered();
        if (! $canManage) {
            $query->active();   // من لا يحرّر لا يرى المُطفأة
        }

        return response()->json([
            'steps' => $query->get()->map(fn ($s) => $this->row($s))->values(),
            'checks' => SchedulingWorkflowService::checkOptions(),
            'canManage' => $canManage,
        ]);
    }

    private function stepRules(bool $creating): array
    {
        return [
            'title' => ($creating ? 'required|' : 'sometimes|required|').'string|max:150',
            'description' => 'nullable|string|max:500',
            'autoKey' => 'nullable|string|max:40',
            'isRequired' => 'nullable|boolean',
            'isActive' => 'nullable|boolean',
        ];
    }

    // POST /settings/scheduling-workflow — خطوة جديدة (تُلحق بالذيل)
    public function store(Request $request)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $validated = $request->validate($this->stepRules(true));

        // مفتاحٌ غير معروف يعني خطوةً «آلية» لا أحد يحسبها، فتبقى معلّقة أبداً
        if (! SchedulingWorkflowService::isKnownCheck($validated['autoKey'] ?? null)) {
            return response()->json(['error' => 'مفتاح التحقّق الآلي غير معروف'], 422);
        }

        $next = (int) SchedulingWorkflowStep::max('position') + 1;
        $step = SchedulingWorkflowStep::create([
            'position' => $next,
            'title_ar' => $validated['title'],
            'description' => $validated['description'] ?? null,
            // الحقل الاختياري غير المُرسَل لا يظهر في validated أصلاً
            'auto_key' => ($validated['autoKey'] ?? null) ?: null,
            'is_required' => $request->boolean('isRequired', true),
            'is_active' => $request->boolean('isActive', true),
        ]);

        $this->log($request, 'CREATE_WORKFLOW_STEP', $step->id, ['title' => $step->title_ar]);

        return response()->json(['message' => 'أُضيفت الخطوة', 'step' => $this->row($step)], 201);
    }

    // PUT /settings/scheduling-workflow/{id} — تعديل خطوة
    public function update(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $step = SchedulingWorkflowStep::find($id);
        if (! $step) {
            return response()->json(['error' => 'الخطوة غير موجودة'], 404);
        }

        $validated = $request->validate($this->stepRules(false));

        if (array_key_exists('autoKey', $validated)
            && ! SchedulingWorkflowService::isKnownCheck($validated['autoKey'])) {
            return response()->json(['error' => 'مفتاح التحقّق الآلي غير معروف'], 422);
        }

        if (isset($validated['title'])) {
            $step->title_ar = $validated['title'];
        }
        if (array_key_exists('description', $validated)) {
            $step->description = $validated['description'];
        }
        if (array_key_exists('autoKey', $validated)) {
            $step->auto_key = $validated['autoKey'] ?: null;
        }
        if ($request->has('isRequired')) {
            $step->is_required = $request->boolean('isRequired');
        }
        if ($request->has('isActive')) {
            $step->is_active = $request->boolean('isActive');
        }
        $step->save();

        $this->log($request, 'UPDATE_WORKFLOW_STEP', $step->id, ['title' => $step->title_ar]);

        return response()->json(['message' => 'حُدّثت الخطوة', 'step' => $this->row($step)]);
    }

    // DELETE /settings/scheduling-workflow/{id} — حذف خطوة
    //
    // تأشيراتها على الموجات تسقط معها (cascade): الخطوة المحذوفة لم تعد من
    // الإجراء، فتأشيرها تاريخٌ لا معنى له. ومن أراد إبقاءها في السجلّ يُطفئها
    // بدل حذفها — ولذلك is_active موجودة.
    public function destroy(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $step = SchedulingWorkflowStep::find($id);
        if (! $step) {
            return response()->json(['error' => 'الخطوة غير موجودة'], 404);
        }
        if (SchedulingWorkflowStep::count() <= 1) {
            return response()->json(['error' => 'لا يُحذف آخر خطوة — سير عمل بلا خطوات لا معنى له'], 422);
        }

        $title = $step->title_ar;
        $marked = PeriodStepProgress::where('step_id', $step->id)->count();
        $step->delete();

        $this->log($request, 'DELETE_WORKFLOW_STEP', $id, ['title' => $title, 'markedOn' => $marked]);

        return response()->json([
            'message' => $marked > 0
                ? 'حُذفت الخطوة، وسقط تأشيرها على '.$marked.' موجة'
                : 'حُذفت الخطوة',
        ]);
    }

    // PUT /settings/scheduling-workflow/reorder — إعادة الترتيب
    public function reorder(Request $request)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required|integer|distinct|exists:scheduling_workflow_steps,id',
        ]);

        // الترتيب يُرسَل كاملاً لا جزئياً: قائمةٌ ناقصة تترك خطوةً بترتيبٍ قديم
        // فتقفز إلى موضعٍ لم يخترْه أحد.
        $all = SchedulingWorkflowStep::pluck('id')->all();
        if (count($validated['ids']) !== count($all) || array_diff($all, $validated['ids'])) {
            return response()->json(['error' => 'أرسل ترتيب الخطوات كاملاً'], 422);
        }

        DB::transaction(function () use ($validated) {
            foreach ($validated['ids'] as $i => $id) {
                SchedulingWorkflowStep::whereKey($id)->update(['position' => $i + 1]);
            }
        });

        $this->log($request, 'REORDER_WORKFLOW_STEPS', null, ['count' => count($validated['ids'])]);

        return response()->json(['message' => 'أُعيد ترتيب الخطوات']);
    }

    // ════════════════════════════════════════════════════════
    //  القياس (الموجة)
    // ════════════════════════════════════════════════════════

    // GET /scheduling-periods/{id}/workflow — أين وصلت هذه الموجة
    public function periodWorkflow(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }

        return response()->json($this->workflow->forPeriod($period));
    }

    // POST /scheduling-periods/{id}/workflow/{stepId} — تأشير خطوة يدوية
    public function markStep(Request $request, int $id, int $stepId)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }
        $step = SchedulingWorkflowStep::find($stepId);
        if (! $step || ! $step->is_active) {
            return response()->json(['error' => 'الخطوة غير موجودة'], 404);
        }

        // الخطوة الآلية تُقاس ولا تُؤشَّر — تأشيرها يدوياً يعني إعلان إنجازٍ
        // يناقض ما يراه النظام، وهو أسوأ من خطوةٍ معلّقة
        if ($step->isAutomatic()) {
            return response()->json([
                'error' => 'هذه خطوة آلية يتحقّق منها النظام: '.(SchedulingWorkflowService::CHECKS[$step->auto_key] ?? ''),
            ], 422);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:pending,done,skipped',
            'note' => 'nullable|string|max:300',
        ]);

        // الاستثناء يلزمه سبب — «لا تنطبق» بلا تعليل لا تُقرأ بعد شهر
        if ($validated['status'] === 'skipped' && empty(trim((string) ($validated['note'] ?? '')))) {
            return response()->json(['error' => 'اكتب سبب استثناء الخطوة'], 422);
        }

        if ($validated['status'] === 'pending') {
            PeriodStepProgress::where('period_id', $period->id)->where('step_id', $step->id)->delete();
        } else {
            PeriodStepProgress::updateOrCreate(
                ['period_id' => $period->id, 'step_id' => $step->id],
                [
                    'status' => $validated['status'],
                    'note' => $validated['note'] ?? null,
                    'done_by' => $request->user()->id,
                    'done_at' => now(),
                ]
            );
        }

        $this->log($request, 'MARK_WORKFLOW_STEP', $period->id, [
            'step' => $step->title_ar,
            'status' => $validated['status'],
        ]);

        return response()->json(array_merge(
            ['message' => 'حُدّثت حالة الخطوة'],
            $this->workflow->forPeriod($period)
        ));
    }
}
