<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\FinalReport;
use App\Models\Schedule;
use App\Models\EvaluationScore;
use Illuminate\Support\Carbon;

// ════════════════════════════════════════════════════════════
//  تقرير اليوم لمدير المركز: من حضر ومن غاب (بالأسباب)، ودرجات
//  التقييم، وحالة كل تقرير. يُعرض في الصفحة ويُطبع ويُرسل بالبريد.
//
//  خدمة واحدة يستدعيها المتحكّم (عرض) والمهمة اليومية (بريد) — فلا
//  يتباعد ما يُطبع عمّا يُرسل.
// ════════════════════════════════════════════════════════════

class DailyReportService
{
    // يجمّع أرقام يومٍ بعينه (اليوم افتراضاً).
    // $allowedClassifications: حين يُمرَّر (مسار المتحكّم) تُحصَر الأرقام على تصنيفات
    // المستخدم — فلا يكشف مَن مُنِح ANALYTICS_VIEW عبر استثناء (بلا تصريح مصنّف)
    // وجودَ المصنّفين. null (مسار المهمة اليومية) = تقرير كامل للنظام.
    public function gather(?string $date = null, ?array $allowedClassifications = null): array
    {
        $date = $date ?: now()->toDateString();
        $scope = fn ($q) => $allowedClassifications === null
            ? $q
            : $q->whereIn('classification', $allowedClassifications);

        // جلسات اليوم + حضورها ومرشحوها — استعلام واحد
        $sessions = Schedule::with(['candidate.sector', 'attendance', 'evaluator'])
            ->whereDate('schedule_date', $date)
            ->when($allowedClassifications !== null, fn ($q) => $q->whereHas('candidate', $scope))
            ->get();

        $present = $sessions->filter(fn ($s) => $s->attendance?->status === 'present');
        $absent = $sessions->filter(fn ($s) => in_array($s->attendance?->status, ['absent_excused', 'absent_unexcused'], true));
        $pending = $sessions->filter(fn ($s) => !$s->attendance || $s->attendance->status === 'pending');

        // درجات التقييم المُدخَلة اليوم — متوسط لكل جلسة قُيّمت
        $scoresToday = EvaluationScore::whereHas('evaluation', fn ($q) => $q->whereDate('updated_at', $date))
            ->when($allowedClassifications !== null, fn ($q) => $q->whereHas('evaluation.candidate', $scope))
            ->with('evaluation.candidate.sector')
            ->get()
            ->groupBy(fn ($s) => $s->evaluation->id);

        // حالة التقارير النشطة اليوم (أُنشئت أو تحرّكت)
        $reports = FinalReport::with('candidate.sector')
            ->where(fn ($q) => $q->whereDate('created_at', $date)->orWhereDate('updated_at', $date))
            ->when($allowedClassifications !== null, fn ($q) => $q->whereHas('candidate', $scope))
            ->get();

        return [
            'date' => $date,
            'totals' => [
                'sessions' => $sessions->count(),
                'present' => $present->count(),
                'absent' => $absent->count(),
                'pending' => $pending->count(),
            ],
            'absences' => $absent->map(fn ($s) => [
                'code' => $s->candidate->participant_code,
                'sector' => $s->candidate->sector->name_ar,
                'activity' => $this->activity($s->activity),
                'kind' => $s->attendance->status === 'absent_excused' ? 'بعذر' : 'بدون عذر',
                'reason' => $s->attendance->absence_reason ?: '—',
            ])->values()->all(),
            'presence' => $present->map(fn ($s) => [
                'code' => $s->candidate->participant_code,
                'sector' => $s->candidate->sector->name_ar,
                'activity' => $this->activity($s->activity),
                'time' => $s->attendance->check_in_time?->format('H:i') ?? '—',
            ])->values()->all(),
            'scores' => $scoresToday->map(function ($rows) {
                $ev = $rows->first()->evaluation;
                return [
                    'code' => $ev->candidate->participant_code,
                    'sector' => $ev->candidate->sector->name_ar,
                    'avg' => round($rows->avg('score'), 2),
                    'count' => $rows->count(),
                ];
            })->values()->all(),
            'reports' => $reports->map(fn ($r) => [
                'code' => $r->candidate->participant_code,
                'sector' => $r->candidate->sector->name_ar,
                'status' => $this->reportStatus($r->status),
            ])->values()->all(),
        ];
    }

    // مستند HTML جاهز للعرض والطباعة (المتصفّح → PDF) والبريد
    public function renderHtml(array $data): string
    {
        $d = e($data['date']);
        $t = $data['totals'];

        $absRows = $this->rows($data['absences'], ['code', 'sector', 'activity', 'kind', 'reason'])
            ?: '<tr><td colspan="5" class="muted">لا غياب اليوم</td></tr>';
        $presRows = $this->rows($data['presence'], ['code', 'sector', 'activity', 'time'])
            ?: '<tr><td colspan="4" class="muted">لا حضور مسجّل</td></tr>';
        $scoreRows = $this->rows(array_map(fn ($s) => [
            $s['code'], $s['sector'], $s['avg'] . ' / 5', $s['count'] . ' كفاءة',
        ], $data['scores']), [0, 1, 2, 3])
            ?: '<tr><td colspan="4" class="muted">لا درجات أُدخلت اليوم</td></tr>';
        $repRows = $this->rows($data['reports'], ['code', 'sector', 'status'])
            ?: '<tr><td colspan="3" class="muted">لا تقارير تحرّكت اليوم</td></tr>';

        return <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>تقرير اليوم — {$d}</title>
<style>
 * { box-sizing: border-box; }
 body { font-family:"Segoe UI","Noto Naskh Arabic",Tahoma,sans-serif; color:#1a2420; margin:0; background:#f0f2ef; }
 .sheet { max-width:860px; margin:24px auto; background:#fff; padding:38px 44px; box-shadow:0 2px 20px rgba(0,0,0,.08); }
 .print-bar { max-width:860px; margin:16px auto 0; text-align:left; }
 .print-bar button { font:inherit; padding:8px 18px; border:0; border-radius:8px; background:#1f6b4a; color:#fff; cursor:pointer; }
 .hd { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #1f6b4a; padding-bottom:16px; }
 .hd .org { font-weight:800; font-size:19px; color:#1f6b4a; }
 .hd .sub { color:#5b6a62; font-size:13px; margin-top:4px; }
 .hd .meta { text-align:left; font-size:13px; color:#5b6a62; }
 .kpis { display:flex; gap:14px; margin:22px 0; }
 .kpi { flex:1; text-align:center; padding:14px; border-radius:10px; background:#f6f8f6; }
 .kpi .n { font-size:26px; font-weight:800; }
 .kpi .l { font-size:12px; color:#5b6a62; margin-top:3px; }
 .kpi.present .n { color:#1f6b4a; } .kpi.absent .n { color:#c0392b; } .kpi.pending .n { color:#b8860b; }
 h2 { font-size:15px; margin:26px 0 8px; color:#1f6b4a; border-right:4px solid #1f6b4a; padding-right:10px; }
 table { width:100%; border-collapse:collapse; font-size:13px; }
 th,td { text-align:right; padding:7px 10px; border-bottom:1px solid #e8ece9; }
 th { color:#5b6a62; font-size:12px; background:#f6f8f6; }
 .muted { color:#8a978f; text-align:center; padding:12px; }
 @media print { body{ background:#fff; } .sheet{ box-shadow:none; margin:0; max-width:none; } .print-bar{ display:none; } @page{ margin:14mm; } }
</style></head><body>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
<div class="sheet">
 <div class="hd">
  <div><div class="org">مركز تمكين الكفاءات لتقييم القيادات</div><div class="sub">التقرير اليومي</div></div>
  <div class="meta">التاريخ: <b>{$d}</b></div>
 </div>
 <div class="kpis">
  <div class="kpi present"><div class="n">{$t['present']}</div><div class="l">حضروا</div></div>
  <div class="kpi absent"><div class="n">{$t['absent']}</div><div class="l">غياب</div></div>
  <div class="kpi pending"><div class="n">{$t['pending']}</div><div class="l">بانتظار</div></div>
  <div class="kpi"><div class="n">{$t['sessions']}</div><div class="l">إجمالي الجلسات</div></div>
 </div>

 <h2>الحضور</h2>
 <table><thead><tr><th>الرمز</th><th>القطاع</th><th>النشاط</th><th>وقت الوصول</th></tr></thead><tbody>{$presRows}</tbody></table>

 <h2>الغياب والأسباب</h2>
 <table><thead><tr><th>الرمز</th><th>القطاع</th><th>النشاط</th><th>النوع</th><th>السبب</th></tr></thead><tbody>{$absRows}</tbody></table>

 <h2>درجات التقييم المُدخَلة اليوم</h2>
 <table><thead><tr><th>الرمز</th><th>القطاع</th><th>المتوسط</th><th>عدد الكفاءات</th></tr></thead><tbody>{$scoreRows}</tbody></table>

 <h2>حالة التقارير</h2>
 <table><thead><tr><th>الرمز</th><th>القطاع</th><th>الحالة</th></tr></thead><tbody>{$repRows}</tbody></table>
</div></body></html>
HTML;
    }

    private function rows(array $items, array $keys): string
    {
        return implode('', array_map(function ($item) use ($keys) {
            $cells = implode('', array_map(fn ($k) => '<td>' . e($item[$k]) . '</td>', $keys));
            return "<tr>{$cells}</tr>";
        }, $items));
    }

    private const ACTIVITY = [
        'interview' => 'مقابلة', 'discussion' => 'حلقة نقاش',
        'measurement' => 'أدوات قياس', 'integration' => 'جلسة تكامل',
    ];

    private function activity(string $a): string
    {
        return self::ACTIVITY[$a] ?? $a;
    }

    private function reportStatus(string $s): string
    {
        return [
            'draft' => 'مسودة',
            'pending_evaluator' => 'بانتظار المقيّم',
            'pending_manager' => 'بانتظار مدير التقييم',
            'pending_dev_approval' => 'بانتظار تطوير الكفاءات',
            'pending_center' => 'بانتظار مدير المركز',
            'approved' => 'معتمد',
            'returned' => 'مُعاد للتعديل',
            'cancelled' => 'ملغى',
        ][$s] ?? $s;
    }
}
