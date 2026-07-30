#!/usr/bin/env node
// ════════════════════════════════════════════════════════════
//  موزّع أحمال بسيط (round-robin) بلا اعتماديات.
//
//  سببه: `php artisan serve` خادم PHP المدمج — خيطٌ واحد يُسلسل كل الطلبات.
//  قياس الضغط عليه يقيس الخادم التطويري لا التطبيق. فنشغّل عدّة نسخ على
//  منافذ متتالية ونضع هذا الموزّع أمامها، فنقيس التوسّع الأفقي فعلياً.
//
//  ليس بديلاً عن nginx/HAProxy في الإنتاج — هو أداة قياس محلية تُحاكي شكل
//  النشر (عدّة عمّال خلف موزّع) بلا تنصيب شيء.
//
//  فحص الصحّة: عاملٌ ساقط يخرج من الدوران ويعود إليه حين يتعافى — وإلا
//  ذهب ثلث الحمل إلى منفذ ميت وظهر التطبيق منهاراً وهو سليم.
// ════════════════════════════════════════════════════════════
import http from 'node:http'

const args = Object.fromEntries(
  process.argv.slice(2).map((a) => {
    const m = /^--([a-zA-Z-]+)(?:=(.*))?$/.exec(a)
    return m ? [m[1], m[2] ?? true] : [a, true]
  })
)

const PORT = Number(args.port ?? 8080)
const BACKENDS = String(args.backends ?? '8001,8002,8003')
  .split(',')
  .map((p) => p.trim())
  .filter(Boolean)
  .map((p) => (p.includes(':') ? p : `127.0.0.1:${p}`))

if (BACKENDS.length === 0) {
  console.error('✗ لا خوادم خلفية. مثال: --backends=8001,8002,8003')
  process.exit(1)
}

const state = BACKENDS.map((b) => ({ target: b, healthy: true, served: 0, failed: 0 }))
let cursor = 0

// تحذير واحد لكل سبب: طباعة سطر لكل طلب فاشل تحت الضغط تُغرق الطرفية
// وتُبطئ الموزّع نفسه حتى يصير هو الاختناق الذي نقيسه.
const warned = new Set()
function warnOnce(key, msg) {
  if (warned.has(key)) return
  warned.add(key)
  console.error(msg)
}

// الاتصالات مُبقاة حيّة: فتحُ اتصال TCP لكل طلب يجعل الموزّع هو الاختناق
const agent = new http.Agent({ keepAlive: true, maxSockets: 1024, keepAliveMsecs: 30000 })

function nextHealthy() {
  for (let i = 0; i < state.length; i++) {
    const s = state[cursor++ % state.length]
    if (s.healthy) return s
  }
  return null // الكل ساقط — نردّ 503 بدل أن نُعلّق الطلب
}

const server = http.createServer((req, res) => {
  const s = nextHealthy()
  if (!s) {
    res.writeHead(503, { 'Content-Type': 'application/json; charset=utf-8' })
    res.end(JSON.stringify({ error: 'لا يوجد خادم خلفي سليم' }))
    return
  }

  const [host, port] = s.target.split(':')
  const proxy = http.request(
    {
      host, port, agent,
      method: req.method,
      path: req.url,
      // ترويسات التتبّع: الخادم يثق بالوسيط (trustProxies) فيفهرس حدّ المعدّل
      // على IP العميل الحقيقي لا على الموزّع — وإلا بدا كل الحمل من مصدر واحد
      headers: {
        ...req.headers,
        'x-forwarded-for': req.socket.remoteAddress,
        'x-forwarded-proto': 'http',
        'x-forwarded-host': req.headers.host ?? '',
        'x-load-balancer-backend': s.target,
      },
    },
    (up) => {
      s.served++
      // ترويسات الخادم الخلفي تُنقَل إلا ما يخصّ الاتصال نفسه: نسخ
      // connection/transfer-encoding من اتصالٍ إلى آخر يُنتج ردّاً غير صالح
      // فيقطعه العميل — وهو انقطاع صامت يبدو كسقوط الخادم.
      const out = { ...up.headers, 'x-served-by': s.target }
      delete out['connection']
      delete out['keep-alive']
      delete out['transfer-encoding']
      delete out['content-length'] // يُعاد حسابه: الطول الأصلي قد لا يطابق ما نُمرّره
      try {
        res.writeHead(up.statusCode ?? 502, out)
      } catch (e) {
        console.error(`  ✗ ترويسة غير صالحة من ${s.target}: ${e.message}`)
        res.destroy()
        return
      }
      up.pipe(res)
      up.on('error', () => res.destroy())
    }
  )

  proxy.on('error', (err) => {
    s.failed++
    // الصمت هنا كان يجعل كل فشلٍ يبدو «سقوط خادم» بلا سبب — نُظهره مرّة لكل نوع
    warnOnce(`proxy:${err.code}`, `  ✗ فشل التمرير إلى ${s.target}: ${err.code ?? err.message}`)
    if (!res.headersSent) {
      res.writeHead(502, { 'Content-Type': 'application/json; charset=utf-8' })
      res.end(JSON.stringify({ error: 'تعذّر الوصول للخادم الخلفي', backend: s.target, reason: err.code }))
    } else {
      res.destroy()
    }
  })

  req.pipe(proxy)
})

// لا مهلة خمول تقطع طلباً بطيئاً أثناء القياس
server.keepAliveTimeout = 65000
server.headersTimeout = 70000

// أخطاء العميل (اتصال يُقطع منتصف الطلب) شائعة تحت الضغط ولا يجوز أن تُسقط الموزّع
server.on('clientError', (err, socket) => {
  warnOnce(`client:${err.code}`, `  ✗ خطأ عميل: ${err.code ?? err.message}`)
  if (socket.writable) socket.end('HTTP/1.1 400 Bad Request\r\n\r\n')
})
process.on('uncaughtException', (e) => {
  console.error(`  ✗ استثناء غير مُلتقَط في الموزّع: ${e.stack ?? e.message}`)
})

// ── فحص الصحّة الدوري ──
function probe(s) {
  const [host, port] = s.target.split(':')
  const req = http.request({ host, port, path: '/api/me', method: 'GET', timeout: 2000 }, (r) => {
    // أي ردّ (حتى 401) يعني أن PHP يستجيب — المطلوب حياة العملية لا نجاح المصادقة
    const alive = (r.statusCode ?? 0) > 0
    if (alive !== s.healthy) console.log(`  ${alive ? '✓ عاد' : '✗ سقط'}  ${s.target}`)
    s.healthy = alive
    r.resume()
  })
  req.on('error', () => { if (s.healthy) console.log(`  ✗ سقط  ${s.target}`); s.healthy = false })
  req.on('timeout', () => req.destroy())
  req.end()
}

setInterval(() => state.forEach(probe), 3000).unref()
state.forEach(probe)

server.listen(PORT, () => {
  console.log(`\n⚖  الموزّع يستمع على http://localhost:${PORT}`)
  console.log(`   الخوادم الخلفية: ${BACKENDS.join('، ')}\n`)
})

// ملخّص التوزيع عند الإيقاف — به يُتحقّق أن الحمل وُزّع فعلاً بالتساوي
function summary() {
  const total = state.reduce((a, s) => a + s.served, 0) || 1
  console.log('\n── توزيع الحمل ──')
  for (const s of state) {
    console.log(`  ${s.target}  ${String(s.served).padStart(8)} طلب  (${((s.served / total) * 100).toFixed(1)}%)`
      + (s.failed ? `  · ${s.failed} فشل` : ''))
  }
  process.exit(0)
}
process.on('SIGINT', summary)
process.on('SIGTERM', summary)
