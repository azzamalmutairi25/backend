#!/usr/bin/env bash
# يجلب خطوط المنصّة من Google مرّة واحدة ويستضيفها محلياً.
# السبب ليس CSP وحدها: منصّة حكومية تجلب خطوطها من خادم خارجي تُسرّب عنوان
# كل مستخدم إليه في كل فتحة صفحة، ولا تعمل أصلاً على شبكة معزولة عن الإنترنت.
set -Eeuo pipefail

OUT="$1"                 # مجلّد الوجهة (frontend/public/fonts)
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
# عائلةٌ واحدة للواجهة كلها: IBM Plex Sans Arabic — خطٌّ مرخّص بـOFL فيُستضاف
# كاملاً بلا اعتمادٍ على ما رُكّب في جهاز المستخدم. الأوزان ثلاثة فقط
# (٤٠٠ عادي، ٥٠٠ للأزرار والتسميات، ٧٠٠ للعناوين) — لا يُجلب ما لا يُستعمل.
CSS_URL='https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;700&display=swap'

mkdir -p "$OUT/files"
raw="$OUT/.google.css"
curl -sS --max-time 60 -A "$UA" "$CSS_URL" -o "$raw"
[[ -s "$raw" ]] || { echo "✗ تعذّر جلب ملف الأنماط" >&2; exit 1; }

# نُبقي مقاطع arabic وlatin فقط: البقيّة (cyrillic، vietnamese…) لا تُستعمل
# في واجهة عربية وتُضاعف حجم ما يُستضاف بلا فائدة.
python3 - "$raw" "$OUT" <<'PY'
import re, sys, os, glob, urllib.request

raw, out = sys.argv[1], sys.argv[2]
css = open(raw, encoding='utf-8').read()

blocks = re.findall(r'/\*\s*([\w\-\[\]]+)\s*\*/\s*(@font-face\s*\{.*?\})', css, re.S)
keep = ('arabic', 'latin', 'latin-ext')
kept, wanted = [], set()

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
    wanted.add(name)
    kept.append(block.replace(url, f"/fonts/files/{name}"))

# ملفّات عائلةٍ سابقة تبقى على القرص بعد تبديل الخط فتُنشر بلا مرجع. تُحذف
# هنا لا يدوياً: المولِّد يعرف ما يُستعمل، والحذف اليدوي يُنسى.
for f in glob.glob(os.path.join(out, 'files', '*.woff2')):
    if os.path.basename(f) not in wanted:
        os.remove(f)
        print(f"حُذف غير المستعمل: {os.path.basename(f)}")

open(os.path.join(out, 'fonts.css'), 'w', encoding='utf-8').write(
    "/* خطوط المنصّة مستضافة محلياً — لا اتصال بخادم خارجي.\n"
    "   مولَّدة بـdeploy/scripts/fetch-fonts.sh؛ لا تُحرَّر يدوياً. */\n\n"
    + "\n\n".join(kept) + "\n"
)
print(f"مقاطع محفوظة: {len(kept)}")
PY

rm -f "$raw"
echo "الحجم: $(du -sh "$OUT" | cut -f1)"
