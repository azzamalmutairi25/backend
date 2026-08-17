<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DispatchAuthority;
use App\Models\Schedule;
use App\Models\ScheduleDispatch;
use App\Models\SchedulingPeriod;
use App\Security\Permissions;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  تسليم الجدولة للجهات — الخطوة الحادية عشرة
// ════════════════════════════════════════════════════════════
//
// التقسيم على **فئة المرشّح** (`personnel_category`) لا على قطاعه: القطاع جهةٌ
// يعمل فيها مدنيّون وعسكريّون معاً، وربطُ الجهة به كان يُرسل مدنيّ «الأمن العام»
// إلى وكالة الشؤون العسكرية.
//
// والربط بين الجهة والفئات **بيانٌ لا شيفرة** (`dispatch_authorities.categories`)،
// لأن المخطّط يذكر فئتين والنظام صار فيه ثلاث — والمتعاقد وُضع مع الموارد
// البشرية افتراضاً ظاهراً يُغيَّر بتعديل صفّ.
//
// **بلا اتصال خارجي**: المخرج ملفٌّ يُنزَّل ومحضرُ تسليمٍ يُطبع. لا بريد ولا
// تكامل — المنصّة على شبكةٍ داخلية، وإخراج البيانات إلى قناةٍ خارجية قرارٌ
// يُتخذ صراحةً لا يُستنتج من ميزة.
class DispatchController extends Controller
{
    public function __construct()
    {
    }

    private function log(Request $request, string $action, ?int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => 'dispatch',
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function denyView(Request $request): ?\Illuminate\Http\JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_VIEW)
            ? null
            : response()->json(['error' => 'ليس لديك صلاحية عرض الجدولة'], 403);
    }

    private function denySend(Request $request): ?\Illuminate\Http\JsonResponse
    {
        return $request->user()->hasPermission(Permissions::SCHEDULE_DISPATCH)
            ? null
            : response()->json(['error' => 'تسليم الجدولة للجهات لمدير المركز'], 403);
    }

    /** تحييد حقن صيغ الجداول + تهريب الفواصل — منقولٌ من مُصدِّر التقارير */
    private function csv($v): string
    {
        $v = (string) $v;
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'" . $v;
        }
        if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    // GET /dispatch/authorities
    public function authorities(Request $request)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        return response()->json([
            'authorities' => DispatchAuthority::active()->orderBy('sort_order')->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name_ar,
                    'categories' => $a->categoryList(),
                    'categoryLabels' => $a->categoryLabels(),
                ]),
            'canSend' => $request->user()->hasPermission(Permissions::SCHEDULE_DISPATCH),
        ]);
    }

    /** المدى المطلوب: موجةٌ أو تاريخان. يرجع [from, to, period] أو null */
    private function range(array $v): ?array
    {
        if (!empty($v['periodId'])) {
            $p = SchedulingPeriod::find($v['periodId']);
            if (!$p) {
                return null;
            }
            return [$p->start_date->toDateString(), $p->end_date->toDateString(), $p];
        }
        if (!empty($v['from']) && !empty($v['to'])) {
            return [$v['from'], $v['to'], null];
        }
        return null;
    }

    /**
     * صفوف التسليم لجهةٍ في مدى.
     *
     * جلسةٌ لكل صفّ لا مشاركاً: الجهة تستقبل جدولاً — من يحضر، متى، وفي أي نشاط.
     */
    private function rowsFor(Request $request, DispatchAuthority $authority, string $from, string $to, ?int $periodId): array
    {
        $categories = $authority->categoryList();
        if (!$categories) {
            return [];
        }

        $query = Schedule::with(['candidate.sector', 'assessment'])
            ->whereDate('schedule_date', '>=', $from)
            ->whereDate('schedule_date', '<=', $to)
            ->whereHas('candidate', fn ($q) => $q->whereIn('personnel_category', $categories));

        // نطاق القارئ يُطبَّق كما في كل قائمة — التصنيف والقطاع
        $this->scopeViaCandidate($request, $query);
        if ($periodId) {
            $query->where('period_id', $periodId);
        }

        $activityLabel = [
            'interview' => 'المقابلة الشخصية',
            'discussion' => 'حلقة النقاش',
            'measurement' => 'أدوات القياس',
            'integration' => 'التمرين التكاملي',
        ];

        $rows = [];
        foreach ($query->orderBy('schedule_date')->orderBy('schedule_time')->get() as $s) {
            $c = $s->candidate;
            if (!$c) {
                continue;
            }
            $rows[] = [
                'code' => $s->assessment?->participant_code ?? $c->participant_code,
                'sector' => optional($c->sector)->name_ar ?? '—',
                'rank' => $c->rank_label ?? '—',
                'category' => DispatchAuthority::CATEGORY_LABEL[$c->personnel_category] ?? $c->personnel_category,
                'date' => substr((string) $s->schedule_date, 0, 10),
                'time' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : '—',
                'activity' => $activityLabel[$s->activity] ?? $s->activity,
            ];
        }
        return $rows;
    }

    // GET /dispatch/preview — ما الذي سيُسلَّم لكل جهة
    public function preview(Request $request)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'periodId' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'authorityId' => 'nullable|integer',
        ]);

        $range = $this->range($validated);
        if (!$range) {
            return response()->json(['error' => 'حدّد موجةً أو مدىً بتاريخين'], 422);
        }
        [$from, $to, $period] = $range;

        $authorities = DispatchAuthority::active()->orderBy('sort_order')
            ->when(!empty($validated['authorityId']), fn ($q) => $q->where('id', $validated['authorityId']))
            ->get();

        $out = [];
        foreach ($authorities as $a) {
            $rows = $this->rowsFor($request, $a, $from, $to, $period?->id);
            $last = ScheduleDispatch::where('authority_id', $a->id)
                ->where('date_from', $from)->where('date_to', $to)
                ->latest('sent_at')->first();

            $out[] = [
                'authorityId' => $a->id,
                'authorityName' => $a->name_ar,
                'categoryLabels' => $a->categoryLabels(),
                'count' => count($rows),
                'rows' => $rows,
                'lastSentAt' => optional($last?->sent_at)?->toDateTimeString(),
            ];
        }

        return response()->json([
            'from' => $from,
            'to' => $to,
            'periodName' => $period?->name,
            'authorities' => $out,
            'canSend' => $request->user()->hasPermission(Permissions::SCHEDULE_DISPATCH),
        ]);
    }

    // POST /dispatch/send — إخراج ملفّ الجهة وتسجيل التسليم
    public function send(Request $request)
    {
        if ($deny = $this->denySend($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'authorityId' => 'required|integer|exists:dispatch_authorities,id',
            'periodId' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $range = $this->range($validated);
        if (!$range) {
            return response()->json(['error' => 'حدّد موجةً أو مدىً بتاريخين'], 422);
        }
        [$from, $to, $period] = $range;

        $authority = DispatchAuthority::find($validated['authorityId']);
        $rows = $this->rowsFor($request, $authority, $from, $to, $period?->id);

        if (!$rows) {
            return response()->json(['error' => 'لا صفوف لهذه الجهة في هذا المدى — لا شيء يُسلَّم'], 422);
        }

        // BOM كي تفتحه Excel بالعربية سليمةً
        $csv = "\xEF\xBB\xBF" . implode(',', [
            'رمز المشارك', 'القطاع', 'الرتبة / المرتبة', 'الفئة', 'التاريخ', 'الوقت', 'النشاط',
        ]) . "\r\n";
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(fn ($v) => $this->csv($v), [
                $r['code'], $r['sector'], $r['rank'], $r['category'], $r['date'], $r['time'], $r['activity'],
            ])) . "\r\n";
        }

        // البصمة تُحسب على ما خرج فعلاً — بها يُثبت بعد شهور أن ما بيد الجهة
        // هو ما أخرجه النظام لا نسخةً عُدِّلت في الطريق
        $checksum = hash('sha256', $csv);

        $dispatch = ScheduleDispatch::create([
            'authority_id' => $authority->id,
            'period_id' => $period?->id,
            'date_from' => $from,
            'date_to' => $to,
            'rows_count' => count($rows),
            'channel' => 'download',
            'checksum' => $checksum,
            'sent_by' => $request->user()->id,
            'sent_at' => now(),
        ]);

        $this->log($request, 'SEND_SCHEDULE_DISPATCH', $dispatch->id, [
            'authority' => $authority->code,
            'rows' => count($rows),
            'from' => $from, 'to' => $to,
            'checksum' => $checksum,
        ]);

        $name = 'dispatch-' . $authority->code . '-' . $from . '_' . $to . '.csv';

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->header('X-Dispatch-Id', (string) $dispatch->id)
            ->header('X-Dispatch-Checksum', $checksum);
    }

    // GET /dispatches — سجلّ التسليمات
    public function index(Request $request)
    {
        if ($deny = $this->denyView($request)) {
            return $deny;
        }

        $rows = ScheduleDispatch::with(['authority', 'sender', 'period'])
            ->orderByDesc('sent_at')->limit(200)->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'authorityName' => optional($d->authority)->name_ar,
                'periodName' => optional($d->period)->name,
                'from' => $d->date_from?->toDateString(),
                'to' => $d->date_to?->toDateString(),
                'rows' => $d->rows_count,
                'checksum' => substr($d->checksum, 0, 12),
                'sentByName' => optional($d->sender)->full_name,
                'sentAt' => optional($d->sent_at)?->toDateTimeString(),
            ]);

        return response()->json(['dispatches' => $rows]);
    }

    // GET /dispatch/document — محضر التسليم للتوقيع (للمناولة اليدوية)
    public function document(Request $request)
    {
        if ($deny = $this->denySend($request)) {
            return $deny;
        }

        $validated = $request->validate(['dispatchId' => 'required|integer']);
        $d = ScheduleDispatch::with(['authority', 'sender', 'period'])->find($validated['dispatchId']);
        if (!$d) {
            return response()->json(['error' => 'سجلّ التسليم غير موجود'], 404);
        }

        $authority = e(optional($d->authority)->name_ar ?? '—');
        $periodName = e(optional($d->period)->name ?? '—');
        $from = e($d->date_from?->toDateString() ?? '—');
        $to = e($d->date_to?->toDateString() ?? '—');
        $rows = (int) $d->rows_count;
        $checksum = e($d->checksum);
        $sender = e(optional($d->sender)->full_name ?? '—');
        $sentAt = e(optional($d->sent_at)?->toDateTimeString() ?? '—');

        $this->log($request, 'PRINT_DISPATCH_RECEIPT', $d->id, ['authority' => optional($d->authority)->code]);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>محضر تسليم — {$authority}</title>
<style>
 @page { size:A4; margin:18mm; }
 body { margin:0; font-family:"Segoe UI",Tahoma,Arial,sans-serif; color:#111; background:#eef1f0; }
 .sheet { max-width:210mm; margin:0 auto; background:#fff; padding:16mm; }
 h1 { font-size:20px; color:#024032; border-bottom:3px solid #008769; padding-bottom:10px; margin:0 0 18px; }
 table { width:100%; border-collapse:collapse; font-size:13.5px; margin-bottom:22px; }
 th,td { border:1px solid #cfd4d9; padding:9px 12px; text-align:right; }
 th { background:#eef2f1; color:#024032; width:38%; font-weight:700; }
 .sum { font-family:"Courier New",monospace; font-size:11.5px; word-break:break-all; }
 .sig { display:flex; gap:24px; margin-top:34px; }
 .sig div { flex:1; }
 .sig .line { border-bottom:1px solid #9aa5a0; height:16mm; margin-bottom:6px; }
 .sig span { font-size:12px; color:#666; }
 .note { font-size:11.5px; color:#666; margin-top:26px; border-top:1px dashed #c9d2ce; padding-top:12px; }
 .print-bar { max-width:210mm; margin:14px auto 0; text-align:left; }
 .print-bar button { font:inherit; padding:8px 18px; border:0; border-radius:8px; background:#008769; color:#fff; cursor:pointer; }
 @media print { body { background:#fff; } .print-bar { display:none; } .sheet { padding:0; } }
</style></head>
<body>
<div class="sheet">
  <h1>محضر تسليم جدولة</h1>
  <table>
    <tr><th>الجهة المستلِمة</th><td>{$authority}</td></tr>
    <tr><th>موجة الجدولة</th><td>{$periodName}</td></tr>
    <tr><th>المدى</th><td>{$from} — {$to}</td></tr>
    <tr><th>عدد الصفوف المسلَّمة</th><td>{$rows}</td></tr>
    <tr><th>بصمة الملفّ (SHA-256)</th><td class="sum">{$checksum}</td></tr>
    <tr><th>سلّمها</th><td>{$sender} — {$sentAt}</td></tr>
  </table>
  <div class="sig">
    <div><div class="line"></div><span>عن مركز تمكين الكفاءات</span></div>
    <div><div class="line"></div><span>عن الجهة المستلِمة</span></div>
  </div>
  <div class="note">
    تُطابَق بصمة الملفّ عند أي مراجعة: ملفٌّ بصمتُه مختلفة ليس الملفّ الذي أخرجه النظام.
  </div>
</div>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
</body></html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
