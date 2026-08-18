#!/usr/bin/env bash
# يجلب خطوط المنصّة من Google مرّة واحدة ويستضيفها محلياً.
# السبب ليس CSP وحدها: منصّة حكومية تجلب خطوطها من خادم خارجي تُسرّب عنوان
# كل مستخدم إليه في كل فتحة صفحة، ولا تعمل أصلاً على شبكة معزولة عن الإنترنت.
set -Eeuo pipefail

OUT="$1"                 # مجلّد الوجهة (frontend/public/fonts)
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
# هوية «برنامج تطوير وزارة الداخلية» عائلةٌ واحدة للكل: Sakkal Majalla الرسمي
# خطُّ نظامِ ويندوز غيرُ قابل لإعادة التوزيع، فلا يُستضاف — يُلتقط من الجهاز
# بـlocal() إن وُجد، وNoto Naskh Arabic هو البديل المرخّص المستضاف هنا.
CSS_URL='https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;500;600;700&display=swap'

mkdir -p "$OUT/files"
raw="$OUT/.google.css"
curl -sS --max-time 60 -A "$UA" "$CSS_URL" -o "$raw"
[[ -s "$raw" ]] || { echo "✗ تعذّر جلب ملف الأنماط" >&2; exit 1; }

# نُبقي مقاطع arabic وlatin فقط: البقيّة (cyrillic، vietnamese…) لا تُستعمل
# في واجهة عربية وتُضاعف حجم ما يُستضاف بلا فائدة.
python3 - "$raw" "$OUT" <<'PY'
import re, sys, os, urllib.request

raw, out = sys.argv[1], sys.argv[2]
css = open(raw, encoding='utf-8').read()

blocks = re.findall(r'/\*\s*([\w\-\[\]]+)\s*\*/\s*(@font-face\s*\{.*?\})', css, re.S)
keep = ('arabic', 'latin', 'latin-ext')
ua_map, kept = {}, []

for subset, block in blocks:
    if not subset.startswith(keep):
        continue
    m = re.search(r"url\((https://[^)]+\.woff2)\)", block)
    if not m:
        continue
    url = m.group(1)
    fam = re.search(r"font-family:\s*'([^']+)'", block).group(1)
    wgt = re.search(r"font-weight:\s*(\d+)", block)
    wgt = wgt.group(1) if wgt else '400'
    name = f"{fam.replace(' ', '')}-{wgt}-{subset}.woff2"
    path = os.path.join(out, 'files', name)
    if not os.path.exists(path):
        urllib.request.urlretrieve(url, path)
    kept.append(block.replace(url, f"/fonts/files/{name}"))

# Sakkal Majalla — خطُّ الهوية الرسمي، ولا يُستضاف: هو خطُّ نظامٍ مرخّص لويندوز
# لا يُعاد توزيعه. يُلتقط من جهاز المستخدم بـlocal() إن وُجد، فيقع عليه الاختيار
# أولاً في --font، ويسقط تلقائياً إلى Noto Naskh Arabic المستضاف حين يغيب.
# لو وفّر فريق الهوية ملفّاً مرخّصاً، ضعه في files/ وأضف url() إلى src هنا.
SAKKAL = """@font-face {
  font-family: 'Sakkal Majalla';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: local('Sakkal Majalla'), local('SakkalMajalla');
}

@font-face {
  font-family: 'Sakkal Majalla';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: local('Sakkal Majalla Bold'), local('SakkalMajalla-Bold'), local('Sakkal Majalla');
}"""

open(os.path.join(out, 'fonts.css'), 'w', encoding='utf-8').write(
    "/* خطوط المنصّة مستضافة محلياً — لا اتصال بخادم خارجي.\n"
    "   مولَّدة بـdeploy/scripts/fetch-fonts.sh؛ لا تُحرَّر يدوياً. */\n\n"
    + SAKKAL + "\n\n"
    + "\n\n".join(kept) + "\n"
)
print(f"مقاطع محفوظة: {len(kept)}")
PY

rm -f "$raw"
echo "الحجم: $(du -sh "$OUT" | cut -f1)"
