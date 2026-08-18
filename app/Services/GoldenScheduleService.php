<?php

namespace App\Services;

use App\Models\GoldenScheduleEntry;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  الجدول الذهبي — التاريخ ورمز المشارك، مجمّعين بالقطاع
// ════════════════════════════════════════════════════════════
//
// توأم `RosterSheetService`: `gather` تبني البيانات و`renderHtml` تُخرج مستنداً
// يُطبع من المتصفّح — نفس العرف (heredoc واحد، `@page`، شريط طباعة يُخفى عند
// الطبع، والشعار قاعدةَ CSS واحدة لا صورةً مكرّرة).
class GoldenScheduleService
{
    private const GREEN = '#008769';
    private const GREEN_DARK = '#024032';
    private const GOLD = '#C8A535';
    private const EMBLEM_PATH = 'brand/moi-emblem.png';

    /**
     * مزامنة الموجة: كل جلسة فيها تصير صفّاً (تاريخ × رمز).
     *
     * `updateOrCreate` على المفتاح الفريد فتُشغَّل مرّتين بلا مضاعفة، و**الصفّ
     * اليدوي لا يُمسّ**: ما كتبه الموظّف بيده لا يمحوه زرٌّ يُضغط بعد أسبوع.
     *
     * @return array{created:int,updated:int,keptManual:int}
     */
    public function sync(SchedulingPeriod $period, int $userId): array
    {
        $sessions = Schedule::with(['candidate', 'assessment'])
            ->where('period_id', $period->id)
            ->orderBy('schedule_date')
            ->get();

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($sessions, $period, $userId, &$created, &$updated) {
            foreach ($sessions as $s) {
                $code = $s->assessment?->participant_code ?? $s->candidate?->participant_code;
                if (!$code || !$s->candidate) {
                    continue;
                }
                $date = substr((string) $s->schedule_date, 0, 10);

                $existing = GoldenScheduleEntry::where('period_id', $period->id)
                    ->whereDate('entry_date', $date)
                    ->where('participant_code', $code)
                    ->first();

                if ($existing) {
                    if ($existing->source === 'manual') {
                        continue;   // يدويٌّ يبقى كما كُتب
                    }
                    $existing->update([
                        'assessment_id' => $s->assessment_id,
                        'schedule_id' => $s->id,
                        'sector_id' => $s->candidate->sector_id,
                    ]);
                    $updated++;
                    continue;
                }

                GoldenScheduleEntry::create([
                    'period_id' => $period->id,
                    'entry_date' => $date,
                    'participant_code' => $code,
                    'assessment_id' => $s->assessment_id,
                    'schedule_id' => $s->id,
                    'sector_id' => $s->candidate->sector_id,
                    'source' => 'sync',
                    'added_by' => $userId,
                ]);
                $created++;
            }
        });

        $keptManual = GoldenScheduleEntry::where('period_id', $period->id)->manual()->count();

        return ['created' => $created, 'updated' => $updated, 'keptManual' => $keptManual];
    }

    /**
     * شبكة (قطاع → تاريخ → رموز) لموجة، محصورةً بنطاق القارئ.
     *
     * @param array|null $allowedClassifications تصنيفات القارئ — null = بلا حصر
     * @param int|null   $sectorId قطاعٌ بعينه — null = الكل
     */
    public function gather(SchedulingPeriod $period, ?array $allowedClassifications = null, ?int $sectorId = null): array
    {
        $query = GoldenScheduleEntry::with('sector')->where('period_id', $period->id);

        if ($sectorId !== null) {
            $query->where('sector_id', $sectorId);
        }
        $rows = $query->orderBy('entry_date')->orderBy('participant_code')->get();

        // الحصر يُطبَّق هنا لا في SQL: الصفّ اليدوي قد لا يحمل دورة، ورمزُه
        // نصٌّ حرّ لا يقود إلى مشارك. من لا دورةَ له يُعرض — لأنه ما كتبه
        // الموظّف بيده لا ما استخرجه النظام من سجلٍّ مصنّف.
        if ($allowedClassifications !== null) {
            $blocked = \App\Models\Assessment::whereIn('id', $rows->pluck('assessment_id')->filter()->unique())
                ->whereHas('candidate', fn ($q) => $q->whereNotIn('classification', $allowedClassifications))
                ->pluck('id')->all();
            $rows = $rows->reject(fn ($r) => $r->assessment_id && in_array($r->assessment_id, $blocked, true));
        }

        $days = array_map(fn ($d) => $d->toDateString(), $period->days());

        $bySector = [];
        foreach ($rows as $r) {
            $sName = optional($r->sector)->name_ar ?? '—';
            $date = $r->entry_date->toDateString();
            $bySector[$sName]['sectorId'] = $r->sector_id;
            $bySector[$sName]['days'][$date][] = [
                'code' => $r->participant_code,
                'source' => $r->source,
                'note' => $r->note,
                'id' => $r->id,
            ];
        }
        ksort($bySector);

        return [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'startDate' => $period->start_date->toDateString(),
                'endDate' => $period->end_date->toDateString(),
            ],
            'days' => $days,
            'sectors' => $bySector,
            'total' => $rows->count(),
        ];
    }

    private function emblemDataUri(): string
    {
        $path = public_path(self::EMBLEM_PATH);
        return is_file($path)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path))
            : '';
    }

    /** المستند المطبوع — شبكة أيام × رموز، قسمٌ لكل قطاع */
    public function renderHtml(array $data): string
    {
        $green = self::GREEN;
        $greenDark = self::GREEN_DARK;
        $gold = self::GOLD;
        $emblem = $this->emblemDataUri();
        $emblemRule = $emblem
            ? ".mark { background-image:url('{$emblem}'); background-size:contain; background-repeat:no-repeat; background-position:center; }"
            : '';

        $name = e($data['period']['name']);
        $range = e($data['period']['startDate']) . ' — ' . e($data['period']['endDate']);
        $days = $data['days'];

        $head = '<th class="sec">القطاع</th>' . implode('', array_map(
            fn ($d) => '<th>' . e($d) . '</th>', $days
        ));

        $body = '';
        foreach ($data['sectors'] as $sectorName => $sector) {
            $cells = '';
            foreach ($days as $d) {
                $codes = $sector['days'][$d] ?? [];
                $inner = $codes
                    ? implode('', array_map(
                        fn ($c) => '<span class="code' . ($c['source'] === 'manual' ? ' manual' : '') . '">'
                            . e($c['code']) . '</span>',
                        $codes))
                    : '<span class="empty">—</span>';
                $cells .= '<td>' . $inner . '</td>';
            }
            $body .= '<tr><th class="sec">' . e($sectorName) . '</th>' . $cells . '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td class="none" colspan="' . (count($days) + 1) . '">لا صفوف في هذا الجدول بعد</td></tr>';
        }

        $total = $data['total'];

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>الجدول الذهبي — {$name}</title>
<style>
 @page { size:A4 landscape; margin:10mm; }
 * { box-sizing:border-box; }
 body { margin:0; font-family:"Segoe UI",Tahoma,Arial,sans-serif; color:#111; background:#f4f5f7; }
 .sheet { background:#fff; padding:14mm 10mm; max-width:290mm; margin:0 auto; }
 .hd { display:flex; align-items:center; gap:14px; border-bottom:3px solid {$green}; padding-bottom:10px; margin-bottom:14px; }
 .mark { width:46px; height:46px; flex:none; }
 {$emblemRule}
 .ttl { font-size:19px; font-weight:800; color:{$greenDark}; }
 .sub { font-size:12px; color:#555; margin-top:3px; }
 .badge { margin-inline-start:auto; font-size:12px; color:{$gold}; font-weight:700; }
 table { width:100%; border-collapse:collapse; font-size:11.5px; }
 th,td { border:1px solid #cfd4d9; padding:6px 7px; text-align:right; vertical-align:top; }
 thead th { background:{$green}; color:#fff; font-weight:700; white-space:nowrap; }
 th.sec { background:#eef2f1; color:{$greenDark}; font-weight:700; white-space:nowrap; width:120px; }
 thead th.sec { background:{$greenDark}; color:#fff; }
 .code { display:inline-block; font-family:"Courier New",monospace; font-size:11px; background:#f2f5f4; border:1px solid #d7dedc; border-radius:4px; padding:1px 6px; margin:1px 2px 1px 0; }
 .code.manual { border-color:{$gold}; background:#fdf8ec; }
 .empty { color:#b8bfc4; }
 .none { text-align:center; color:#888; padding:22px; }
 .foot { margin-top:12px; font-size:11px; color:#666; display:flex; justify-content:space-between; }
 .print-bar { max-width:290mm; margin:16px auto 0; text-align:left; }
 .print-bar button { font:inherit; padding:8px 18px; border:0; border-radius:8px; background:{$green}; color:#fff; cursor:pointer; }
 @media print { body { background:#fff; } .print-bar { display:none; } .sheet { padding:0; } }
</style></head>
<body>
<div class="sheet">
  <div class="hd">
    <div class="mark"></div>
    <div>
      <div class="ttl">الجدول الذهبي — {$name}</div>
      <div class="sub">{$range}</div>
    </div>
    <div class="badge">{$total} صفّاً</div>
  </div>
  <table>
    <thead><tr>{$head}</tr></thead>
    <tbody>{$body}</tbody>
  </table>
  <div class="foot">
    <span>الرمز المذهَّب الإطار أُضيف يدوياً</span>
    <span>مركز تمكين الكفاءات</span>
  </div>
</div>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
</body></html>
HTML;
    }
}
