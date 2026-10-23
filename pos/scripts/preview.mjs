// One-click preview (owner request 2026-10-23): builds the till into the server, brings the database up to date, then
// runs the server on http://127.0.0.1:8010 — the back office at /admin and the till at /pos/, one address, no separate
// till server. Used by the "ERP preview" entry in .claude/launch.json (the Claude app's browser pane) or by hand:
//   node pos/scripts/preview.mjs
import { spawn, spawnSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const pos = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const server = resolve(pos, '../server')
const win = process.platform === 'win32'
const run = (cmd, args, cwd) => {
  const r = spawnSync(cmd, args, { cwd, stdio: 'inherit', shell: win })
  if (r.status !== 0) process.exit(r.status ?? 1)
}

console.log('1/3 Building the till into the server…')
run('npm', ['run', 'build:server'], pos)
console.log('2/3 Updating the database…')
run('php', ['artisan', 'migrate', '--force'], server)
console.log('3/3 Back office: http://127.0.0.1:8010/admin   Till: http://127.0.0.1:8010/pos/')
const s = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8010'], { cwd: server, stdio: 'inherit', shell: win })
s.on('exit', (code) => process.exit(code ?? 0))
