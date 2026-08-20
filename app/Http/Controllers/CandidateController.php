<?php

namespace App\Http\Controllers;

use App\Exceptions\CvTooLargeException;
use App\Models\Candidate;
use App\Models\Assessment;
use App\Models\CandidateCv;
use App\Models\CandidateUpdateRequest;
use App\Models\Sector;
use App\Models\User;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Rules\SaudiNationalId;
use App\Services\CommunicationService;
use App\Services\CvGuard;
use App\Services\CvValidator;
use App\Services\IdentityVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CandidateController extends Controller
{
    // ── الفرز: أسماء الواجهة ← أعمدة القاعدة ──
    //
    // قائمةٌ مغلقة لا تمرير مباشر: `orderBy($request->sort)` يضع نصّاً من
    // المستخدم في جملة SQL. وتُشتقّ منها قاعدة التحقّق فيبقى المفتاحان —
    // المسموح والمترجَم — شيئاً واحداً لا شيئين يفترقان.
    private function sortable(): array
    {
        return [
            'code' => 'participant_code',
            'rank' => 'rank_label',
            'tier' => 'tier',
            'status' => 'status',
            'classification' => 'classification',
            'created' => 'created_at',
            // اسم القطاع لا معرّفه — الترتيب الهجائي هو ما يراه المستخدم.
            // استعلامٌ مرتبط لا انضمام: الانضمام يُدخِل أعمدة sectors في
            // النتيجة فيتصادم `id` مع `candidates.id` بصمت.
            'sector' => fn ($q, $dir) => $q->orderBy(
                Sector::select('name_ar')->whereColumn('sectors.id', 'candidates.sector_id'),
                $dir
            ),
        ];
    }

    public function index(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المشاركين'], 403);
        }

        $request->validate($this->listPagingRules($this->sortable()));

        // المجالات تُحمَّل مع الصفحة لا مع كل صفّ — شاشة الترشيح تعرضها وتفلتر بها
        $query = Candidate::with(['sector', 'technicalAreas']);
        $query->whereIn('classification', $this->allowedClassifications($request));

        // المستخدم المحصور بقطاع لا يرى غير مشاركيه — الحصر قبل أي فلتر يطلبه هو،
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

        // نسخةٌ من الاستعلام المحصور قبل تنفيذه — تُستعمل استعلاماً فرعياً أدناه
        $scopedIds = (clone $query)->select('candidates.id');

        $meta = $this->applyListPaging($request, $query, $this->sortable(), 'code', 'participant_code');

        $candidates = $query->get();

        // المشاركون الذين لهم جلسة غياب مسجّلة — استعلام واحد لا N+1.
        // يظهر لهم في الواجهة علم غياب وخيار إعادة الجدولة بتاريخ جديد.
        //
        // استعلامٌ فرعي لا قائمة معرّفات: كان يُمرّر pluck('id') فيُبنى شرط
        // IN بعدد صفوف القائمة كلّها. على مركزٍ فيه عشرون ألف مشارك يصير
        // نصّ الاستعلام وحده مئات الكيلوبايتات تُرسَل في كل فتحة للشاشة.
        // القاعدة تحصر بنفسها هنا، فلا تعبر المعرّفات الشبكة أصلاً.
        $absentIds = \App\Models\Schedule::query()
            ->whereIn('candidate_id', $scopedIds)
            ->whereHas('attendance', fn ($q) => $q->whereIn('status', ['absent_excused', 'absent_unexcused']))
            ->pluck('candidate_id')->unique()->flip();

        $rows = $candidates->map(fn ($c) => [
            'id' => $c->id,
            'participantCode' => $c->participant_code,
            'sectorName' => $c->sector->name_ar,
            'sectorId' => $c->sector_id,
            'gender' => $c->gender,
            'rankLabel' => $c->rank_label,
            'personnelCategory' => $c->personnel_category,
            'tier' => $c->tier,
            'assessmentType' => $c->assessment_type,
            'status' => $c->status,
            'classification' => $c->classification,
            'technicalAreas' => $c->technicalAreas->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->label_ar,
            ])->values(),
            'hasAbsence' => $absentIds->has($c->id),
        ]);

        // مُضافة لا بديلة: العميل القديم يقرأ candidates ويتجاهل هذه
        return response()->json([
            'candidates' => $rows,
            'meta' => $meta + ['shown' => $rows->count()],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المشاركين'], 403);
        }
        // النطاق كاملاً — التصنيف والقطاع معاً. كان يفحص التصنيف وحده بينما
        // index() محصور بالقطاع، فكانت التفاصيل الكاملة تُفتح بالمعرّف لمشارك
        // من قطاع آخر لا يظهر في القائمة أصلاً.
        $candidate = $this->resolveCandidateInScope($request, $id, ['sector', 'technicalAreas']);
        if (!$candidate) {
            $this->log($request, 'DENIED_CANDIDATE_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'المشارك غير موجود'], 404);
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
            'sectorName' => $candidate->sector->name_ar,
            'sectorId' => $candidate->sector_id,
            'gender' => $candidate->gender,
            'technicalAreaIds' => $candidate->technicalAreas->pluck('id')->values(),
            'notes' => $candidate->notes,
            'technicalAreas' => $candidate->technicalAreas->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->label_ar,
            ])->values(),
            'rankLabel' => $candidate->rank_label,
            'personnelCategory' => $candidate->personnel_category,
            'tier' => $candidate->tier,
            'assessmentType' => $candidate->assessment_type,
            'status' => $candidate->status,
            'classification' => $candidate->classification,
            'createdAt' => $candidate->created_at,
            'trail' => array_slice(array_reverse($this->writeTrail($candidate)), 0, 6),
            'canSeeNames' => $canSeeNames,
        ]]);
    }

    // ── PATCH /candidates/{id}/notes — الملاحظات وحدها ──
    // مسارٌ مستقلّ عن `update` عمداً: ذاك يشترط الهوية والاسم في حمولته،
    // وهما يُحجبان عمّن لا يملك CANDIDATE_VIEW_NAMES — فكان مَن يرى المشارك
    // بلا اسمه لا يستطيع كتابة ملاحظةٍ عليه، أو يُضطرّ أن يُعيد إرسال هويةٍ
    // لا يراها أصلاً. والملاحظة ليست بياناً شخصياً يُحرَس بحارسه.
    public function updateNotes(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_EDIT)) {
            return response()->json(['error' => 'ليس لديك صلاحية تعديل المشارك'], 403);
        }

        $candidate = $this->resolveCandidateInScope($request, $id);
        if (!$candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        $validated = $request->validate(['notes' => 'nullable|string|max:2000']);

        $candidate->notes = $validated['notes'] ?: null;
        $candidate->save();

        $this->log($request, 'UPDATE_CANDIDATE_NOTES', $candidate->id, [
            'code' => $candidate->participant_code,
        ]);

        return response()->json(['message' => 'حُفظت الملاحظات', 'notes' => $candidate->notes]);
    }

    // GET /candidates/{id}/cv — قراءة السيرة (مسار الإدارة، صلاحية مستقلّة)
    public function showCv(Request $request, int $id)
    {
        $user = $request->user();
        // صلاحية مستقلّة عن CANDIDATE_VIEW: المقيّم/الاستقبال/القياس لا يصلون سيرة بالمعرّف
        if (!$user->hasPermission(Permissions::CANDIDATE_CV_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض السيرة الذاتية'], 403);
        }
        $candidate = $this->resolveCandidateInScope($request, $id, ['cv']);
        if (!$candidate) {
            $this->log($request, 'DENIED_CANDIDATE_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        $cv = $candidate->cv;
        $doc = $cv?->data ?? CandidateCv::emptyDoc(); // السجلّ الحيّ (وثيقة الإدارة)
        $canSeeNames = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        if (!$canSeeNames) {
            $doc = CvGuard::scrub($doc, $candidate);
        }

        $this->log($request, 'VIEW_CV_ADMIN', $id, ['code' => $candidate->participant_code]);

        // إقرار المشارك برتبته لا يستبدل الرتبة الرسمية في ملفّه — الرتبة تقود
        // تصنيف الفئة القيادية، فتغييرها من بوّابة عامة يعبث بالتصنيف. لكنّ
        // الاختلاف يُعلَن هنا كي تراجعه الإدارة وتحسم أيّهما الصحيح.
        $declared = $doc['rankLabel'] ?? null;
        $official = $candidate->rank_label;
        $mismatch = $declared !== null && $declared !== ''
            && $official !== null && $official !== ''
            && trim($declared) !== trim($official);

        return response()->json(['cv' => [
            'participantCode' => $candidate->participant_code,
            'name' => $canSeeNames ? $candidate->full_name : null,
            'hasCv' => !CandidateCv::isEmptyDoc($doc),
            'version' => $cv?->version ?? 0,
            'source' => $cv?->source,
            'document' => $doc,
            'age' => CandidateCv::ageFrom($doc['birthDate'] ?? null),
            'officialRank' => $official,
            'rankMismatch' => $mismatch,
            'canSeeNames' => $canSeeNames,
        ]]);
    }

    // PUT /candidates/{id}/cv — تعديل الإدارة للسيرة (تصحيحات) — حيّ فقط، بلا قفل
    public function saveCv(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_EDIT)) {
            return response()->json(['error' => 'ليس لديك صلاحية تعديل السيرة'], 403);
        }
        $candidate = $this->resolveCandidateInScope($request, $id, ['cv']);
        if (!$candidate) {
            $this->log($request, 'DENIED_CANDIDATE_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        $cvInput = $request->input('cv');
        if (!is_array($cvInput) || $cvInput === []) {
            return response()->json(['error' => 'بيانات غير صحيحة'], 422);
        }

        try {
            $clean = app(CvValidator::class)->clean($cvInput);
        } catch (CvTooLargeException $e) {
            return response()->json(['error' => 'عناصر أكثر من المسموح'], 413);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'بيانات غير صحيحة', 'fields' => $e->errors()], 422);
        }

        // الإدارة ليست معفاة من فحص التسرّب — لا تُدخل اسم المشارك في وثيقة يراها المقيّم
        if ($hit = CvGuard::directIdentifierHit($clean, $candidate)) {
            return response()->json(['error' => 'السيرة تحوي اسم المشارك أو معرّفاً — أزِله', 'field' => $hit], 422);
        }

        $result = DB::transaction(function () use ($candidate, $clean, $request, $user) {
            Candidate::whereKey($candidate->id)->lockForUpdate()->first();
            $cv = CandidateCv::firstOrNew(['candidate_id' => $candidate->id]);
            $expected = $request->input('expectedVersion');
            if ($cv->exists && ($expected === null || (int) $expected !== (int) $cv->version)) {
                return 'conflict';
            }
            $cv->data = $clean;
            $cv->version = ($cv->version ?? 0) + 1;
            $cv->source = 'admin';
            $cv->updated_by = $user->id;
            try {
                $cv->save();
            } catch (QueryException $e) {
                return 'conflict';
            }
            return $cv->fresh();
        });

        if ($result === 'conflict') {
            return response()->json(['error' => 'عُدّلت السيرة، أعد التحميل'], 409);
        }

        $this->log($request, 'CV_UPDATE', $id, ['code' => $candidate->participant_code]);

        return response()->json(['message' => 'تم حفظ السيرة', 'version' => $result->version]);
    }

    // ══ فحص تكرار الهوية قبل ملء النموذج ══
    // «هل هذا مسجَّل عندنا؟» سؤالٌ كان لا يُجاب إلا بمحاولة الحفظ: يُملأ نموذجٌ
    // من اثني عشر حقلاً وسيرةٌ من عشرين، ثمّ يُردّ بأنه مُضاف مسبقاً. هنا يُجاب
    // عنه لحظة كتابة الهوية — قبل أن يُبذل العمل الذي سيُرمى.
    //
    // وهو سطحُ تعدادٍ بطبعه: تُجرَّب أرقامُ هويةٍ فيُعرف أصحابُها مسجَّلين. لذا
    // قُيّد بأربعة — صلاحيةُ الإضافة، وخنقٌ بالمعدّل، وردٌّ بلا اسمٍ ولا رمز لمن
    // لا يملك التعديل، وقيدٌ في السجلّ عند كل إصابة. والمصنَّف فوق درجة الطالب
    // يُردّ «غير موجود» كما في store: النفيُ الصادق هنا كشفٌ لوجوده.
    //
    // POST لا GET رغم أنه قراءة: الهوية في مسار GET تُكتب في سجلّات الخادم
    // الوسيط وفي تاريخ المتصفّح — بيانٌ شخصي يتسرّب إلى حيث لا يُحمى.
    public function lookup(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إضافة مشارك'], 403);
        }

        $validated = $request->validate([
            'nationalId' => ['required', 'string', new SaudiNationalId()],
        ]);

        $candidate = Candidate::where('national_id_hash', hash('sha256', $validated['nationalId']))->first();

        if (!$candidate || !in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['exists' => false]);
        }

        $this->log($request, 'LOOKUP_DUPLICATE_CANDIDATE', $candidate->id);

        $canEdit = $request->user()->hasPermission(Permissions::CANDIDATE_EDIT);
        $active = $candidate->assessments()
            ->where('status', '!=', 'completed')->orderByDesc('id')->first();

        return response()->json([
            'exists' => true,
            'addedAt' => optional($candidate->created_at)->toIso8601String(),
            // دورةٌ نشطة تمنع إنشاء دورة جديدة — يُعلَم بها قبل أن يملأ النموذج
            'hasActiveCycle' => (bool) $active,
            // الرمز مفتاحُ الوصول إلى المشارك، فلا يُردّ إلا لمن يملك تعديله:
            // ردُّه لكل من جرّب هويةً يُحوّل هذا الباب إلى حاصدة رموز
            'activeCode' => $canEdit && $active ? $active->participant_code : null,
            'canEdit' => $canEdit,
            'canRequestUpdate' => $request->user()->hasPermission(Permissions::CANDIDATE_UPDATE_REQUEST),
        ]);
    }

    public function store(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_CREATE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إضافة مشارك'], 403);
        }

        $validated = $request->validate([
            'nationalId' => ['required', 'string', new SaudiNationalId()],
            'fullName' => 'required|string|max:200',
            'mobile' => ['nullable', 'string', 'regex:/^05\d{8}$/'],
            'sectorId' => 'required|exists:sectors,id',
            'gender' => 'required|in:' . implode(',', Candidate::GENDERS),
            'rankLabel' => 'required|string',
            // الفئة صفةُ الشخص لا صفةُ قطاعه — عليها تُبنى قائمة الرتب والطبقة
            'personnelCategory' => 'required|in:civilian,military,contractor',
            // المتعاقد بلا قائمة مُدارة، فطبقته تُرسَل صراحةً لا تُستنتج من مسمّاه
            'tier' => 'required_if:personnelCategory,contractor|nullable|in:upper,middle',
            'assessmentType' => 'nullable|in:' . implode(',', Assessment::TYPES),
            'classification' => 'nullable|in:normal,secret,top_secret',
            // ── المجالات الفنية: تُحدَّد بعد الإضافة لا معها ──
            // كانت شرطاً هنا، وهي أبطأ قرارٍ في النموذج: المُدخِل يعرف الهوية
            // والرتبة والقطاع فوراً، ولا يعرف مجالات المشارك إلا بعد مراجعة
            // سيرته أو سؤال جهته — فكان النموذج كلّه يقف على أبطأ حقوله.
            //
            // فبقيت مقبولةً هنا لمن يعرفها (والاستيراد يرسلها)، وصارت شرطاً
            // في التعديل حيث تُستكمل. وثمنُ ذلك مُعلَن: مشاركٌ بلا مجال لا
            // يظهر في أي قائمة ترشيح — ولذلك تردّ الاستجابة `needsTechnicalAreas`
            // لتسوق الشاشة إلى استكمالها فور الحفظ.
            'technicalAreaIds' => 'nullable|array',
            'technicalAreaIds.*' => 'integer|exists:technical_areas,id',
            // ملاحظاتٌ حرّة اختيارية — ما كان لها موضع فكانت تُكتب في حقولٍ
            // ليست لها (الإدارة، المسمّى) فتُفسدها للتصفية والتقارير
            'notes' => 'nullable|string|max:2000',
        ], [
            'gender.required' => 'اختر الجنس',
        ]);

        // ── نموذج السيرة الذاتية المرافق — إلزامي ──
        // كانت اختيارية، فكان يدخل مشاركٌ بلا سيرة ثم يقف عند الترشيح بلا سببٍ
        // ظاهر. الآن تصل مع بياناته في نداء واحد من كل باب: النموذج اليدوي،
        // وبوّابة الإضافة الخارجية، والاستيراد. التحقّق البنيوي هنا (رخيص، يفشل
        // مبكراً)؛ وفحص تسرّب الاسم يؤجَّل إلى ما بعد تعبئة بيانات المشارك.
        $cvInput = $request->input('cv');
        if (!is_array($cvInput) || $cvInput === []) {
            return response()->json([
                'error' => 'السيرة الذاتية إلزامية — أكمل بيانات السيرة قبل الحفظ',
                'fields' => ['cv' => ['السيرة الذاتية إلزامية']],
            ], 422);
        }
        if (strlen($request->getContent()) > CvValidator::MAX_BYTES) {
            return response()->json(['error' => 'الحجم كبير جداً'], 413);
        }
        try {
            $cleanCv = app(CvValidator::class)->clean($cvInput);
        } catch (CvTooLargeException $e) {
            return response()->json(['error' => 'عناصر أكثر من المسموح'], 413);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'بيانات السيرة غير صحيحة', 'fields' => $e->errors()], 422);
        }

        $sector = Sector::findOrFail($validated['sectorId']);
        $category = $validated['personnelCategory'];
        $tier = Candidate::resolveTier($category, $validated['rankLabel'], $validated['tier'] ?? null);
        $assessmentType = $validated['assessmentType'] ?? 'comprehensive';

        // ديدَاب الشخص بالهوية — شخص واحد ← عدة دورات/رموز
        $candidate = Candidate::where('national_id_hash', hash('sha256', $validated['nationalId']))->first();
        $isReturning = (bool) $candidate;

        // لا يُكشف/يُكتب سجلّ مصنّف لمن لا يملك صلاحيته — نُعامله كأنه غير موجود (منع كشف وجود + طمس بيانات مصنّفة)
        if ($candidate && !in_array($candidate->classification, $this->allowedClassifications($request))) {
            return response()->json(['error' => 'تعذّرت المعالجة'], 422);
        }

        if ($candidate) {
            // ── مشارك مسجّل مسبقاً، والمُدخِل لا يملك التعديل (المستخدم الخارجي) ──
            // الكتابة فوق سجلٍّ قائم تعديلٌ لا إنشاء — تتطلّب CANDIDATE_EDIT.
            // بدونها كان EXTERNAL_ADD (وصلاحيته الإضافة وحدها) يعيد تسمية أي
            // مشارك ونقله بين القطاعات بمجرّد «إضافته» بهويته.
            //
            // الفحص قبل حارس الدورة النشطة عمداً: ذاك يُرجِع رمز المشارك في نصّ
            // خطئه، فكان مَن لا يملك القراءة يحصد الرموز بتجريب أرقام الهوية.
            // هنا يُعلَم بالتسجيل السابق وتاريخه فقط — لا رمز ولا اسم — ويُفتح
            // له طريق «طلب تحديث البيانات» ليمرّ التغيير باعتماد صاحب صلاحية.
            if (!$request->user()->hasPermission(Permissions::CANDIDATE_EDIT)) {
                $pending = CandidateUpdateRequest::where('candidate_id', $candidate->id)
                    ->pending()->first();
                $this->log($request, 'DUPLICATE_CANDIDATE_ADD', $candidate->id);

                return response()->json([
                    'error' => 'هذا المشارك مُضاف مسبقاً في النظام',
                    'duplicate' => true,
                    'candidateId' => $candidate->id,
                    'addedAt' => optional($candidate->created_at)->toIso8601String(),
                    'canRequestUpdate' => $request->user()->hasPermission(Permissions::CANDIDATE_UPDATE_REQUEST),
                    'pendingRequest' => $pending ? [
                        'id' => $pending->id,
                        'createdAt' => optional($pending->created_at)->toIso8601String(),
                    ] : null,
                ], 403);
            }

            // امنع دورة جديدة إن كانت له دورة نشطة (لم تكتمل) — «كل رمز له تقييم»
            $active = $candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
            if ($active) {
                return response()->json([
                    'error' => "لدى المشارك دورة تقييم نشطة ({$active->participant_code}) — يجب إكمالها قبل إنشاء دورة جديدة",
                    'participantCode' => $active->participant_code,
                ], 422);
            }

            // تحديث بيانات الشخص للأحدث (قد يكون تغيّر قطاعه/رتبته). التصنيف يُدار عبر reclassify فقط.
            $candidate->full_name = $validated['fullName'];
            $candidate->mobile = $validated['mobile'] ?? null;
            $candidate->sector_id = $sector->id;
            $candidate->gender = $validated['gender'];
            $candidate->rank_label = $validated['rankLabel'];
            $candidate->personnel_category = $category;
            $candidate->tier = $tier;
            // الملاحظة تُكتب فوق سابقتها فقط إن أُرسلت — الدورة الجديدة لا
            // تمحو ملاحظةً كُتبت على الشخص في دورةٍ ماضية بلا أن يُطلَب ذلك
            if (array_key_exists('notes', $validated)) $candidate->notes = $validated['notes'];
        } else {
            // شخص جديد
            $candidate = new Candidate();
            $candidate->national_id = $validated['nationalId']; // mutator: تشفير + hash
            $candidate->full_name = $validated['fullName'];
            $candidate->mobile = $validated['mobile'] ?? null;
            $candidate->sector_id = $sector->id;
            $candidate->gender = $validated['gender'];
            $candidate->rank_label = $validated['rankLabel'];
            $candidate->personnel_category = $category;
            $candidate->tier = $tier;
            $candidate->notes = $validated['notes'] ?? null;
            // تعيين تصنيف أمني يتطلب صلاحية VIEW_CLASSIFIED — منع التصعيد
            $requestedClass = $validated['classification'] ?? 'normal';
            if ($requestedClass !== 'normal' && !$request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
                return response()->json(['error' => 'ليس لديك صلاحية تعيين تصنيف أمني'], 403);
            }
            $candidate->classification = $requestedClass;
        }

        // فحص تسرّب هوية المشارك داخل السيرة — يحتاج بياناته، فيقع بعد تعبئتها.
        // المُدخِل الخارجي ليس معفىً منه: السيرة تصل المقيّم بلا اسم.
        if ($cleanCv !== null && ($hit = CvGuard::directIdentifierHit($cleanCv, $candidate))) {
            return response()->json([
                'error' => 'السيرة تحوي اسم المشارك أو معرّفاً — أزِله',
                'field' => $hit,
            ], 422);
        }

        // مصدر السيرة: من لا يملك التعديل جهةٌ خارجية لا إدارة — يُعلَن للمراجع
        $cvSource = $request->user()->hasPermission(Permissions::CANDIDATE_EDIT) ? 'admin' : 'external';

        // دورة تقييم جديدة برمز فريد + مزامنة الحقول «الحالية» على سجل الشخص
        $code = Assessment::generateParticipantCode($sector);
        $candidate->participant_code = $code;
        $candidate->status = 'draft';
        $candidate->assessment_type = $assessmentType;

        // فرقٌ جوهري بين «لم تُرسَل» و«أُرسلت فارغة»: المشارك العائد له مجالات
        // من دورةٍ سابقة، وsync([]) كان يمحوها لمجرّد أن النموذج لم يعد يرسلها.
        // null ⇒ لا تُمسّ، ومصفوفةٌ ⇒ تحلّ محلّها.
        $areaIds = $validated['technicalAreaIds'] ?? null;

        $assessment = DB::transaction(function () use ($candidate, $code, $assessmentType, $request, $cleanCv, $cvSource, $areaIds) {
            $candidate->save();

            // السيرة تُحفظ داخل المعاملة: إمّا مشارك بسيرته أو لا مشارك —
            // لا سجلّ ناقص يستدعي إعادة إدخال النموذج كاملاً
            $cv = CandidateCv::firstOrNew(['candidate_id' => $candidate->id]);
            $cv->data = $cleanCv;
            $cv->version = ($cv->version ?? 0) + 1;
            $cv->source = $cvSource;
            $cv->updated_by = $request->user()->id;
            $cv->save();

            // المجالات استبدالٌ لا إضافة: الدورة الجديدة تُعيد وصف المشارك،
            // ووسمٌ من دورةٍ سابقة يبقى فيُرشَّح على مجالٍ لم يعد يوصف به
            if ($areaIds !== null) {
                $candidate->technicalAreas()->sync($areaIds);
            }

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

        // التحقق من الهوية عبر البوّابة الخارجية — فقط إن كانت مُعَدّة (وإلا لا أثر).
        // fail-open: نتيجة سلبية/فشل لا توقف الإضافة (الدورة أُنشئت)، بل تُسجَّل وتُبلَّغ.
        $idVerification = null;
        if (IdentityVerificationService::isConfigured()) {
            $r = app(IdentityVerificationService::class)
                ->verifyAndLog($validated['nationalId'], $candidate->id, $request->user()->id);
            $idVerification = ['status' => $r['status'], 'matched' => $r['matched']];
        }

        $smsQueued = $this->sendConfirmationSms($candidate, $assessment, $request->user()->id);

        return response()->json([
            'message' => $isReturning ? 'تمّت إضافة دورة تقييم جديدة لمشارك موجود' : 'تمت إضافة المشارك',
            'participantCode' => $code,
            'candidateId' => $candidate->id,
            'tier' => $tier,
            'isReturning' => $isReturning,
            'assessmentId' => $assessment->id,
            'cvSaved' => $cleanCv !== null,
            'smsQueued' => $smsQueued,
            'idVerification' => $idVerification,
            // ثمنُ رفع المجالات من نموذج الإضافة، مدفوعاً في العلن: مشاركٌ بلا
            // مجالٍ فنيّ لا يظهر في أي قائمة ترشيح. تُحسب من العلاقة بعد الحفظ
            // لا من المُدخَل، فتصدق على العائد الذي وَرِث مجالات دورةٍ سابقة.
            'needsTechnicalAreas' => $candidate->technicalAreas()->count() === 0,
        ], 201);
    }

    // إرسال رسالة تأكيد للمشارك تحوي بياناته ورابطًا فريدًا للتأكيد والوصول
    private function sendConfirmationSms(Candidate $candidate, Assessment $assessment, ?int $actorId): bool
    {
        $mobile = $candidate->mobile; // فك التشفير عبر المُلحق
        if (!$mobile) {
            return false; // لا جوّال مسجّل — لا رسالة
        }
        $name = $candidate->full_name ?: 'المشارك';
        $message = "عزيزي {$name}، تم تسجيلك في مركز تمكين الكفاءات لتقييم القيادات."
            . " رمز المشارك: {$assessment->participant_code}.";

        // رابط البوّابة يُضاف متى كانت مُشغَّلة وحدها. مع تعطيلها يبقى رمزُ
        // المشارك — وهو المفيد فعلاً عند الاستقبال — ويسقط رابطٌ يفتح صفحة
        // فارغة. رسالةٌ تحمل رابطاً ميتاً أسوأ من رسالةٍ بلا رابط.
        if (config('features.candidate_portal')) {
            $link = rtrim(config('app.frontend_url'), '/') . '/confirm/' . $assessment->confirm_token;
            $message .= " لتأكيد بياناتك وتسجيل الوصول: {$link}";
        } else {
            $message .= ' وسيصلك موعد الحضور من إدارة المركز.';
        }

        // غير متزامن: لا نحبس دورة الطلب بانتظار البوّابة (قد تتأخّر 10ث أو تسقط).
        // queueSms ينشئ سجلّاً معلّقاً ويجدول التسليم؛ يرجع true أي «جُدولت» لا «أُرسلت».
        // فشل الجدولة (كتابة السجلّ) لا يُفشل إضافة المشارك (الدورة أُنشئت فعلاً).
        try {
            return app(CommunicationService::class)->queueSms(
                $mobile, $message, 'invitation', $candidate->id, $actorId
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('confirmation SMS queue failed: ' . $e->getMessage());
            return false;
        }
    }

    public function update(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_EDIT)) {
            return response()->json(['error' => 'ليس لديك صلاحية التعديل'], 403);
        }

                $candidate = $this->resolveCandidateInScope($request, $id);
        if (!$candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        $validated = $request->validate([
            'nationalId' => ['required', 'string', new SaudiNationalId()],
            'fullName' => 'required|string|max:200',
            'mobile' => ['nullable', 'string', 'regex:/^05\d{8}$/'],
            'sectorId' => 'required|exists:sectors,id',
            'gender' => 'required|in:' . implode(',', Candidate::GENDERS),
            'rankLabel' => 'required|string',
            // الفئة صفةُ الشخص لا صفةُ قطاعه — عليها تُبنى قائمة الرتب والطبقة
            'personnelCategory' => 'required|in:civilian,military,contractor',
            // المتعاقد بلا قائمة مُدارة، فطبقته تُرسَل صراحةً لا تُستنتج من مسمّاه
            'tier' => 'required_if:personnelCategory,contractor|nullable|in:upper,middle',
            'assessmentType' => 'nullable|in:' . implode(',', Assessment::TYPES),
            'classification' => 'nullable|in:normal,secret,top_secret',
            'technicalAreaIds' => 'required|array|min:1',
            'technicalAreaIds.*' => 'integer|exists:technical_areas,id',
            'notes' => 'nullable|string|max:2000',
        ], [
            'gender.required' => 'اختر الجنس',
            'technicalAreaIds.required' => 'اختر مجالاً فنياً واحداً على الأقل',
            'technicalAreaIds.min' => 'اختر مجالاً فنياً واحداً على الأقل',
        ]);

        if (Candidate::nationalIdExists($validated['nationalId'], $id)) {
            return response()->json(['error' => 'رقم الهوية مسجّل مسبقاً لمشارك آخر'], 422);
        }

        $sector = Sector::findOrFail($validated['sectorId']);
        $category = $validated['personnelCategory'];
        $tier = Candidate::resolveTier($category, $validated['rankLabel'], $validated['tier'] ?? null);

        $candidate->national_id = $validated['nationalId'];
        $candidate->full_name = $validated['fullName'];
        $candidate->mobile = $validated['mobile'] ?? null;
        $candidate->sector_id = $sector->id;
        $candidate->gender = $validated['gender'];
        $candidate->rank_label = $validated['rankLabel'];
        $candidate->personnel_category = $category;
        $candidate->tier = $tier;
        $candidate->notes = $validated['notes'] ?? null;
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
        $areaIds = $validated['technicalAreaIds'];

        DB::transaction(function () use ($candidate, $areaIds) {
            $candidate->save();
            $candidate->technicalAreas()->sync($areaIds);
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

        return response()->json(['message' => 'تم تحديث بيانات المشارك', 'tier' => $tier]);
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

                $candidate = $this->resolveCandidateInScope($request, $id);
        if (!$candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        if (!in_array($candidate->status, ['draft', 'scheduled'])) {
            return response()->json(['error' => 'لا يمكن حذف مشارك بدأت عملية تقييمه'], 422);
        }

        $code = $candidate->participant_code;
        $classification = $candidate->classification;

        // الحذف والتوثيق في معاملة واحدة (ذرّية): إمّا حذف موثّق أو لا حذف — فشل كتابة السجل يُرجِع الحذف،
        // فلا يبقى سجل حذف لمشارك لم يُحذف، ولا حذف مُهلِك بلا أثر تدقيقي (كلاهما غير مقبول لسجل يُلزِم بسبب)
        DB::transaction(function () use ($candidate, $request, $id, $code, $classification, $validated) {
            $candidate->delete();
            $this->log($request, 'DELETE_CANDIDATE', $id, [
                'code' => $code,
                'reason' => $validated['reason'],
                'classification' => $classification,
            ]);
        });

        return response()->json(['message' => "تم حذف المشارك {$code} (موثّق)"]);
    }

    public function approve(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_APPROVE)) {
            return response()->json(['error' => 'ليس لديك صلاحية الاعتماد'], 403);
        }

        // النطاق كاملاً — مصنّف أو خارج القطاع يُعامَل كـ«غير موجود»
        $candidate = $this->resolveCandidateInScope($request, $id);
        if (!$candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }
        // الاعتماد انتقال مسودة→مجدول فقط — بلا حارس، يعيد اعتماد مشارك مكتمل فيُرجِع دورته من completed إلى scheduled
        if ($candidate->status !== 'draft') {
            return response()->json(['error' => 'لا يمكن اعتماد مشارك غادر حالة المسودة'], 422);
        }
        $candidate->setStatus('scheduled'); // يزامن الدورة الحالية
        $this->log($request, 'APPROVE_CANDIDATE', $id, ['code' => $candidate->participant_code]);

        return response()->json(['message' => 'تم اعتماد المشارك']);
    }

    public function reclassify(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_VIEW_CLASSIFIED)) {
            return response()->json(['error' => 'ليس لديك صلاحية تغيير التصنيف'], 403);
        }

        $validated = $request->validate([
            'classification' => 'required|in:normal,secret,top_secret',
        ]);

        // النطاق: حامل VIEW_CLASSIFIED يرى كل التصنيفات، لكن حدّ القطاع يبقى
        // قائماً — لا يُصنَّف مشارك خارج قطاع من يصنّفه
        $candidate = $this->resolveCandidateInScope($request, $id);
        if (!$candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }
        $old = $candidate->classification;
        $candidate->update(['classification' => $validated['classification']]);

        $this->log($request, 'RECLASSIFY_CANDIDATE', $id, [
            'code' => $candidate->participant_code,
            'from' => $old,
            'to' => $validated['classification'],
        ]);

        return response()->json(['message' => 'تم تحديث التصنيف']);
    }

    // سجل دورات المشارك مع تقييماتها وتفاصيلها (لعرض التاريخ + التقييم السابق)
    public function assessments(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المشارك'], 403);
        }
        // النطاق كاملاً: التصنيف + القطاع — الحارس الموحّد في Controller
        $candidate = $this->resolveCandidateInScope($request, $id);
        if (!$candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
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

    // إنشاء دورة تقييم جديدة لمشارك موجود (زر «تقييم جديد»)
    public function reassess(Request $request, int $id)
    {
        // القراءة شرط الكتابة: لا يُعاد تقييم من لا يُرى. بلا CANDIDATE_VIEW كان
        // EXTERNAL_ADD — وصلاحيته الوحيدة الإضافة — يمرّ بالمعرّفات ١، ٢، ٣… فيفرّق
        // بردّ الخادم بين الموجود والمعدوم، ويحصد رموز المشاركين من رسالة الخطأ.
        $user = $request->user();
        if (!$user->hasPermission(Permissions::CANDIDATE_CREATE) || !$user->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية إنشاء تقييم'], 403);
        }
        $candidate = $this->resolveCandidateInScope($request, $id, ['sector']);
        if (!$candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        $active = $candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
        if ($active) {
            // الرمز يُعاد لأن المستخدم يملك CANDIDATE_VIEW — يراه في القائمة أصلاً
            return response()->json([
                'error' => "لدى المشارك دورة نشطة ({$active->participant_code}) — يجب إكمالها أولاً",
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
        $smsQueued = $this->sendConfirmationSms($candidate, $assessment, $request->user()->id);
        return response()->json(['message' => 'تمّت إضافة دورة تقييم جديدة', 'participantCode' => $code, 'smsQueued' => $smsQueued], 201);
    }

    // ── رحلة المشارك: خط زمني كامل (إضافة → جدولة → حضور → تقييم → تقرير → اعتماد) ──
    // ══ أثرُ الكتابة على سجلّ المشارك: من أضاف، ومن عدّل، ومتى ══
    //
    // كان هذا الجواب محبوساً في شاشة التدقيق خلف AUDIT_VIEW — صلاحيةٌ لا
    // يملكها إلا دوران، وليس منهما مسؤولُ الجدولة الذي يُدخل المشاركين
    // ويعدّلهم. فصار يُقرأ من موضعين: درجُ التفاصيل لكل من يرى المشارك،
    // ورحلتُه الكاملة لمن يملك عرضها.
    //
    // وأفعال **القراءة** خارجه عمداً: «فلانٌ اطّلع على البيانات الشخصية»
    // سجلٌّ رقابي موضعُه شاشة التدقيق بحارسها — لا درجٌ يفتحه كل من يرى
    // المشارك. القائمة بيضاء لا سوداء، فالفعلُ الجديد يغيب حتى يُدرَج
    // عمداً — وهو أسلم من أن يظهر لأن أحداً لم يتذكّر استثناءه.
    private const WRITE_ACTIONS = [
        'CREATE_CANDIDATE' => ['أُضيف المشارك إلى النظام', 'user'],
        'IMPORT_CANDIDATE' => ['أُضيف عبر الاستيراد الجماعي', 'upload'],
        'UPDATE_CANDIDATE' => ['عُدّلت بيانات المشارك', 'edit'],
        'UPDATE_CANDIDATE_NOTES' => ['عُدّلت الملاحظات', 'edit'],
        'REASSESS_CANDIDATE' => ['فُتحت دورة تقييم جديدة', 'refresh'],
        'APPROVE_CANDIDATE' => ['اعتُمد المشارك', 'check'],
        'RECLASSIFY_CANDIDATE' => ['غُيّرت الدرجة', 'lock'],
        'CV_UPDATE' => ['عُدّلت السيرة الذاتية', 'file'],
    ];

    private function writeTrail(Candidate $candidate): array
    {
        $logs = AuditLog::where('entity_type', 'candidate')
            ->where('entity_id', (string) $candidate->id)
            ->whereIn('action', array_keys(self::WRITE_ACTIONS))
            ->orderBy('created_at')
            ->get();

        $names = User::whereIn('id', $logs->pluck('user_id')->filter()->unique())
            ->pluck('full_name', 'id');

        $events = [];
        foreach ($logs as $log) {
            [$title, $icon] = self::WRITE_ACTIONS[$log->action];
            $events[] = [
                'type' => 'audit', 'at' => optional($log->created_at)->toIso8601String(),
                'title' => $title, 'meta' => null, 'cycle' => null,
                'actor' => $log->user_id ? ($names[$log->user_id] ?? 'مستخدم محذوف') : 'النظام',
                'status' => null, 'icon' => $icon,
            ];
        }

        // سجلّ التدقيق بدأ بعد أن دخل بعضُ المشاركين، فمن أُضيف قبله لا قيدَ
        // لإضافته. تُصطنع له بدايةٌ من تاريخ سجلّه كي لا يبدأ أثرُه من فراغ.
        if (!$logs->contains(fn ($l) => in_array($l->action, ['CREATE_CANDIDATE', 'IMPORT_CANDIDATE'], true))) {
            array_unshift($events, [
                'type' => 'candidate_created',
                'at' => optional($candidate->created_at)->toIso8601String(),
                'title' => 'أُضيف المشارك إلى النظام',
                'cycle' => null, 'meta' => null, 'actor' => null, 'status' => null,
                'icon' => 'user',
            ]);
        }

        return $events;
    }

    public function journey(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_JOURNEY)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض رحلة المشارك'], 403);
        }
        // النطاق كاملاً: التصنيف + القطاع — الحارس الموحّد في Controller
        $candidate = $this->resolveCandidateInScope($request, $id);
        if (!$candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
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

        $events = $this->writeTrail($candidate);

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
            return response()->json(['error' => 'ليس لديك صلاحية عرض المشاركين'], 403);
        }
        $allowed = $this->allowedClassifications($request);
        $user = $request->user();
        $base = Candidate::whereIn('classification', $allowed)
            // نفس حصر index — وإلا أفشى المؤشّر حجم ما تخفيه القائمة:
            // مقيّم يرى ٥ مشاركين ومؤشّرٌ يقول ٤٤ يكشف اتساع القطاعات الأخرى
            ->when($user->isSectorBound(), fn ($q) => $q->where('sector_id', $user->sector_id));

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
            return response()->json(['error' => 'ليس لديك صلاحية تصدير المشاركين'], 403);
        }
        $canSeeNames = $user->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);

        $query = Candidate::with('sector');
        // نفس نطاق index — التصدير لا يكون ثغرةً لما تُخفيه الشاشة.
        // الحصر قبل الفلاتر، فلا يوسّعه sectorId لقطاع آخر.
        $this->scopeCandidateQuery($request, $query);

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
                'الفئة' => Candidate::categoryLabel((string) $c->personnel_category),
                'الرتبة / المرتبة' => $c->rank_label,
                'الفئة القيادية' => $c->tier === 'upper' ? 'قيادة عليا' : 'قيادة وسطى',
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

    // GET /candidates/{id}/cv/document — نموذج السيرة الذاتية المطبوع (المتصفّح → PDF)
    //
    // وثيقة إدارية: تحمل البيانات الوظيفية كاملة، فتلزمها صلاحية عرض السيرة
    // نفسها التي يفرضها showCv — والاسم يبقى محجوباً كبقية المستندات.
    public function cvDocument(Request $request, int $id)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_CV_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض السيرة الذاتية'], 403);
        }

        $candidate = $this->resolveCandidateInScope($request, $id, ['cv', 'sector']);
        if (!$candidate) {
            $this->log($request, 'DENIED_CANDIDATE_OUT_OF_SCOPE', $id);
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        $doc = $candidate->cv?->data ?? CandidateCv::emptyDoc();
        $canSeeNames = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        if (!$canSeeNames) {
            $doc = CvGuard::scrub($doc, $candidate);
        }

        // أقرب جلسة قادمة، وإلا آخر جلسة — لترويسة «تاريخ التقييم/الساعة»
        $session = \App\Models\Schedule::where('candidate_id', $candidate->id)
            ->orderByRaw('CASE WHEN schedule_date >= ? THEN 0 ELSE 1 END', [now()->toDateString()])
            ->orderBy('schedule_date')
            ->first();

        // ── إقرار المشارك وتوقيعه ──
        // من آخر زيارة استقبال موقّعة. `isSigned()` يشترط التوقيع والإقرار
        // معاً، فلا يُطبع رسمٌ على لوحة بلا إقرارٍ خلفه.
        //
        // ⚠ التوقيع محجوز على صلاحية الأسماء لا على صلاحية السيرة: التوقيع
        // اسمُ صاحبه مكتوباً بخطّ يده. من لا يملك رؤية الأسماء تُنقّى له
        // السيرة نصّاً (CvGuard::scrub) ثم يقرأ الاسم من صورة التوقيع — فيسقط
        // إخفاءُ الهوية الذي تقوم عليه المنصّة كلّها من بابٍ خلفي.
        // ومن لا يملكها تُطبع له خانة توقيعٍ فارغة كما كان النموذج الورقي.
        //
        // وفكّ التشفير هنا لا في الخدمة: المفتاح يُستعمل في أضيق نطاق ممكن.
        $attest = null;
        if ($canSeeNames) {
            $visit = \App\Models\ReceptionVisit::where('candidate_id', $candidate->id)
                ->whereNotNull('signed_at')
                ->where('attested', true)
                ->orderByDesc('signed_at')
                ->first();
            if ($visit && $visit->isSigned()) {
                $attest = ['signature' => $visit->signature, 'at' => optional($visit->signed_at)->toIso8601String()];
            }
        }

        $this->log($request, 'PRINT_CV_SHEET', $id, ['code' => $candidate->participant_code, 'signed' => (bool) $attest]);

        $html = app(\App\Services\CvSheetService::class)->renderHtml($candidate, $doc, [
            'date' => $session?->schedule_date?->toDateString(),
            'time' => $session?->schedule_time ? substr((string) $session->schedule_time, 0, 5) : null,
            'attest' => $attest,
        ]);

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // GET /candidates/cards — بطاقات المشاركين جاهزة للطباعة (المتصفّح → PDF)
    //
    // البطاقة تحمل الرمز وحده، فلا تلزمها صلاحية الأسماء. لكن النطاق
    // يُطبَّق كاملاً كما في التصدير: البطاقة لا تكون طريقاً لمعرفة وجود
    // مشارك خارج نطاقك — المعرّف خارج النطاق يسقط صامتاً.
    public function cards(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::CANDIDATE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض المشاركين'], 403);
        }

        $validated = $request->validate([
            'ids' => 'required|string|max:4000',
        ], [
            'ids.required' => 'اختر مشاركاً واحداً على الأقل',
        ]);

        $ids = array_slice(array_filter(array_map(
            'intval',
            explode(',', $validated['ids'])
        )), 0, 500);

        if (!$ids) {
            return response()->json(['error' => 'اختر مشاركاً واحداً على الأقل'], 422);
        }

        $query = Candidate::query()->whereIn('id', $ids);
        $this->scopeCandidateQuery($request, $query);
        $codes = $query->orderBy('participant_code')->pluck('participant_code')->all();

        if (!$codes) {
            return response()->json(['error' => 'لا يوجد مشاركون ضمن نطاقك في هذا الاختيار'], 422);
        }

        $this->log($request, 'PRINT_PARTICIPANT_CARDS', 0, [
            'count' => count($codes),
            'codes' => $codes,
        ]);

        return response(app(\App\Services\ParticipantCardService::class)->renderHtml($codes), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
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
