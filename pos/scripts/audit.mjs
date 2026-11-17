// npm audit as CI runs it: any high or critical advisory fails the build, except one listed below. A waiver names the
// advisory, says why it does not reach the shop, and ends on a date, so it is looked at again rather than forgotten.
// Run: npm run audit (the pos folder).
import { execSync } from 'node:child_process'

/** Advisories with no fixed release yet that only reach build tools, never the till or the browser. */
const WAIVED = {
  'GHSA-fc5q-39xc-vjpf': {
    until: '2026-12-31',
    why: 'picomatch <=4.0.7 ReDoS; 4.0.7 is the newest release. Only vite, vitest and the PWA builder use it, at build time, on our own '
      + 'file patterns: nothing a customer or user types reaches it, and it is not shipped (npm audit --omit=dev finds nothing).',
  },
}

let report
try {
  report = JSON.parse(execSync('npm audit --json', { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }))
} catch (e) {
  report = JSON.parse(e.stdout) // npm audit exits non-zero when it finds anything; the report is still on stdout
}

const today = new Date().toISOString().slice(0, 10)
const serious = new Map() // advisory id → { title, severity, packages }
for (const [name, v] of Object.entries(report.vulnerabilities ?? {})) {
  for (const via of v.via) {
    if (typeof via !== 'object' || !['high', 'critical'].includes(via.severity)) continue
    const id = (via.url ?? '').split('/').pop() || via.title
    const entry = serious.get(id) ?? { title: via.title, severity: via.severity, packages: new Set() }
    entry.packages.add(name)
    serious.set(id, entry)
  }
}

const failing = []
for (const [id, a] of serious) {
  const w = WAIVED[id]
  if (w && today <= w.until) console.log(`waived until ${w.until}: ${id} (${a.severity}) ${a.title} — ${w.why}`)
  else failing.push(`${id} (${a.severity}) ${a.title} in ${[...a.packages].join(', ')}${w ? ` — its waiver ended ${w.until}` : ''}`)
}
// Packages flagged only because they depend on a waived one are covered by that waiver; anything else fails.
if (failing.length) {
  console.error(`npm audit: ${failing.length} high or critical advisory(ies) to fix:\n- ${failing.join('\n- ')}`)
  process.exit(1)
}
console.log(`npm audit: no high or critical advisories outside the waivers (${Object.keys(report.vulnerabilities ?? {}).length} package(s) flagged in all).`)
