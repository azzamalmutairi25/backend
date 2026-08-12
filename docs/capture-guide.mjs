#!/usr/bin/env node
// ════════════════════════════════════════════════════════════
//  التقاط صور دليل المستخدم آلياً.
//
//  دليلٌ بصورٍ تُلتقط يدوياً يتقادم بصمت: تتغيّر الشاشة ولا تتغيّر صورتها،
//  فيقرأ الموظّف وصفاً لواجهةٍ لم تعد موجودة. هذا السكربت يجعل التحديث
//  أمراً واحداً، فيصير تنفيذه أرخص من إهماله.
//
//  اللقطات تُقرأ من guide-shots.json لا من هذا الملف: الدليل صار يشرح
//  «كيف تعمل» لا «كيف تبدو»، فلزمته صورُ حالاتٍ لا صورُ صفحات — نافذةٌ
//  مفتوحة، ونموذجٌ معبّأ، وما يراه دورٌ آخر. وصفات كهذه تُضاف وتُعدَّل
//  كثيراً، فمكانها ملفُ بيانات لا شيفرة.
//
//  الاستعمال (بيئة تطوير محلية وبها بيانات عرض):
//      cd backend-new
//      GUIDE_PASS=... node docs/capture-guide.mjs
//      … --only=candidates,candidates-add        # لقطات بعينها
//      … --role=reception                        # ما يُلتقط بحساب واحد
//      … --list                                  # عرض الكتالوج بلا التقاط
//
//  المتطلّبات: خادم Laravel على 8000، وVite على 5173، وحسابات التجربة
//  (TestUsersSeeder) بكلمة مرور واحدة تُمرَّر في GUIDE_PASS.
// ════════════════════════════════════════════════════════════
import { chromium } from 'playwright'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const OUT = join(HERE, 'guide-images')
const CATALOG = join(HERE, 'guide-shots.json')

const BASE = process.env.GUIDE_BASE ?? 'http://localhost:5173'
const PASS = process.env.GUIDE_PASS

const arg = (name, dflt = '') =>
  (process.argv.find((a) => a.startsWith(`--${name}=`)) ?? '').replace(`--${name}=`, '') || dflt
const flag = (name) => process.argv.includes(`--${name}`)

const ONLY = arg('only').split(',').map((s) => s.trim()).filter(Boolean)
const ROLE_FILTER = arg('role')

const catalog = JSON.parse(await readFile(CATALOG, 'utf8'))
const SHOTS = catalog.shots

if (flag('list')) {
  for (const s of SHOTS) console.log(`${(s.role ?? 'admin').padEnd(11)} ${s.id.padEnd(34)} ${s.route}  ${s.title ?? ''}`)
  console.log(`\n${SHOTS.length} لقطة`)
  process.exit(0)
}

// لا كلمة مرور افتراضية في الشيفرة — تُمرَّر من البيئة أو يتوقّف السكربت.
if (!PASS) {
  console.error('✗ اضبط GUIDE_PASS (كلمة مرور حسابات التجربة المحلية).')
  console.error('  مثال: GUIDE_PASS=... node docs/capture-guide.mjs')
  process.exit(1)
}

if (ONLY.length) {
  const unknown = ONLY.filter((id) => !SHOTS.some((s) => s.id === id))
  if (unknown.length) {
    console.error(`✗ معرّفات غير معروفة: ${unknown.join('، ')}`)
    process.exit(1)
  }
}

const wanted = (s) =>
  (ONLY.length === 0 || ONLY.includes(s.id)) &&
  (!ROLE_FILTER || (s.role ?? 'admin') === ROLE_FILTER)

const queue = SHOTS.filter(wanted)
if (!queue.length) {
  console.error('✗ لا لقطة تطابق المرشِّحات.')
  process.exit(1)
}

// ── انتظار اكتمال الرسم فعلاً ──────────────────────────────
// `main` يظهر فور تركيب المكوّن، قبل أن تعود أي استجابة — فالانتظار عليه
// وحده كان يلتقط هياكل التحميل (.skel) بدل البيانات: دليلٌ من مستطيلات
// رمادية. الترتيب: العنصر، ثم هدوء الشبكة، ثم اختفاء الهياكل.
async function settle(page, wait = 'main', { quick = false } = {}) {
  if (wait) {
    try { await page.waitForSelector(wait, { timeout: quick ? 6000 : 12000, state: 'visible' }) } catch { /* نلتقط ما ظهر */ }
  }
  try { await page.waitForLoadState('networkidle', { timeout: quick ? 6000 : 15000 }) } catch { /* شاشة تُبقي اتصالاً مفتوحاً */ }
  try {
    await page.waitForFunction(() => document.querySelectorAll('.skel, .skeleton').length === 0,
      null, { timeout: quick ? 4000 : 8000 })
  } catch { /* لا بيانات لهذه الشاشة — تُلتقط كما هي */ }
  // انتظار الخطوط بسباقٍ لا بلا حدّ: تعليق تحميل خطٍّ عربي كان يوقف
  // الالتقاط كلّه، فيُنتَج دليلٌ ناقص بلا سببٍ ظاهر.
  await Promise.race([
    page.evaluate(() => document.fonts.ready).catch(() => {}),
    new Promise((r) => setTimeout(r, 3000)),
  ])
  await page.waitForTimeout(quick ? 350 : 700)  // انطفاء حركات الدخول
}

// خطوة واحدة من وصفة اللقطة. الفشل لا يُسقط الالتقاط كلّه: تُسجَّل الخطوة
// الساقطة وتُلتقط الشاشة كما وصلت إليها، فيرى محرّر الدليل ما حدث بدل أن
// يجد ملفاً ناقصاً بلا تفسير.
async function runStep(page, step) {
  const { action, selector, value } = step
  const loc = selector ? page.locator(selector).first() : null
  switch (action) {
    case 'goto':      await page.goto(`${BASE}${value}`, { waitUntil: 'domcontentloaded' }); await settle(page); break
    case 'click':     await loc.click({ timeout: 8000 }); await settle(page, null, { quick: true }); break
    case 'fill':      await loc.fill(value ?? '', { timeout: 8000 }); await page.waitForTimeout(500); break
    // قيم القوائم المنسدلة هنا معرّفات صفوفٍ تتغيّر مع كل إعادة بذر للبيانات،
    // فتثبيتها في الكتالوج يجعل اللقطة تسقط بعد كل تجديد. index: يختار بالموضع.
    case 'select':    await loc.selectOption(
                        value?.startsWith('index:') ? { index: Number(value.slice(6)) } : (value ?? ''),
                        { timeout: 8000 }); await settle(page, null, { quick: true }); break
    case 'hover':     await loc.hover({ timeout: 8000 }); await page.waitForTimeout(400); break
    case 'press':     await page.keyboard.press(value ?? 'Enter'); await settle(page, null, { quick: true }); break
    case 'check':     await loc.check({ timeout: 8000 }); await page.waitForTimeout(400); break
    case 'waitFor':   await page.waitForSelector(selector, { timeout: 10000, state: 'visible' }); break
    case 'scrollTo':  await loc.scrollIntoViewIfNeeded({ timeout: 8000 }); await page.waitForTimeout(400); break
    case 'wait':      await page.waitForTimeout(Number(value ?? 800)); break
    default: throw new Error(`إجراء غير معروف: ${action}`)
  }
}

const browser = await chromium.launch()
await mkdir(OUT, { recursive: true })

const captured = []
const failures = []
const consoleErrors = new Map()

async function contextFor(role) {
  const ctx = await browser.newContext({
    viewport: { width: 1512, height: 950 },
    // 1.5 لا 2: الأخيرة تُنتج صوراً بعرض 3024 بكسل — أوضح مما يحتاجه دليلٌ
    // يُقرأ على شاشة، وأثقل أربعة أضعاف في مستودعٍ تُعاد كتابة صوره.
    deviceScaleFactor: 1.5,
    locale: 'ar-SA',
    timezoneId: 'Asia/Riyadh',
    reducedMotion: 'reduce',
  })
  const page = await ctx.newPage()
  page.on('console', (m) => {
    if (m.type() !== 'error') return
    const list = consoleErrors.get(role) ?? []
    list.push(m.text()); consoleErrors.set(role, list)
  })
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
  await settle(page, '.form-card')
  return { ctx, page }
}

// مسار الدخول محدود بعشر محاولات في الدقيقة لكل عنوان (throttle:10,1)، والالتقاط
// يسجّل دخولاً لكل دور. مع أحد عشر دوراً يُردّ آخرها بـ429 فيسقط ثلث الدليل بلا
// سببٍ ظاهر — فالإعادة بعد انقضاء النافذة، لا الفشل.
async function login(page, role) {
  for (let attempt = 1; ; attempt++) {
    await page.fill('#lg-user', role)
    await page.fill('#lg-pass', PASS)
    try {
      await Promise.all([
        page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
        page.click('button.submit'),
      ])
      break
    } catch (e) {
      if (attempt >= 4) throw e
      console.log(`  … تعذّر الدخول بـ${role} (محاولة ${attempt}) — انتظار ٦٥ ثانية لانقضاء حدّ المحاولات`)
      await page.waitForTimeout(65000)
    }
  }
  if (page.url().includes('/change-password')) {
    throw new Error(`الحساب ${role} مُلزَم بتغيير كلمة المرور — استعمل حساباً غير مُلزَم.`)
  }
}

// التجميع بالدور: تسجيل دخولٍ واحد لكل حساب بدل واحدٍ لكل لقطة.
const byRole = new Map()
for (const s of queue) {
  const role = s.role ?? 'admin'
  if (!byRole.has(role)) byRole.set(role, [])
  byRole.get(role).push(s)
}

try {
  for (const [role, shots] of byRole) {
    console.log(`\n═══ ${role} (${shots.length} لقطة) ═══`)
    const { ctx, page } = await contextFor(role)

    // لقطات ما قبل الدخول (شاشة الدخول نفسها) تُلتقط قبل تعبئة الحقول —
    // وإلا ظهر اسم الحساب في الدليل.
    for (const s of shots.filter((x) => x.auth === false)) {
      process.stdout.write(`▸ ${s.id} … `)
      try {
        if (s.route !== '/login') { await page.goto(`${BASE}${s.route}`, { waitUntil: 'domcontentloaded' }); await settle(page, s.wait) }
        for (const step of s.steps ?? []) await runStep(page, step)
        await shoot(page, s)
        captured.push({ ...meta(s, role) }); console.log('✓')
      } catch (e) {
        failures.push({ id: s.id, role, error: e.message }); console.log(`✗ ${e.message.split('\n')[0]}`)
      }
    }

    const rest = shots.filter((x) => x.auth !== false)
    if (rest.length) {
      try {
        await login(page, role)
      } catch (e) {
        console.log(`✗ تعذّر الدخول بـ${role}: ${e.message}`)
        for (const s of rest) failures.push({ id: s.id, role, error: `فشل الدخول: ${e.message}` })
        await ctx.close()
        continue
      }
    }

    for (const s of rest) {
      process.stdout.write(`▸ ${s.id} … `)
      try {
        // هوية البرنامج سطحٌ أبيض واحد: لا وضع داكن ولا مفتاح مظهر. مفتاح
        // «theme» القديم قد يبقى في تخزين جهازٍ التقط قبل إعادة الكساء، فيُمسح
        // كي لا يُورَّث إلى الجلسة شيءٌ لم يعد له معنى.
        await page.evaluate(() => {
          try { localStorage.removeItem('theme') } catch (e) { /* تجاهل */ }
          document.documentElement.removeAttribute('data-theme')
        }).catch(() => {})
        await page.goto(`${BASE}${s.route}`, { waitUntil: 'domcontentloaded' })
        // التحويل إلى /dashboard يعني أن الحساب لا يملك صلاحية الشاشة —
        // يُقال صراحةً بدل التقاط اللوحة الرئيسة باسم شاشةٍ أخرى.
        if (s.route !== '/dashboard' && !page.url().includes(s.route)) {
          throw new Error(`حُوِّل إلى ${new URL(page.url()).pathname} — صلاحية ناقصة لـ${role}؟`)
        }
        await settle(page, s.wait)
        const target = await runSteps(page, s)
        if (s.settleAfter !== false) await target.waitForTimeout(400)
        await shoot(target, s)
        if (target !== page) await target.close()
        captured.push({ ...meta(s, role) }); console.log('✓')
      } catch (e) {
        failures.push({ id: s.id, role, error: e.message })
        console.log(`✗ ${e.message.split('\n')[0].slice(0, 120)}`)
      }
    }

    await ctx.close()
  }
} finally {
  await browser.close()
}

function meta(s, role) {
  return { id: s.id, title: s.title ?? s.id, file: `${s.id}.png`, route: s.route, role }
}

// المستند الرسمي للتقرير يُفتح في تبويب جديد يُكتَب محتواه من الشيفرة
// (window.open ثم document.write)، لا بانتقالٍ إلى رابط. فالتقاطه يلزمه
// انتظار التبويب نفسه: بدونه تُلتقط الشاشة الأولى وقد بقيت كما هي.
async function runSteps(page, s) {
  const steps = s.steps ?? []
  if (!s.popup) {
    for (const step of steps) await runStep(page, step)
    return page
  }
  for (const step of steps.slice(0, -1)) await runStep(page, step)
  const [popup] = await Promise.all([
    page.context().waitForEvent('page', { timeout: 15000 }),
    runStep(page, steps[steps.length - 1]),
  ])
  await popup.waitForLoadState('domcontentloaded').catch(() => {})
  await settle(popup, s.popupWait ?? null, { quick: true })
  return popup
}

async function shoot(page, s) {
  const path = join(OUT, `${s.id}.png`)
  if (s.clip) {
    const el = page.locator(s.clip).first()
    await el.waitFor({ state: 'visible', timeout: 8000 })
    await el.screenshot({ path })
  } else {
    await page.screenshot({ path, fullPage: s.fullPage === true })
  }
}

// بيان بما التُقط: يقرؤه محرّر الدليل ليعرف ما الجديد وما الذي سقط.
// يُدمَج مع القديم لا يُستبدل به: مع --only كانت الكتابة الكاملة تُسقط بقيّة
// اللقطات من البيان فتبدو غير موجودة وهي ملتقطة على القرص.
let previous = {}
try { previous = JSON.parse(await readFile(join(OUT, 'manifest.json'), 'utf8')) } catch { /* أول مرّة */ }
const merged = new Map((previous.screens ?? []).map((s) => [s.id, s]))
const stamp = new Date().toISOString()
for (const c of captured) merged.set(c.id, { ...c, capturedAt: stamp })

await writeFile(
  join(OUT, 'manifest.json'),
  JSON.stringify({
    updatedAt: stamp,
    base: BASE,
    screens: SHOTS.map((s) => merged.get(s.id)).filter(Boolean),
  }, null, 2),
  'utf8'
)

console.log(`\n✓ ${captured.length} من ${queue.length} لقطة في docs/guide-images/`)
if (failures.length) {
  console.log(`\n! سقطت ${failures.length}:`)
  for (const f of failures) console.log(`   ${f.role}/${f.id}: ${f.error.split('\n')[0].slice(0, 140)}`)
}
for (const [role, errs] of consoleErrors) {
  const uniq = [...new Set(errs)]
  if (uniq.length) {
    console.log(`\n! ${uniq.length} خطأ في طرفية المتصفّح (${role}):`)
    for (const e of uniq.slice(0, 6)) console.log(`   ${e.slice(0, 150)}`)
  }
}
process.exit(failures.length ? 1 : 0)
