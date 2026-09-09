<?php

namespace App\Services;

use App\Models\GateManifest;

// ════════════════════════════════════════════════════════════
//  ورقة بيان تصاريح الدخول — جدولٌ واحد يُقدَّم لحارس البوّابة.
//
//  ── ما فيه ولماذا ──
//  الاسم · رقم الهوية · الرتبة أو المرتبة. والحارس يطابق شخصاً أمامه بسطرٍ
//  في كشف، فهذه الثلاثة هي ما يطابق به.
//
//  ── وما ليس فيه ──
//  **الرمز.** أداةٌ داخلية لا يعرفها الحارس، وطبعُه يُخرج مُعرِّفاً داخلياً
//  إلى ورقةٍ تُقدَّم عند بوّابة. ولا الوقتُ لكل شخص ولا مكانُ جلسته: البوّابة
//  تأذن بالدخول، ولا شأن لها بما يقع داخل المركز.
//
//  ── والختم في أسفله ──
//  اسم مدير المركز وتاريخ اعتماده — «يُطبع مختوماً باسمه». فورقةٌ بلا ختمٍ
//  لا يُعرف من أذِن بها، والحارس لا يميّز المعتمَد من المسوّدة.
// ════════════════════════════════════════════════════════════

class GateManifestService
{
    private const GREEN = '#008769';

    private const GREEN_DARK = '#024032';

    private const GOLD = '#C8A535';

    private const EMBLEM_PATH = 'brand/moi-emblem.png';

    private function emblemDataUri(): string
    {
        $path = public_path(self::EMBLEM_PATH);

        return is_file($path)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path))
            : '';
    }

    public function renderHtml(GateManifest $m): string
    {
        $green = self::GREEN;
        $greenDark = self::GREEN_DARK;
        $gold = self::GOLD;

        $emblem = $this->emblemDataUri();
        $emblemTag = $emblem ? '<img class="mark" src="'.$emblem.'" alt="">' : '';

        $date = e($m->manifest_date->toDateString());
        $time = e($m->gate_time ?: '—');
        $place = e($m->location ?: 'مركز تمكين الكفاءات');
        $note = $m->note ? '<p class="note">'.e($m->note).'</p>' : '';

        $rows = '';
        $i = 0;
        foreach ($m->candidates as $c) {
            $i++;
            $r = GateManifest::rowFor($c);
            $rows .= '<tr>'
                .'<td class="n">'.$i.'</td>'
                .'<td class="nm">'.e((string) $r['name']).'</td>'
                .'<td class="id">'.e((string) $r['nationalId']).'</td>'
                .'<td>'.e((string) $r['rank']).'</td>'
                .'<td>'.e((string) $r['sector']).'</td>'
                .'</tr>';
        }
        if ($i === 0) {
            $rows = '<tr><td colspan="5" class="empty">لا أسماء في هذا البيان</td></tr>';
        }

        $approver = e($m->approvedBy?->full_name ?? '—');
        $approvedAt = e($m->approved_at?->format('Y-m-d H:i') ?? '—');

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>بيان تصاريح الدخول — {$date}</title>
<style>
 @page { size:A4; margin:12mm; }
 * { box-sizing:border-box; }
 body { margin:0; font-family:"Segoe UI",Tahoma,Arial,sans-serif; color:#111; background:#eef1f0; }
 .sheet { max-width:210mm; margin:0 auto; background:#fff; padding:10mm 12mm 14mm; }

 .hd { display:flex; align-items:center; gap:12px; border-bottom:2px solid {$green}; padding-bottom:9px; }
 .mark { width:20mm; height:20mm; object-fit:contain; }
 .kicker { font-size:10px; letter-spacing:.09em; color:{$gold}; font-weight:700; }
 .ttl { font-size:19px; font-weight:800; color:{$greenDark}; margin-top:2px; }
 .meta { margin-inline-start:auto; text-align:end; font-size:11.5px; color:#555; line-height:1.9; }
 .meta b { color:{$greenDark}; }

 .note { font-size:11.5px; color:#555; margin:6mm 0 0; }

 table { width:100%; border-collapse:collapse; margin-top:7mm; font-size:12px; }
 th { background:{$greenDark}; color:#fff; padding:7px 8px; text-align:start; font-weight:700; font-size:11.5px; }
 td { padding:6px 8px; border-bottom:1px solid #dde4e1; }
 tr:nth-child(even) td { background:#f7faf9; }
 .n { width:10mm; color:#888; text-align:center; }
 .nm { font-weight:700; }
 .id { font-family:"Courier New",monospace; letter-spacing:.04em; direction:ltr; text-align:right; }
 .empty { text-align:center; color:#999; padding:14px; }

 /* ── الختم: من أذِن ومتى ── */
 .stamp { margin-top:9mm; border:1.5px solid {$green}; border-radius:6px; padding:5mm 6mm;
   display:flex; justify-content:space-between; align-items:flex-end; gap:10mm; page-break-inside:avoid; }
 .stamp .lbl { font-size:10px; color:{$gold}; font-weight:700; letter-spacing:.06em; }
 .stamp .who { font-size:13.5px; font-weight:800; color:{$greenDark}; margin-top:3px; }
 .stamp .when { font-size:11px; color:#666; margin-top:2px; direction:ltr; text-align:right; }
 .sign { width:52mm; }
 .sign .line { border-bottom:1px solid #9aa5a0; height:10mm; }
 .sign .cap { font-size:10px; color:#888; margin-top:3px; text-align:center; }

 .foot { margin-top:6mm; font-size:10px; color:#8a938f; text-align:center; }
 @media print { body { background:#fff; } .sheet { padding:0; } }
</style></head><body>
<div class="sheet">
  <div class="hd">
    {$emblemTag}
    <div>
      <div class="kicker">مركز تمكين الكفاءات</div>
      <div class="ttl">بيان تصاريح الدخول</div>
    </div>
    <div class="meta">
      <div>التاريخ: <b>{$date}</b></div>
      <div>موعد الحضور: <b>{$time}</b></div>
      <div>المكان: <b>{$place}</b></div>
      <div>عدد الأسماء: <b>{$i}</b></div>
    </div>
  </div>
  {$note}

  <table>
    <thead><tr><th class="n">م</th><th>الاسم</th><th>رقم الهوية</th><th>الرتبة / المرتبة</th><th>الجهة</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>

  <div class="stamp">
    <div>
      <div class="lbl">اعتماد مدير المركز</div>
      <div class="who">{$approver}</div>
      <div class="when">{$approvedAt}</div>
    </div>
    <div class="sign"><div class="line"></div><div class="cap">التوقيع</div></div>
  </div>

  <div class="foot">يُقدَّم هذا البيان لحارس البوّابة — والمطابقة بالاسم ورقم الهوية.</div>
</div>
</body></html>
HTML;
    }
}
