<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\RosterGroup;
use App\Security\Permissions;
use App\Services\RosterSheetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  مجموعتا كشف اليوم وكشف الحضور المطبوع.
//
//  الإسناد قرار بشري محمي بـ ROSTER_MANAGE: النظام لا يوزّع المشاركين
//  على المجموعتين تلقائياً ولا يخمّن، لأن التوزيع يقرّره من يعرف حال
//  اليوم لا خوارزمية ترتيب.
//
//  الطباعة تكفيها SCHEDULE_VIEW — قراءةُ ما أُسنِد لا تغييره.
// ════════════════════════════════════════════════════════════

class RosterController extends Controller
{
    public function __construct(private RosterSheetService $service)
    {
    }

    private function log(Request $request, string $action, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'roster',
            'entity_id' => '0',
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function date(Request $request): string
    {
        $d = $request->query('date');

        return $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : now()->toDateString();
    }

    // قطاع المستخدم إن كان محصوراً — يُمرَّر للخدمة لتحصر الكشف
    private function sectorScope(Request $request): ?int
    {
        $user = $request->user();

        return $user->isSectorBound() ? $user->sector_id : null;
    }

    // GET /roster — مجموعات يومٍ بعينه (للشاشة)
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $date = $this->date($request);
        $query = RosterGroup::with('candidate')->whereDate('roster_date', $date);
        $this->scopeViaCandidate($request, $query);

        return response()->json([
            'date' => $date,
            'groups' => $query->get()->map(fn ($g) => [
                'candidateId' => $g->candidate_id,
                'participantCode' => $g->candidate?->participant_code,
                'group' => $g->group_letter,
                'groupLabel' => RosterGroup::label($g->group_letter),
            ])->values()->all(),
        ]);
    }

    // POST /roster/assign — إسناد مجموعة لعدة مشاركين دفعةً واحدة
    public function assign(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::ROSTER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إسناد مجموعات المشاركين'], 403);
        }

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'group' => ['required', 'string', 'in:' . implode(',', RosterGroup::LETTERS)],
            'candidateIds' => 'required|array|min:1|max:200',
            'candidateIds.*' => 'required|integer',
        ], [
            'candidateIds.required' => 'اختر مشاركاً واحداً على الأقل',
            'candidateIds.max' => 'الحدّ الأقصى 200 مشارك في المرة الواحدة',
            'group.in' => 'المجموعة يجب أن تكون أ أو ب',
        ]);

        // حصر المعرّفات على نطاق المستخدم قبل الكتابة — خارج النطاق يسقط
        // صامتاً كما لو لم يكن، فلا يصير المعرّف أداةً لكشف من هو موجود.
        $query = Candidate::query()->whereIn('id', $validated['candidateIds']);
        $this->scopeCandidateQuery($request, $query);
        $candidates = $query->with(['assessments' => fn ($q) => $q->where('status', '!=', 'completed')->orderByDesc('id')])->get();

        if ($candidates->isEmpty()) {
            return response()->json(['error' => 'لا يوجد مشاركون ضمن نطاقك في هذا الاختيار'], 422);
        }

        $userId = $request->user()->id;
        DB::transaction(function () use ($candidates, $validated, $userId) {
            foreach ($candidates as $c) {
                RosterGroup::updateOrCreate(
                    ['candidate_id' => $c->id, 'roster_date' => $validated['date']],
                    [
                        'assessment_id' => $c->assessments->first()?->id,
                        'group_letter' => $validated['group'],
                        'assigned_by' => $userId,
                    ]
                );
            }
        });

        $this->log($request, 'ASSIGN_ROSTER_GROUP', [
            'date' => $validated['date'],
            'group' => $validated['group'],
            'count' => $candidates->count(),
            'codes' => $candidates->pluck('participant_code')->all(),
        ]);

        return response()->json([
            'message' => 'تم إسناد ' . $candidates->count() . ' مشاركاً للمجموعة ' . RosterGroup::label($validated['group']),
            'assigned' => $candidates->count(),
            'skipped' => count($validated['candidateIds']) - $candidates->count(),
        ]);
    }

    // DELETE /roster — سحب الإسناد عن مشاركين في يوم
    public function unassign(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::ROSTER_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إسناد مجموعات المشاركين'], 403);
        }

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'candidateIds' => 'required|array|min:1|max:200',
            'candidateIds.*' => 'required|integer',
        ]);

        $query = Candidate::query()->whereIn('id', $validated['candidateIds']);
        $this->scopeCandidateQuery($request, $query);
        $ids = $query->pluck('id');

        $removed = RosterGroup::whereDate('roster_date', $validated['date'])
            ->whereIn('candidate_id', $ids)
            ->delete();

        $this->log($request, 'UNASSIGN_ROSTER_GROUP', [
            'date' => $validated['date'],
            'count' => $removed,
        ]);

        return response()->json(['message' => 'تم سحب الإسناد عن ' . $removed . ' مشاركاً', 'removed' => $removed]);
    }

    // GET /roster/document — كشف الحضور المطبوع (المتصفّح → PDF)
    public function document(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        // ── إظهار الهوية الوطنية ──
        // يطلبه الطابع، ولا يُمنح إلا لحامل CANDIDATE_VIEW_NAMES. النظام كله
        // مبني على حجب هوية المرشّح خلف رمزه، ووثيقة تخرج من المركز أولى
        // بالحصر لا أدنى منه. من لا يملكها يُطبع له الكشف بالرموز.
        $wants = $request->boolean('showNationalId');
        $mayShow = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        $showNationalId = $wants && $mayShow;

        $date = $this->date($request);

        $data = $this->service->gather(
            $date,
            $this->allowedClassifications($request),
            $this->sectorScope($request),
            $showNationalId
        );

        // نسخة تحمل أرقام هوية يجب أن يُعرف من أخرجها ومتى
        $this->log($request, 'EXPORT_ROSTER_SHEET', [
            'date' => $date,
            'rows' => count($data['rows']),
            'nationalId' => $showNationalId,
            'requested' => $wants,
        ]);

        return response($this->service->renderHtml($data), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
