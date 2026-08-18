#!/usr/bin/env node
// ════════════════════════════════════════════════════════════
//  مولّد حمل لواجهة «تمكين الكفاءات» — بلا أي اعتماديات.
//
//  متعدّد النوى: خيطٌ واحد لا يشبع خادماً، فيقيس سقفَ مولّد الحمل لا سقف
//  التطبيق. نوزّع المستخدمين الافتراضيين على عمّال بعدد الأنوية.
//
//  الرموز تأتي من `php artisan loadtest:prepare` لا من /login: حدّ الدخول
//  (١٠/دقيقة/IP) وحدّ الـAPI (٣٠٠/دقيقة/مستخدم) يُفسدان القياس إن مررنا بهما.
//
//  التمييز الحاسم في التقرير: 429 ليست فشلاً بل حدّ معدّل يعمل كما صُمّم.
//  خلطُها بالأخطاء يجعل نظاماً سليماً يبدو منهاراً.
// ════════════════════════════════════════════════════════════
import { Worker, isMainThread, parentPort, workerData } from 'node:worker_threads'
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { availableParallelism } from 'node:os'
import { dirname, resolve } from 'node:path'

// ── الخيارات ──
function parseArgs(argv) {
  const o = {
    url: 'http://localhost:8000',
    vus: 50,
    duration: 60,
    ramp: 10,
    mix: 'read',
    think: 0,
    workers: Math.max(1, availableParallelism() - 1),
    tokens: 'load-test/tokens.json',
    out: '',
    timeout: 15000,
  }
  for (const a of argv.slice(2)) {
    const m = /^--([a-zA-Z-]+)(?:=(.*))?$/.exec(a)
    if (!m) continue
    const [, k, v] = m
    const key = k.replace(/-([a-z])/g, (_, c) => c.toUpperCase())
    if (key in o) o[key] = typeof o[key] === 'number' ? Number(v) : (v ?? true)
    else if (key === 'help') o.help = true
  }
  return o
}

const HELP = `
مولّد حمل — منصّة تمكين الكفاءات

  node load-test/loadtest.mjs [--url=...] [--vus=50] [--duration=60] ...

  --url        عنوان الهدف (افتراضي http://localhost:8000)
  --vus        المستخدمون الافتراضيون المتزامنون (افتراضي 50)
  --duration   مدة القياس بالثواني (افتراضي 60)
  --ramp       ثواني التصعيد التدريجي حتى vus الكامل (افتراضي 10)
  --mix        read | write | mixed | public   (افتراضي read)
  --think      زمن تفكير بين طلبات المستخدم الواحد بالمللي (افتراضي 0 = ضغط أقصى)
  --workers    خيوط توليد الحمل (افتراضي الأنوية − 1)
  --tokens     ملف الرموز من loadtest:prepare
  --timeout    مهلة الطلب بالمللي (افتراضي 15000)
  --out        مسار تقرير JSON (اختياري)

  قبل التشغيل:  php artisan loadtest:prepare
  بعد الانتهاء: php artisan loadtest:prepare --cleanup
`

// ── السيناريوهات ──
// كل سيناريو: اسم، وزن، من يملك الرمز (reader/writer/none)، وباني الطلب.
// الأوزان تحاكي واقع الاستعمال: قراءة كثيرة، كتابة قليلة.
function buildScenarios(mix, ctx) {
  const read = [
    { name: 'قائمة المشاركين', weight: 30, actor: 'reader', req: () => ({ method: 'GET', path: '/api/candidates' }) },
    { name: 'مؤشرات المشاركين', weight: 15, actor: 'reader', req: () => ({ method: 'GET', path: '/api/candidates/stats' }) },
    { name: 'لوحة البداية', weight: 20, actor: 'reader', req: () => ({ method: 'GET', path: '/api/dashboard/overview' }) },
    { name: 'الجدولة', weight: 15, actor: 'reader', req: () => ({ method: 'GET', path: '/api/schedules' }) },
    { name: 'طلبات التحديث', weight: 10, actor: 'reader', req: () => ({ method: 'GET', path: '/api/candidate-update-requests?status=pending' }) },
    { name: 'تفاصيل مشارك', weight: 10, actor: 'reader', req: () => ({ method: 'GET', path: `/api/candidates/${ctx.randomCandidateId()}` }) },
  ]

  const write = [
    {
      name: 'ترشيح مشارك (كتابة)',
      weight: 100,
      actor: 'writer',
      req: (vu, seq) => ({
        method: 'POST',
        path: '/api/candidates',
        body: nominationBody(vu, seq, ctx.sectorId),
      }),
    },
  ]

  const pub = [
    {
      name: 'بوّابة عامة — تحقّق',
      weight: 100,
      actor: 'none',
      req: () => ({
        method: 'POST',
        path: `/api/public/assessment/${'x'.repeat(48)}/verify`,
        body: { nationalId: '1000000008' },
      }),
    },
  ]

  if (mix === 'read') return read
  if (mix === 'write') return write
  if (mix === 'public') return pub
  if (mix === 'mixed') return [...read.map((s) => ({ ...s, weight: s.weight * 0.9 })), { ...write[0], weight: 10 }]
  throw new Error(`خليط غير معروف: ${mix}`)
}

// هوية اصطناعية صالحة (لُون) فريدة لكل (عامل، مستخدم، تسلسل)
function syntheticNationalId(n) {
  const body = '2' + String(n % 100000000).padStart(8, '0')
  let sum = 0
  for (let i = 0; i < 9; i++) {
    const d = +body[i]
    if (i % 2 === 0) { const x = d * 2; sum += x > 9 ? x - 9 : x }
    else sum += d
  }
  return body + ((10 - (sum % 10)) % 10)
}

function nominationBody(vu, seq, sectorId) {
  // مدى واسع يمنع تصادم الهويات بين العمّال والمستخدمين
  const n = 30000000 + (vu * 100000) + (seq % 100000)
  return {
    nationalId: syntheticNationalId(n),
    fullName: `مشارك ضغط ${n}`,
    mobile: '05' + String(n % 100000000).padStart(8, '0'),
    sectorId,
    rankLabel: 'عميد',
    cv: {
      birthDate: '1982-04-11',
      appointmentDate: '2006-09-01',
      rankLabel: 'عميد',
      department: 'الإدارة العامة للعمليات',
      region: 'الرياض',
      currentPosition: 'مدير عام',
      totalYearsExperience: 15,
      briefBio: 'قيادي متمرّس في القطاع الحكومي',
      qualifications: [{ degree: 'master', major: 'إدارة أعمال', institution: 'جامعة الملك سعود', studyPlace: 'السعودية', gradYear: 2008 }],
      experiences: [{ position: 'مدير إدارة', organization: 'وزارة', fromYear: 2010, toYear: null, current: true, summary: 'قيادة الفريق' }],
      certifications: [{ name: 'شهادة احترافية', issuer: 'المعهد', year: 2015 }],
    },
  }
}

// اختيار سيناريو بالوزن
function pick(scenarios, total) {
  let r = Math.random() * total
  for (const s of scenarios) {
    r -= s.weight
    if (r <= 0) return s
  }
  return scenarios[scenarios.length - 1]
}

// ══════════════ العامل ══════════════
if (!isMainThread) {
  const { opts, slice, ctx, readerTokens, writerTokens } = workerData
  const scenarios = buildScenarios(opts.mix, {
    sectorId: ctx.sectorId,
    randomCandidateId: () => ctx.candidateIds[(Math.random() * ctx.candidateIds.length) | 0] ?? 1,
  })
  // الرموز تُوزَّع على المستخدمين الافتراضيين دورياً — كلٌّ بدلوه في حدّ المعدّل.
  // الإزاحة عالمية عبر العمّال فلا يتشارك اثنان الرمز نفسه ما دام العدد كافياً.
  const readerFor = (i) => readerTokens[(slice.offset + i) % Math.max(1, readerTokens.length)]
  const writerFor = (i) => writerTokens[(slice.offset + i) % Math.max(1, writerTokens.length)]
  const totalWeight = scenarios.reduce((a, s) => a + s.weight, 0)
  const stats = new Map() // اسم السيناريو → إحصاءات

  const bucket = (name) => {
    if (!stats.has(name)) {
      stats.set(name, { ok: 0, throttled: 0, clientErr: 0, serverErr: 0, netErr: 0, latencies: [], statuses: {} })
    }
    return stats.get(name)
  }

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
  const endAt = Date.now() + opts.duration * 1000

  async function virtualUser(vuIndex) {
    // التصعيد: كل مستخدم يبدأ متأخّراً بنسبته — يمنع اندفاعة أولى تُشوّه p99
    const delay = opts.ramp > 0 ? (vuIndex / Math.max(1, slice.count)) * opts.ramp * 1000 : 0
    await sleep(delay)

    let seq = 0

    while (Date.now() < endAt) {
      const sc = pick(scenarios, totalWeight)
      const r = sc.req(slice.offset + vuIndex, seq++)
      const b = bucket(sc.name)
      const headers = { Accept: 'application/json' }
      if (sc.actor !== 'none') headers.Authorization = `Bearer ${sc.actor === 'writer' ? writerFor(vuIndex) : readerFor(vuIndex)}`
      if (r.body) headers['Content-Type'] = 'application/json'

      const t0 = performance.now()
      try {
        const res = await fetch(opts.url + r.path, {
          method: r.method,
          headers,
          body: r.body ? JSON.stringify(r.body) : undefined,
          signal: AbortSignal.timeout(opts.timeout),
        })
        // استهلاك الجسم إلزامي وإلا بقي الاتصال محجوزاً فانهار المولّد قبل الخادم
        await res.arrayBuffer()
        const dt = performance.now() - t0
        b.latencies.push(dt)
        b.statuses[res.status] = (b.statuses[res.status] ?? 0) + 1
        if (res.status === 429) b.throttled++
        else if (res.status >= 500) b.serverErr++
        else if (res.status >= 400) b.clientErr++
        else b.ok++
      } catch (e) {
        b.netErr++
        b.statuses[e.name === 'TimeoutError' ? 'timeout' : 'network'] =
          (b.statuses[e.name === 'TimeoutError' ? 'timeout' : 'network'] ?? 0) + 1
      }

      if (opts.think > 0) await sleep(opts.think)
    }
  }

  const users = Array.from({ length: slice.count }, (_, i) => virtualUser(i))
  Promise.all(users).then(() => {
    parentPort.postMessage(
      [...stats.entries()].map(([name, s]) => ({ name, ...s }))
    )
  })
}

// ══════════════ الرئيسي ══════════════
else {
  const opts = parseArgs(process.argv)
  if (opts.help) { console.log(HELP); process.exit(0) }

  let tokens
  try {
    tokens = JSON.parse(readFileSync(resolve(opts.tokens), 'utf8'))
  } catch {
    console.error(`✗ تعذّر قراءة ملف الرموز: ${opts.tokens}\n  شغّل أولاً:  php artisan loadtest:prepare`)
    process.exit(1)
  }

  const readerTokens = tokens.readers?.tokens ?? []
  const writerTokens = tokens.writers?.tokens ?? []
  if ((opts.mix === 'read' || opts.mix === 'mixed') && readerTokens.length === 0) {
    console.error('✗ لا رموز قراءة في الملف — أعد التحضير بـ--readers=20'); process.exit(1)
  }
  if ((opts.mix === 'write' || opts.mix === 'mixed') && writerTokens.length === 0) {
    console.error('✗ لا رموز كتابة في الملف — أعد التحضير بـ--writers=10'); process.exit(1)
  }

  // فحص حياة الهدف قبل إطلاق العمّال — أفضل من تقرير مليء بأخطاء شبكة.
  // المهلة هي مهلة الطلب نفسها لا رقماً أقصر: أول طلب على خادم بارد يدفع
  // ثمن الإقلاع كاملاً، ومهلةٌ ضيّقة تعلن «الهدف ساقط» وهو يعمل.
  const t0 = Date.now()
  try {
    const probe = await fetch(opts.url + '/api/me', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${readerTokens[0] ?? ''}` },
      signal: AbortSignal.timeout(opts.timeout),
    })
    if (probe.status >= 500) throw new Error(`الهدف يرجع ${probe.status}`)
  } catch (e) {
    console.error(`✗ الهدف لا يستجيب: ${opts.url}\n  ${e.message}`)
    if (/localhost/.test(opts.url)) {
      console.error('  جرّب 127.0.0.1 بدل localhost: حلّ الاسم قد يتعثّر ثوانيَ على IPv6 فيبدو الهدف ساقطاً.')
    }
    process.exit(1)
  }
  // تعثّر حلّ الاسم يضيف ثوانيَ لكل اتصال جديد فيُفسد القياس نفسه لا الفحص وحده
  const probeMs = Date.now() - t0
  if (probeMs > 2000) {
    console.warn(`⚠ الطلب الأول استغرق ${probeMs}ms — تعثّرُ حلّ اسمٍ أو خادمٌ بارد. الأرقام قد تتضخّم.`)
    if (/localhost/.test(opts.url)) console.warn('  استعمل 127.0.0.1 لتفادي تعثّر حلّ localhost.')
  }

  const nWorkers = Math.max(1, Math.min(opts.workers, opts.vus))
  const perWorker = Math.floor(opts.vus / nWorkers)
  const remainder = opts.vus % nWorkers

  banner(opts, tokens, nWorkers)

  const started = Date.now()
  const results = await Promise.all(
    Array.from({ length: nWorkers }, (_, w) => {
      const count = perWorker + (w < remainder ? 1 : 0)
      const offset = w * perWorker + Math.min(w, remainder)
      return runWorker(opts, { count, offset }, tokens, readerTokens, writerTokens)
    })
  )
  const elapsed = (Date.now() - started) / 1000

  report(merge(results), elapsed, opts, tokens)
}

function runWorker(opts, slice, tokens, readerTokens, writerTokens) {
  return new Promise((res, rej) => {
    // كل ما يعبر إلى العامل بيانات صرفة: الدوالّ لا تنجو من الاستنساخ البنيوي
    const w = new Worker(new URL(import.meta.url), {
      workerData: {
        opts,
        ctx: { sectorId: tokens.sectorId, candidateIds: tokens.candidateIdSample ?? [] },
        slice: { count: slice.count, offset: slice.offset },
        readerTokens,
        writerTokens,
      },
    })
    w.on('message', res)
    w.on('error', rej)
  })
}

function merge(all) {
  const out = new Map()
  for (const workerResult of all) {
    for (const s of workerResult) {
      if (!out.has(s.name)) out.set(s.name, { ok: 0, throttled: 0, clientErr: 0, serverErr: 0, netErr: 0, latencies: [], statuses: {} })
      const t = out.get(s.name)
      t.ok += s.ok; t.throttled += s.throttled; t.clientErr += s.clientErr
      t.serverErr += s.serverErr; t.netErr += s.netErr
      t.latencies.push(...s.latencies)
      for (const [k, v] of Object.entries(s.statuses)) t.statuses[k] = (t.statuses[k] ?? 0) + v
    }
  }
  return out
}

// تصريحات دوالّ لا ثوابت سهمية: الكتلة الرئيسية أعلاه تُنفَّذ قبل الوصول إلى
// هذا السطر، فثابتٌ هنا يقع في المنطقة الميتة الزمنية ويُسقط التقرير بعد
// انتهاء القياس كاملاً — أسوأ لحظة ممكنة لخسارة النتائج.
function pct(sorted, p) {
  return sorted.length ? sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)] : 0
}
function ms(v) { return `${v.toFixed(0)}ms` }
function pad(s, n) { return String(s).padEnd(n) }
function padS(s, n) { return String(s).padStart(n) }

function banner(opts, tokens, nWorkers) {
  const cap = tokens.apiRateLimitPerMinute
  const users = (tokens.readers?.count ?? 0) + (tokens.writers?.count ?? 0)
  console.log(`
╭─ اختبار الحمل — منصّة تمكين الكفاءات
│  الهدف     ${opts.url}
│  الخليط    ${opts.mix}
│  الحمل     ${opts.vus} مستخدماً افتراضياً على ${nWorkers} خيطاً · تصعيد ${opts.ramp}ث · مدة ${opts.duration}ث
│  السقف     ${cap} طلب/دقيقة × ${users} مستخدماً ≈ ${Math.round((cap * users) / 60)} طلب/ثانية قبل 429
╰─`)
}

function report(merged, elapsed, opts, tokens) {
  let T = { ok: 0, throttled: 0, clientErr: 0, serverErr: 0, netErr: 0 }
  const rows = []

  for (const [name, s] of merged) {
    const sorted = s.latencies.slice().sort((a, b) => a - b)
    const total = s.ok + s.throttled + s.clientErr + s.serverErr + s.netErr
    rows.push({
      name, total, rps: total / elapsed,
      ok: s.ok, throttled: s.throttled, clientErr: s.clientErr, serverErr: s.serverErr, netErr: s.netErr,
      p50: pct(sorted, 50), p95: pct(sorted, 95), p99: pct(sorted, 99), max: sorted.at(-1) ?? 0,
      avg: sorted.length ? sorted.reduce((a, b) => a + b, 0) / sorted.length : 0,
      statuses: s.statuses,
    })
    for (const k of Object.keys(T)) T[k] += s[k]
  }

  const grand = T.ok + T.throttled + T.clientErr + T.serverErr + T.netErr
  const allLat = [...merged.values()].flatMap((s) => s.latencies).sort((a, b) => a - b)

  console.log(`\n${pad('السيناريو', 26)}${padS('طلبات', 8)}${padS('ط/ث', 9)}${padS('p50', 9)}${padS('p95', 9)}${padS('p99', 9)}${padS('أقصى', 9)}${padS('429', 7)}${padS('5xx', 6)}`)
  console.log('─'.repeat(92))
  for (const r of rows.sort((a, b) => b.total - a.total)) {
    console.log(
      pad(r.name, 26) + padS(r.total, 8) + padS(r.rps.toFixed(1), 9) +
      padS(ms(r.p50), 9) + padS(ms(r.p95), 9) + padS(ms(r.p99), 9) + padS(ms(r.max), 9) +
      padS(r.throttled, 7) + padS(r.serverErr + r.netErr, 6)
    )
  }
  console.log('─'.repeat(92))

  const okRate = grand ? (T.ok / grand) * 100 : 0
  console.log(`
الإجمالي   ${grand} طلباً في ${elapsed.toFixed(1)}ث  ⇒  ${(grand / elapsed).toFixed(1)} طلب/ثانية
النجاح     ${T.ok} (${okRate.toFixed(1)}%)   ·   p50 ${ms(pct(allLat, 50))} · p95 ${ms(pct(allLat, 95))} · p99 ${ms(pct(allLat, 99))}
الحدّ       ${T.throttled} استجابة 429 (حدّ معدّل يعمل — ليست أعطالاً)
الأعطال    ${T.serverErr} خطأ خادم · ${T.netErr} خطأ شبكة/مهلة · ${T.clientErr} خطأ عميل آخر`)

  // قراءة الأرقام: الأهمّ ألّا تُقرأ 429 كانهيار، ولا 5xx كضجيج
  const notes = []
  if (T.serverErr > 0) notes.push(`⚠ ${T.serverErr} خطأ خادم (5xx) — هذه أعطال حقيقية، افحص storage/logs/laravel.log`)
  if (T.netErr > grand * 0.01) notes.push(`⚠ ${T.netErr} انقطاع/مهلة — الخادم بلغ سقف اتصالاته أو المولّد أسرع منه`)
  if (T.throttled > grand * 0.2) notes.push(`ℹ ${((T.throttled / grand) * 100).toFixed(0)}% من الطلبات حدّها المُقيِّد — ارفع API_RATE_LIMIT أو زد المستخدمين لقياس السعة الخام`)
  if (pct(allLat, 99) > 2000) notes.push(`⚠ p99 يتجاوز ٢ ثانية — تشبّع؛ راجع «الاختناقات المعروفة» في README`)
  if (notes.length) console.log('\n' + notes.join('\n'))

  if (opts.out) {
    const path = resolve(opts.out)
    mkdirSync(dirname(path), { recursive: true })
    writeFileSync(path, JSON.stringify({
      target: opts.url, mix: opts.mix, vus: opts.vus, workers: opts.workers,
      durationSec: elapsed, preparedAt: tokens.preparedAt,
      totals: { ...T, requests: grand, rps: grand / elapsed },
      percentilesMs: { p50: pct(allLat, 50), p90: pct(allLat, 90), p95: pct(allLat, 95), p99: pct(allLat, 99), max: allLat.at(-1) ?? 0 },
      scenarios: rows,
    }, null, 2))
    console.log(`\nالتقرير: ${path}`)
  }
}
