<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\PeriodAssessor;
use App\Models\Schedule;
use App\Models\User;
use App\Security\Permissions;
use App\Services\EntryPermitService;
use App\Services\ExpertiseMatcher;
use App\Services\WaveGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  خدمة الجدولة — إنشاء/إدارة مواعيد جلسات التقييم
//  (المشارك ← دورته الحالية ← جلسات بأنشطتها والمُقيّمين والقاعات)
// ════════════════════════════════════════════════════════════

class ScheduleController extends Controller
{
    private const ACTIVITY_LABEL = [
        'interview' => 'المقابلة الشخصية',
        'discussion' => 'حلقة النقاش',
        'measurement' => 'أدوات القياس',
        'integration' => 'التمرين التكاملي',
    ];

    public function __construct(private WaveGuard $waves) {}

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'schedule',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    // حدّ القطاع لجلسة محمّلة بالمعرّف: المحصور قطاعياً لا يمسّ جلسة قطاع آخر — كما
    // في القوائم (index/absences). التصنيف + القطاع، وخارج النطاق = «غير موجودة» (لا
    // كشف وجود). لازمٌ لأن schedule.manage/candidate.edit قابلتان للتفويض لدورٍ محصور.
    private function scheduleOutOfScope(Request $request, Schedule $schedule): bool
    {
        $user = $request->user();
        if (! in_array($schedule->candidate->classification, $this->allowedClassifications($request), true)) {
            return true;
        }

        return $user->isSectorBound() && $schedule->candidate->sector_id !== $user->sector_id;
    }

    // GET /schedules — قائمة الجلسات (فلترة بالتاريخ/النشاط/المشارك/المُقيّم)
    public function index(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $validated = $request->validate([
            'date' => 'nullable|date',
            'activity' => 'nullable|in:interview,discussion,measurement,integration',
            'candidateId' => 'nullable|integer',
            'evaluatorId' => 'nullable|integer',
            'periodId' => 'nullable|integer',
        ]);

        $canSeeNames = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        $allowed = $this->allowedClassifications($request);

        $query = Schedule::with(['candidate.sector', 'attendance', 'evaluator', 'assistant', 'period'])
            ->whereHas('candidate', fn ($q) => $q->whereIn('classification', $allowed));

        // المحصور بقطاع يرى جلسات قطاعه وحدها
        $user = $request->user();
        if ($user->isSectorBound()) {
            $query->whereHas('candidate', fn ($q) => $q->where('sector_id', $user->sector_id));
        }

        if (! empty($validated['date'])) {
            $query->whereDate('schedule_date', $validated['date']);
        }
        if (! empty($validated['activity'])) {
            $query->where('activity', $validated['activity']);
        }
        if (! empty($validated['candidateId'])) {
            $query->where('candidate_id', $validated['candidateId']);
        }
        if (! empty($validated['evaluatorId'])) {
            $query->where('evaluator_id', $validated['evaluatorId']);
        }
        if (! empty($validated['periodId'])) {
            $query->where('period_id', $validated['periodId']);
        }

        // بلا أي مُرشِّح حاصر كانت القائمة تُحمّل كل جلسات كل الدورات (تنمو بلا حدّ مع
        // التاريخ). نافذة متدحرجة افتراضية (٦٠ يوماً للخلف فصاعداً) + سقف صلب.
        // الموجة مُرشِّح حاصر بطبعها: مداها محدود بتاريخيها.
        $unfiltered = empty($validated['date']) && empty($validated['candidateId'])
            && empty($validated['evaluatorId']) && empty($validated['periodId']);
        if ($unfiltered) {
            $query->whereDate('schedule_date', '>=', now()->subDays(60)->toDateString());
        }

        $rows = $query->orderBy('schedule_date')->orderBy('schedule_time')->limit(2000)->get()->map(fn ($s) => [
            'id' => $s->id,
            'candidateId' => $s->candidate_id,
            'participantCode' => $s->candidate->participant_code,
            'candidateName' => $canSeeNames ? $s->candidate->full_name : null,
            'sectorName' => optional($s->candidate->sector)->name_ar,
            'date' => substr((string) $s->schedule_date, 0, 10),
            'time' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null,
            'activity' => $s->activity,
            'activityLabel' => self::ACTIVITY_LABEL[$s->activity] ?? $s->activity,
            'location' => $s->location,
            'evaluatorId' => $s->evaluator_id,
            'evaluatorName' => optional($s->evaluator)->full_name,
            'assistantId' => $s->assistant_id,
            'assistantName' => optional($s->assistant)->full_name,
            'periodId' => $s->period_id,
            'periodName' => optional($s->period)->name,
            'attendanceStatus' => optional($s->attendance)->status ?? 'pending',
        ]);

        return response()->json(['schedules' => $rows]);
    }

    // قواعد التحقّق المشتركة للإنشاء/التعديل
    private function rules(bool $creating): array
    {
        return [
            // النشاط إلزامي عند الإنشاء، واختياري عند التعديل الجزئي (يُطبَّق فقط إن أُرسل)
            'activity' => ($creating ? 'required|' : 'sometimes|').'in:interview,discussion,measurement,integration',
            'date' => ($creating ? 'required|' : 'nullable|').'date|after_or_equal:today',
            // الوقت إلزامي عند الإنشاء: كشف الحضور المطبوع يوزّع الجلسات على أعمدة
            // الأوقات المعتمدة، وجلسة بلا وقت لا مكان لها فيه. وعند التعديل الجزئي
            // 'sometimes|required' يمنع إرسال null صراحةً فيمسح وقتاً مسجَّلاً.
            'time' => ($creating ? 'required|' : 'sometimes|required|').'date_format:H:i',
            'location' => 'nullable|string|max:200',
            'evaluatorId' => 'nullable|integer|exists:users,id',
            'assistantId' => 'nullable|integer|exists:users,id',
            // الموجة اختيارية: جلسةٌ بلا موجة تُنشأ كما كانت تُنشأ دائماً
            'periodId' => 'nullable|integer|exists:scheduling_periods,id',
        ];
    }

    // ── حارس الموجة ──
    // موجةٌ مغلقة أو معتمَدة لا يُضاف إليها ولا يُعدَّل فيها: ما اعتُمد يُقرأ.
    // والتاريخ خارج مدى الموجة يُرفض — جلسةٌ تنتمي لموجة لا يشملها تاريخها تظهر
    // في الجدول وتغيب عن كل مستند يُبنى من أيام الموجة.
    //
    // نُقل نصّ الفحص إلى WaveGuard وبقي النداء هنا: كان مكرّراً حرفياً في هذا
    // المتحكّم وفي DiscussionCircleController، فافترقا — استُدعي في خمسة مواضع
    // من أحد عشر تكتب في موجة، وكتبت الستّةُ الباقية في موجةٍ معتمَدة بلا رفض.
    private function periodError(?int $periodId, ?string $date): ?string
    {
        return $this->waves->refuse($periodId, $date);
    }

    // POST /schedules — جدولة جلسة لمشارك ضمن دورته الحالية
    // ── حدّ القطاع عند التوزيع ──
    // كل مقيّم ومساعد مخصَّص لقطاع ولا يُقيّم غيره. الإسناد عبر القطاعات يُمنع،
    // ولا يمرّ إلا لحامل CROSS_SECTOR_ASSIGN وبعد تأكيد صريح (confirmCrossSector).
    // يرجع خطأً جاهزاً للرد، أو null إن كان الإسناد سليماً.
    private function crossSectorError(Request $request, Candidate $candidate, array $validated): ?array
    {
        $offenders = [];

        foreach (['evaluatorId' => 'المقيّم', 'assistantId' => 'المساعد'] as $key => $label) {
            $id = $validated[$key] ?? null;
            if (! $id) {
                continue;
            }
            $u = User::with(['role', 'sector'])->find($id);
            if (! $u || $u->coversSector($candidate->sector_id)) {
                continue;
            }
            $offenders[] = $label.' «'.$u->full_name.'» ('
                .($u->sector?->name_ar ?? 'بلا قطاع').')';
        }

        if (! $offenders) {
            return null;
        }

        $sector = $candidate->sector?->name_ar ?? '—';
        $warning = 'تنبيه: هذا المشارك ليس من نفس القطاع. المشارك من قطاع «'.$sector
            .'» بينما '.implode(' و', $offenders).'.';

        if (! $request->user()->hasPermission(Permissions::CROSS_SECTOR_ASSIGN)) {
            return ['body' => ['error' => $warning.' الإسناد عبر القطاعات يتطلّب صلاحية إدارة المشاركين.'], 'status' => 403];
        }

        // يملك الصلاحية لكنه لم يؤكّد بعد — أعِد التحذير ليُعرض قبل التوزيع
        if (! $request->boolean('confirmCrossSector')) {
            return ['body' => [
                'error' => $warning,
                'requiresConfirmation' => true,
                'confirmField' => 'confirmCrossSector',
            ], 'status' => 409];
        }

        return null; // أكّد وهو يملك الصلاحية — يمرّ، ويُدوَّن التجاوز عند الحفظ
    }

    private function isCrossSector(Request $request, Candidate $candidate, array $validated): bool
    {
        foreach (['evaluatorId', 'assistantId'] as $key) {
            $id = $validated[$key] ?? null;
            if ($id && ($u = User::with('role')->find($id)) && ! $u->coversSector($candidate->sector_id)) {
                return true;
            }
        }

        return false;
    }

    // GET /candidates/{id}/interviewers — مستشارو المقابلة المؤهّلون لهذا المشارك
    // (مقيّمو قطاعه الفعّالون)، لاختيار المستشار عند الجدولة بعد مراجعة السيرة.
    //
    // بقي بمساره وشكل استجابته كما كان، وصار غلافاً لـassessors() — المتكامِل
    // القائم والشاشة القائمة لا يريان فرقاً.
    public function interviewers(Request $request, int $id)
    {
        return $this->assessors($request, $id);
    }

    // GET /candidates/{id}/assessors — المؤهّلون لهذا المشارك في نشاطٍ ومقعدٍ بعينه
    //
    // تعميم interviewers(): كانت تسأل عن الدور 'EVALUATOR' حرفياً، فلم يكن في
    // المنصّة أي مسارٍ يُرجع مستشاري حلقة النقاش ولا **المساعدين** — والعمود
    // schedules.assistant_id موجود منذ البداية ويُطبع في كشف الحضور، فكان
    // يُكتب بنداء يدوي أو لا يُكتب.
    //
    // ومع periodId يعود كل اسمٍ ومعه نصابه وحمله الفعلي («٣/٥»)، فيختار
    // المُجدوِل بعينه لا بحدسه. عدّاد لا سدّ: لا يُحجب المتجاوز — التجاوز قرارٌ
    // إداري يُرى ولا يُمنع.
    public function assessors(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $validated = $request->validate([
            'activity' => 'nullable|in:interview,discussion,measurement,integration',
            'seat' => 'nullable|in:evaluator,assistant',
            'periodId' => 'nullable|integer|exists:scheduling_periods,id',
            'date' => 'nullable|date_format:Y-m-d',
        ]);
        $activity = $validated['activity'] ?? 'interview';
        $seat = $validated['seat'] ?? 'evaluator';

        $candidate = $this->resolveCandidateInScope($request, $id);
        if (! $candidate) {
            $this->log($request, 'DENIED_CANDIDATE_OUT_OF_SCOPE', $id);

            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        $roles = PeriodAssessor::eligibleRoles($activity, $seat);
        $people = User::with('expertiseAreas')
            ->whereHas('role', fn ($q) => $q->whereIn('code', $roles))
            ->where('is_active', true)
            // ── من لا يحصره قطاع يخدم القطاعات كلّها ──
            // المساواةُ بالعمود وحدها كانت تُفرغ قائمة «أدوات القياس» أبداً:
            // MEASURE_SUPER ليس في User::SECTOR_BOUND_ROLES، وUserController
            // يرفض أن يُعطى قطاعاً أصلاً («هذا الدور غير محصور بقطاع») —
            // فـsector_id فيه NULL دائماً، وNULL لا يساوي قطاع المشارك. فلم
            // يكن يظهر في الشاشة مشرفُ أدوات قياسٍ واحد.
            //
            // والشرط على **الدور** لا على خلوّ العمود: دورٌ محصورٌ بقطاع صادف
            // أن قطاعه فارغ خللٌ في بياناته لا إذنٌ بعرضه على كل القطاعات.
            ->where(fn ($q) => $q->where('sector_id', $candidate->sector_id)
                ->orWhereHas('role', fn ($r) => $r->whereNotIn('code', User::SECTOR_BOUND_ROLES)))
            ->orderBy('full_name')
            ->get();

        // «حسب الخبرات»: المجالات التي تذكرها سيرة المشارك، تُقارَن بوسم كل مقيّم.
        // اقتراح ترتيبٍ لا حجب: من درجته صفر يبقى في القائمة قابلاً للاختيار.
        $matcher = new ExpertiseMatcher;
        $candidateAreas = $matcher->areasInText($matcher->candidateText($candidate));

        // لوحة الموجة وحملها — تُقرأ مرّة واحدة لا مرّة لكل اسم
        $panel = [];
        $periodLoad = [];
        $dayLoad = [];
        $periodId = $validated['periodId'] ?? null;
        if ($periodId) {
            $panel = PeriodAssessor::where('period_id', $periodId)
                ->where('activity', $activity)
                ->where('seat', $seat)
                ->get()
                ->keyBy('user_id')
                ->all();

            $column = $seat === 'assistant' ? 'assistant_id' : 'evaluator_id';
            $base = Schedule::where('period_id', $periodId)
                ->where('activity', $activity)
                ->whereIn($column, $people->pluck('id'));

            $periodLoad = (clone $base)->groupBy($column)
                ->selectRaw($column.' as uid, count(*) as c')
                ->pluck('c', 'uid')->all();

            if (! empty($validated['date'])) {
                $dayLoad = (clone $base)->whereDate('schedule_date', $validated['date'])
                    ->groupBy($column)
                    ->selectRaw($column.' as uid, count(*) as c')
                    ->pluck('c', 'uid')->all();
            }
        }

        $rows = $people->map(function (User $u) use ($panel, $periodLoad, $dayLoad, $periodId, $candidateAreas) {
            $seatRow = $panel[$u->id] ?? null;
            $matched = array_values(array_intersect_key(
                $candidateAreas,
                $u->expertiseAreas->keyBy('id')->all()
            ));

            return [
                'id' => $u->id,
                'name' => $u->full_name,
                // مجالات هذا المقيّم التي تذكرها سيرة المشارك — تُعرض للمُجدوِل
                'matchedAreas' => $matched,
                'matchScore' => count($matched),
                // مُدرَجٌ في لوحة الموجة؟ من ليس فيها يظهر ويُختار — اللوحة
                // ترتيبٌ للأسماء لا قائمةٌ مغلقة، فلا تقف جدولةٌ عاجلة على إدراج.
                'onPanel' => $periodId ? ($seatRow !== null) : null,
                'available' => $seatRow?->is_available ?? true,
                'dailyQuota' => $seatRow?->dailyQuota(),
                'periodQuota' => $seatRow?->period_quota,
                'periodLoad' => (int) ($periodLoad[$u->id] ?? 0),
                'dayLoad' => array_key_exists($u->id, $dayLoad) ? (int) $dayLoad[$u->id] : null,
                // «ينذر ولا يمنع» — والإنذار يُحسب هنا لا في القالب: نافذة
                // الجدولة هي موضع اختيار المقيّم، وكانت تعرض «٣/٥» نصّاً أبيض
                // بلا أي إشارةٍ إلى التجاوز، فالقاعدة مكتوبةٌ في تعليقٍ ومطبَّقةٌ
                // في جدول اللوحة وحده. وصفر النصاب يُنذَر عند أول إسناد.
                'overDayQuota' => $seatRow && array_key_exists($u->id, $dayLoad)
                    ? ($seatRow->dailyQuota() > 0
                        ? $dayLoad[$u->id] > $seatRow->dailyQuota()
                        : $dayLoad[$u->id] > 0)
                    : false,
            ];
        });

        // المُدرَجون المتاحون أولاً، ثم الأقرب خبرةً، ثم الأخفّ حملاً.
        // ترتيبٌ اقتراحيّ لا حصر: كل الأسماء تبقى معروضةً قابلةً للاختيار.
        $rows = $rows->sortBy(fn ($r) => [
            $r['onPanel'] ? 0 : 1,
            $r['available'] ? 0 : 1,
            -$r['matchScore'],
            $r['periodLoad'],
            $r['name'],
        ])->values();

        return response()->json([
            // الاسم القديم يبقى في الاستجابة — الشاشة القائمة تقرأه
            'interviewers' => $rows,
            'assessors' => $rows,
            'activity' => $activity,
            'seat' => $seat,
            'hasCv' => $candidate->cv()->exists(), // هل توجد سيرة للمراجعة قبل التعيين
        ]);
    }

    public function store(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $validated = $request->validate(array_merge(
            ['candidateId' => 'required|integer'],
            $this->rules(true)
        ));

        // النطاق كاملاً (التصنيف + القطاع). كان التصنيف وحده، فمن مُنح schedule.manage
        // بالاستثناء وهو محصور قطاعياً كان يجدول مشارك قطاع آخر (خارج النطاق = «غير موجود»).
        $candidate = $this->resolveCandidateInScope($request, $validated['candidateId']);
        if (! $candidate) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }
        if (! in_array($candidate->status, ['scheduled', 'assessed'], true)) {
            return response()->json(['error' => 'لا يمكن جدولة مشارك غير معتمد للتقييم'], 422);
        }
        // نربط الجلسة بالدورة الحالية غير المكتملة
        $assessment = $candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
        if (! $assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم نشطة للمشارك'], 422);
        }

        if ($err = $this->periodError($validated['periodId'] ?? null, $validated['date'])) {
            return response()->json(['error' => $err], 422);
        }

        if ($err = $this->crossSectorError($request, $candidate, $validated)) {
            return response()->json($err['body'], $err['status']);
        }
        $crossed = $this->isCrossSector($request, $candidate, $validated);

        // تعارض الوقت يحسمه القيد لا فحصٌ يسبقه: ضغطتان متزامنتان تمرّان من أي
        // فحصٍ في الكود، والقيد لا تمرّان منه. وهنا يُترجَم إلى عربية.
        try {
            $schedule = Schedule::create([
                'candidate_id' => $candidate->id,
                'assessment_id' => $assessment->id,
                'period_id' => $validated['periodId'] ?? null,
                'schedule_date' => $validated['date'],
                'schedule_time' => $validated['time'],
                'activity' => $validated['activity'],
                'evaluator_id' => $validated['evaluatorId'] ?? null,
                'assistant_id' => $validated['assistantId'] ?? null,
                'location' => $validated['location'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($msg = $this->waves->conflictMessage($e)) {
                return response()->json(['error' => $msg], 409);
            }
            throw $e;
        }

        // تاريخا الدورة حقلان يُصدَّران ويُفلتَران — يتبعان كل كتابة على الجلسات
        $assessment->refreshSessionDates();

        // التجاوز يُدوَّن بفعل مستقل — تجاوز حدّ القطاع يجب أن يكون مرئياً في التدقيق
        $this->log($request, $crossed ? 'CREATE_SCHEDULE_CROSS_SECTOR' : 'CREATE_SCHEDULE', $schedule->id, [
            'candidate' => $candidate->participant_code,
            'activity' => $schedule->activity,
            'date' => $validated['date'],
            'candidateSector' => $candidate->sector?->code,
        ]);

        return response()->json([
            'message' => 'تمت جدولة الجلسة',
            'scheduleId' => $schedule->id,
            'crossSector' => $crossed,
        ], 201);
    }

    // PUT /schedules/{id} — تعديل جلسة (يُمنع بعد تسجيل الحضور تفادياً للتنافر)
    public function update(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $schedule = Schedule::with('candidate')->find($id);
        if (! $schedule) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        if ($this->scheduleOutOfScope($request, $schedule)) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        // القفل بعد تسجيل الحضور يبقى للجميع، إلا إدارة المشاركين (CANDIDATE_EDIT):
        // تعدّل مع تدوين التجاوز. القفل يمنع تنافر «حضورٌ لجلسة تغيّر تاريخها».
        $recorded = Attendance::where('schedule_id', $schedule->id)->exists();
        $canOverride = $request->user()->hasPermission(Permissions::CANDIDATE_EDIT);
        if ($recorded && ! $canOverride) {
            return response()->json(['error' => 'لا يمكن تعديل جلسة سُجّل حضورها'], 422);
        }

        $validated = $request->validate($this->rules(false));

        // الموجة: القائمة على الصفّ ما لم تُرسَل جديدة، والتاريخ الجديد يُقاس عليها
        $targetPeriod = array_key_exists('periodId', $validated)
            ? $validated['periodId']
            : $schedule->period_id;
        if ($err = $this->periodError($targetPeriod, $validated['date'] ?? $schedule->schedule_date->toDateString())) {
            return response()->json(['error' => $err], 422);
        }

        // إعادة الإسناد تمرّ بنفس حدّ القطاع — وإلا التفّ التوزيع عبر التعديل
        if ($err = $this->crossSectorError($request, $schedule->candidate, $validated)) {
            return response()->json($err['body'], $err['status']);
        }
        $crossed = $this->isCrossSector($request, $schedule->candidate, $validated);

        // تغيّر التاريخ أو الوقت يُبطل الحضور المسجّل: حضورٌ لجلسة موعدها تبدّل
        // لم يعد صحيحاً. تغيير المكان أو المُقيّم لا يمسّ الحضور.
        // طبّع الجانبين: schedule_date مصبوب date فـ(string) يعطي «Y-m-d H:i:s»، فمقارنته
        // بـ«Y-m-d» الخام كانت غير متساوية دائماً — يحذف حضوراً سليماً عند تعديلٍ لا يمسّ الموعد.
        $timeChanged = (isset($validated['date']) && $validated['date'] !== $schedule->schedule_date->toDateString())
            || (array_key_exists('time', $validated) && $validated['time'] !== substr((string) $schedule->schedule_time, 0, 5));

        if (isset($validated['activity'])) {
            $schedule->activity = $validated['activity'];
        }
        if (isset($validated['date'])) {
            $schedule->schedule_date = $validated['date'];
        }
        if (array_key_exists('time', $validated)) {
            $schedule->schedule_time = $validated['time'];
        }
        if (array_key_exists('location', $validated)) {
            $schedule->location = $validated['location'];
        }
        if (array_key_exists('evaluatorId', $validated)) {
            $schedule->evaluator_id = $validated['evaluatorId'];
        }
        if (array_key_exists('assistantId', $validated)) {
            $schedule->assistant_id = $validated['assistantId'];
        }
        if (array_key_exists('periodId', $validated)) {
            $schedule->period_id = $validated['periodId'];
        }

        // الحفظ والإبطال في معاملة. الحذف مشروط بتغيّر الموعد فقط لا بقراءة $recorded
        // السابقة: إدخال حضور متزامن بعد تلك القراءة كان ينجو من الحذف فيبقى حضورٌ
        // لموعد تبدّل (TOCTOU).
        $attendanceCleared = false;
        try {
            DB::transaction(function () use ($schedule, $timeChanged, &$attendanceCleared) {
                $schedule->save();
                if ($timeChanged) {
                    $attendanceCleared = Attendance::where('schedule_id', $schedule->id)->delete() > 0;
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            // نقلُ جلسةٍ إلى وقتٍ مشغول كإنشائها فيه — نفس القيد ونفس الرسالة
            if ($msg = $this->waves->conflictMessage($e)) {
                return response()->json(['error' => $msg], 409);
            }
            throw $e;
        }

        Assessment::refreshDatesFor($schedule->assessment_id);

        $action = $recorded ? 'UPDATE_SCHEDULE_OVERRIDE' : ($crossed ? 'UPDATE_SCHEDULE_CROSS_SECTOR' : 'UPDATE_SCHEDULE');
        $this->log($request, $action, $schedule->id, [
            'activity' => $schedule->activity,
            'attendanceCleared' => $attendanceCleared,
        ]);

        return response()->json([
            'message' => $attendanceCleared
                ? 'تم تحديث الجلسة — أُلغي الحضور المسجّل لتغيّر الموعد'
                : 'تم تحديث الجلسة',
            'crossSector' => $crossed,
            'attendanceCleared' => $attendanceCleared,
        ]);
    }

    // DELETE /schedules/{id} — حذف جلسة (يُمنع بعد تسجيل الحضور)
    public function destroy(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
        }

        $schedule = Schedule::with('candidate')->find($id);
        if (! $schedule) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        if ($this->scheduleOutOfScope($request, $schedule)) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }
        if (Attendance::where('schedule_id', $schedule->id)->exists()) {
            return response()->json(['error' => 'لا يمكن حذف جلسة سُجّل حضورها'], 422);
        }
        // الحذف كالإضافة: موجةٌ اعتُمدت بجلساتها لا تُنقَص إحداها بعد ختمها.
        // كان الحارس على الإنشاء والتعديل وحدهما، فكان ما يُمنع إضافةً يُبلَغ حذفاً.
        if ($err = $this->periodError($schedule->period_id, null)) {
            return response()->json(['error' => $err], 422);
        }

        $code = $schedule->candidate->participant_code;
        $assessmentId = $schedule->assessment_id;
        $schedule->delete();
        Assessment::refreshDatesFor($assessmentId);
        $this->log($request, 'DELETE_SCHEDULE', $id, ['candidate' => $code]);

        return response()->json(['message' => 'تم حذف الجلسة']);
    }

    // GET /schedules/permits — تصاريح دخول مشاركي يومٍ بعينه (الخطوة ٩)
    //
    // «الحضور» أبكر وقتِ جلسةٍ للمشارك في ذلك اليوم لا وقتُ كل جلساته: التصريح
    // يُقدَّم عند البوّابة مرّةً واحدة، وسردُ ثلاثة أوقاتٍ عليه يُربك الحارس.
    public function permits(Request $request)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $validated = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'sectorId' => 'nullable|integer',
            'periodId' => 'nullable|integer',
        ]);
        $date = $validated['date'] ?? now()->toDateString();

        // الاسم بشرطين معاً: طلبٌ صريح وصلاحية — عرف كشف الحضور مع رقم الهوية
        $wants = $request->boolean('showName');
        $mayShow = $request->user()->hasPermission(Permissions::CANDIDATE_VIEW_NAMES);
        $showName = $wants && $mayShow;

        $user = $request->user();
        $query = Schedule::with(['candidate.sector', 'assessment'])
            ->whereDate('schedule_date', $date);
        $this->scopeViaCandidate($request, $query);

        // المحصور بقطاع يُشدّ إلى قطاعه مهما طلب — والحرّ يختار
        $sectorId = $user->isSectorBound() ? $user->sector_id : ($validated['sectorId'] ?? null);
        if ($sectorId) {
            $query->whereHas('candidate', fn ($q) => $q->where('sector_id', $sectorId));
        }
        if (! empty($validated['periodId'])) {
            $query->where('period_id', $validated['periodId']);
        }

        // تصريحٌ واحد لكل مشارك: أبكر وقتٍ له في اليوم، ومكانُ تلك الجلسة
        $byCandidate = [];
        foreach ($query->orderBy('schedule_time')->get() as $s) {
            $c = $s->candidate;
            if (! $c) {
                continue;
            }
            $code = $s->assessment?->participant_code ?? $c->participant_code;
            if (isset($byCandidate[$c->id])) {
                continue;
            }
            $byCandidate[$c->id] = [
                'code' => $code,
                'name' => $showName ? $c->full_name : null,
                'sector' => optional($c->sector)->name_ar,
                'date' => $date,
                'window' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : '—',
                'location' => $s->location ?: '—',
                'serial' => $code.'/'.str_replace('-', '', $date),
            ];
        }
        $permits = array_values($byCandidate);

        $this->log($request, 'PRINT_ENTRY_PERMITS', 0, [
            'date' => $date,
            'count' => count($permits),
            'withName' => $showName,
            'requested' => $wants,
        ]);

        return response((new EntryPermitService)->renderHtml($permits, $date))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // GET /schedules/absences/{candidateId} — جلسات الغياب القابلة لإعادة الجدولة
    public function absences(Request $request, int $candidateId)
    {
        if (! $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)) {
            return response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
        }

        $candidate = Candidate::find($candidateId);
        if (! $candidate || ! in_array($candidate->classification, $this->allowedClassifications($request), true)) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }
        // المحصور بقطاع لا يرى غياب قطاع آخر
        $user = $request->user();
        if ($user->isSectorBound() && $candidate->sector_id !== $user->sector_id) {
            return response()->json(['error' => 'المشارك غير موجود'], 404);
        }

        $rows = Schedule::with('attendance')
            ->where('candidate_id', $candidateId)
            ->whereNull('rescheduled_at') // الغياب المُستهلَك لا يُعرض للإعادة ثانيةً
            ->whereHas('attendance', fn ($q) => $q->whereIn('status', ['absent_excused', 'absent_unexcused']))
            ->orderByDesc('schedule_date')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'activity' => self::ACTIVITY_LABEL[$s->activity] ?? $s->activity,
                'date' => (string) $s->schedule_date,
                'status' => $s->attendance->status === 'absent_excused' ? 'غياب بعذر' : 'غياب',
                'reason' => $s->attendance->absence_reason,
            ]);

        return response()->json(['absences' => $rows]);
    }

    // POST /schedules/{id}/reschedule — إعادة جدولة جلسة غياب بتاريخ جديد.
    // إدارة المشاركين (CANDIDATE_EDIT) وحدها: إعادة الجدولة قرار إداري لا تسجيل.
    // تُنشئ جلسة جديدة بنفس النشاط والإسناد، وتُبقي جلسة الغياب للتدقيق.
    public function reschedule(Request $request, int $id)
    {
        if (! $request->user()->hasPermission(Permissions::CANDIDATE_EDIT)) {
            return response()->json(['error' => 'ليس لديك صلاحية إعادة الجدولة'], 403);
        }

        $old = Schedule::with(['candidate', 'attendance'])->find($id);
        if (! $old || $this->scheduleOutOfScope($request, $old)) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }

        // لا يُعاد جدولة إلا جلسة غياب — الحاضر والمعلّق لا يحتاجان
        $status = $old->attendance?->status;
        if (! in_array($status, ['absent_excused', 'absent_unexcused'], true)) {
            return response()->json(['error' => 'لا تُعاد جدولة إلا جلسة سُجّل فيها غياب'], 422);
        }

        // نفس حرّاس store: لا نُنشئ جلسة حيّة لمشارك غير مؤهّل أو داخل دورة منتهية.
        // نربط بالدورة الحالية غير المكتملة لا بدورة القديمة (قد تكون أُغلقت).
        if (! in_array($old->candidate->status, ['scheduled', 'assessed'], true)) {
            return response()->json(['error' => 'لا يمكن إعادة جدولة مشارك غير معتمد للتقييم'], 422);
        }
        $assessment = $old->candidate->assessments()->where('status', '!=', 'completed')->orderByDesc('id')->first();
        if (! $assessment) {
            return response()->json(['error' => 'لا توجد دورة تقييم نشطة للمشارك'], 422);
        }

        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'time' => 'nullable|date_format:H:i',
            'location' => 'nullable|string|max:150',
        ], [
            'date.after_or_equal' => 'تاريخ إعادة الجدولة يجب ألا يكون في الماضي',
        ]);

        // مرّة واحدة لكل غياب: نقفل الصف القديم ونضع rescheduled_at داخل معاملة.
        // نداءان متكرّران/متزامنان كانا يُنشئان جلسات مكرّرة (لا عمود يستهلك الغياب).
        try {
            $new = DB::transaction(function () use ($old, $assessment, $validated) {
                $locked = Schedule::whereKey($old->id)->lockForUpdate()->first();
                if ($locked->rescheduled_at !== null) {
                    return null; // استُهلك الغياب مسبقاً
                }
                // ── موجة الجلسة المُعوِّضة: تُختار بالتاريخ ولا تُورَّث عن الغياب ──
                //
                // كانت تُورَّث متى وقع التاريخ الجديد داخل مدى موجة الغياب. والغياب
                // لا يقع إلا في موجةٍ تعمل، ومعنى «تعمل» أنها اعتُمدت — فكانت كل جلسة
                // تعويضٍ تُضاف إلى موجةٍ ختمها مدير المركز، فيصير المنفَّذ غيرَ
                // المعتمَد بلا رفضٍ ولا أثر. وهو الباب الوحيد الذي كان يلتفّ على القفل.
                //
                // والمُعوِّضة تذهب إلى موجةٍ أخرى: التي تشمل تاريخها الجديد وما زالت
                // تُبنى. فإن لم توجد — أو وُجدت أكثر من واحدة فالقسمة بينهما قرار
                // المُجدوِل لا ترجيح استعلام — بقيت بلا موجة حتى يُسنِدها بيده.
                $target = $this->waves->openPeriodOn($validated['date']);

                $created = Schedule::create([
                    'candidate_id' => $old->candidate_id,
                    'assessment_id' => $assessment->id,        // الدورة الحالية لا القديمة
                    'period_id' => $target,
                    'schedule_date' => $validated['date'],
                    'schedule_time' => $validated['time'] ?? $old->schedule_time,
                    'activity' => $old->activity,              // نفس النشاط الذي تغيّب عنه
                    'evaluator_id' => $old->evaluator_id,       // نفس الإسناد
                    'assistant_id' => $old->assistant_id,
                    'location' => $validated['location'] ?? $old->location,
                ]);
                $locked->rescheduled_at = now();
                $locked->save();

                return $created;
            });
        } catch (UniqueConstraintViolationException $e) {
            // التعويض يقع في وقتٍ مشغول — نفس حارس الإنشاء
            if ($msg = $this->waves->conflictMessage($e)) {
                return response()->json(['error' => $msg], 409);
            }
            throw $e;
        }

        if ($new === null) {
            return response()->json(['error' => 'أُعيدت جدولة هذا الغياب مسبقاً'], 409);
        }

        Assessment::refreshDatesFor($assessment->id);

        $this->log($request, 'RESCHEDULE_SESSION', $new->id, [
            'candidate' => $old->candidate->participant_code,
            'fromSchedule' => $old->id,
            'activity' => $old->activity,
            'date' => $validated['date'],
            'toPeriod' => $new->period_id,
        ]);

        return response()->json([
            'message' => 'تمت إعادة جدولة الجلسة',
            'scheduleId' => $new->id,
        ], 201);
    }
}
