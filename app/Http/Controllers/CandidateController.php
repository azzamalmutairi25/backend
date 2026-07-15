<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Assessment;
use App\Models\Sector;
use App\Models\User;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Rules\SaudiNationalId;
use App\Services\CommunicationService;
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

        // المستخدم المحصور بقطاع لا يرى غير مرشحيه — الحصر قبل أي فلتر يطلبه هو،
        // فلا يوسّعه بتمرير sectorId لقطاع آخر
        $user = $request->user();
        if ($user->isSectorBound()) {
            $query->where('sector_id', $user->sector_id);
        }

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
            return response()->json(['error' => 'المرشح غير موجود'], 404);
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

        // لا يُكشف/يُكتب سجلّ مصنّف لمن لا يملك صلاحيته — نُعامله كأنه غير موجود (منع كشف وجود + طمس بيانات مصنّفة)
        if ($candidate && !in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'تعذّرت المعالجة'], 422);
        }

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
                'confirm_token' => Assessment::generateConfirmToken(),
            ]);
        });

        $this->log($request, $isReturning ? 'REASSESS_CANDIDATE' : 'CREATE_CANDIDATE', $candidate->id, ['code' => $code]);

        $smsSent = $this->sendConfirmationSms($candidate, $assessment, $request->user()->id);

        return response()->json([
            'message' => $isReturning ? 'تمّت إضافة دورة تقييم جديدة لمرشح موجود' : 'تمت إضافة المرشح',
            'participantCode' => $code,
            'tier' => $tier,
            'isReturning' => $isReturning,
            'assessmentId' => $assessment->id,
            'smsSent' => $smsSent,
        ], 201);
    }

    // إرسال رسالة تأكيد للمرشح تحوي بياناته ورابطًا فريدًا للتأكيد والوصول
    private function sendConfirmationSms(Candidate $candidate, Assessment $assessment, ?int $actorId): bool
    {
        $mobile = $candidate->mobile; // فك التشفير عبر المُلحق
        if (!$mobile) {
            return false; // لا جوّال مسجّل — لا رسالة
        }
        $link = rtrim(config('app.frontend_url'), '/') . '/confirm/' . $assessment->confirm_token;
        $name = $candidate->full_name ?: 'المرشح';
        $message = "عزيزي {$name}، تم تسجيلك في مركز تمكين الكفاءات لتقييم القيادات."
            . " رمز المشارك: {$assessment->participant_code}."
            . " لتأكيد بياناتك وتسجيل الوصول: {$link}";

        // فشل الاتصالات يجب ألا يُفشل إضافة المرشح (الدورة أُنشئت فعلاً)
        try {
            return app(CommunicationService::class)->sendSms(
                $mobile, $message, 'invitation', $candidate->id, $actorId
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('confirmation SMS failed: ' . $e->getMessage());
            return false;
        }
    }

    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_EDIT)) {
            return response()->json(['error' => 'ليس لديك صلاحية التعديل'], 403);
        }

        $candidate = Candidate::findOrFail($id);

        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
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

        // نوع التقييم سمة للدورة الحالية — زامن الدورة الأحدث «غير المكتملة» فقط.
        // دورة مكتملة سجلٌّ تاريخي لما جرى فعلاً؛ إعادة كتابة نوعها تُفسد التاريخ (لا نمسّها)
        DB::transaction(function () use ($candidate) {
            $candidate->save();
            $latest = $candidate->assessments()->latest('id')->first();
            if ($latest && $latest->status !== 'completed'
                && $latest->assessment_type !== $candidate->assessment_type) {
                $latest->update(['assessment_type' => $candidate->assessment_type]);
            }
        });

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
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        if (!in_array($candidate->status, ['draft', 'scheduled'])) {
            return response()->json(['error' => 'لا يمكن حذف مرشح بدأت عملية تقييمه'], 422);
        }

        $code = $candidate->participant_code;
        $classification = $candidate->classification;

        // الحذف والتوثيق في معاملة واحدة (ذرّية): إمّا حذف موثّق أو لا حذف — فشل كتابة السجل يُرجِع الحذف،
        // فلا يبقى سجل حذف لمرشح لم يُحذف، ولا حذف مُهلِك بلا أثر تدقيقي (كلاهما غير مقبول لسجل يُلزِم بسبب)
        DB::transaction(function () use ($candidate, $request, $id, $code, $classification, $validated) {
            $candidate->delete();
            $this->log($request, 'DELETE_CANDIDATE', $id, [
                'code' => $code,
                'reason' => $validated['reason'],
                'classification' => $classification,
            ]);
        });

        return response()->json(['message' => "تم حذف المرشح {$code} (موثّق)"]);
    }

    public function approve(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاعتماد'], 403);
        }

        $candidate = Candidate::findOrFail($id);
        // بوابة التصنيف كبقية الإجراءات — مصنّف خارج الصلاحية يُعامَل كـ«غير موجود»
        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        // الاعتماد انتقال مسودة→مجدول فقط — بلا حارس، يعيد اعتماد مرشح مكتمل فيُرجِع دورته من completed إلى scheduled
        if ($candidate->status !== 'draft') {
            return response()->json(['error' => 'لا يمكن اعتماد مرشح غادر حالة المسودة'], 422);
        }
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

    // سجل دورات المرشح مع تقييماتها وتفاصيلها (لعرض التاريخ + التقييم السابق)
    public function assessments(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المرشح'], 403);
        }
        $candidate = Candidate::find($id);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        $assessments = $candidate->assessments()
            ->with(['evaluations.scores.competency', 'evaluations.evaluator', 'report'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'code' => $a->participant_code,
                'status' => $a->status,
                'assessmentType' => $a->assessment_type,
                'createdAt' => $a->created_at,
                'evaluations' => $a->evaluations->map(fn ($e) => [
                    'id' => $e->id,
                    'activity' => $e->activity,
                    'status' => $e->status,
                    'submittedAt' => $e->submitted_at,
                    'evaluatorName' => optional($e->evaluator)->full_name,
                    'notes' => $e->notes,
                    'scores' => $e->scores->map(fn ($s) => [
                        'competency' => optional($s->competency)->name_ar,
                        'score' => $s->score,
                    ])->values(),
                ])->values(),
                'report' => $a->report ? [
                    'status' => $a->report->status,
                    'recommendation' => $a->report->recommendation,
                    'behavioralFit' => $a->report->behavioral_fit,
                    'technicalFit' => $a->report->technical_fit,
                ] : null,
            ]);

        return response()->json(['assessments' => $assessments]);
    }

    // إنشاء دورة تقييم جديدة لمرشح موجود (زر «تقييم جديد»)
    public function reassess(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إنشاء تقييم'], 403);
        }
        $candidate = Candidate::with('sector')->find($id);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        $active = $candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
        if ($active) {
            return response()->json([
                'error' => "لدى المرشح دورة نشطة ({$active->participant_code}) — يجب إكمالها أولاً",
                'participantCode' => $active->participant_code,
            ], 422);
        }

        $code = Assessment::generateParticipantCode($candidate->sector);
        $assessment = DB::transaction(function () use ($candidate, $code, $request) {
            $candidate->participant_code = $code;
            $candidate->status = 'draft';
            $candidate->save();
            return Assessment::create([
                'candidate_id' => $candidate->id,
                'participant_code' => $code,
                'assessment_type' => $candidate->assessment_type ?? 'comprehensive',
                'status' => 'draft',
                'created_by' => $request->user()->id,
                'confirm_token' => Assessment::generateConfirmToken(),
            ]);
        });

        $this->log($request, 'REASSESS_CANDIDATE', $candidate->id, ['code' => $code]);
        $smsSent = $this->sendConfirmationSms($candidate, $assessment, $request->user()->id);
        return response()->json(['message' => 'تمّت إضافة دورة تقييم جديدة', 'participantCode' => $code, 'smsSent' => $smsSent], 201);
    }

    // ── رحلة المرشح: خط زمني كامل (إضافة → جدولة → حضور → تقييم → تقرير → اعتماد) ──
    public function journey(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_JOURNEY)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض رحلة المرشح'], 403);
        }
        $candidate = Candidate::find($id);
        if (!$candidate) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }
        if (!in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'المرشح غير موجود'], 404);
        }

        $assessments = $candidate->assessments()
            ->with(['schedules.attendance', 'evaluations.evaluator', 'report'])
            ->orderBy('id')
            ->get();

        // حلّ أسماء الفاعلين دفعةً واحدة (تفادي N+1)
        $userIds = collect();
        foreach ($assessments as $a) {
            $userIds->push($a->created_by);
            if ($a->report) $userIds->push($a->report->created_by, $a->report->last_returned_by);
            foreach ($a->schedules as $s) {
                $userIds->push($s->evaluator_id);
                if ($s->attendance) $userIds->push($s->attendance->recorded_by);
            }
        }
        $names = User::whereIn('id', $userIds->filter()->unique()->values())->pluck('full_name', 'id');
        $nameOf = fn ($uid) => $uid ? ($names[$uid] ?? null) : null;

        $activityLabel = [
            'interview' => 'المقابلة الشخصية',
            'discussion' => 'حلقة النقاش',
            'measurement' => 'أدوات القياس',
            'integration' => 'التمرين التكاملي',
        ];
        $act = fn ($a) => $activityLabel[$a] ?? $a;

        $events = [];
        $events[] = [
            'type' => 'candidate_created',
            'at' => optional($candidate->created_at)->toIso8601String(),
            'title' => 'أُضيف المرشح إلى النظام',
            'cycle' => null, 'meta' => null, 'actor' => null, 'status' => null,
            'icon' => 'user',
        ];

        foreach ($assessments as $a) {
            $code = $a->participant_code;
            $events[] = [
                'type' => 'cycle_started',
                'at' => optional($a->created_at)->toIso8601String(),
                'title' => 'بدأت دورة تقييم', 'meta' => null, 'status' => null,
                'cycle' => $code, 'actor' => $nameOf($a->created_by), 'icon' => 'refresh',
            ];

            foreach ($a->schedules as $s) {
                $when = $this->toIso(trim(((string) $s->schedule_date) . ' ' . ((string) $s->schedule_time)))
                    ?? optional($s->created_at)->toIso8601String();
                $events[] = [
                    'type' => 'scheduled', 'at' => $when,
                    'title' => 'جدولة: ' . $act($s->activity), 'meta' => $s->location ?: null,
                    'cycle' => $code, 'actor' => $nameOf($s->evaluator_id), 'status' => null,
                    'icon' => 'calendar',
                ];
                if ($s->attendance) {
                    $att = $s->attendance;
                    $present = $att->status === 'present';
                    $events[] = [
                        'type' => 'attendance',
                        'at' => optional($att->check_in_time ?? $att->created_at)->toIso8601String(),
                        'title' => ($present ? 'حضر: ' : 'غياب: ') . $act($s->activity),
                        'meta' => $present ? null : ($att->absence_reason ?: null),
                        'cycle' => $code, 'actor' => $nameOf($att->recorded_by), 'status' => $att->status,
                        'icon' => $present ? 'check' : 'x',
                    ];
                }
            }

            foreach ($a->evaluations as $e) {
                $submitted = $e->status === 'submitted' || $e->submitted_at;
                $events[] = [
                    'type' => 'evaluation',
                    'at' => optional($e->submitted_at ?? $e->created_at)->toIso8601String(),
                    'title' => ($submitted ? 'تسليم تقييم: ' : 'مسودة تقييم: ') . $act($e->activity),
                    'meta' => null, 'cycle' => $code,
                    'actor' => optional($e->evaluator)->full_name, 'status' => $e->status,
                    'icon' => 'clipboard',
                ];
            }

            if ($a->report) {
                $rep = $a->report;
                $events[] = [
                    'type' => 'report_created',
                    'at' => optional($rep->created_at)->toIso8601String(),
                    'title' => 'أُنشئ التقرير النهائي', 'meta' => null,
                    'cycle' => $code, 'actor' => $nameOf($rep->created_by), 'status' => null,
                    'icon' => 'file',
                ];
                if ($rep->last_returned_at) {
                    $events[] = [
                        'type' => 'report_returned',
                        'at' => optional($rep->last_returned_at)->toIso8601String(),
                        'title' => 'أُعيد التقرير للتعديل', 'meta' => $rep->return_reason ?: null,
                        'cycle' => $code, 'actor' => $nameOf($rep->last_returned_by), 'status' => null,
                        'icon' => 'undo',
                    ];
                }
                if ($rep->status === 'approved') {
                    $events[] = [
                        'type' => 'report_approved',
                        'at' => optional($rep->updated_at)->toIso8601String(),
                        'title' => 'اعتُمد التقرير نهائياً', 'meta' => null,
                        'cycle' => $code, 'actor' => null, 'status' => null, 'icon' => 'award',
                    ];
                } elseif (in_array($rep->status, \App\Http\Controllers\ReportController::pendingStatuses(), true)) {
                    $events[] = [
                        'type' => 'report_submitted',
                        'at' => optional($rep->updated_at)->toIso8601String(),
                        'title' => 'أُرسل التقرير للاعتماد', 'meta' => null,
                        'cycle' => $code, 'actor' => null, 'status' => null, 'icon' => 'send',
                    ];
                }
            }
        }

        // ترتيب زمني تصاعدي؛ الأحداث بلا وقت تُوضع في النهاية
        usort($events, function ($x, $y) {
            if ($x['at'] === $y['at']) return 0;
            if ($x['at'] === null) return 1;
            if ($y['at'] === null) return -1;
            return strcmp($x['at'], $y['at']);
        });

        $this->log($request, 'VIEW_CANDIDATE_JOURNEY', $candidate->id);

        return response()->json([
            'candidate' => ['code' => $candidate->participant_code, 'status' => $candidate->status],
            'journey' => $events,
        ]);
    }

    // تحويل نص تاريخ/وقت إلى ISO8601 بأمان (يرجع null عند الفشل)
    private function toIso($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        if (!$value) return null;
        try { return \Illuminate\Support\Carbon::parse($value)->toIso8601String(); }
        catch (\Throwable $e) { return null; }
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
        // يدعم قيمة واحدة أو عدّة حالات مفصولة بفواصل (كما في index) — وإلا رجع تصدير فارغ لفلتر متعدّد
        if ($request->filled('status')) $query->whereIn('status', explode(',', $request->status));
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
