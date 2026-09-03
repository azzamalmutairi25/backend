<?php

namespace App\Services;

use App\Http\Controllers\SettingsController;
use App\Models\RosterGroup;
use App\Models\Schedule;

// ════════════════════════════════════════════════════════════
//  كشف «حضور المشاركين لمركز تمكين الكفاءات» — النموذج الورقي
//  المعتمد لدى إدارة العمليات، صفحتان:
//
//  الأولى: كشف اليوم كاملاً، مجموعة أ ثم مجموعة ب، بأعمدة الأوقات
//          المعتمدة وأيقونة النشاط في كل خانة، وخانتَي التوقيع و S
//          تُطبعان فارغتين للتعبئة اليدوية.
//  الثانية: جدول إسناد المقيّمين لكل مجموعة، معنون بفترتها
//          (صباحاً/مساءً) المستنتجة من وقت جلسة النقاش.
//
//  المبدأ الحاكم: الكشف مرآة للبيانات لا بديل عنها. ما لا يوجد في
//  قاعدة البيانات يُطبع شرطةً، ولا يُشتق ولا يُخمَّن — فالكشف يكشف
//  فجوات الإسناد بدل أن يسترها. لذلك أعمدة مقيّمي جلسة النقاش قد
//  تخرج فارغة تماماً حتى تُسنَد جلساتها فعلياً.
// ════════════════════════════════════════════════════════════

class RosterSheetService
{
    private const ACTIVITY_LABEL = [
        'interview' => 'مقابلة شخصية',
        'discussion' => 'جلسة النقاش',
        'measurement' => 'أدوات القياس',
        'integration' => 'جلسة تكامل',
    ];

    private const DAY_NAMES = [
        'Sunday' => 'الأحد', 'Monday' => 'الاثنين', 'Tuesday' => 'الثلاثاء',
        'Wednesday' => 'الأربعاء', 'Thursday' => 'الخميس',
        'Friday' => 'الجمعة', 'Saturday' => 'السبت',
    ];

    // يجمّع كشف يومٍ بعينه.
    // $allowedClassifications: تُحصَر النتيجة على تصنيفات المستخدم (null = بلا حصر).
    // $sectorId: قطاع المستخدم المحصور (null = بلا حصر قطاعي).
    // $showNationalId: قرار الطابع — يُمرَّر بعد التحقق من الصلاحية في المتحكّم.
    public function gather(
        ?string $date = null,
        ?array $allowedClassifications = null,
        ?int $sectorId = null,
        bool $showNationalId = false
    ): array {
        $date = $date ?: now()->toDateString();
        $slots = SettingsController::sessionTimes();

        // حصر المشاركين على نطاق المستخدم — التصنيف والقطاع معاً. لو حُصر
        // التصنيف وحده لرأى المحصور قطاعياً كشفَ قطاعٍ ليس قطاعه.
        $scope = function ($q) use ($allowedClassifications, $sectorId) {
            if ($allowedClassifications !== null) {
                $q->whereIn('classification', $allowedClassifications);
            }
            if ($sectorId !== null) {
                $q->where('sector_id', $sectorId);
            }
        };
        $scoped = $allowedClassifications !== null || $sectorId !== null;

        $sessions = Schedule::with(['candidate.sector', 'evaluator', 'assistant', 'assessment'])
            ->whereDate('schedule_date', $date)
            ->when($scoped, fn ($q) => $q->whereHas('candidate', $scope))
            ->get();

        // مجموعات اليوم — مفهرسة بالمشارك
        $groups = RosterGroup::whereDate('roster_date', $date)
            ->get()
            ->keyBy('candidate_id');

        // جلسات خارج الأوقات المعتمدة: تُحصى وتُعلَن أسفل الكشف بدل أن
        // تُقحَم في عمود ليس لها، أو تختفي بلا أثر.
        $offSlot = 0;

        $byCandidate = [];
        foreach ($sessions as $s) {
            $c = $s->candidate;
            if (! $c) {
                continue;
            }
            $time = $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null;
            if ($time !== null && ! in_array($time, $slots, true)) {
                $offSlot++;
            }

            if (! isset($byCandidate[$c->id])) {
                $byCandidate[$c->id] = [
                    'candidate' => $c,
                    // رمز دورة التقييم من الجلسة نفسها — لا من المشارك: المشارك
                    // قد تتعدّد دوراته، والكشف يخصّ الدورة التي جُدولت له اليوم
                    'assessment' => $s->assessment,
                    'group' => $groups[$c->id]->group_letter ?? null,
                    'slots' => array_fill_keys($slots, null),
                    'interview' => null,
                    'discussion' => null,
                ];
            }

            if ($time !== null && array_key_exists($time, $byCandidate[$c->id]['slots'])) {
                $byCandidate[$c->id]['slots'][$time] = $s->activity;
            }
            // صفّا المقابلة والنقاش هما مصدر أعمدة المقيّمين الأربعة
            if ($s->activity === 'interview' || $s->activity === 'discussion') {
                $byCandidate[$c->id][$s->activity] = $s;
            }
        }

        $rows = [];
        foreach ($byCandidate as $entry) {
            $c = $entry['candidate'];
            $interview = $entry['interview'];
            $discussion = $entry['discussion'];

            $rows[] = [
                'group' => $entry['group'],
                'groupLabel' => RosterGroup::label($entry['group']),
                'nationalId' => $showNationalId ? ($c->national_id ?: '—') : '—',
                'rank' => $c->rank_label ?: '—',
                'code' => $entry['assessment']?->participant_code ?: $c->participant_code,
                'slots' => $entry['slots'],
                'evaluator' => $interview?->evaluator?->full_name ?: '—',
                'assistant' => $interview?->assistant?->full_name ?: '—',
                'discussionEvaluator' => $discussion?->evaluator?->full_name ?: '—',
                'discussionAssistant' => $discussion?->assistant?->full_name ?: '—',
                // فترة جلسة النقاش — تُعنون بها جداول الصفحة الثانية
                'discussionTime' => $discussion && $discussion->schedule_time
                    ? substr((string) $discussion->schedule_time, 0, 5)
                    : null,
            ];
        }

        // الترتيب: المجموعة أولاً (وغير المُسنَدين آخراً)، ثم رمز المشارك
        usort($rows, function ($a, $b) {
            $ga = $a['group'] ?? 'ZZ';
            $gb = $b['group'] ?? 'ZZ';

            return [$ga, $a['code']] <=> [$gb, $b['code']];
        });

        // ترقيم متسلسل عبر الكشف كله، كما في النموذج الورقي (1..10)
        foreach ($rows as $i => $_) {
            $rows[$i]['seq'] = $i + 1;
        }

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['group'] ?? '—'][] = $r;
        }
        ksort($grouped);

        return [
            'date' => $date,
            'dayName' => self::DAY_NAMES[date('l', strtotime($date))] ?? '',
            'slots' => $slots,
            'rows' => $rows,
            'grouped' => $grouped,
            'offSlot' => $offSlot,
            'showNationalId' => $showNationalId,
            'ungrouped' => count(array_filter($rows, fn ($r) => $r['group'] === null)),
        ];
    }

    // مستند HTML جاهز للطباعة (المتصفّح → PDF)
    public function renderHtml(array $data): string
    {
        $d = e($data['date']);
        $day = e($data['dayName']);
        $slots = $data['slots'];

        $slotHead = implode('', array_map(
            fn ($t) => '<th class="gold">'.e($t).'</th>',
            $slots
        ));

        $page1 = $this->renderRosterBlocks($data['grouped'], $slots);
        $page2 = $this->renderAssessorTables($data['grouped']);

        $legend = $this->renderLegend();

        $notes = [];
        if ($data['ungrouped'] > 0) {
            $notes[] = '<span class="warn">'.e($data['ungrouped']).' مشاركاً بلا مجموعة مُسنَدة</span>';
        }
        if ($data['offSlot'] > 0) {
            $notes[] = '<span class="warn">'.e($data['offSlot']).' جلسة خارج الأوقات المعتمدة — لا تظهر في أعمدة التوقيت</span>';
        }
        if (! $data['showNationalId']) {
            $notes[] = 'عمود الهوية الوطنية محجوب في هذه النسخة';
        }
        $notesHtml = $notes ? '<div class="notes">'.implode(' · ', $notes).'</div>' : '';

        $empty = $data['rows']
            ? ''
            : '<div class="empty">لا جلسات مجدولة في هذا اليوم</div>';

        return <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>حضور المشاركين — {$d}</title>
<style>
 * { box-sizing: border-box; }
 body { font-family:"Segoe UI","Noto Naskh Arabic",Tahoma,sans-serif; color:#101010; margin:0; background:#f0f2ef; }
 .sheet { max-width:1180px; margin:24px auto; background:#fff; padding:26px 30px 34px; box-shadow:0 2px 20px rgba(0,0,0,.08); }
 .print-bar { max-width:1180px; margin:16px auto 0; text-align:left; }
 .print-bar button { font:inherit; padding:8px 18px; border:0; border-radius:8px; background:#12795C; color:#fff; cursor:pointer; }
 .dept { text-align:center; font-weight:700; font-size:15px; margin-bottom:14px; }
 .fhead { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:14px; }
 .ftitle { text-align:right; }
 .ftitle .t { font-size:15px; font-weight:700; }
 .ftitle .d { font-size:13.5px; margin-top:8px; display:flex; gap:26px; justify-content:flex-start; }
 .legend { display:flex; gap:22px; }
 .leg { display:flex; flex-direction:column; align-items:center; gap:3px; }
 .leg span { font-size:12.5px; font-weight:600; }
 table.form { border-collapse:collapse; width:100%; table-layout:fixed; }
 table.form th, table.form td { border:1px solid #101010; text-align:center; vertical-align:middle; font-size:12px; padding:0 3px; }
 table.form thead th { color:#fff; background:#12795C; font-weight:700; height:62px; line-height:1.25; }
 table.form thead th.gold { background:#C4A02F; }
 table.form tbody td { height:30px; background:#fff; }
 table.form tbody td.seq { font-weight:700; }
 .gap { height:22px; }
 h2 { font-size:14px; margin:26px 0 8px; color:#12795C; border-right:4px solid #12795C; padding-right:10px; }
 .period { font-weight:700; }
 .notes { margin-top:14px; font-size:12px; color:#5b6a62; }
 .notes .warn { color:#b8860b; font-weight:700; }
 .empty { text-align:center; color:#8a978f; padding:40px 0; font-size:14px; }
 .page-break { page-break-before:always; }
 .rights { margin-top:18px; padding-top:10px; border-top:1px solid #e8ece9; text-align:center; font-size:10px; color:#8a978f; }
 @media print { body{ background:#fff; } .sheet{ box-shadow:none; margin:0; max-width:none; padding:0; } .print-bar{ display:none; } @page{ margin:10mm; size:A4 landscape; } }
</style></head><body>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
<div class="sheet">
 <div class="dept">إدارة العمليات</div>
 <div class="fhead">
  <div class="legend">{$legend}</div>
  <div class="ftitle">
   <div class="t">حضور المشاركين لمركز تمكين الكفاءات</div>
   <div class="d"><span>يوم : {$day}</span><span>الموافق : {$d}</span></div>
  </div>
 </div>
 {$empty}
 <table class="form">
  <thead><tr>
   <th style="width:3.2%">م</th>
   <th style="width:3.2%">ج</th>
   <th style="width:9%">الهوية الوطنية</th>
   <th style="width:8%">الرتبة/المرتبة</th>
   <th style="width:8%">رمز المشارك</th>
   <th style="width:10%">التوقيع</th>
   {$slotHead}
   <th>اسم المقيم</th>
   <th>مساعد المقيم</th>
   <th>مقيم جلسة النقاش</th>
   <th>مساعد مقيم جلسة النقاش</th>
   <th class="gold" style="width:5%">S</th>
  </tr></thead>
  {$page1}
 </table>
 {$notesHtml}

 <div class="page-break"></div>
 <h2>إسناد المقيّمين</h2>
 {$page2}
 <div class="rights">جميع الحقوق محفوظة © إدارة تقنية المعلومات والذكاء الاصطناعي</div>
</div></body></html>
HTML;
    }

    // ── الصفحة الأولى: كتلة صفوف لكل مجموعة ──
    private function renderRosterBlocks(array $grouped, array $slots): string
    {
        $out = '';
        $first = true;

        foreach ($grouped as $rows) {
            if (! $first) {
                // فاصل بصري بين المجموعتين، كما في النموذج الورقي
                $span = 6 + count($slots) + 5;
                $out .= '<tbody><tr class="gap"><td colspan="'.$span.'" style="border:0"></td></tr></tbody>';
            }
            $first = false;

            $body = '';
            foreach ($rows as $r) {
                $slotCells = '';
                foreach ($slots as $t) {
                    $slotCells .= '<td>'.$this->activityIcon($r['slots'][$t] ?? null).'</td>';
                }

                $body .= '<tr>'
                    .'<td class="seq">'.e($r['seq']).'</td>'
                    .'<td class="seq">'.e($r['groupLabel']).'</td>'
                    .'<td>'.e($r['nationalId']).'</td>'
                    .'<td>'.e($r['rank']).'</td>'
                    .'<td>'.e($r['code']).'</td>'
                    .'<td></td>'
                    .$slotCells
                    .'<td>'.e($r['evaluator']).'</td>'
                    .'<td>'.e($r['assistant']).'</td>'
                    .'<td>'.e($r['discussionEvaluator']).'</td>'
                    .'<td>'.e($r['discussionAssistant']).'</td>'
                    .'<td></td>'
                    .'</tr>';
            }
            $out .= '<tbody>'.$body.'</tbody>';
        }

        return $out;
    }

    // ── الصفحة الثانية: جدول إسناد لكل مجموعة، معنون بفترة جلسة نقاشها ──
    private function renderAssessorTables(array $grouped): string
    {
        if (! $grouped) {
            return '<div class="empty">لا مجموعات مُسنَدة</div>';
        }

        $out = '';
        foreach ($grouped as $rows) {
            $period = $this->periodLabel($rows);
            $body = '';
            foreach ($rows as $i => $r) {
                $periodCell = $i === 0
                    ? '<td colspan="2" class="period">'.e($period).'</td>'
                    : '<td>'.e($r['discussionEvaluator']).'</td><td>'.e($r['discussionAssistant']).'</td>';

                $body .= '<tr>'
                    .'<td class="seq">'.e($i + 1).'</td>'
                    .'<td class="seq">'.e($r['groupLabel']).'</td>'
                    .'<td>'.e($r['rank']).'</td>'
                    .'<td>'.e($r['code']).'</td>'
                    .'<td>'.e($r['evaluator']).'</td>'
                    .'<td>'.e($r['assistant']).'</td>'
                    .$periodCell
                    .'<td></td>'
                    .'</tr>';
            }

            $out .= '<table class="form" style="max-width:62%; margin-inline-start:auto; margin-bottom:28px">'
                .'<thead><tr>'
                .'<th style="width:6%">م</th><th style="width:6%">ج</th>'
                .'<th style="width:13%">الرتبة/المرتبة</th><th style="width:13%">رمز المشارك</th>'
                .'<th style="width:15%">اسم المقيم</th><th style="width:15%">مساعد المقيم</th>'
                .'<th style="width:14%">مقيم جلسة النقاش</th><th style="width:14%">مساعد مقيم جلسة النقاش</th>'
                .'<th class="gold" style="width:9%">S</th>'
                .'</tr></thead><tbody>'.$body.'</tbody></table>';
        }

        return $out;
    }

    // فترة المجموعة من وقت جلسة نقاشها — لا تُخمَّن حين لا وقت مسجَّل
    private function periodLabel(array $rows): string
    {
        foreach ($rows as $r) {
            if ($r['discussionTime'] !== null) {
                return $r['discussionTime'] < '12:00' ? 'صباحاً' : 'مساءً';
            }
        }

        return '—';
    }

    // ── أيقونات الأنشطة (SVG مضمَّن — لا أصول خارجية، فالمستند يُطبع دون شبكة) ──

    private function activityIcon(?string $activity): string
    {
        return match ($activity) {
            'measurement' => self::ICON_LAPTOP,
            'interview' => self::ICON_INTERVIEW,
            'discussion' => self::ICON_DISCUSSION,
            'integration' => self::ICON_INTEGRATION,
            default => '',
        };
    }

    private const ICON_LAPTOP = '<svg width="30" height="23" viewBox="0 0 44 34" fill="none">'
        .'<rect x="8" y="5" width="28" height="18" rx="2.2" fill="#2F6B3E"/>'
        .'<rect x="10.5" y="7.5" width="23" height="13" rx="1.2" fill="#EAF3EC"/>'
        .'<path d="M3 27h38l-2.5-3H5.5L3 27Z" fill="#2F6B3E"/></svg>';

    private const ICON_INTERVIEW = '<svg width="25" height="21" viewBox="0 0 40 34" fill="none">'
        .'<circle cx="12" cy="8" r="4" fill="#12795C"/>'
        .'<path d="M6 22c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke="#12795C" stroke-width="2.4" stroke-linecap="round"/>'
        .'<circle cx="28" cy="8" r="4" fill="#12795C"/>'
        .'<path d="M22 22c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke="#12795C" stroke-width="2.4" stroke-linecap="round"/>'
        .'<path d="M4 26h32" stroke="#12795C" stroke-width="2.4" stroke-linecap="round"/></svg>';

    private const ICON_DISCUSSION = '<svg width="28" height="21" viewBox="0 0 44 34" fill="none">'
        .'<circle cx="9" cy="10" r="4" fill="#C4A02F"/>'
        .'<circle cx="22" cy="7" r="4.6" fill="#C4A02F"/>'
        .'<circle cx="35" cy="10" r="4" fill="#C4A02F"/>'
        .'<path d="M2 26c0-3.9 3.1-7 7-7s7 3.1 7 7" fill="#C4A02F"/>'
        .'<path d="M14 28c0-4.4 3.6-8 8-8s8 3.6 8 8" fill="#C4A02F"/>'
        .'<path d="M28 26c0-3.9 3.1-7 7-7s7 3.1 7 7" fill="#C4A02F"/></svg>';

    private const ICON_INTEGRATION = '<svg width="24" height="21" viewBox="0 0 34 34" fill="none">'
        .'<circle cx="12" cy="17" r="8" stroke="#5b6a62" stroke-width="2.4"/>'
        .'<circle cx="22" cy="17" r="8" stroke="#5b6a62" stroke-width="2.4"/></svg>';

    private function renderLegend(): string
    {
        $items = [
            [self::ICON_INTERVIEW, self::ACTIVITY_LABEL['interview']],
            [self::ICON_DISCUSSION, self::ACTIVITY_LABEL['discussion']],
            [self::ICON_LAPTOP, self::ACTIVITY_LABEL['measurement']],
        ];

        return implode('', array_map(
            fn ($i) => '<div class="leg">'.$i[0].'<span>'.e($i[1]).'</span></div>',
            $items
        ));
    }
}
