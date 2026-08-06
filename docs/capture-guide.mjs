#!/usr/bin/env node
// ════════════════════════════════════════════════════════════
//  التقاط صور دليل المستخدم آلياً.
//
//  دليلٌ بصورٍ تُلتقط يدوياً يتقادم بصمت: تتغيّر الشاشة ولا تتغيّر صورتها،
//  فيقرأ الموظّف وصفاً لواجهةٍ لم تعد موجودة. هذا السكربت يجعل التحديث
//  أمراً واحداً، فيصير تنفيذه أرخص من إهماله.
//
//  الاستعمال (بيئة التطوير المحلية وبها بيانات عرض):
//      cd backend-new
//      GUIDE_USER=admin GUIDE_PASS=... node docs/capture-guide.mjs
//      … node docs/capture-guide.mjs --only=candidates,reports   # شاشات بعينها
//
//  المتطلّبات: خادم Laravel على 8000، وVite على 5173، وحساب يملك «*».
// ════════════════════════════════════════════════════════════
import { chromium } from 'playwright'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const OUT = join(HERE, 'guide-images')

const BASE = process.env.GUIDE_BASE ?? 'http://localhost:5173'
const USER = process.env.GUIDE_USER
const PASS = process.env.GUIDE_PASS

// لا كلمة مرور افتراضية في الشيفرة — تُمرَّر من البيئة أو يتوقّف السكربت.
if (!USER || !PASS) {
  console.error('✗ اضبط GUIDE_USER و GUIDE_PASS (حساب تطوير محلي يملك كل الصلاحيات).')
  console.error('  مثال: GUIDE_USER=admin GUIDE_PASS=... node docs/capture-guide.mjs')
  process.exit(1)
}

// الشاشات بترتيب الدليل نفسه. `wait` مُحدِّدٌ يدلّ على اكتمال الرسم فعلاً:
// الانتظار الزمني وحده يلتقط هياكل تحميل فارغة على جهازٍ بطيء.
const SCREENS = [
  { id: 'login',                path: '/login',                auth: false, wait: '.form-card',  title: 'تسجيل الدخول' },
  { id: 'dashboard',            path: '/dashboard',                         wait: 'main',        title: 'اللوحة الرئيسة' },
  { id: 'candidates',           path: '/candidates',                        wait: 'main',        title: 'المشاركون' },
  { id: 'nominate',             path: '/nominate',                          wait: 'main',        title: 'ترشيح مشارك (الجهات الخارجية)' },
  // طلبات تحديث البيانات مُخفاة بمفتاح تشغيل (features.js) — المسار يعيد
  // إلى لوحة التحكم، فلا لقطة لها حتى تُعاد
  { id: 'reception',            path: '/reception',                         wait: 'main',        title: 'استقبال الموظفين' },
  { id: 'schedules',            path: '/schedules',                         wait: 'main',        title: 'الجدولة' },
  { id: 'distribution',         path: '/distribution',                      wait: 'main',        title: 'توزيع المقيّمين' },
  { id: 'attendance',           path: '/attendance',                        wait: 'main',        title: 'الحضور' },
  { id: 'assessment',           path: '/assessment',                        wait: 'main',        title: 'التقييم' },
  { id: 'measurements',         path: '/measurements',                      wait: 'main',        title: 'أدوات القياس' },
  { id: 'reports',              path: '/reports',                           wait: 'main',        title: 'التقارير' },
  { id: 'development-plans',    path: '/development-plans',                 wait: 'main',        title: 'خطط التطوير' },
  { id: 'competency-framework', path: '/competency-framework',              wait: 'main',        title: 'إطار الكفاءات' },
  { id: 'competency-map',       path: '/competency-map',                    wait: 'main',        title: 'خريطة الكفاءات والأنشطة' },
  { id: 'analytics',            path: '/analytics',                         wait: 'main',        title: 'التحليلات' },
  { id: 'executive',            path: '/executive',                         wait: 'main',        title: 'لوحة القيادة التنفيذية' },
  { id: 'daily-report',         path: '/daily-report',                      wait: 'main',        title: 'التقرير اليومي' },
  { id: 'chat',                 path: '/chat',                              wait: 'main',        title: 'المراسلات' },
  { id: 'notifications',        path: '/notifications',                     wait: 'main',        title: 'الإشعارات' },
  { id: 'workflow',             path: '/workflow',                          wait: 'main',        title: 'مراحل الاعتماد' },
  { id: 'users',                path: '/users',                             wait: 'main',        title: 'المستخدمون والصلاحيات' },
  { id: 'settings',             path: '/settings',                          wait: 'main',        title: 'الإعدادات' },
  { id: 'audit',                path: '/audit',                             wait: 'main',        title: 'سجل التدقيق' },
  { id: 'change-password',      path: '/change-password',                   wait: 'main',        title: 'تغيير كلمة المرور' },
]

async function settle(page, wait) {
  // `main` يظهر فور تركيب المكوّن، قبل أن تعود أي استجابة — فالانتظار عليه
  // وحده كان يلتقط هياكل التحميل (.skel) بدل البيانات: دليلٌ من مستطيلات
  // رمادية. الترتيب هنا: العنصر، ثم هدوء الشبكة، ثم اختفاء الهياكل.
  try { await page.waitForSelector(wait, { timeout: 12000 }) } catch { /* نلتقط ما ظهر */ }

  try { await page.waitForLoadState('networkidle', { timeout: 15000 }) } catch { /* شاشة تُبقي اتصالاً مفتوحاً */ }

  // اختفاء آخر هيكل تحميل — بمهلة: شاشةٌ بلا بيانات تُبقيه ظاهراً بحقّ
  try {
    await page.waitForFunction(() => document.querySelectorAll('.skel, .skeleton').length === 0,
      null, { timeout: 8000 })
  } catch { /* لا بيانات لهذه الشاشة — تُلتقط كما هي */ }

  // انتظار الخطوط بسباقٍ لا بلا حدّ: تعليق تحميل خطٍّ عربي كان يوقف
  // الالتقاط كلّه، فيُنتَج دليلٌ ناقص بلا سببٍ ظاهر.
  await Promise.race([
    page.evaluate(() => document.fonts.ready),
    new Promise((r) => setTimeout(r, 4000)),
  ])
  await page.waitForTimeout(800)  // انطفاء حركات الدخول (fadeInUp وأخواتها)
}

// إعادة التقاط الكلّ تُعيد كتابة ٢٤ صورة في كل مرّة، وتاريخ Git يحتفظ بكل نسخة:
// تحديثٌ لشاشة واحدة كان يُضيف ميغابايتات لا تُسترجَع. --only يلتقط ما تغيّر وحده.
const ONLY = (process.argv.find((a) => a.startsWith('--only=')) ?? '')
  .replace('--only=', '').split(',').map((s) => s.trim()).filter(Boolean)
const wanted = (s) => ONLY.length === 0 || ONLY.includes(s.id)

if (ONLY.length) {
  const unknown = ONLY.filter((id) => !SCREENS.some((s) => s.id === id))
  if (unknown.length) {
    console.error(`✗ معرّفات غير معروفة: ${unknown.join('، ')}`)
    console.error(`  المتاح: ${SCREENS.map((s) => s.id).join('، ')}`)
    process.exit(1)
  }
}

const browser = await chromium.launch()
const ctx = await browser.newContext({
  viewport: { width: 1512, height: 950 },
  // 1.5 لا 2: الأخيرة تُنتج صوراً بعرض 3024 بكسل — أوضح مما يحتاجه دليلٌ يُقرأ
  // على شاشة، وأثقل أربعة أضعاف في مستودعٍ تُعاد كتابة صوره بعد كل خدمة.
  deviceScaleFactor: 1.5,
  locale: 'ar-SA',
  timezoneId: 'Asia/Riyadh',
  reducedMotion: 'reduce',       // لا حركة أثناء الالتقاط
})
const page = await ctx.newPage()

const errors = []
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })

await mkdir(OUT, { recursive: true })
const captured = []

try {
  // ── الدخول مرّة واحدة، والجلسة تُستعمل لكل ما بعده ──
  console.log('▸ تسجيل الدخول…')
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
  await settle(page, '.form-card')

  // شاشة الدخول تُلتقط قبل تعبئة الحقول — وإلا ظهر اسم الحساب في الدليل
  if (wanted({ id: 'login' })) {
    await page.screenshot({ path: join(OUT, 'login.png') })
    captured.push({ id: 'login', title: 'تسجيل الدخول', file: 'login.png' })
    console.log('  ✓ login')
  }

  await page.fill('#lg-user', USER)
  await page.fill('#lg-pass', PASS)
  await Promise.all([
    page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
    page.click('button.submit'),
  ])

  if (page.url().includes('/change-password')) {
    console.error('✗ الحساب مُلزَم بتغيير كلمة المرور — استعمل حساب تطوير غير مُلزَم.')
    process.exit(1)
  }

  // ── بقيّة الشاشات ──
  for (const s of SCREENS.filter((x) => x.auth !== false && wanted(x))) {
    process.stdout.write(`▸ ${s.id} … `)
    await page.goto(`${BASE}${s.path}`, { waitUntil: 'domcontentloaded' })

    // التحويل إلى /dashboard يعني أن الحساب لا يملك صلاحية الشاشة —
    // يُقال صراحةً بدل التقاط اللوحة الرئيسة باسم شاشةٍ أخرى.
    if (!page.url().includes(s.path)) {
      console.log(`تخطٍّ (حُوِّل إلى ${new URL(page.url()).pathname} — صلاحية ناقصة؟)`)
      continue
    }

    await settle(page, s.wait)
    const file = `${s.id}.png`
    await page.screenshot({ path: join(OUT, file) })
    captured.push({ id: s.id, title: s.title, file })
    console.log('✓')
  }
} finally {
  await browser.close()
}

// بيان بما التُقط: يقرؤه محرّر الدليل ليعرف ما الجديد وما الذي سقط.
// يُدمَج مع القديم لا يُستبدل به: مع --only كانت الكتابة الكاملة تُسقط بقيّة
// الشاشات من البيان فتبدو غير موجودة وهي ملتقطة على القرص.
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
    screens: SCREENS.map((s) => merged.get(s.id)).filter(Boolean),
  }, null, 2),
  'utf8'
)

const scope = ONLY.length ? `${captured.length} من ${ONLY.length} مطلوبة` : `${captured.length} من ${SCREENS.length}`
console.log(`\n✓ ${scope} شاشة في docs/guide-images/`)
const missing = SCREENS.filter(wanted).filter((s) => !captured.some((c) => c.id === s.id))
if (missing.length) console.log(`! لم تُلتقط: ${missing.map((m) => m.id).join('، ')}`)
if (errors.length) {
  console.log(`\n! ${errors.length} خطأ في طرفية المتصفّح أثناء الالتقاط:`)
  for (const e of [...new Set(errors)].slice(0, 8)) console.log(`   ${e.slice(0, 160)}`)
}
