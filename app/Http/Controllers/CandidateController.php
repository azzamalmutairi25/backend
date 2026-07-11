<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Assessment;
use App\Models\Sector;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Rules\SaudiNationalId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CandidateController extends Controller
{
    private function allowedClassifications(Request $request): array
    {
        $canSeeClassified = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED);
        return $canSeeClassified ? ['normal', 'secret', 'top_secret'] : ['normal'];
    }

    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المرشحين'], 403);
        }

        $query = Candidate::with('sector');
        $query->whereIn('classification', $this->allowedClassifications($request));

        if ($request->filled('status')) {
            // يدعم قيمة واحدة أو عدّة حالات مفصولة بفواصل (مثل: scheduled,assessed)
            $query->whereIn('status', explode(',', $request->status));
        }
        if ($request->filled('sectorId')) {
            $query->where('sector_id', $request->sectorId);
        }
        if ($request->filled('tier')) {
            $query->where('tier', $request->tier);
        }
        if ($request->filled('classification')) {
            $query->where('classification', $request->classification);
        }
        if ($request->filled('search')) {
            $query->where('participant_code', 'like', '%' . $request->search . '%');
        }

        $candidates = $query->orderBy('participant_code')->get()->map(function ($c) {
            return [
                'id' => $c->id,
                'participantCode' => $c->participant_code,
                'sectorName' => $c->sector->name_ar,
                'sectorId' => $c->sector_id,
                'rankLabel' => $c->rank_label,
                'tier' => $c->tier,
                'assessmentType' => $c->assessment_type,
                'status' => $c->status,
                'classification' => $c->classification,
            ];
        });

        return response()->json(['candidates' => $candidates]);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المرشحين'], 403);
        }
        $candidate = Candidate::with('sector')->findOrFail($id);

        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            $this->log($request, 'DENIED_CLASSIFIED_ACCESS', $id, ['code' => $candidate->participant_code]);
            return response()->json(['error' => 'هذا المرشح مصنّف، وليس لديك صلاحية الوصول'], 403);
        }

        $canSeeNames = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);

        if ($canSeeNames) {
            $this->log($request, 'VIEW_CANDIDATE_PII', $id, ['code' => $candidate->participant_code]);
        }

        return response()->json(['candidate' => [
            'id' => $candidate->id,
            'participantCode' => $candidate->participant_code,
            'name' => $canSeeNames ? $candidate->full_name : null,
            'nationalId' => $canSeeNames ? $candidate->national_id : null,
            'mobile' => $canSeeNames ? $candidate->mobile : null,
            'email' => $canSeeNames ? $candidate->email : null,
            'sectorName' => $candidate->sector->name_ar,
            'sectorId' => $candidate->sector_id,
            'rankLabel' => $candidate->rank_label,
            'tier' => $candidate->tier,
            'assessmentType' => $candidate->assessment_type,
            'status' => $candidate->status,
            'classification' => $candidate->classification,
            'createdAt' => $candidate->created_at,
            'canSeeNames' => $canSeeNames,
        ]]);
    }

    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إضافة مرشح'], 403);
        }

        $validated = $request->validate([
            'nationalId' => ['required', 'string', new SaudiNationalId()],
            'fullName' => 'required|string|max:200',
            'mobile' => ['nullable', 'string', 'regex:/^05\d{8}$/'],
            'email' => 'nullable|email',
            'sectorId' => 'required|exists:sectors,id',
            'rankLabel' => 'required|string',
            'assessmentType' => 'nullable|in:comprehensive,executive',
            'classification' => 'nullable|in:normal,secret,top_secret',
        ]);

        $sector = Sector::findOrFail($validated['sectorId']);
        $tier = Candidate::classifyTier($validated['rankLabel'], $sector->is_military);
        $assessmentType = $validated['assessmentType'] ?? 'comprehensive';

        // ديدَاب الشخص بالهوية — شخص واحد ← عدة دورات/رموز
        $candidate = Candidate::where('national_id_hash', hash('sha256', $validated['nationalId']))->first();
        $isReturning = (bool) $candidate;

        if ($candidate) {
            // امنع دورة جديدة إن كانت له دورة نشطة (لم تكتمل) — «كل رمز له تقييم»
            $active = $candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
            if ($active) {
                return response()->json([
                    'error' => "لدى المرشح دورة تقييم نشطة ({$active->participant_code}) — يجب إكمالها قبل إنشاء دورة جديدة",
                    'participantCode' => $active->participant_code,
                ], 422);
            }
            // تحديث بيانات الشخص للأحدث (قد يكون تغيّر قطاعه/رتبته). التصنيف يُدار عبر reclassify فقط.
            $candidate->full_name = $validated['fullName'];
            $candidate->mobile = $validated['mobile'] ?? null;
            $candidate->email = $validated['email'] ?? null;
            $candidate->sector_id = $sector->id;
            $candidate->rank_label = $validated['rankLabel'];
            $candidate->tier = $tier;
        } else {
            // شخص جديد
            $candidate = new Candidate();
            $candidate->national_id = $validated['nationalId']; // mutator: تشفير + hash
            $candidate->full_name = $validated['fullName'];
            $candidate->mobile = $validated['mobile'] ?? null;
            $candidate->email = $validated['email'] ?? null;
            $candidate->sector_id = $sector->id;
            $candidate->rank_label = $validated['rankLabel'];
            $candidate->tier = $tier;
            // تعيين تصنيف أمني يتطلب صلاحية VIEW_CLASSIFIED — منع التصعيد
            $requestedClass = $validated['classification'] ?? 'normal';
            if ($requestedClass !== 'normal' && !$request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
                return response()->json(['error' => 'ليس لديك صلاحية تعيين تصنيف أمني'], 403);
            }
            $candidate->classification = $requestedClass;
        }

        // دورة تقييم جديدة برمز فريد + مزامنة الحقول «الحالية» على سجل الشخص
        $code = Assessment::generateParticipantCode($sector);
        $candidate->participant_code = $code;
        $candidate->status = 'draft';
        $candidate->assessment_type = $assessmentType;

        $assessment = DB::transaction(function () use ($candidate, $code, $assessmentType, $request) {
            $candidate->save();
            return Assessment::create([
                'candidate_id' => $candidate->id,
                'participant_code' => $code,
                'assessment_type' => $assessmentType,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
        });

        $this->log($request, $isReturning ? 'REASSESS_CANDIDATE' : 'CREATE_CANDIDATE', $candidate->id, ['code' => $code]);

        return response()->json([
            'message' => $isReturning ? 'تمّت إضافة دورة تقييم جديدة لمرشح موجود' : 'تمت إضافة المرشح',
            'participantCode' => $code,
            'tier' => $tier,
            'isReturning' => $isReturning,
            'assessmentId' => $assessment->id,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_EDIT)) {
            return response()->json(['error' => 'ليس لديك صلاحية التعديل'], 403);
        }

        $candidate = Candidate::findOrFail($id);

        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'هذا المرشح مصنّف، وليس لديك صلاحية'], 403);
        }

        $validated = $request->validate([
            'nationalId' => ['required', 'string', new SaudiNationalId()],
            'fullName' => 'required|string|max:200',
            'mobile' => ['nullable', 'string', 'regex:/^05\d{8}$/'],
            'email' => 'nullable|email',
            'sectorId' => 'required|exists:sectors,id',
            'rankLabel' => 'required|string',
            'assessmentType' => 'nullable|in:comprehensive,executive',
            'classification' => 'nullable|in:normal,secret,top_secret',
        ]);

        if (Candidate::nationalIdExists($validated['nationalId'], $id)) {
            return response()->json(['error' => 'رقم الهوية مسجّل مسبقاً لمرشح آخر'], 422);
        }

        $sector = Sector::findOrFail($validated['sectorId']);
        $tier = Candidate::classifyTier($validated['rankLabel'], $sector->is_military);

        $candidate->national_id = $validated['nationalId'];
        $candidate->full_name = $validated['fullName'];
        $candidate->mobile = $validated['mobile'] ?? null;
        $candidate->email = $validated['email'] ?? null;
        $candidate->sector_id = $sector->id;
        $candidate->rank_label = $validated['rankLabel'];
        $candidate->tier = $tier;
        $candidate->assessment_type = $validated['assessmentType'] ?? 'comprehensive';
        // تغيير التصنيف الأمني حوكمة حسّاسة — يتطلب صلاحية VIEW_CLASSIFIED (كما في reclassify) ويُسجَّل
        $classChanged = false;
        $oldClass = $candidate->classification;
        if (isset($validated['classification']) && $validated['classification'] !== $candidate->classification) {
            if (!$request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
                return response()->json(['error' => 'ليس لديك صلاحية تغيير التصنيف الأمني'], 403);
            }
            $candidate->classification = $validated['classification'];
            $classChanged = true;
        }
        $candidate->save();

        $this->log($request, 'UPDATE_CANDIDATE', $candidate->id, ['code' => $candidate->participant_code]);
        if ($classChanged) {
            $this->log($request, 'RECLASSIFY_CANDIDATE', $candidate->id, [
                'code' => $candidate->participant_code, 'from' => $oldClass, 'to' => $candidate->classification,
            ]);
        }

        return response()->json(['message' => 'تم تحديث بيانات المرشح', 'tier' => $tier]);
    }

    public function destroy(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_EDIT)) {
            return response()->json(['error' => 'ليس لديك صلاحية الحذف'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'يجب ذكر سبب الحذف (للتوثيق)',
            'reason.min' => 'سبب الحذف قصير جداً',
        ]);

        $candidate = Candidate::findOrFail($id);

        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'هذا المرشح مصنّف، وليس لديك صلاحية'], 403);
        }

        if (!in_array($candidate->status, ['draft', 'scheduled'])) {
            return response()->json(['error' => 'لا يمكن حذف مرشح بدأت عملية تقييمه'], 422);
        }

        $code = $candidate->participant_code;

        $this->log($request, 'DELETE_CANDIDATE', $id, [
            'code' => $code,
            'reason' => $validated['reason'],
            'classification' => $candidate->classification,
        ]);

        $candidate->delete();

        return response()->json(['message' => "تم حذف المرشح {$code} (موثّق)"]);
    }

    public function approve(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاعتماد'], 403);
        }

        $candidate = Candidate::findOrFail($id);
        $candidate->setStatus('scheduled'); // يزامن الدورة الحالية
        $this->log($request, 'APPROVE_CANDIDATE', $id, ['code' => $candidate->participant_code]);

        return response()->json(['message' => 'تم اعتماد المرشح']);
    }

    public function reclassify(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
            return response()->json(['error' => 'ليس لديك صلاحية تغيير التصنيف'], 403);
        }

        $validated = $request->validate([
            'classification' => 'required|in:normal,secret,top_secret',
        ]);

        $candidate = Candidate::findOrFail($id);
        $old = $candidate->classification;
        $candidate->update(['classification' => $validated['classification']]);

        $this->log($request, 'RECLASSIFY_CANDIDATE', $id, [
            'code' => $candidate->participant_code,
            'from' => $old,
            'to' => $validated['classification'],
        ]);

        return response()->json(['message' => 'تم تحديث التصنيف']);
    }

    public function stats(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المرشحين'], 403);
        }
        $allowed = $this->allowedClassifications($request);
        $base = Candidate::whereIn('classification', $allowed);

        $total = (clone $base)->count();
        $upper = (clone $base)->where('tier', 'upper')->count();
        $middle = (clone $base)->where('tier', 'middle')->count();

        $byStatus = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $byClass = (clone $base)->selectRaw('classification, count(*) as c')->groupBy('classification')->pluck('c', 'classification');

        return response()->json([
            'total' => $total,
            'upper' => $upper,
            'middle' => $middle,
            'byStatus' => [
                'draft' => $byStatus['draft'] ?? 0,
                'scheduled' => $byStatus['scheduled'] ?? 0,
                'assessed' => $byStatus['assessed'] ?? 0,
                'approved' => $byStatus['approved'] ?? 0,
                'completed' => $byStatus['completed'] ?? 0,
            ],
            'byClassification' => [
                'normal' => $byClass['normal'] ?? 0,
                'secret' => $byClass['secret'] ?? 0,
                'top_secret' => $byClass['top_secret'] ?? 0,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية تصدير المرشحين'], 403);
        }
        $canSeeNames = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        $allowed = $this->allowedClassifications($request);

        $query = Candidate::with('sector')->whereIn('classification', $allowed);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('sectorId')) $query->where('sector_id', $request->sectorId);
        if ($request->filled('tier')) $query->where('tier', $request->tier);
        if ($request->filled('classification')) $query->where('classification', $request->classification);

        $candidates = $query->orderBy('participant_code')->get();

        $this->log($request, 'EXPORT_CANDIDATES', 0, [
            'count' => $candidates->count(),
            'includedNames' => $canSeeNames,
        ]);

        $rows = $candidates->map(function ($c) use ($canSeeNames) {
            $row = [
                'الرمز' => $c->participant_code,
                'القطاع' => $c->sector->name_ar,
                'الرتبة' => $c->rank_label,
                'الفئة' => $c->tier === 'upper' ? 'قيادة عليا' : 'قيادة وسطى',
                'الحالة' => $c->status,
                'التصنيف' => $c->classification,
            ];
            if ($canSeeNames) {
                $row['الاسم'] = $c->full_name;
                $row['الهوية'] = $c->national_id;
            }
            return $row;
        });

        return response()->json([
            'rows' => $rows,
            'includedNames' => $canSeeNames,
            'count' => $candidates->count(),
        ]);
    }

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'candidate',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
