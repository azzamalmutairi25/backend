#!/usr/bin/env node
// ════════════════════════════════════════════════════════════
//  بناء نسخة قابلة للتسليم من دليل المستخدم.
//
//  الدليل مكتوب بصيغة Markdown ليبقى قابلاً للمراجعة في المستودع، لكنّ من
//  يُسلَّم إليه لا يقرأ Markdown ولا يفتح المستودع: يريد ملفاً واحداً يفتحه
//  ويطبعه. فيُبنى منه:
//      USER_GUIDE.html — ملف واحد مكتفٍ بنفسه، الصور مضمَّنة فيه، يعمل بلا
//                        شبكة وبلا خادم (شرطٌ في شبكة مغلقة)
//      USER_GUIDE.pdf  — بـ--pdf، للطباعة والتوزيع الرسمي
//
//  ولا مكتبة Markdown خارجية: إضافة اعتماد إلى مستودعٍ يُنشَر في شبكة معزولة
//  ثمنُها أعلى من مئة سطرٍ تحوّل الصيغة التي نكتب بها نحن.
//
//  الاستعمال:
//      node docs/build-guide.mjs          # HTML وحده
//      node docs/build-guide.mjs --pdf    # ومعه PDF
// ════════════════════════════════════════════════════════════
import { readFile, writeFile, mkdir, stat } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { dirname, join, basename } from 'node:path'
import { fileURLToPath } from 'node:url'

const run = promisify(execFile)
const HERE = dirname(fileURLToPath(import.meta.url))
const SRC = join(HERE, 'USER_GUIDE.md')
const OUT_HTML = join(HERE, 'USER_GUIDE.html')
const OUT_PDF = join(HERE, 'USER_GUIDE.pdf')
const CACHE = join(HERE, '.guide-build-cache')

const WANT_PDF = process.argv.includes('--pdf')

// ── صور الدليل ثقيلة (١٥ ميغابايت) لأنها لقطات بدقّة ١٫٥×. تضمينها كما هي
// يُنتج ملفاً لا يُرسَل بالبريد. التصغير إلى عرض ١٤٠٠ وjpeg يُبقيها مقروءة
// على الشاشة والورق ويردّ الحجم إلى الثلث. sips أداة نظامٍ في macOS فلا
// تُضاف بها تبعيّة.
async function inlineImage(src) {
  const abs = join(HERE, src)
  if (!existsSync(abs)) return null
  await mkdir(CACHE, { recursive: true })
  const small = join(CACHE, basename(src).replace(/\.png$/i, '.jpg'))
  const fresh = existsSync(small) &&
    (await stat(small)).mtimeMs > (await stat(abs)).mtimeMs
  if (!fresh) {
    try {
      await run('sips', ['-s', 'format', 'jpeg', '-s', 'formatOptions', '72',
        '-Z', '1200', abs, '--out', small])
    } catch {
      // بلا sips (خارج macOS): تُضمَّن الصورة الأصلية كما هي
      const raw = await readFile(abs)
      return `data:image/png;base64,${raw.toString('base64')}`
    }
  }
  const buf = await readFile(small)
  return `data:image/jpeg;base64,${buf.toString('base64')}`
}

// ── مُحوّل Markdown ─────────────────────────────────────────
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

const slugs = new Map()
function slug(text) {
  const base = text
    .replace(/[*_`\[\]()#]/g, '')
    .trim()
    .replace(/\s+/g, '-')
    .replace(/[.,:;!؟?«»"']/g, '')
  const n = (slugs.get(base) ?? 0) + 1
  slugs.set(base, n)
  return n === 1 ? base : `${base}-${n}`
}

const images = []
function inline(s) {
  let out = esc(s)
  out = out.replace(/`([^`]+)`/g, (_, c) => `<code>${c}</code>`)
  out = out.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (_, alt, src) => {
    const i = images.push(src) - 1
    return `</p><figure><img data-src-index="${i}" alt="${alt}"><figcaption>${alt}</figcaption></figure><p>`
  })
  out = out.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, t, href) => `<a href="${href}">${t}</a>`)
  out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
  out = out.replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>')
  return out
}

const cells = (line) => line.replace(/^\||\|$/g, '').split('|').map((c) => c.trim())

function convert(md) {
  const lines = md.split('\n')
  const toc = []
  const html = []
  let i = 0

  const flushParagraph = (buf) => {
    if (!buf.length) return
    html.push(`<p>${inline(buf.join(' '))}</p>`)
    buf.length = 0
  }

  while (i < lines.length) {
    const line = lines[i]

    // كتلة شيفرة
    if (line.startsWith('```')) {
      const body = []
      i++
      while (i < lines.length && !lines[i].startsWith('```')) body.push(lines[i++])
      i++
      html.push(`<pre><code>${esc(body.join('\n'))}</code></pre>`)
      continue
    }

    // عنوان
    const h = line.match(/^(#{1,6})\s+(.*)$/)
    if (h) {
      const level = h[1].length
      const text = h[2].trim()
      const id = slug(text)
      if (level === 2 || level === 3) toc.push({ level, text: text.replace(/[*`]/g, ''), id })
      html.push(`<h${level} id="${id}">${inline(text)}</h${level}>`)
      i++
      continue
    }

    // فاصل
    if (/^---+\s*$/.test(line)) { html.push('<hr>'); i++; continue }

    // جدول
    if (line.trim().startsWith('|') && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1] ?? '')) {
      const head = cells(line.trim())
      i += 2
      const rows = []
      while (i < lines.length && lines[i].trim().startsWith('|')) rows.push(cells(lines[i++].trim()))
      html.push('<div class="table-wrap"><table><thead><tr>' +
        head.map((c) => `<th>${inline(c)}</th>`).join('') + '</tr></thead><tbody>' +
        rows.map((r) => '<tr>' + r.map((c) => `<td>${inline(c)}</td>`).join('') + '</tr>').join('') +
        '</tbody></table></div>')
      continue
    }

    // اقتباس
    if (line.startsWith('>')) {
      const body = []
      while (i < lines.length && (lines[i].startsWith('>') || (body.length && lines[i].trim() && !lines[i].startsWith('#')))) {
        body.push(lines[i].replace(/^>\s?/, ''))
        i++
      }
      html.push(`<blockquote>${convert(body.join('\n')).html}</blockquote>`)
      continue
    }

    // قائمة
    const li = line.match(/^(\s*)([-*]|\d+\.)\s+(.*)$/)
    if (li) {
      const ordered = /\d/.test(li[2])
      const items = []
      while (i < lines.length) {
        const m = lines[i].match(/^(\s*)([-*]|\d+\.)\s+(.*)$/)
        if (m) { items.push(m[3]); i++; continue }
        // سطر تكملة لعنصرٍ سابق (مسافة بادئة)
        if (items.length && /^\s{2,}\S/.test(lines[i])) { items[items.length - 1] += ' ' + lines[i].trim(); i++; continue }
        break
      }
      const tag = ordered ? 'ol' : 'ul'
      html.push(`<${tag}>${items.map((t) => `<li>${inline(t)}</li>`).join('')}</${tag}>`)
      continue
    }

    // فقرة
    if (line.trim() === '') { i++; continue }
    const buf = []
    while (i < lines.length && lines[i].trim() !== '' && !/^(#{1,6}\s|```|>|\s*[-*]\s|\s*\d+\.\s|---+\s*$)/.test(lines[i]) &&
           !lines[i].trim().startsWith('|')) {
      buf.push(lines[i]); i++
    }
    flushParagraph(buf)
  }

  return { html: html.join('\n'), toc }
}

// ── البناء ─────────────────────────────────────────────────
let md = await readFile(SRC, 'utf8')

// الغلاف يحمل العنوان، والفهرس يُولَّد من العناوين نفسها — فإبقاء عنوان
// المستند الأول وفهرسه المكتوب يدوياً يُنتج عنواناً مكرّراً وفهرسين. يُقرآن
// في Markdown على GitHub، ويُحذفان هنا وحدهما.
md = md.replace(/^#\s+.*\n/, '')
md = md.replace(/^##\s*الفهرس\s*\n[\s\S]*?(?=^##\s)/m, '')

const { html: body, toc } = convert(md)

console.log(`▸ ${toc.length} عنواناً، ${images.length} صورة`)

// ── تضمين الصور، مرّةً واحدة لكل صورة ───────────────────────
// الصورة الواحدة يُشار إليها في عدّة فصول (شاشة الاستقبال مثلاً تظهر في دليل
// الدور وفي السيناريو وفي المرجع). تضمينها عند كل إشارة كان يُخرج ملفاً بـ٣٢
// ميغابايت وفيه كل صورة مكرّرة مرّتين وثلاثاً. فتُضمَّن الصور الفريدة وحدها في
// جدول، وتُوصَل بعناصرها عند فتح الصفحة.
let missing = 0
const uniq = [...new Set(images)]
const dataFor = new Map()
await Promise.all(uniq.map(async (src) => {
  const data = await inlineImage(src)
  if (!data) { missing++; console.log(`  ! صورة مفقودة: ${src}`); return }
  dataFor.set(src, data)
}))
const slot = new Map([...dataFor.keys()].map((src, i) => [src, i]))
const table = [...dataFor.keys()].map((src) => dataFor.get(src))

const withImages = body.replace(/<img data-src-index="(\d+)" alt="([^"]*)">/g, (_, idx, alt) => {
  const src = images[Number(idx)]
  if (!slot.has(src)) return `<span class="img-missing">[صورة مفقودة: ${alt}]</span>`
  return `<img data-i="${slot.get(src)}" alt="${alt}" loading="lazy">`
})

const imageScript = `<script>
(function () {
  var D = ${JSON.stringify(table)}
  function paint () {
    var n = document.querySelectorAll('img[data-i]')
    for (var i = 0; i < n.length; i++) n[i].src = D[+n[i].dataset.i]
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', paint)
  else paint()
})()
</script>`

const tocHtml = toc.map((t) =>
  `<li class="lvl${t.level}"><a href="#${t.id}">${t.text}</a></li>`).join('\n')

const stamp = new Intl.DateTimeFormat('ar-SA-u-ca-islamic', { dateStyle: 'long' }).format(new Date())
const stampG = new Intl.DateTimeFormat('ar', { dateStyle: 'long' }).format(new Date())

const page = `<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>دليل المستخدم — منصة مركز تمكين الكفاءات</title>
<style>
  :root {
    --tx: #14261d; --tx2: #3d5548; --tx3: #6b7f74; --line: #dfe7e2;
    --accent: #0f6b45; --accent-soft: #eaf5ef; --bg: #ffffff; --surface: #f7faf8;
    --warn-bg: #fff8e6; --warn-line: #e0b355;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--tx);
    font-family: "SF Arabic", "Geeza Pro", "Segoe UI", Tahoma, system-ui, sans-serif;
    font-size: 15px; line-height: 1.85;
  }
  .wrap { max-width: 980px; margin: 0 auto; padding: 0 28px 80px; }

  .cover { text-align: center; padding: 90px 0 40px; border-block-end: 3px solid var(--accent); margin-block-end: 40px; }
  .cover .mark { width: 62px; height: 62px; border-radius: 16px; background: var(--accent); color: #fff;
    display: inline-flex; align-items: center; justify-content: center; font-size: 30px; margin-block-end: 18px; }
  .cover h1 { font-size: 34px; margin: 0 0 8px; letter-spacing: -0.4px; }
  .cover .sub { color: var(--tx3); font-size: 15px; margin: 0; }
  .cover .date { color: var(--tx3); font-size: 13px; margin-block-start: 22px; }

  h1, h2, h3, h4 { line-height: 1.4; }
  h2 { font-size: 25px; margin: 54px 0 16px; padding-block-end: 10px; border-block-end: 2px solid var(--line); }
  h3 { font-size: 19px; margin: 38px 0 12px; color: var(--accent); }
  h4 { font-size: 16px; margin: 26px 0 10px; color: var(--tx2); }
  p { margin: 12px 0; }
  a { color: var(--accent); text-decoration: none; }
  a:hover { text-decoration: underline; }
  hr { border: 0; border-block-start: 1px solid var(--line); margin: 34px 0; }

  code { font-family: "SF Mono", ui-monospace, Menlo, monospace; font-size: 0.86em;
    background: var(--surface); border: 1px solid var(--line); border-radius: 5px; padding: 1px 5px;
    direction: ltr; display: inline-block; }
  pre { background: #10241b; color: #e6f2ec; padding: 16px 18px; border-radius: 10px; overflow-x: auto; direction: ltr; text-align: left; }
  pre code { background: none; border: 0; color: inherit; font-size: 12.6px; padding: 0; }

  .table-wrap { overflow-x: auto; margin: 16px 0; }
  table { border-collapse: collapse; width: 100%; font-size: 13.8px; }
  th, td { border: 1px solid var(--line); padding: 9px 12px; text-align: right; vertical-align: top; }
  th { background: var(--accent-soft); font-weight: 700; }
  tbody tr:nth-child(even) { background: var(--surface); }

  blockquote { margin: 18px 0; padding: 12px 18px; background: var(--warn-bg);
    border-inline-start: 4px solid var(--warn-line); border-radius: 0 8px 8px 0; }
  blockquote p:first-child { margin-block-start: 0; } blockquote p:last-child { margin-block-end: 0; }

  ul, ol { padding-inline-start: 26px; }
  li { margin: 6px 0; }

  figure { margin: 22px 0; text-align: center; }
  /* لقطات القوائم الجانبية طويلة ضيّقة (٤٠٠×١٤٠٠): بعرضٍ كامل تبتلع الصفحة
     كلّها. الحدّ على الارتفاع مع width:auto يُصغّرها ولا يمسّ اللقطات العريضة. */
  figure img { max-width: 100%; max-height: 660px; width: auto; height: auto;
    border: 1px solid var(--line); border-radius: 10px;
    box-shadow: 0 2px 14px rgba(20,38,29,.09); }
  figcaption { color: var(--tx3); font-size: 12.6px; margin-block-start: 8px; }
  .img-missing { color: #b23; font-size: 13px; }

  nav.toc { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 20px 26px; margin: 30px 0 44px; }
  nav.toc h2 { margin: 0 0 12px; font-size: 18px; border: 0; padding: 0; }
  nav.toc ul { list-style: none; padding: 0; margin: 0; columns: 2; column-gap: 34px; }
  nav.toc li { margin: 3px 0; break-inside: avoid; }
  nav.toc .lvl3 { padding-inline-start: 16px; font-size: 13.4px; color: var(--tx2); }
  nav.toc .lvl3 a { color: var(--tx2); }

  @media (prefers-color-scheme: dark) {
    :root { --tx: #e8f0ec; --tx2: #b7c8bf; --tx3: #8ea79b; --line: #22392e;
      --accent: #4ec191; --accent-soft: #14291f; --bg: #0c1512; --surface: #111e19;
      --warn-bg: #2a2312; --warn-line: #8a6a22; }
    figure img { box-shadow: none; }
  }

  @media print {
    body { font-size: 11.4pt; }
    .wrap { max-width: none; padding: 0; }
    nav.toc ul { columns: 2; }
    h2 { break-before: page; break-after: avoid; }
    .cover { break-after: page; border: 0; }
    h3, h4 { break-after: avoid; }
    figure, .table-wrap, blockquote, pre { break-inside: avoid; }
    figure img { max-height: 190mm; }
    a { color: inherit; text-decoration: none; }
  }
  @page { size: A4; margin: 16mm 14mm; }
</style>
</head>
<body>
<div class="wrap">
  <header class="cover">
    <div class="mark">◆</div>
    <h1>دليل المستخدم</h1>
    <p class="sub">منصة مركز تمكين الكفاءات</p>
    <p class="date">${stamp} — ${stampG}</p>
  </header>

  <nav class="toc">
    <h2>الفهرس</h2>
    <ul>${tocHtml}</ul>
  </nav>

${withImages}
</div>
${imageScript}
</body>
</html>
`

await writeFile(OUT_HTML, page, 'utf8')
const kb = Math.round((await stat(OUT_HTML)).size / 1024)
console.log(`✓ ${OUT_HTML.replace(HERE, 'docs')} — ${kb} كيلوبايت${missing ? `، ${missing} صورة مفقودة` : ''}`)

if (WANT_PDF) {
  const { chromium } = await import('playwright')
  const browser = await chromium.launch()
  const p = await browser.newPage()
  await p.goto(`file://${OUT_HTML}`, { waitUntil: 'networkidle' })
  await p.pdf({
    path: OUT_PDF, format: 'A4', printBackground: true,
    margin: { top: '16mm', bottom: '18mm', left: '14mm', right: '14mm' },
    displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate: `<div style="width:100%;font-size:8pt;color:#889;padding:0 14mm;
      font-family:'SF Arabic',Tahoma,sans-serif;display:flex;justify-content:space-between;direction:rtl">
      <span>دليل المستخدم — منصة مركز تمكين الكفاءات</span>
      <span class="pageNumber"></span></div>`,
  })
  await browser.close()
  const pkb = Math.round((await stat(OUT_PDF)).size / 1024)
  console.log(`✓ ${OUT_PDF.replace(HERE, 'docs')} — ${pkb} كيلوبايت`)
}
