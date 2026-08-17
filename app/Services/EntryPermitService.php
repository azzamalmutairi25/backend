<?php

namespace App\Services;

// ════════════════════════════════════════════════════════════
//  تصاريح الدخول — الخطوة التاسعة في مخطّط «إجراءات الجدولة».
//
//  التصريح ورقةٌ تُقدَّم لحارس البوّابة، لا بطاقةً تُعلَّق على الصدر. ولذلك
//  اختلف عن بطاقة المشارك في أمرٍ واحد جوهري:
//
//  **الاسم يظهر بشرطين معاً**: أن يطلبه الطابع صراحةً، وأن يملك صلاحية رؤية
//  الأسماء. هذا هو عرف كشف الحضور نفسه مع رقم الهوية — لا استثناء اخترعته.
//  والافتراض بلا طلب: الرمز وحده.
//
//  السبب أن الحارس يطابق التصريح بشخصٍ أمامه، فورقةٌ بلا اسمٍ قد لا تؤدّي
//  غرضها في بوّابةٍ تشترط المطابقة. والقرار للمركز لا للنظام — فالنظام يعرض
//  الخيار ويقيّده بالصلاحية ويدوّن استعماله.
// ════════════════════════════════════════════════════════════

class EntryPermitService
{
    private const GREEN = '#008769';
    private const GREEN_DARK = '#024032';
    private const GOLD = '#C8A535';
    private const EMBLEM_PATH = 'brand/moi-emblem.png';

    private function emblemDataUri(): string
    {
        $path = public_path(self::EMBLEM_PATH);
        return is_file($path)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path))
            : '';
    }

    /**
     * @param array $permits صفوفٌ فيها: code, name (أو null), sector, date, window, location
     * @param string $dateLabel التاريخ المعروض في الترويسة
     */
    public function renderHtml(array $permits, string $dateLabel): string
    {
        $green = self::GREEN;
        $greenDark = self::GREEN_DARK;
        $gold = self::GOLD;

        $emblem = $this->emblemDataUri();
        // الشعار قاعدةَ CSS واحدة لا صورةً في كل تصريح: تكراره في مئتَي تصريح
        // يضخّم المستند إلى ميغابايتات ويُثقل الطباعة.
        $emblemRule = $emblem
            ? ".permit .mark { background-image:url('{$emblem}'); background-size:contain; background-repeat:no-repeat; background-position:center; }"
            : '';

        $cards = $permits
            ? implode('', array_map(fn ($p) => $this->permit($p), $permits))
            : '<div class="empty">لا جلسات في هذا اليوم</div>';

        $count = count($permits);
        $dateLabel = e($dateLabel);

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>تصاريح الدخول — {$dateLabel}</title>
<style>
 @page { size:A4; margin:8mm; }
 * { box-sizing:border-box; }
 body { margin:0; font-family:"Segoe UI",Tahoma,Arial,sans-serif; color:#111; background:#eef1f0; }
 .sheet { max-width:210mm; margin:0 auto; background:#fff; padding:8mm; }
 .doc-hd { display:flex; align-items:center; gap:10px; border-bottom:2px solid {$green}; padding-bottom:8px; margin-bottom:8px; }
 .doc-ttl { font-size:15px; font-weight:800; color:{$greenDark}; }
 .doc-sub { font-size:11px; color:#666; margin-inline-start:auto; }

 .permit {
   border:1.5px solid {$greenDark}; border-radius:6px; padding:6mm;
   margin-bottom:5mm; page-break-inside:avoid; position:relative; min-height:48mm;
 }
 .permit .mark { position:absolute; inset-inline-end:6mm; top:6mm; width:18mm; height:18mm; opacity:.9; }
 .p-kicker { font-size:10px; letter-spacing:.08em; color:{$gold}; font-weight:700; }
 .p-ttl { font-size:17px; font-weight:800; color:{$greenDark}; margin-top:2px; }
 .p-code { font-family:"Courier New",monospace; font-size:26px; font-weight:700; color:{$green}; margin:5mm 0 3mm; letter-spacing:.06em; }
 .p-name { font-size:14px; font-weight:700; color:#111; margin-bottom:3mm; }
 .p-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:2mm 6mm; font-size:11.5px; }
 .p-grid div { display:flex; gap:5px; }
 .p-grid b { color:#555; font-weight:600; min-width:52px; }
 .p-foot { margin-top:4mm; padding-top:3mm; border-top:1px dashed #c9d2ce; display:flex; justify-content:space-between; font-size:10px; color:#777; }
 .sign { width:46mm; border-bottom:1px solid #9aa5a0; height:8mm; }
 {$emblemRule}
 .empty { text-align:center; padding:30mm 0; color:#888; }
 .print-bar { max-width:210mm; margin:14px auto 0; text-align:left; }
 .print-bar button { font:inherit; padding:8px 18px; border:0; border-radius:8px; background:{$green}; color:#fff; cursor:pointer; }
 @media print { body { background:#fff; } .print-bar { display:none; } .sheet { padding:0; max-width:none; } }
</style></head>
<body>
<div class="sheet">
  <div class="doc-hd">
    <div class="doc-ttl">تصاريح دخول المشاركين</div>
    <div class="doc-sub">{$dateLabel} · {$count} تصريحاً</div>
  </div>
  {$cards}
</div>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
</body></html>
HTML;
    }

    private function permit(array $p): string
    {
        $code = e($p['code'] ?? '—');
        $sector = e($p['sector'] ?? '—');
        $date = e($p['date'] ?? '—');
        $window = e($p['window'] ?? '—');
        $location = e($p['location'] ?? '—');
        $serial = e($p['serial'] ?? '—');

        $nameRow = !empty($p['name'])
            ? '<div class="p-name">' . e($p['name']) . '</div>'
            : '';

        return <<<HTML
<div class="permit">
  <div class="mark"></div>
  <div class="p-kicker">مركز تمكين الكفاءات</div>
  <div class="p-ttl">تصريح دخول</div>
  <div class="p-code">{$code}</div>
  {$nameRow}
  <div class="p-grid">
    <div><b>التاريخ</b><span>{$date}</span></div>
    <div><b>الحضور</b><span>{$window}</span></div>
    <div><b>القطاع</b><span>{$sector}</span></div>
    <div><b>المكان</b><span>{$location}</span></div>
  </div>
  <div class="p-foot">
    <span>مسلسل: {$serial}</span>
    <span class="sign"></span>
    <span>توقيع المسؤول</span>
  </div>
</div>
HTML;
    }
}
