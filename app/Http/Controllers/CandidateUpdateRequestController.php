<?php

namespace App\Http\Controllers;

use App\Exceptions\CvTooLargeException;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateCv;
use App\Models\CandidateUpdateRequest;
use App\Models\Sector;
use App\Models\User;
use App\Rules\SaudiNationalId;
use App\Security\Permissions;
use App\Services\CvGuard;
use App\Services\CvValidator;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

// ════════════════════════════════════════════════════════════
//  طلبات تحديث بيانات المشاركين.
//
//  المستخدم الخارجي يُدخل مشاركاً فيجده مسجّلاً مسبقاً؛ لا يُسمح له بالكتابة
//  فوق السجلّ (فذلك تعديل لا إضافة)، فيرفع طلباً بما يراه صحيحاً. الطلب
//  اقتراحٌ محفوظ مشفّراً، ولا يمسّ السجلّ حتى يعتمده صاحب صلاحية.
//
//  المعتمِد يرى المقارنة بين لقطة «ما كان لحظة الرفع» و«ما يُقترح» — فلا
//  يعتمد على ذاكرته ولا على قراءة ثانية قد تكون تغيّرت تحته.
// ════════════════════════════════════════════════════════════

class CandidateUpdateRequestController extends Controller
{
    private const STATUSES = [
        CandidateUpdateRequest::PENDING,
        CandidateUpdateRequest::APPROVED,
        CandidateUpdateRequest::REJECTED,
    ];

    // ── رفع الطلب (المستخدم الخارجي) ──
    // POST /candidate-update-requests
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_UPDATE_REQUEST)) {
            return response()->json(['error' => 'ليس لديك صلاحية طلب تحديث بيانات مشارك'], 403);
        }

        // الحجم قبل أي عمل ثقيل (نفخ الحمولة) — الطلب يحمل وثيقة كاملة
        if (strlen($request->getContent()) > CvValidator::MAX_BYTES) {
            return response()->json(['error' => 'الحجم كبير جداً'], 413);
        }

        $validated = $request->validate([
            'nationalId' => ['required', 'string', new SaudiNationalId()],
            'fullName' => 'required|string|max:200',
            'mobile' => ['nullable', 'string', 'regex:/^05\d{8}$/'],
            'email' => 'nullable|email|max:200',
            'militaryNumber' => 'nullable|string|max:30',
            'gender' => 'nullable|in:male,female',
            'sectorId' => 'required|exists:sectors,id',
            'rankLabel' => 'required|string|max:50',
            // رُفع عنها الإلزام كبقية الحقول — والاعتماد يُبقي فئة المشارك متى غابت
            'personnelCategory' => 'nullable|in:civilian,military,contractor',
            // الطبقة لا تُفرض على المُرشِّح الخارجي: حكمٌ داخليّ يضبطه الموظّف
            // عند الاعتماد. المتعاقد يدخل «وسطى» افتراضاً ويُصحَّح من الشاشة.
            'tier' => 'nullable|in:upper,middle',
            'note' => 'nullable|string|max:500',
        ], [
            'nationalId.required' => 'أدخل رقم هوية المشارك',
            'fullName.required' => 'أدخل اسم المشارك',
            'sectorId.required' => 'اختر القطاع',
            'rankLabel.required' => 'أدخل الرتبة أو المرتبة',
        ]);

        // تقييد بالمعدّل على المستخدم — الطلب مفتوح لجهة خارجية
        if (RateLimiter::hit('curq:user:' . $user->id, 600) > 20) {
            return response()->json(['error' => 'طلبات كثيرة، حاول لاحقاً'], 429);
        }

        // السيرة اختيارية — وطلبٌ لا يحمل سيرةً ولا تغييراً في الهوية لا معنى
        // له، فيُردّ بذلك لا بـ«أرفق السيرة»: التغيير هو موضوع الطلب.
        $cvInput = $request->input('cv');
        $cvInput = is_array($cvInput) && $cvInput !== [] ? $cvInput : null;

        // ديدَاب المشارك بالهوية. غير الموجود وغير المرئي (مصنّف) يُعطيان الردّ
        // نفسه — فلا يصير الطلب طريقاً لمعرفة من هو مسجّل ومصنَّف.
        $candidate = Candidate::with(['cv', 'sector'])
            ->where('national_id_hash', hash('sha256', $validated['nationalId']))
            ->first();
        if (!$candidate || !in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json([
                'error' => 'لا يوجد مشارك مسجّل بهذا الرقم — أضِفه كمشارك جديد',
            ], 404);
        }

        $cleanCv = null;
        if ($cvInput !== null) {
            try {
                $cleanCv = app(CvValidator::class)->clean($cvInput);
            } catch (CvTooLargeException $e) {
                return response()->json(['error' => 'عناصر أكثر من المسموح'], 413);
            } catch (ValidationException $e) {
                return response()->json(['error' => 'بيانات السيرة غير صحيحة', 'fields' => $e->errors()], 422);
            }
        }

        // السيرة تصل المقيّم بلا اسم — الطلب ليس باباً خلفياً لإدراج المعرّفات
        if ($cleanCv !== null && ($hit = CvGuard::directIdentifierHit($cleanCv, $candidate))) {
            return response()->json([
                'error' => 'السيرة تحوي اسم المشارك أو معرّفاً — أزِله',
                'field' => $hit,
            ], 422);
        }

        // طلب معلّق قائم ← لا طلب ثانٍ (والقيد في القاعدة يحسم السباق)
        $existing = CandidateUpdateRequest::where('candidate_id', $candidate->id)->pending()->first();
        if ($existing) {
            return response()->json([
                'error' => 'يوجد طلب تحديث معلّق لهذا المشارك — بانتظار البتّ فيه',
                'pendingRequest' => [
                    'id' => $existing->id,
                    'createdAt' => optional($existing->created_at)->toIso8601String(),
                ],
            ], 409);
        }

        $sector = Sector::findOrFail($validated['sectorId']);

        try {
            $updateRequest = CandidateUpdateRequest::create([
                'candidate_id' => $candidate->id,
                'requested_by' => $user->id,
                'status' => CandidateUpdateRequest::PENDING,
                'note' => $validated['note'] ?? null,
                'snapshot' => $this->snapshotOf($candidate),
                'payload' => [
                    'identity' => [
                        'fullName' => $validated['fullName'],
                        'mobile' => $validated['mobile'] ?? null,
                        'email' => $validated['email'] ?? null,
                        'militaryNumber' => $validated['militaryNumber'] ?? null,
                        'gender' => $validated['gender'] ?? null,
                        'sectorId' => $sector->id,
                        'sectorName' => $sector->name_ar,
                        'rankLabel' => $validated['rankLabel'],
            'personnelCategory' => $validated['personnelCategory'],
            'tier' => $validated['tier'] ?? null,
                    ],
                    'cv' => $cleanCv,
                ],
            ]);
        } catch (QueryException $e) {
            // خسر سباق «طلب معلّق واحد» — الفهرس الفريد الجزئي حسمه
            return response()->json(['error' => 'يوجد طلب تحديث معلّق لهذا المشارك'], 409);
        }

        $this->log($request, 'REQUEST_CANDIDATE_UPDATE', $updateRequest->id, [
            'candidateId' => $candidate->id,
        ]);
        $this->notifyApprovers($candidate, $updateRequest, $user);

        return response()->json([
            'message' => 'أُرسل طلب تحديث البيانات — بانتظار اعتماد صاحب الصلاحية',
            'requestId' => $updateRequest->id,
            'status' => $updateRequest->status,
            'createdAt' => optional($updateRequest->created_at)->toIso8601String(),
        ], 201);
    }

    // ── طلباتي (مقدّم الطلب يتابع مصيرها) ──
    // GET /candidate-update-requests/mine
    //
    // بلا رمز المشارك ولا حالة المشارك: مقدّم الطلب لا يملك قراءة القاعدة،
    // فلا يصير سجلّ طلباته نافذةً عليها. يرى ما أرسله هو ونتيجته.
    public function mine(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_UPDATE_REQUEST)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض طلبات التحديث'], 403);
        }

        $rows = CandidateUpdateRequest::where('requested_by', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (CandidateUpdateRequest $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'candidateName' => $r->payload['identity']['fullName'] ?? null, // اسمٌ كتبه هو
                'note' => $r->note,
                'reviewNote' => $r->review_note,
                'createdAt' => optional($r->created_at)->toIso8601String(),
                'reviewedAt' => optional($r->reviewed_at)->toIso8601String(),
            ]);

        return response()->json(['requests' => $rows]);
    }

    // ── قائمة الطلبات (صاحب الصلاحية) ──
    // GET /candidate-update-requests?status=pending
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_UPDATE_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض طلبات التحديث'], 403);
        }

        $query = CandidateUpdateRequest::with(['candidate.sector', 'requester']);
        // نطاق المشارك كاملاً (تصنيف + قطاع) — الطلب لا يوسّع ما تراه من القاعدة
        $this->scopeViaCandidate($request, $query);

        if ($request->filled('status') && in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        $rows = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (CandidateUpdateRequest $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'participantCode' => optional($r->candidate)->participant_code,
                'sectorName' => optional(optional($r->candidate)->sector)->name_ar,
                'requesterName' => optional($r->requester)->full_name,
                'changedFields' => $this->diff($r->snapshot, $r->payload)['fields'],
                'createdAt' => optional($r->created_at)->toIso8601String(),
                'reviewedAt' => optional($r->reviewed_at)->toIso8601String(),
            ]);

        // العدّادات على النطاق نفسه — مؤشّر أوسع من القائمة يفشي حجم ما تخفيه
        $countQuery = CandidateUpdateRequest::query();
        $this->scopeViaCandidate($request, $countQuery);
        $counts = $countQuery->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'requests' => $rows,
            'counts' => [
                'pending' => (int) ($counts[CandidateUpdateRequest::PENDING] ?? 0),
                'approved' => (int) ($counts[CandidateUpdateRequest::APPROVED] ?? 0),
                'rejected' => (int) ($counts[CandidateUpdateRequest::REJECTED] ?? 0),
            ],
        ]);
    }

    // ── تفاصيل الطلب + المقارنة ──
    // GET /candidate-update-requests/{id}
    public function show(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_UPDATE_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض طلبات التحديث'], 403);
        }

        $updateRequest = $this->resolveInScope($request, $id);
        if (!$updateRequest) {
            return response()->json(['error' => 'الطلب غير موجود'], 404);
        }

        $snapshot = $updateRequest->snapshot;
        $payload = $updateRequest->payload;
        $liveVersion = optional($updateRequest->candidate->cv)->version ?? 0;

        $this->log($request, 'VIEW_CANDIDATE_UPDATE_REQUEST', $updateRequest->id, [
            'candidateId' => $updateRequest->candidate_id,
        ]);

        return response()->json(['request' => [
            'id' => $updateRequest->id,
            'status' => $updateRequest->status,
            'participantCode' => $updateRequest->candidate->participant_code,
            'candidateId' => $updateRequest->candidate_id,
            'requesterName' => optional($updateRequest->requester)->full_name,
            'reviewerName' => optional($updateRequest->reviewer)->full_name,
            'note' => $updateRequest->note,
            'reviewNote' => $updateRequest->review_note,
            'createdAt' => optional($updateRequest->created_at)->toIso8601String(),
            'reviewedAt' => optional($updateRequest->reviewed_at)->toIso8601String(),
            'current' => $snapshot,
            'proposed' => $payload,
            'diff' => $this->diff($snapshot, $payload),
            // تغيّرت السيرة بعد رفع الطلب: المقارنة المعروضة صارت أقدم من السجلّ.
            // لا نمنع الاعتماد — نُعلنه، والقرار للمعتمِد.
            'stale' => (int) ($snapshot['cvVersion'] ?? 0) !== (int) $liveVersion,
        ]]);
    }

    // ── الاعتماد: يطبّق المقترح على السجلّ ──
    // POST /candidate-update-requests/{id}/approve
    public function approve(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_UPDATE_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية اعتماد طلبات التحديث'], 403);
        }

        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $updateRequest = $this->resolveInScope($request, $id);
        if (!$updateRequest) {
            return response()->json(['error' => 'الطلب غير موجود'], 404);
        }
        if ($updateRequest->status !== CandidateUpdateRequest::PENDING) {
            return response()->json(['error' => 'بُتّ في هذا الطلب مسبقاً'], 422);
        }

        $payload = $updateRequest->payload;
        $identity = $payload['identity'] ?? [];
        // السيرة اختيارية: طلبٌ حُفظ بلا سيرة كان يصير غيرَ قابلٍ للاعتماد أبداً
        // («محتوى الطلب تالف») — أي طلبٌ مسجونٌ لا يُبتّ فيه بنعم ولا بلا.
        $cvDoc = is_array($payload['cv'] ?? null) ? $payload['cv'] : null;
        if (!$identity) {
            return response()->json(['error' => 'محتوى الطلب تالف — تعذّر تطبيقه'], 422);
        }

        $sector = Sector::find($identity['sectorId'] ?? null);
        if (!$sector) {
            return response()->json(['error' => 'القطاع المطلوب لم يعد موجوداً — ارفض الطلب'], 422);
        }

        $applied = DB::transaction(function () use ($updateRequest, $identity, $cvDoc, $sector, $user, $validated) {
            // القفل يسلسل معتمِدَين متزامنين، وإعادة الفحص تحته تمنع الاعتماد المزدوج
            $locked = CandidateUpdateRequest::whereKey($updateRequest->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== CandidateUpdateRequest::PENDING) {
                return false;
            }
            $candidate = Candidate::whereKey($locked->candidate_id)->lockForUpdate()->first();
            if (!$candidate) {
                return false;
            }

            // الهوية والتصنيف الأمني خارج نطاق الطلب: الأول مفتاح الشخص،
            // والثاني حوكمة تُدار عبر reclassify وحدها
            // قراءاتٌ كانت آمنةً بحكم `required` — والحقلان صارا اختياريين،
            // فالمفتاح قد يغيب عن طلبٍ أُنشئ بعد رفع الإلزام
            $candidate->full_name = $identity['fullName'] ?? $candidate->full_name;
            $candidate->mobile = $identity['mobile'] ?? null;
            $candidate->email = $identity['email'] ?? null;
            $candidate->military_number = $identity['militaryNumber'] ?? $candidate->military_number;
            $candidate->gender = $identity['gender'] ?? $candidate->gender;
            $candidate->sector_id = $sector->id;
            $candidate->rank_label = $identity['rankLabel'] ?? $candidate->rank_label;
            // الفئة تأتي مع الطلب؛ وطلبٌ قديم أُنشئ قبل العمود يُبقي فئة المشارك
            $category = $identity['personnelCategory'] ?? $candidate->personnel_category ?? 'civilian';
            $candidate->personnel_category = $category;
            $candidate->tier = Candidate::resolveTier($category, $candidate->rank_label, $identity['tier'] ?? null);
            $candidate->save();

            // طلبٌ بلا سيرة يُعدِّل الهوية وحدها ولا يمسّ سيرةً قائمة — استبدالُها
            // بوثيقةٍ فارغة كان يمحو سيرةً لم يطلب أحدٌ محوَها
            if ($cvDoc !== null) {
                $cv = CandidateCv::firstOrNew(['candidate_id' => $candidate->id]);
                $cv->data = $cvDoc;
                $cv->version = ($cv->version ?? 0) + 1;
                $cv->source = 'external'; // أصلها جهة خارجية، واعتمدها موظّف — كلاهما مسجَّل
                $cv->updated_by = $user->id;
                $cv->save();
            }

            $locked->status = CandidateUpdateRequest::APPROVED;
            $locked->review_note = $validated['note'] ?? null;
            $locked->reviewed_by = $user->id;
            $locked->reviewed_at = now();
            $locked->save();

            return true;
        });

        if (!$applied) {
            return response()->json(['error' => 'بُتّ في هذا الطلب مسبقاً'], 422);
        }

        $this->log($request, 'APPROVE_CANDIDATE_UPDATE', $updateRequest->id, [
            'candidateId' => $updateRequest->candidate_id,
            'code' => $updateRequest->candidate->participant_code,
        ]);
        $this->notifyRequester($updateRequest, 'اعتُمد طلب تحديث بيانات المشارك', $validated['note'] ?? null);

        return response()->json(['message' => 'اعتُمد الطلب وطُبِّق على بيانات المشارك']);
    }

    // ── الرفض ──
    // POST /candidate-update-requests/{id}/reject
    public function reject(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_UPDATE_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية البتّ في طلبات التحديث'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ], [
            'reason.required' => 'يجب ذكر سبب الرفض (يصل مقدّم الطلب)',
            'reason.min' => 'سبب الرفض قصير جداً',
        ]);

        $updateRequest = $this->resolveInScope($request, $id);
        if (!$updateRequest) {
            return response()->json(['error' => 'الطلب غير موجود'], 404);
        }

        $rejected = DB::transaction(function () use ($updateRequest, $user, $validated) {
            $locked = CandidateUpdateRequest::whereKey($updateRequest->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== CandidateUpdateRequest::PENDING) {
                return false;
            }
            $locked->status = CandidateUpdateRequest::REJECTED;
            $locked->review_note = $validated['reason'];
            $locked->reviewed_by = $user->id;
            $locked->reviewed_at = now();
            $locked->save();

            return true;
        });

        if (!$rejected) {
            return response()->json(['error' => 'بُتّ في هذا الطلب مسبقاً'], 422);
        }

        $this->log($request, 'REJECT_CANDIDATE_UPDATE', $updateRequest->id, [
            'candidateId' => $updateRequest->candidate_id,
        ]);
        $this->notifyRequester($updateRequest, 'رُفض طلب تحديث بيانات المشارك', $validated['reason']);

        return response()->json(['message' => 'رُفض الطلب وأُبلغ مقدّمه']);
    }

    // ══════ مساعدات ══════

    // حلّ الطلب ضمن نطاق المستخدم (تصنيف المشارك + قطاعه) — خارج النطاق «غير موجود»
    private function resolveInScope(Request $request, int $id): ?CandidateUpdateRequest
    {
        $query = CandidateUpdateRequest::with(['candidate.sector', 'candidate.cv', 'requester', 'reviewer'])
            ->whereKey($id);
        $this->scopeViaCandidate($request, $query);

        return $query->first();
    }

    // لقطة القيم الحالية لحظة الرفع — مرجع المقارنة عند البتّ
    private function snapshotOf(Candidate $candidate): array
    {
        return [
            'identity' => [
                'fullName' => $candidate->full_name,
                'mobile' => $candidate->mobile,
                'email' => $candidate->email,
                'militaryNumber' => $candidate->military_number,
                'gender' => $candidate->gender,
                'sectorId' => $candidate->sector_id,
                'sectorName' => optional($candidate->sector)->name_ar,
                'rankLabel' => $candidate->rank_label,
                'personnelCategory' => $candidate->personnel_category,
            ],
            'cv' => $candidate->cv?->data ?? CandidateCv::emptyDoc(),
            'cvVersion' => $candidate->cv?->version ?? 0,
        ];
    }

    // ── المقارنة: ما الذي تغيّر فعلاً؟ ──
    // الحقول المفردة تُقارن قيمةً بقيمة؛ والأقسام المتكرّرة (مؤهلات/خبرات/دورات)
    // تُقارن ككتلة — مقارنة سطرٍ بسطر تُوهم بتغيّر شامل عند إدراج صفٍّ في الوسط.
    private function diff(array $snapshot, array $payload): array
    {
        $labels = [
            'fullName' => 'الاسم',
            'mobile' => 'الجوال',
            'email' => 'البريد الإلكتروني',
            'militaryNumber' => 'الرقم العسكري/الوظيفي',
            'gender' => 'الجنس',
            'rankTitle' => 'لقب الرتبة',
            'rankPromotedAt' => 'تاريخ الترقية',
            'generalDepartment' => 'الإدارة العامة',
            'workCity' => 'مدينة العمل',
            'currentPositionYears' => 'مدة الخدمة في الوظيفة الحالية',
            'sectorName' => 'القطاع',
            'rankLabel' => 'الرتبة / المرتبة',
            'personnelCategory' => 'الفئة',
            'birthDate' => 'تاريخ الميلاد',
            'appointmentDate' => 'تاريخ التعيين',
            'department' => 'الإدارة',
            'region' => 'المنطقة',
            'currentPosition' => 'الوظيفة الحالية',
            'totalYearsExperience' => 'سنوات الخبرة',
            'briefBio' => 'نبذة',
        ];

        $oldIdentity = $snapshot['identity'] ?? [];
        $newIdentity = $payload['identity'] ?? [];
        $oldCv = $snapshot['cv'] ?? [];
        $newCv = $payload['cv'] ?? [];

        $changes = [];
        $norm = fn ($v) => $v === null ? '' : trim((string) $v);

        foreach (['fullName', 'mobile', 'email', 'militaryNumber', 'sectorName', 'rankLabel'] as $key) {
            if ($norm($oldIdentity[$key] ?? null) !== $norm($newIdentity[$key] ?? null)) {
                $changes[] = [
                    'key' => $key, 'label' => $labels[$key],
                    'from' => $oldIdentity[$key] ?? null, 'to' => $newIdentity[$key] ?? null,
                ];
            }
        }
        // الجنس يُخزَّن مفتاحاً ويُعرَض عربياً — «male» في جدول الفروق لا يُقرأ
        $genderAr = fn ($v) => ['male' => 'ذكر', 'female' => 'أنثى'][$v] ?? $v;

        if ($norm($oldIdentity['gender'] ?? null) !== $norm($newIdentity['gender'] ?? null)) {
            $changes[] = [
                'key' => 'gender', 'label' => $labels['gender'],
                'from' => $genderAr($oldIdentity['gender'] ?? null),
                'to' => $genderAr($newIdentity['gender'] ?? null),
            ];
        }

        // زيادات نموذج الوزارة معها: الاعتماد يستبدل الوثيقة كاملةً، فتغييرُ
        // «الإدارة العامة» أو «مدينة العمل» كان يُطبَّق بلا أن يراه المعتمِد —
        // ثغرةُ حوكمةٍ في شاشةٍ وظيفتُها أن تُري ما سيقع قبل أن يقع.
        foreach (['birthDate', 'appointmentDate', 'department', 'region', 'currentPosition', 'totalYearsExperience', 'briefBio',
            'rankTitle', 'rankPromotedAt', 'generalDepartment', 'workCity', 'currentPositionYears'] as $key) {
            if ($norm($oldCv[$key] ?? null) !== $norm($newCv[$key] ?? null)) {
                $changes[] = [
                    'key' => $key, 'label' => $labels[$key],
                    'from' => $oldCv[$key] ?? null, 'to' => $newCv[$key] ?? null,
                ];
            }
        }

        $sections = [];
        foreach (['qualifications' => 'المؤهلات', 'experiences' => 'الخبرات', 'certifications' => 'الدورات التدريبية'] as $key => $label) {
            $before = $oldCv[$key] ?? [];
            $after = $newCv[$key] ?? [];
            if (json_encode($before, JSON_UNESCAPED_UNICODE) !== json_encode($after, JSON_UNESCAPED_UNICODE)) {
                $sections[] = [
                    'key' => $key, 'label' => $label,
                    'countFrom' => count($before), 'countTo' => count($after),
                ];
            }
        }

        return [
            'changes' => $changes,
            'sections' => $sections,
            // ملخّص للقائمة: أسماء ما تغيّر بلا قيمه (القيم في شاشة التفاصيل)
            'fields' => array_values(array_merge(
                array_column($changes, 'label'),
                array_column($sections, 'label')
            )),
        ];
    }

    // إشعار كل من يملك البتّ فعلاً (الدور أو استثناء فردي) ضمن نطاق المشارك
    private function notifyApprovers(Candidate $candidate, CandidateUpdateRequest $updateRequest, User $requester): void
    {
        // المشاركون للإشعار: حاملو الأدوار التي تملك الصلاحية + كل من له استثناء
        // فردي عليها (منحاً أو سحباً) — ثم يحسم hasPermission لكلٍّ منهم.
        // بلا هذا التضييق كان يُحمَّل كل مستخدمي النظام لفحصهم واحداً واحداً.
        $roleCodes = array_keys(array_filter(
            Permissions::matrix(),
            fn (array $perms) => in_array('*', $perms, true)
                || in_array(Permissions::CANDIDATE_UPDATE_APPROVE, $perms, true)
        ));

        $recipients = User::where('is_active', true)
            ->with(['role', 'permissionOverrides'])
            ->where(fn ($q) => $q
                ->whereHas('role', fn ($r) => $r->whereIn('code', $roleCodes))
                ->orWhereHas('permissionOverrides', fn ($o) => $o->where('permission', Permissions::CANDIDATE_UPDATE_APPROVE)))
            ->get()
            ->filter(fn (User $u) => $u->hasPermission(Permissions::CANDIDATE_UPDATE_APPROVE)
                && ($candidate->classification === 'normal' || $u->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED))
                && $u->coversSector($candidate->sector_id));

        $service = app(NotificationService::class);
        foreach ($recipients as $u) {
            $service->notify(
                $u->id,
                'approval',
                'طلب تحديث بيانات مشارك',
                "طلب من {$requester->full_name} لتحديث بيانات المشارك {$candidate->participant_code}",
                'candidate_update_request',
                (string) $updateRequest->id,
                $requester->id
            );
        }
    }

    // إبلاغ مقدّم الطلب بالنتيجة — بلا رمز المشارك (لا يملك قراءته)
    private function notifyRequester(CandidateUpdateRequest $updateRequest, string $title, ?string $note): void
    {
        if (!$updateRequest->requested_by) {
            return;
        }
        app(NotificationService::class)->notify(
            $updateRequest->requested_by,
            'info',
            $title,
            $note,
            'candidate_update_request',
            (string) $updateRequest->id
        );
    }

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'candidate_update_request',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
