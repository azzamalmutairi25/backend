<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateCv;

// ════════════════════════════════════════════════════════════
//  «سيرة ذاتية» — النموذج الورقي المعتمد لدى المركز، A4.
//
//  وثيقة إدارية لا تصل المقيّم: تحمل البيانات الوظيفية كاملة
//  (الإدارة والمنطقة وتاريخ التعيين) التي تُطمَس في مسار المقيّم.
//  الاسم يبقى محجوباً كبقية مستندات النظام — الرمز هو المعرّف.
//
//  خانتا I.A و I.A.A تُطبعان فارغتين: لا يقابلهما شيء في النظام،
//  وهما في الأصل للتعبئة اليدوية في المركز.
// ════════════════════════════════════════════════════════════

class CvSheetService
{
    private const EMBLEM = 'brand/moi-emblem-mono.png';
    private const FOOTER = 'brand/cv-footer.png';

    private const DEGREES = [
        'diploma' => 'دبلوم', 'bachelor' => 'بكالوريوس', 'master' => 'ماجستير',
        'doctorate' => 'دكتوراه', 'fellowship' => 'زمالة',
    ];

    // ── الهجري (أم القرى) من الميلادي ──
    // نفس جداول ICU التي تستعملها الواجهة عبر Intl، فيخرج الطرفان بالنتيجة
    // نفسها. المخزَّن ميلادي وحده؛ الهجري يُشتقّ عند العرض فلا يتباعدان.
    private function hijri(?string $iso): string
    {
        if (!$iso || !extension_loaded('intl')) {
            return '';
        }
        try {
            $fmt = new \IntlDateFormatter(
                'ar_SA@calendar=islamic-umalqura;numbers=latn',
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                'UTC',
                \IntlDateFormatter::TRADITIONAL,
                'yyyy/MM/dd'
            );

            return (string) $fmt->format(new \DateTime($iso . ' 12:00:00', new \DateTimeZone('UTC')));
        } catch (\Throwable) {
            return '';
        }
    }

    // «2006-09-01 م / 1427/08/08 هـ» — التقويمان معاً كما يُقرآن في المركز
    private function bothCalendars(?string $iso): string
    {
        if (!$iso) {
            return '—';
        }
        $h = $this->hijri($iso);

        return $h === '' ? e($iso) : e($iso) . ' م<br>' . e($h) . ' هـ';
    }

    private function asset(string $rel): string
    {
        $path = public_path($rel);

        return is_file($path)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path))
            : '';
    }

    // $session: ['date' => 'Y-m-d'|null, 'time' => 'HH:MM'|null] — أقرب جلسة تقييم
    public function renderHtml(Candidate $candidate, array $doc, array $session = []): string
    {
        $emblem = $this->asset(self::EMBLEM);
        $footer = $this->asset(self::FOOTER);

        $code = e($candidate->participant_code);
        $sector = e(optional($candidate->sector)->name_ar ?: '—');
        $age = CandidateCv::ageFrom($doc['birthDate'] ?? null);

        $d = fn ($k) => e($doc[$k] ?? null) ?: '—';
        $date = e($session['date'] ?? null) ?: '—';
        $time = e($session['time'] ?? null) ?: '—';

        $emblemImg = $emblem ? '<img src="' . $emblem . '" alt="" />' : '';
        $footerImg = $footer ? '<div class="foot"><img src="' . $footer . '" alt="" /></div>' : '';

        $eduRows = $this->eduRows($doc['qualifications'] ?? []);
        $expRows = $this->expRows($doc['experiences'] ?? []);
        $courseRows = $this->courseRows($doc['certifications'] ?? []);

        $ageTxt = $age !== null ? e($age) : '—';
        $years = e((string) ($doc['totalYearsExperience'] ?? 0));
        $appointment = $this->bothCalendars($doc['appointmentDate'] ?? null);
        $attestBlock = $this->attestBlock($session['attest'] ?? null);

        return <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>سيرة ذاتية — {$code}</title>
<style>
 * { box-sizing: border-box; }
 body { font-family:"Segoe UI","Noto Naskh Arabic",Tahoma,sans-serif; color:#1a1a1a; margin:0; background:#f0f2ef; }
 .print-bar { max-width:210mm; margin:16px auto 0; text-align:left; }
 .print-bar button { font:inherit; padding:8px 18px; border:0; border-radius:8px; background:#1f6b4a; color:#fff; cursor:pointer; }
 .sheet { width:210mm; margin:16px auto; background:#fff; padding:14mm 12mm; box-shadow:0 2px 20px rgba(0,0,0,.08); font-size:9pt; }
 .hd { display:flex; align-items:flex-start; justify-content:space-between; gap:10mm; margin-bottom:6mm; }
 .hd .org { text-align:right; font-weight:700; font-size:10pt; line-height:2; }
 .hd .org div { white-space:nowrap; }
 .hd .mid { text-align:center; }
 .hd .mid img { width:24mm; height:auto; display:block; margin:0 auto; }
 .hd .mid .t { font-weight:700; font-size:11pt; margin-top:3mm; }
 table.cv { width:100%; border-collapse:collapse; table-layout:fixed; }
 table.cv th, table.cv td { border:1px solid #1a1a1a; padding:1.6mm 2mm; font-size:8.5pt; text-align:right; vertical-align:middle; height:7mm; }
 table.cv th { background:#d9d9d9; font-weight:700; }
 table.cv td.lbl { background:#d9d9d9; font-weight:700; }
 .sechd { background:#d9d9d9; border:1px solid #1a1a1a; border-bottom:none; padding:1.6mm 2mm; font-weight:700; font-size:9pt; text-align:center; margin-top:5mm; }
 .foot { margin-top:8mm; }
 .foot img { width:100%; height:auto; display:block; }
 .rights { margin-top:18px; padding-top:10px; border-top:1px solid #e8ece9; text-align:center; font-size:10px; color:#8a978f; }
 .attest { font-size:8.5pt; line-height:1.9; margin-bottom:3mm; }
 .sigwrap { display:flex; justify-content:flex-start; }
 .sigcell { width:70mm; text-align:center; }
 .siglbl { font-size:8.5pt; font-weight:700; margin-bottom:1.5mm; }
 .sigbox { height:22mm; border-bottom:1px solid #1a1a1a; display:flex; align-items:flex-end; justify-content:center; }
 .sigbox.empty { height:22mm; }
 /* التوقيع مرسومٌ بحبر داكن على شفافية — يُحصر بالارتفاع لا بالعرض كي لا
    يُشوَّه توقيعٌ عريض، ويُمنع من تجاوز الخانة على الأجهزة العالية الكثافة */
 img.sig { max-height:20mm; max-width:100%; object-fit:contain; }
 .sigdate { font-size:8pt; margin-top:1.5mm; color:#333; }
 @media print { body{ background:#fff; } .sheet{ box-shadow:none; margin:0; width:auto; } .print-bar{ display:none; } @page{ size:A4; margin:12mm; } }
</style></head><body>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
<div class="sheet">
 <div class="hd">
  <div class="mid">{$emblemImg}<div class="t">سيرة ذاتية</div></div>
  <div class="org">
   <div>المملكة العربية السعودية</div>
   <div>وزارة الداخلية</div>
   <div>برنامج تطوير وزارة الداخلية</div>
   <div>مركز تمكين الكفاءات</div>
  </div>
 </div>

 <table class="cv">
  <colgroup><col style="width:16%"><col style="width:20%"><col style="width:12%"><col style="width:16%"><col style="width:12%"><col style="width:24%"></colgroup>
  <tr>
   <td class="lbl">تاريخ التقييم</td><td>{$date}</td>
   <td class="lbl">الساعة</td><td>{$time}</td>
   <td class="lbl">I.A</td><td></td>
  </tr>
  <tr>
   <td class="lbl">رمز المشارك</td><td>{$code}</td>
   <td class="lbl">العمر</td><td>{$ageTxt}</td>
   <td class="lbl">I.A.A</td><td></td>
  </tr>
  <tr>
   <td class="lbl">الرتبة العسكرية/المرتبة</td><td>{$d('rankLabel')}</td>
   <td class="lbl">سنوات الخبرة</td><td>{$years}</td>
   <td class="lbl">تاريخ التعيين</td><td>{$appointment}</td>
  </tr>
  <tr>
   <td class="lbl">القطاع</td><td>{$sector}</td>
   <td class="lbl">الإدارة</td><td>{$d('department')}</td>
   <td class="lbl">المنطقة</td><td>{$d('region')}</td>
  </tr>
 </table>

 <div class="sechd">التعليم الأكاديمي</div>
 <table class="cv">
  <tr><th style="width:22%">المؤهل</th><th style="width:26%">التخصص</th><th style="width:30%">الجهة</th><th>مقر/دولة الدراسة</th></tr>
  {$eduRows}
 </table>

 <div class="sechd">الخبرة العملية آخر عشر سنوات</div>
 <table class="cv">
  <tr><th style="width:6%">م</th><th style="width:28%">الوظيفة</th><th>الجهة / الشعبة / الإدارة العامة</th><th style="width:24%">مدة الخدمة في الوظيفة</th></tr>
  {$expRows}
 </table>

 <div class="sechd">الدورات التدريبية</div>
 <table class="cv">
  <tr><th style="width:50%">اسم الدورة / البرنامج</th><th>اسم الدورة / البرنامج</th></tr>
  {$courseRows}
 </table>

 {$attestBlock}

 {$footerImg}
 <div class="rights">جميع الحقوق محفوظة © إدارة تقنية المعلومات والذكاء الاصطناعي</div>
</div></body></html>
HTML;
    }

    // ── الإقرار والتوقيع ──
    //
    // النموذج يُطبع ليُقرّ المرشّح بصحّة بياناته، والاستقبال يلتقط توقيعه
    // مرسوماً ويحفظه مشفَّراً — ثم لا يظهر التوقيع على الورقة التي وقّع
    // عليها. فيُطبع النموذجُ فارغَ خانةِ التوقيع ويُوقَّع يدوياً مرّة ثانية،
    // أو يُحفظ التوقيعُ الرقمي بلا مستندٍ يشهد عليه.
    //
    // فإن وُجد توقيعٌ مقترن بإقرار طُبع بتاريخه، وإلا طُبعت خانةٌ فارغة كما
    // كان النموذج الورقي. ولا نطبع توقيعاً بلا إقرار: رسمٌ على لوحة ليس
    // إقراراً بشيء.
    private function attestBlock(?array $attest): string
    {
        $signed = $attest && !empty($attest['signature']) && !empty($attest['at']);

        // صورة التوقيع data:image/png فقط — أي مخطّط آخر (وخاصةً data:text/html
        // أو javascript:) يُصيّر الوسمَ ثغرةَ حقنٍ في صفحةٍ تُفتح بنافذة جديدة
        $img = '';
        if ($signed && preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $attest['signature'])) {
            $img = '<img class="sig" src="' . e($attest['signature']) . '" alt="التوقيع" />';
        }

        $when = $signed ? $this->bothCalendars(substr((string) $attest['at'], 0, 10)) : '';
        $stamp = $img
            ? '<div class="sigbox">' . $img . '</div><div class="sigdate">وُقِّع إلكترونياً في ' . $when . '</div>'
            : '<div class="sigbox empty"></div><div class="sigdate">التاريخ: ……/……/…… هـ</div>';

        return <<<HTML
 <div class="sechd">الإقرار</div>
 <table class="cv"><tr><td style="height:auto; padding:3mm">
  <div class="attest">أُقرّ بأن جميع البيانات الواردة في هذا النموذج صحيحة، وأتحمّل مسؤولية أي معلومة غير صحيحة.</div>
  <div class="sigwrap">
   <div class="sigcell"><div class="siglbl">توقيع المشارك</div>{$stamp}</div>
  </div>
 </td></tr></table>
HTML;
    }

    // ── الصفوف ──
    // النموذج ورقيّ: تُطبع صفوف فارغة تكملةً للحدّ الأدنى ليُكتب فيها يدوياً
    private const MIN_EDU = 2;
    private const MIN_EXP = 4;
    private const MIN_COURSE = 3;

    private function eduRows(array $quals): string
    {
        $out = '';
        foreach ($quals as $q) {
            $out .= '<tr>'
                . '<td>' . e(self::DEGREES[$q['degree'] ?? ''] ?? ($q['degree'] ?? '—')) . '</td>'
                . '<td>' . (e($q['major'] ?? null) ?: '—') . '</td>'
                . '<td>' . (e($q['institution'] ?? null) ?: '—') . '</td>'
                . '<td>' . (e($q['studyPlace'] ?? null) ?: '—') . '</td>'
                . '</tr>';
        }

        return $out . $this->blankRows(4, self::MIN_EDU - count($quals));
    }

    private function expRows(array $exps): string
    {
        $out = '';
        foreach ($exps as $i => $x) {
            $from = $x['fromYear'] ?? null;
            $to = !empty($x['current']) ? 'حتى الآن' : ($x['toYear'] ?? null);
            $span = $from ? e($from) . ' — ' . (e($to) ?: '—') : '—';

            $out .= '<tr>'
                . '<td>' . e($i + 1) . '</td>'
                . '<td>' . (e($x['position'] ?? null) ?: '—') . '</td>'
                . '<td>' . (e($x['organization'] ?? null) ?: '—') . '</td>'
                . '<td>' . $span . '</td>'
                . '</tr>';
        }

        return $out . $this->blankRows(4, self::MIN_EXP - count($exps));
    }

    // الدورات في عمودين كما في النموذج — تُوزَّع اثنتين في كل صف
    private function courseRows(array $certs): string
    {
        $names = array_values(array_map(fn ($c) => (string) ($c['name'] ?? ''), $certs));
        $out = '';
        for ($i = 0; $i < count($names); $i += 2) {
            $out .= '<tr><td>' . e($names[$i]) . '</td>'
                . '<td>' . (isset($names[$i + 1]) ? e($names[$i + 1]) : '') . '</td></tr>';
        }
        $rows = (int) ceil(count($names) / 2);

        return $out . $this->blankRows(2, self::MIN_COURSE - $rows);
    }

    private function blankRows(int $cols, int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return str_repeat('<tr>' . str_repeat('<td>&nbsp;</td>', $cols) . '</tr>', $count);
    }
}
