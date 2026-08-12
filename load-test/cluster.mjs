#!/usr/bin/env node
// ════════════════════════════════════════════════════════════
//  تشغيل عنقود محلي: N نسخة من التطبيق + موزّع أمامها.
//
//  ينهي كل ما شغّله عند Ctrl-C — عمليات PHP يتيمة على منافذ مشغولة
//  تُفسد كل تشغيل تالٍ وتجعل النتائج غير قابلة للتكرار.
// ════════════════════════════════════════════════════════════
import { spawn } from 'node:child_process'
import { createConnection } from 'node:net'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
const appRoot = resolve(here, '..')

const args = Object.fromEntries(
  process.argv.slice(2).map((a) => {
    const m = /^--([a-zA-Z-]+)(?:=(.*))?$/.exec(a)
    return m ? [m[1], m[2] ?? true] : [a, true]
  })
)

const NODES = Number(args.nodes ?? 4)
const BASE_PORT = Number(args['base-port'] ?? 8001)
const LB_PORT = Number(args.port ?? 8080)

const ports = Array.from({ length: NODES }, (_, i) => BASE_PORT + i)
const children = []

const portOpen = (port) =>
  new Promise((res) => {
    const s = createConnection({ host: '127.0.0.1', port })
    s.on('connect', () => { s.destroy(); res(true) })
    s.on('error', () => res(false))
    setTimeout(() => { s.destroy(); res(false) }, 500)
  })

async function waitPort(port, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    if (await portOpen(port)) return true
    await new Promise((r) => setTimeout(r, 250))
  }
  return false
}

// منفذ مشغول مسبقاً يجعل النسخة تفشل صامتة ويذهب حملها لغيرها
for (const p of [...ports, LB_PORT]) {
  if (await portOpen(p)) {
    console.error(`✗ المنفذ ${p} مشغول. أوقف ما يستعمله أو غيّر --base-port/--port.`)
    process.exit(1)
  }
}

console.log(`\n▶ تشغيل ${NODES} نسخة من التطبيق…`)
for (const port of ports) {
  const child = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
    cwd: appRoot,
    stdio: ['ignore', 'ignore', 'pipe'],
    // بيئة القياس: بلا تنقيح ولا كتابة سجلّات مطوّلة تُبطئ كل طلب
    env: { ...process.env, APP_DEBUG: 'false', LOG_LEVEL: 'error' },
  })
  child.stderr.on('data', (d) => {
    const s = String(d)
    if (/error|exception|fatal/i.test(s)) process.stderr.write(`[:${port}] ${s}`)
  })
  children.push(child)
}

for (const port of ports) {
  const up = await waitPort(port)
  console.log(`  ${up ? '✓' : '✗'} 127.0.0.1:${port}`)
  if (!up) { shutdown(1); }
}

console.log('▶ تشغيل الموزّع…')
const lb = spawn('node', [resolve(here, 'balancer.mjs'), `--port=${LB_PORT}`, `--backends=${ports.join(',')}`], {
  stdio: 'inherit',
})
children.push(lb)

if (!(await waitPort(LB_PORT))) {
  console.error('✗ الموزّع لم يستجب')
  shutdown(1)
}

console.log(`العنقود جاهز. وجّه الاختبار إلى:  http://localhost:${LB_PORT}
مثال:  node load-test/loadtest.mjs --url=http://localhost:${LB_PORT} --vus=100 --duration=60
اضغط Ctrl-C للإيقاف.\n`)

let shuttingDown = false
function shutdown(code = 0) {
  if (shuttingDown) return
  shuttingDown = true
  console.log('\n■ إيقاف العنقود…')
  // SIGTERM أولاً ليطبع الموزّع ملخّص التوزيع، ثم قتلٌ حاسم لمن تعلّق
  for (const c of children) { try { c.kill('SIGTERM') } catch {} }
  setTimeout(() => {
    for (const c of children) { try { c.kill('SIGKILL') } catch {} }
    process.exit(code)
  }, 1200)
}

process.on('SIGINT', () => shutdown(0))
process.on('SIGTERM', () => shutdown(0))
