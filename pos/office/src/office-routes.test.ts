import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { appPathFor } from './paths'

// server/resources/office-routes.json: each page of the current back office with its page in the new one, by this app's
// own address map — the one map the server uses too (App\Support\OfficeUrl). `npm run office:routes` writes it; this test
// fails while it is stale (a page added or moved here without writing it again).

const FILE = resolve(__dirname, '../../../server/resources/office-routes.json')
const IDS = Array.from({ length: 6 }, (_, i) => `0190c3a4-5b6d-7e8f-9a0b-1c2d3e4f5a6${i}`)

type Entry = { admin: string; office: string | null }

/** A template such as /admin/products/{record}/edit and its new-look template, e.g. /office/products/{record}/edit. */
export function officeTemplate(admin: string): string | null {
  const params: string[] = []
  const filled = admin.replace(/\{([^}]+)\}/g, (_, name: string) => { params.push(name); return IDS[params.length - 1] })
  let office = appPathFor(filled)
  if (office.startsWith('/office/a/')) return null // no page of its own in the new look
  params.forEach((name, i) => { office = office.split(IDS[i]).join(`{${name.replace(/\?$/, '')}}`) })
  return office
}

describe('office-routes.json', () => {
  const write = process.env.OFFICE_ROUTES_WRITE
  if (write) {
    it('is written from the current back office\'s routes', () => {
      const admin = JSON.parse(readFileSync(write, 'utf8')) as string[]
      const routes: Entry[] = admin.map((a) => ({ admin: a, office: officeTemplate(a) }))
      writeFileSync(FILE, `${JSON.stringify({ note: 'Written by npm run office:routes (pos); do not edit by hand.', routes }, null, 1)}\n`)
    })
    return
  }
  it('matches the new back office\'s address map', () => {
    const { routes } = JSON.parse(readFileSync(FILE, 'utf8')) as { routes: Entry[] }
    expect(routes.length).toBeGreaterThan(100)
    expect(routes.filter((r) => r.office !== officeTemplate(r.admin)).map((r) => r.admin)).toEqual([])
  })
})
