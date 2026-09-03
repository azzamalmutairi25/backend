<?php

namespace App\Services;

// ════════════════════════════════════════════════════════════
//  بطاقات المشاركين — النموذج المعتمد لدى المركز.
//
//  المقاسات والألوان مقروءة من ملف التصميم الأصلي لا مقدَّرة:
//  البطاقة 91.4×52.3 مم، عشر بطاقات في ورقة Letter بعمودين
//  وخمسة صفوف، والفاصل بين العمودين 12.7 مم.
//
//  البطاقة تحمل رمز المشارك وحده — لا اسمه. هذا ليس نقصاً بل
//  امتداد لمبدأ النظام: الاسم محجوب خلف الرمز، وبطاقةٌ تُعلَّق
//  على الصدر أولى الوثائق بالحجب لا أدناها.
// ════════════════════════════════════════════════════════════

class ParticipantCardService
{
    // ألوان الهوية — من ملف التصميم
    private const GREEN = '#008769';

    private const GREEN_DARK = '#024032';

    private const GOLD = '#C8A535';

    private const EMBLEM_PATH = 'brand/moi-emblem.png';

    // الشعار يُضمَّن base64 في المستند نفسه: المستند يُكتب في تبويب
    // about:blank فلا أصل له تُحلّ منه المسارات النسبية، وعلى النشر
    // الداخلي قد لا يكون هناك منفذ للأصول أصلاً.
    private function emblemDataUri(): string
    {
        $path = public_path(self::EMBLEM_PATH);
        if (! is_file($path)) {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }

    // $codes: رموز المشاركين بالترتيب المطلوب طباعته
    public function renderHtml(array $codes): string
    {
        $emblem = $this->emblemDataUri();
        $count = count($codes);

        // الشعار خلفيةٌ في قاعدة CSS واحدة لا <img> في كل بطاقة: تكراره
        // في خمسمئة بطاقة يضخّم المستند إلى ميغابايتات ويُثقل الطباعة.
        $emblemRule = $emblem
            ? ".card .art { background-image:url('{$emblem}'); background-size:contain; background-repeat:no-repeat; background-position:left top; }"
            : '';

        $cards = $codes
            ? implode('', array_map(fn ($c) => $this->card($c), $codes))
            : '<div class="empty">لا مشاركين مختارين</div>';

        $green = self::GREEN;
        $greenDark = self::GREEN_DARK;
        $gold = self::GOLD;

        // ── بطاقة واحدة: صفحة بمقاسها لا ورقة Letter ببطاقة في زاويتها ──
        // طباعة مشارك واحد على ورقة كاملة تهدر الورق وتُخرج بطاقة في ركن،
        // فالصفحة نفسها تصير بحجم البطاقة ويخرج ملف PDF ببطاقة واحدة تماماً.
        $single = $count === 1;
        $title = $single ? 'بطاقة المشارك — '.e($codes[0]) : 'بطاقات المشاركين — '.$count.' بطاقة';

        $sheetCss = $single
            ? '.sheet { width:91.4mm; margin:16px auto; padding:0; display:block; direction:ltr; }'
            : '.sheet {
   width:215.9mm; margin:16px auto; background:#fff; box-shadow:0 2px 20px rgba(0,0,0,.08);
   padding:5.1mm 10.3mm; display:grid; grid-template-columns:repeat(2, 91.4mm);
   grid-auto-rows:52.3mm; gap:2.4mm 12.7mm; align-content:start; direction:ltr;
 }';

        $pageCss = $single ? '@page { size:91.4mm 52.3mm; margin:0; }' : '@page { size:Letter; margin:0; }';
        $barWidth = $single ? '91.4mm' : '215.9mm';

        return <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>{$title}</title>
<style>
 * { box-sizing: border-box; }
 body { font-family:"Segoe UI","Noto Naskh Arabic",Tahoma,sans-serif; color:#101010; margin:0; background:#f0f2ef; }
 .print-bar { max-width:{$barWidth}; margin:16px auto 0; text-align:left; }
 .print-bar button { font:inherit; padding:8px 18px; border:0; border-radius:8px; background:{$green}; color:#fff; cursor:pointer; }
 {$sheetCss}
 .card { width:91.4mm; height:52.3mm; position:relative; overflow:hidden; background:#fff; border:1px dashed #cfd8d3; box-shadow:0 2px 20px rgba(0,0,0,.08); }
 .card .goldbar { position:absolute; left:0; right:0; top:0; height:1.2mm; background:{$gold}; }
 /* مقاس الشعار وموضعه من مصفوفة الوضع في ملف التصميم: 42.9×26.1 مم ملاصقاً للحافة */
 .card .art { position:absolute; top:0.8mm; left:0; width:42.9mm; height:26.1mm; }
 {$emblemRule}
 .card .org { position:absolute; top:4.4mm; right:4mm; text-align:right; line-height:1.75; direction:rtl; }
 .card .org div { font-size:9.2pt; font-weight:700; color:#111; white-space:nowrap; }
 .card .code {
   /* يتوسّط الفراغ بين أسفل الشعار (26.9مم) وأعلى الشريط الأخضر (36.4مم) */
   position:absolute; left:0; right:0; top:28mm; text-align:center; direction:ltr;
   font-family:"Segoe UI",Tahoma,sans-serif; font-size:20pt; font-weight:800;
   letter-spacing:.06em; color:{$greenDark};
 }
 .card .band { position:absolute; left:0; right:0; bottom:0; height:15.9mm; background:{$green}; }
 .empty { grid-column:1 / -1; text-align:center; color:#8a978f; padding:40px 0; font-size:14px; direction:rtl; }
 @media print {
   body { background:#fff; }
   .sheet { box-shadow:none; margin:0; }
   .card { border:none; box-shadow:none; }
   .print-bar { display:none; }
   {$pageCss}
 }
</style></head><body>
<div class="print-bar"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
<div class="sheet">{$cards}</div>
</body></html>
HTML;
    }

    private function card(string $code): string
    {
        return '<div class="card">'
            .'<div class="goldbar"></div>'
            .'<div class="art"></div>'
            .'<div class="org">'
            .'<div>وزارة الداخلية</div>'
            .'<div>برنامج تطوير وزارة الداخلية</div>'
            .'<div>مركز تمكين الكفاءات</div>'
            .'</div>'
            .'<div class="code">'.e($code).'</div>'
            .'<div class="band"></div>'
            .'</div>';
    }
}
