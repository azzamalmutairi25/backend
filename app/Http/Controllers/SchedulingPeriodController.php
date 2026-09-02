<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PeriodAssessor;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\User;
use App\Security\Permissions;
use App\Services\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  موجات الجدولة — التواريخ، ولوحة المقيّمين والمساعدين ونصابهم،
//  ومسار اعتماد مدير المركز
// ════════════════════════════════════════════════════════════
//
// الجدولة يدوية: المستخدم يحدّد تواريخ الموجة، ويختار الأسماء التي تعمل فيها
// ويضع نصاب كلٍّ منها، ثم يبني الجلسات بنفسه من شاشة الجدولة. التوزيع الآلي
// (DistributionController) يبقى كما هو خياراً مساعداً لا مساراً وحيداً.
//
// النصاب هنا **عدّاد لا سدّ**: يُعرض «٣/٥» بجانب الاسم وقت الاختيار ويُنذر عند
// التجاوز ولا يمنعه — لأن القرار صار للمستخدم لا للخوارزمية، ولأن مركزاً في
// يوم ضغطٍ يتجاوز النصاب عن عمد. ما يُمنع فعلاً هو تعارض الوقت (نفس الشخص في
// لحظتين)، وهو خطأ إدخال لا قرار إداري — وموضعه شاشة الجدولة لا هذه.
class SchedulingPeriodController extends Controller
{
    private const ACTIVITIES = ['interview', 'discussion', 'measurement', 'integration'];

    private const ACTIVITY_LABEL = [
        'interview' => 'المقابلة الشخصية',
        'discussion' => 'حلقة النقاش',
        'measurement' => 'أدوات القياس',
        'integration' => 'التمرين التكاملي',
    ];

    public function __construct(private NotificationService $notifications) {}

    private function log(Request $request, string $action, int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'scheduling_period',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function denyView(Request $request): ?JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
    }

    private function denyManage(Request $request): ?JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية إدارة الجدولة'], 403);
    }

    private function denyApprove(Request $request): ?JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_APPROVE_CENTER)
            ? null
            : response()->json(['error' => 'اعتماد الجدولة لمدير المركز'], 403);
    }

    private function row(SchedulingPeriod $p, array $counts = []): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'startDate' => $p->start_date?->toDateString(),
            'endDate' => $p->end_date?->toDateString(),
            'dayCount' => $p->dayCount(),
            'sessionTimes' => $p->sessionTimes(),
            'sessionTimesOverridden' => trim((string) $p->session_times) !== '',
            'status' => $p->status,
            'statusLabel' => SchedulingPeriod::label($p->status),
            'notes' => $p->notes,
            'rejectReason' => $p->reject_reason,
            'approvedByName' => optional($p->approver)->full_name,
            'approvedAt' => optional($p->approved_at)?->toDateTimeString(),
            'submittedAt' => optional($p->submitted_at)?->toDateTimeString(),
            'createdByName' => optional($p->creator)->full_name,
            'assessorCount' => $counts['assessors'] ?? $p->assessors()->count(),
            'sessionCount' => $counts['sessions'] ?? $p->schedules()->count(),
            'editable' => $p->isEditable(),
        ];
    }

    // GET /scheduling-periods — قائمة الموجات (الأحدث بدايةً أولاً)
    public function index(Request $request)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'status' => 'nullable|string|in:'.implode(',', SchedulingPeriod::STATUSES),
            'openOnly' => 'nullable|boolean',
        ]);

        $query = SchedulingPeriod::with(['creator', 'approver'])
            ->withCount(['assessors', 'schedules']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if ($request->boolean('openOnly')) {
            $query->open();
        }

        $rows = $query->orderByDesc('start_date')->orderByDesc('id')->limit(200)->get()
            ->map(fn ($p) => $this->row($p, [
                'assessors' => $p->assessors_count,
                'sessions' => $p->schedules_count,
            ]));

        return response()->json([
            'periods' => $rows,
            'canManage' => $request->user()->hasPermission(Permissions::SCHEDULE_MANAGE),
            'canApprove' => $request->user()->hasPermission(Permissions::SCHEDULE_APPROVE_CENTER),
        ]);
    }

    private function periodRules(bool $creating): array
    {
        return [
            'name' => ($creating ? 'required|' : 'sometimes|required|').'string|max:100',
            'startDate' => ($creating ? 'required|' : 'sometimes|required|').'date_format:Y-m-d',
            'endDate' => ($creating ? 'required|' : 'sometimes|required|').'date_format:Y-m-d',
            // أوقات الجلسات: فارغة ⇒ الإعداد العام. تُرسل نصّاً «H:i,H:i»
            'sessionTimes' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /** يتحقّق من المدى ويرجع رسالة الخطأ، أو null إن كان سليماً */
    private function rangeError(string $start, string $end): ?string
    {
        if ($end < $start) {
            return 'تاريخ النهاية قبل تاريخ البداية';
        }
        $days = (strtotime($end) - strtotime($start)) / 86400 + 1;
        if ($days > SchedulingPeriod::MAX_DAYS) {
            return 'مدّة الموجة تتجاوز '.SchedulingPeriod::MAX_DAYS.' يوماً — تحقّق من التاريخ';
        }

        return null;
    }

    /** يتحقّق من صيغة أوقات الجلسات، ويرجع النصّ المطبَّع أو رسالة الخطأ */
    private function normaliseTimes(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['value' => null, 'error' => null];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        foreach ($parts as $p) {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $p)) {
                return ['value' => null, 'error' => 'صيغة الوقت «'.$p.'» غير صحيحة — الشكل HH:MM'];
            }
        }
        $parts = array_values(array_unique($parts));
        sort($parts);

        if (count($parts) > 8) {
            return ['value' => null, 'error' => 'الحدّ الأقصى ٨ أوقات جلسات'];
        }

        return ['value' => implode(',', $parts), 'error' => null];
    }

    // POST /scheduling-periods — إنشاء موجة
    public function store(Request $request)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $validated = $request->validate($this->periodRules(true));

        if ($err = $this->rangeError($validated['startDate'], $validated['endDate'])) {
            return response()->json(['error' => $err], 422);
        }
        $times = $this->normaliseTimes($validated['sessionTimes'] ?? null);
        if ($times['error']) {
            return response()->json(['error' => $times['error']], 422);
        }

        // الاسم فريد — والقيد هو الحارس لا فحصٌ يسبقه ضغطتان متزامنتان
        // داخل معاملته: انتهاك القيد في postgres يُجهض المعاملة المحيطة كلها
        try {
            $period = DB::transaction(fn () => SchedulingPeriod::create([
                'name' => $validated['name'],
                'start_date' => $validated['startDate'],
                'end_date' => $validated['endDate'],
                'session_times' => $times['value'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            return response()->json(['error' => 'توجد موجة بهذا الاسم — اختر اسماً آخر'], 409);
        }

        $this->log($request, 'CREATE_PERIOD', $period->id, [
            'name' => $period->name,
            'from' => $validated['startDate'],
            'to' => $validated['endDate'],
        ]);

        return response()->json([
            'message' => 'أُنشئت موجة الجدولة',
            'period' => $this->row($period->fresh(['creator', 'approver'])),
        ], 201);
    }

    // PUT /scheduling-periods/{id} — تعديل موجة (ما لم تُعتمد)
    public function update(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }
        if (! $period->isEditable()) {
            return response()->json(['error' => 'لا تُعدَّل موجة '.SchedulingPeriod::label($period->status)], 422);
        }

        $validated = $request->validate($this->periodRules(false));

        $start = $validated['startDate'] ?? $period->start_date->toDateString();
        $end = $validated['endDate'] ?? $period->end_date->toDateString();
        if ($err = $this->rangeError($start, $end)) {
            return response()->json(['error' => $err], 422);
        }

        // تضييق المدى بعد بناء جلسات خارجه يترك جلساتٍ تنتمي لموجة لا تشملها
        // تواريخها — تظهر في الجدول وتغيب عن كل مستند يُبنى من أيام الموجة.
        $orphans = Schedule::where('period_id', $period->id)
            ->where(fn ($q) => $q->whereDate('schedule_date', '<', $start)
                ->orWhereDate('schedule_date', '>', $end))
            ->count();
        if ($orphans > 0) {
            return response()->json([
                'error' => 'المدى الجديد يستثني '.$orphans.' جلسة مجدولة — انقلها أو احذفها أولاً',
            ], 422);
        }

        if (array_key_exists('sessionTimes', $validated)) {
            $times = $this->normaliseTimes($validated['sessionTimes']);
            if ($times['error']) {
                return response()->json(['error' => $times['error']], 422);
            }
            $period->session_times = $times['value'];
        }

        if (isset($validated['name'])) {
            $period->name = $validated['name'];
        }
        $period->start_date = $start;
        $period->end_date = $end;
        if (array_key_exists('notes', $validated)) {
            $period->notes = $validated['notes'];
        }

        try {
            DB::transaction(fn () => $period->save());
        } catch (UniqueConstraintViolationException) {
            return response()->json(['error' => 'توجد موجة بهذا الاسم — اختر اسماً آخر'], 409);
        }

        $this->log($request, 'UPDATE_PERIOD', $period->id, ['name' => $period->name]);

        return response()->json([
            'message' => 'تم تحديث الموجة',
            'period' => $this->row($period->fresh(['creator', 'approver'])),
        ]);
    }

    // DELETE /scheduling-periods/{id} — حذف موجة فارغة فقط
    public function destroy(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }
        // ما اعتُمد لا يُحذف. كان الفحص على الجلسات وحدها، والقاعدة تسمح: موجةٌ
        // معتمَدة بلا جلسة (رُفعت جلساتها ثم اعتُمدت) كانت تُحذف بنداءٍ مباشر —
        // والزرّ مخفيٌّ في الشاشة وحدها، وإخفاءُ زرٍّ ليس حارساً.
        if (! $period->isEditable()) {
            return response()->json([
                'error' => 'لا تُحذف موجة '.SchedulingPeriod::label($period->status),
            ], 422);
        }
        // الجلسات لا تُحذف مع الموجة (nullOnDelete)، لكنّ فقدانها لانتمائها
        // صامتاً أسوأ من منعٍ صريح: تختفي من كل مستند يُبنى على الموجة.
        if ($period->schedules()->exists()) {
            return response()->json(['error' => 'لا تُحذف موجة لها جلسات مجدولة'], 422);
        }

        $name = $period->name;
        $period->delete();
        $this->log($request, 'DELETE_PERIOD', $id, ['name' => $name]);

        return response()->json(['message' => 'تم حذف الموجة']);
    }

    // ════════════════════════════════════════════════════════
    //  لوحة المقيّمين والمساعدين
    // ════════════════════════════════════════════════════════

    // GET /scheduling-periods/{id}/assessors — من يعمل في الموجة، ونصابه، وحمله
    public function assessors(Request $request, int $id)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }

        $rows = PeriodAssessor::with(['user.role', 'user.sector'])
            ->where('period_id', $period->id)
            ->get();

        // الحمل الفعلي: جلسات هذه الموجة المُسنَدة لكلٍّ منهم — عدّاد النصاب
        // يُقرأ بجانب الرقم المُعلن، وإلا كان النصاب رقماً لا يقابله شيء.
        $loadEvaluator = Schedule::where('period_id', $period->id)
            ->whereNotNull('evaluator_id')
            ->groupBy('evaluator_id', 'activity')
            ->selectRaw('evaluator_id as uid, activity, count(*) as c')
            ->get();
        $loadAssistant = Schedule::where('period_id', $period->id)
            ->whereNotNull('assistant_id')
            ->groupBy('assistant_id', 'activity')
            ->selectRaw('assistant_id as uid, activity, count(*) as c')
            ->get();

        $load = [];
        foreach ($loadEvaluator as $r) {
            $load['evaluator:'.$r->uid.':'.$r->activity] = (int) $r->c;
        }
        foreach ($loadAssistant as $r) {
            $load['assistant:'.$r->uid.':'.$r->activity] = (int) $r->c;
        }

        // ── السقف يُبنى على أيام الموجة التي فيها جلسات، لا على أيام تقويمها ──
        // dayCount يعدّ كل الأيام عمداً (لا تقويم إجازات موثوق في المنصّة)، فكان
        // السقف = النصاب × أيام التقويم يشمل جُمَعاً وسبوتاً لا يعمل فيها المركز
        // — ينتفخ نحو الثلث، فيبتلع التجاوز الحقيقي ولا يُنذر أحد. وأيامُ الموجة
        // التي جُدولت فيها جلسة هي أيام عملها كما أعلنها من بناها، بلا مفهومٍ
        // جديد ولا هجرة.
        $workedDays = Schedule::where('period_id', $period->id)
            ->distinct()->count(DB::raw('schedule_date::date'));
        $dayCount = max(1, $workedDays ?: $period->dayCount());

        return response()->json([
            'period' => $this->row($period->load(['creator', 'approver'])),
            'assessors' => $rows->map(function (PeriodAssessor $a) use ($load, $dayCount) {
                $assigned = $load[$a->seat.':'.$a->user_id.':'.$a->activity] ?? 0;
                $capacity = $a->period_quota ?? ($a->dailyQuota() * $dayCount);

                return [
                    'id' => $a->id,
                    'userId' => $a->user_id,
                    'name' => optional($a->user)->full_name,
                    'roleName' => optional(optional($a->user)->role)->name_ar,
                    'sectorName' => optional(optional($a->user)->sector)->name_ar,
                    'activity' => $a->activity,
                    'activityLabel' => self::ACTIVITY_LABEL[$a->activity] ?? $a->activity,
                    'seat' => $a->seat,
                    'seatLabel' => PeriodAssessor::seatLabel($a->seat),
                    'dailyQuota' => $a->daily_quota,
                    'effectiveDailyQuota' => $a->dailyQuota(),
                    'periodQuota' => $a->period_quota,
                    'isAvailable' => $a->is_available,
                    // الحمل مقابل السقف: النصاب اليومي × عدد الأيام هو سقف الموجة
                    // ما لم يُصرَّح بسقفٍ آخر — رقمٌ يُقرأ لا يُفرض.
                    'assigned' => $assigned,
                    'periodCapacity' => $capacity,
                    // القرار في الخادم لا في القالب: كانت كل شاشة تحسبه بنفسها،
                    // فحسبته شاشةٌ وسكتت عنه أخرى — ونافذة الجدولة، وهي موضع
                    // الاختيار الفعلي، كانت تعرض «٣/٥» نصّاً بلا إنذار.
                    // والمقارنة `>=` لا `>`: من بلغ نصابه بلغه، ومن نصابه صفر
                    // (مُدرَجٌ ولا يُسنَد إليه) يُنذَر عند أول إسناد — وكان
                    // السقف صفراً يسقط في الشاشة لأنه قيمةٌ كاذبة في جافاسكربت.
                    'overQuota' => $capacity > 0
                        ? $assigned > $capacity
                        : $assigned > 0,
                ];
            })->values(),
            'activities' => collect(self::ACTIVITIES)->map(fn ($a) => [
                'value' => $a,
                'label' => self::ACTIVITY_LABEL[$a],
            ]),
        ]);
    }

    // GET /scheduling-periods/{id}/eligible — من يصلح للإدراج في اللوحة
    //
    // مسارٌ خاص بدل فتح /users لمسؤول الجدولة: قائمة المستخدمين سلطةُ نظام
    // (user.manage) وليست حاجة الجدولة. ما يحتاجه المُجدوِل أسماء من يصلح
    // لنشاطٍ ومقعد لا غير — بلا اسم مستخدم ولا دخولٍ أخير ولا صلاحيات.
    public function eligible(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }

        $validated = $request->validate([
            'activity' => 'required|string|in:'.implode(',', self::ACTIVITIES),
            'seat' => 'required|string|in:'.implode(',', PeriodAssessor::SEATS),
        ]);

        $roles = PeriodAssessor::eligibleRoles($validated['activity'], $validated['seat']);

        $rows = User::with(['role', 'sector'])
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('code', $roles))
            // المحصور بقطاع لا يرى غير أهل قطاعه — كما في كل قائمة أخرى.
            // ومن لا يحصره قطاع (مشرف أدوات القياس، وsector_id فيه NULL دائماً
            // بحكم UserController) يبقى ظاهراً للجميع: حصرُه بقطاعٍ لا ينتمي
            // إليه يُخفيه عن كل مُجدوِل محصور.
            ->when($request->user()->isSectorBound(),
                fn ($q) => $q->where(fn ($w) => $w->where('sector_id', $request->user()->sector_id)
                    ->orWhereHas('role', fn ($r) => $r->whereNotIn('code', User::SECTOR_BOUND_ROLES))))
            ->orderBy('full_name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'roleName' => $u->role?->name_ar,
                'sectorId' => $u->sector_id,
                'sectorName' => $u->sector?->name_ar,
            ])->values();

        return response()->json([
            'eligible' => $rows,
            'activity' => $validated['activity'],
            'seat' => $validated['seat'],
        ]);
    }

    // PUT /scheduling-periods/{id}/assessors — حفظ اللوحة كاملةً (استبدال ذرّي)
    //
    // الشاشة تُرسل اللوحة كما صارت لا فروقاتها: حفظٌ تفاضلي من واجهةٍ فُتحت
    // قبل دقيقتين يُعيد سطراً حذفه غيرك. والاستبدال داخل معاملة واحدة.
    public function saveAssessors(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }
        if (! $period->isEditable()) {
            return response()->json(['error' => 'لا تُعدَّل لوحة موجة '.SchedulingPeriod::label($period->status)], 422);
        }

        $validated = $request->validate([
            'rows' => 'present|array|max:200',
            'rows.*.userId' => 'required|integer|exists:users,id',
            'rows.*.activity' => 'required|string|in:'.implode(',', self::ACTIVITIES),
            'rows.*.seat' => 'required|string|in:'.implode(',', PeriodAssessor::SEATS),
            'rows.*.dailyQuota' => 'nullable|integer|min:0|max:50',
            'rows.*.periodQuota' => 'nullable|integer|min:0|max:2000',
            'rows.*.isAvailable' => 'nullable|boolean',
        ], [
            'rows.*.dailyQuota.max' => 'النصاب اليومي لا يتجاوز ٥٠',
        ]);

        $rows = $validated['rows'];

        // كل مستخدم مذكور يُحمَّل مرّة واحدة، وتُفحص أهليته للمقعد المطلوب.
        // بلا هذا الفحص كانت اللوحة تقبل أي مستخدم لأي نشاط — فيظهر محاسب
        // في قائمة مستشاري حلقة النقاش عند الجدولة.
        $users = User::with('role')->whereIn('id', array_column($rows, 'userId'))->get()->keyBy('id');

        $seen = [];
        $rejected = [];
        $clean = [];
        foreach ($rows as $r) {
            $key = $r['userId'].':'.$r['activity'].':'.$r['seat'];
            if (isset($seen[$key])) {
                continue;   // تكرار في الطلب نفسه — يُطوى بلا ضجيج
            }
            $seen[$key] = true;

            $u = $users->get($r['userId']);
            if (! $u || ! $u->is_active) {
                $rejected[] = ['userId' => $r['userId'], 'reason' => 'مستخدم غير فعّال'];

                continue;
            }
            $allowedRoles = PeriodAssessor::eligibleRoles($r['activity'], $r['seat']);
            if (! $u->role || ! in_array($u->role->code, $allowedRoles, true)) {
                $rejected[] = [
                    'userId' => $r['userId'],
                    'name' => $u->full_name,
                    'reason' => 'دوره لا يؤهّله لـ'.(self::ACTIVITY_LABEL[$r['activity']] ?? $r['activity'])
                        .' ('.PeriodAssessor::seatLabel($r['seat']).')',
                ];

                continue;
            }
            $clean[] = $r;
        }

        $userId = $request->user()->id;
        DB::transaction(function () use ($period, $clean, $userId) {
            $keep = [];
            foreach ($clean as $r) {
                $row = PeriodAssessor::updateOrCreate(
                    [
                        'period_id' => $period->id,
                        'user_id' => $r['userId'],
                        'activity' => $r['activity'],
                        'seat' => $r['seat'],
                    ],
                    [
                        'daily_quota' => $r['dailyQuota'] ?? null,
                        'period_quota' => $r['periodQuota'] ?? null,
                        'is_available' => $r['isAvailable'] ?? true,
                        'assigned_by' => $userId,
                    ]
                );
                $keep[] = $row->id;
            }
            // ما لم يرد في اللوحة يُسحب — الحذف بعد الإدراج داخل المعاملة نفسها
            PeriodAssessor::where('period_id', $period->id)
                ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
                ->delete();
        });

        $this->log($request, 'SET_PERIOD_ASSESSORS', $period->id, [
            'name' => $period->name,
            'count' => count($clean),
            'rejected' => count($rejected),
        ]);

        return response()->json([
            'message' => 'حُفظت لوحة الموجة ('.count($clean).')',
            'saved' => count($clean),
            'rejected' => $rejected,
        ]);
    }

    // ════════════════════════════════════════════════════════
    //  مسار الاعتماد: إرسال ← اعتماد/رفض ← إغلاق
    // ════════════════════════════════════════════════════════

    // POST /scheduling-periods/{id}/submit — إرسال الجدولة لمدير المركز
    public function submit(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }
        if ($period->status !== 'draft') {
            return response()->json(['error' => 'لا تُرسَل إلا موجة في حالة مسودّة'], 422);
        }
        if (! $period->schedules()->exists()) {
            return response()->json(['error' => 'لا توجد جلسات في هذه الموجة — ابنِ الجدول قبل إرساله'], 422);
        }

        $period->status = 'pending_center';
        $period->submitted_by = $request->user()->id;
        $period->submitted_at = now();
        $period->reject_reason = null;   // إرسالٌ جديد يمسح سبب رفضٍ سابق
        $period->save();

        // بالصلاحية لا بالدور: مركزٌ لم يُنشئ دور مدير المركز كان الإشعار
        // فيه يذهب إلى لا أحد فتقف الموجة بلا أن يعلم أحد.
        $reached = $this->notifications->notifyPermission(
            Permissions::SCHEDULE_APPROVE_CENTER,
            'approval',
            'جدولة بانتظار اعتمادك',
            'موجة «'.$period->name.'» ('.$period->start_date->toDateString()
                .' — '.$period->end_date->toDateString().') بانتظار الاعتماد',
            'scheduling_period',
            (string) $period->id,
            $request->user()->id,
            $request->user()->id,
        );

        $this->log($request, 'SUBMIT_PERIOD', $period->id, [
            'name' => $period->name,
            'notified' => $reached,
        ]);

        return response()->json([
            'message' => $reached > 0
                ? 'أُرسلت الموجة لمدير المركز للاعتماد'
                : 'أُرسلت الموجة — لا يوجد حاملٌ لصلاحية الاعتماد لإشعاره',
            'notified' => $reached,
            'period' => $this->row($period->fresh(['creator', 'approver'])),
        ]);
    }

    // POST /scheduling-periods/{id}/approve — اعتماد مدير المركز
    public function approve(Request $request, int $id)
    {
        if ($deny = $this->denyApprove($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }
        if ($period->status !== 'pending_center') {
            return response()->json(['error' => 'لا تُعتمد إلا موجة مُرسَلة للاعتماد'], 422);
        }
        // ── من يبني الجدول لا يعتمده ──
        // الهجرة التي منحت هذه الصلاحية كتبت غرضها صراحةً: «فصل مهام لا صلاحية
        // تجميلية». لكنها فصلت الأدوار ولم تفصل الأشخاص — ومدير المركز يحمل
        // schedule.manage وschedule.approve_center معاً، فكان يبني ويرسل ويعتمد
        // وحده، وخطوة «إرسال الجدولة إلى مدير المركز» بلا معنى.
        //
        // والفحص على المُرسِل لا على المُنشِئ ولا على من مسّ اللوحة: الإرسال هو
        // فعل «أُعلنُها جاهزة»، وهو ما يقابله الاعتماد. ومديرٌ صحّح نصاب اسمٍ في
        // لوحة موجةٍ بناها غيره لا يفقد حقّه في اعتمادها.
        if ($period->submitted_by === $request->user()->id) {
            return response()->json([
                'error' => 'لا تعتمد موجةً أرسلتَها بنفسك — الاعتماد لمن لم يبنِها',
            ], 422);
        }

        $period->status = 'approved';
        $period->approved_by = $request->user()->id;
        $period->approved_at = now();
        $period->save();

        if ($period->submitted_by) {
            $this->notifications->notify(
                $period->submitted_by,
                'approval',
                'اعتُمدت الجدولة',
                'اعتُمدت موجة «'.$period->name.'»',
                'scheduling_period',
                (string) $period->id,
                $request->user()->id,
            );
        }

        $this->log($request, 'APPROVE_PERIOD', $period->id, ['name' => $period->name]);

        return response()->json([
            'message' => 'اعتُمدت موجة الجدولة',
            'period' => $this->row($period->fresh(['creator', 'approver'])),
        ]);
    }

    // POST /scheduling-periods/{id}/reject — إرجاعها مسودّةً بسبب
    public function reject(Request $request, int $id)
    {
        if ($deny = $this->denyApprove($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }
        if ($period->status !== 'pending_center') {
            return response()->json(['error' => 'لا تُرجَع إلا موجة مُرسَلة للاعتماد'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:300',
        ], [
            'reason.required' => 'اكتب سبب الإرجاع — بلا سبب لا يعرف من بناها ما يُصلح',
        ]);

        $period->status = 'draft';
        $period->reject_reason = $validated['reason'];
        $period->save();

        if ($period->submitted_by) {
            $this->notifications->notify(
                $period->submitted_by,
                'approval',
                'أُرجعت الجدولة للتعديل',
                'موجة «'.$period->name.'»: '.$validated['reason'],
                'scheduling_period',
                (string) $period->id,
                $request->user()->id,
            );
        }

        $this->log($request, 'REJECT_PERIOD', $period->id, [
            'name' => $period->name,
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'أُرجعت الموجة لصاحبها',
            'period' => $this->row($period->fresh(['creator', 'approver'])),
        ]);
    }

    // POST /scheduling-periods/{id}/close — إغلاق موجة انتهت
    public function close(Request $request, int $id)
    {
        if ($deny = $this->denyManage($request)) {
            return $deny;
        }

        $period = SchedulingPeriod::find($id);
        if (! $period) {
            return response()->json(['error' => 'الموجة غير موجودة'], 404);
        }
        if ($period->status === 'closed') {
            return response()->json(['error' => 'الموجة مغلقة أصلاً'], 422);
        }
        if ($period->status !== 'approved') {
            return response()->json(['error' => 'لا تُغلق إلا موجة معتمَدة'], 422);
        }

        $period->status = 'closed';
        $period->save();

        $this->log($request, 'CLOSE_PERIOD', $period->id, ['name' => $period->name]);

        return response()->json([
            'message' => 'أُغلقت الموجة',
            'period' => $this->row($period->fresh(['creator', 'approver'])),
        ]);
    }
}
