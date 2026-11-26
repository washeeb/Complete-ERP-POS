// Write server/resources/office-routes.json: every page of the current back office (/admin, GET) with the address of its
// page in the new back office, worked out by the new back office's own address map (office/src/paths.ts, appPathFor). The
// server uses it (App\Support\OfficeUrl) so that what it sends — notifications, links, tiles — opens the new look, from
// one map. `npm run office:routes` after adding a page; the tests fail while the file is stale.
import { execFileSync } from 'node:child_process'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const server = resolve(here, '../../server')
const raw = execFileSync('php', ['artisan', 'route:list', '--json', '--path=admin', '--method=GET'], { cwd: server, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, shell: process.platform === 'win32' })
const routes = JSON.parse(raw).map((r) => '/' + r.uri).filter((u) => u.startsWith('/admin'))
const list = join(mkdtempSync(join(tmpdir(), 'office-routes-')), 'admin-routes.json')
writeFileSync(list, JSON.stringify([...new Set(routes)].sort()))
execFileSync(process.platform === 'win32' ? 'npx.cmd' : 'npx', ['vitest', 'run', '-c', 'vite.office.config.ts', 'office/src/office-routes.test.ts'],
  { cwd: resolve(here, '..'), stdio: 'inherit', env: { ...process.env, OFFICE_ROUTES_WRITE: list }, shell: process.platform === 'win32' })
console.log('Wrote server/resources/office-routes.json')
