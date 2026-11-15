// Folds the per-change notes into the shared files, once, before a release (owner request 2026-11-15: every PR edited the
// same lines of CHANGELOG.md, STATE.md and DECISIONS.md, so any two open PRs collided).
//
// Each change adds its own new files instead (see changes/README.md):
//   changes/<YYYY-MM-DD>-<topic>.md          → CHANGELOG.md, under "## [Unreleased]", grouped by ### Added / Changed / Fixed / Security
//   docs/state/<YYYY-MM-DD>-<topic>.md       → docs/STATE.md, appended at the end (oldest first)
//   docs/decisions/<YYYY-MM-DD>-<topic>.md   → docs/DECISIONS.md, appended at the end (oldest first)
// The folded files are then deleted. Run from the pos folder on an up-to-date main, in its own PR: npm run notes
// (--dry shows what would change). Nothing else edits those three files, so that PR never collides either.
import { existsSync, readdirSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const dry = process.argv.includes('--dry')
const SECTIONS = ['Added', 'Changed', 'Fixed', 'Security', 'Removed']

const fragments = (dir) => existsSync(join(root, dir))
  ? readdirSync(join(root, dir)).filter((f) => f.endsWith('.md') && f !== 'README.md').sort().map((f) => ({ file: join(root, dir, f), name: f, text: readFileSync(join(root, dir, f), 'utf8').replace(/\r\n/g, '\n').trim() }))
  : []
const read = (rel) => readFileSync(join(root, rel), 'utf8')
const nl = (text) => (text.includes('\r\n') ? '\r\n' : '\n')
const write = (rel, text, eol) => { if (!dry) writeFileSync(join(root, rel), text.replace(/\r?\n/g, eol)) }

// CHANGELOG: every fragment's entries, grouped by kind, at the top of "## [Unreleased]".
const changes = fragments('changes')
if (changes.length) {
  const grouped = Object.fromEntries(SECTIONS.map((s) => [s, []]))
  for (const c of changes) {
    let kind = 'Changed'
    for (const line of c.text.split('\n')) {
      const h = line.match(/^###\s+(\w+)/)
      if (h) {
        kind = SECTIONS.find((s) => s.toLowerCase() === h[1].toLowerCase()) ?? h[1]
        grouped[kind] ??= []
      } else if (line.trim() !== '') {
        grouped[kind].push(line)
      }
    }
  }
  const block = Object.entries(grouped).filter(([, lines]) => lines.length).map(([k, lines]) => `### ${k}\n${lines.join('\n')}`).join('\n')
  const raw = read('CHANGELOG.md')
  const text = raw.replace(/\r\n/g, '\n')
  const at = text.indexOf('## [Unreleased]\n')
  const out = at >= 0
    ? text.slice(0, at) + '## [Unreleased]\n' + block + '\n' + text.slice(at + '## [Unreleased]\n'.length)
    : text.replace(/^(# [^\n]*\n\n?)/, `$1## [Unreleased]\n${block}\n\n`)
  write('CHANGELOG.md', out, nl(raw))
  console.log(`CHANGELOG.md: ${changes.length} change note(s) added under [Unreleased]`)
}

// STATE and DECISIONS: each note appended at the end, oldest first.
for (const [dir, target] of [['docs/state', 'docs/STATE.md'], ['docs/decisions', 'docs/DECISIONS.md']]) {
  const notes = fragments(dir)
  if (!notes.length) continue
  const raw = read(target)
  write(target, raw.replace(/\r\n/g, '\n').trimEnd() + '\n\n' + notes.map((n) => n.text).join('\n\n') + '\n', nl(raw))
  console.log(`${target}: ${notes.length} note(s) appended`)
}

const all = [...changes, ...fragments('docs/state'), ...fragments('docs/decisions')]
if (!all.length) console.log('No notes to fold in.')
else if (dry) console.log('(dry run: nothing written or deleted)')
else for (const f of all) unlinkSync(f.file)
