// Builds the training material (docs/training/*.md) into one PDF each, in docs/training/pdf/:
// the cashier quick card (two columns, to print and keep at the till), the role guides and the video scripts.
// Run from the pos folder: npm run training. Same tools as the installation guide (marked + Playwright's Chromium).
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { basename, dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { marked } from 'marked'
import { chromium } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))
const dir = resolve(here, '../../docs/training')
const outDir = join(dir, 'pdf')
mkdirSync(outDir, { recursive: true })
const files = readdirSync(dir).filter((f) => /^\d\d-.*\.md$/.test(f)).sort()
if (!files.length) throw new Error(`No training files in ${dir}`)
const changelog = readFileSync(resolve(here, '../../CHANGELOG.md'), 'utf8')
const release = changelog.match(/^## \[([^\]]+)\]/m)?.[1] ?? ''
const brand = JSON.parse(readFileSync(resolve(here, '../../branding/product/brand.json'), 'utf8'))

const base = `
  body { font: 10.5pt/1.5 "Segoe UI", Arial, sans-serif; color: #1f2937; margin: 0; }
  h1 { font-size: 18pt; color: #0f3d5e; border-bottom: 3px solid #0f3d5e; padding-bottom: 4px; margin: 0 0 8px; }
  h2 { font-size: 12.5pt; color: #0f3d5e; margin: 14px 0 4px; break-after: avoid; }
  table { border-collapse: collapse; width: 100%; margin: 6px 0; font-size: 9.5pt; }
  th, td { border: 1px solid #d1d5db; padding: 3px 6px; text-align: left; vertical-align: top; }
  th { background: #e5edf3; } tr { break-inside: avoid; }
  code { font: 9pt Consolas, monospace; background: #f3f4f6; padding: 0 3px; border-radius: 3px; }
  ul, ol { padding-left: 18px; } li { margin: 2px 0; }`
// The quick card: small type in two columns so it fits on both sides of one A4 sheet.
const card = `
  body { font-size: 9pt; line-height: 1.35; } h1 { font-size: 15pt; } h2 { font-size: 10.5pt; margin-top: 8px; }
  .content { column-count: 2; column-gap: 8mm; } .content h2, .content table, .content li { break-inside: avoid; }
  table { font-size: 8.5pt; }`

const browser = await chromium.launch()
for (const f of files) {
  const md = readFileSync(join(dir, f), 'utf8')
  const isCard = md.includes('<!-- layout: card -->')
  const [, title = basename(f, '.md'), rest = md] = md.match(/^(?:<!--.*-->\s*)?#\s+(.+)\n([\s\S]*)$/) ?? []
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${title}</title><style>${base}${isCard ? card : ''}</style></head>
<body><h1>${title}</h1><div class="content">${marked.parse(rest)}</div></body></html>`
  const tmp = join(dir, `.${basename(f, '.md')}.html`)
  writeFileSync(tmp, html)
  const page = await browser.newPage()
  await page.goto(pathToFileURL(tmp).href, { waitUntil: 'load' })
  const out = join(outDir, `${basename(f, '.md').replace(/^\d\d-/, '')}.pdf`)
  await page.pdf({ path: out, format: 'A4', printBackground: true, displayHeaderFooter: true, headerTemplate: '<div></div>',
    footerTemplate: `<div style="font:7.5pt Segoe UI,Arial;color:#6b7280;width:100%;padding:0 12mm;display:flex;justify-content:space-between"><span>${brand.name} · ${title}${release ? ` · version ${release}` : ''}</span><span><span class="pageNumber"></span>/<span class="totalPages"></span></span></div>`,
    margin: isCard ? { top: '10mm', bottom: '12mm', left: '10mm', right: '10mm' } : { top: '16mm', bottom: '18mm', left: '15mm', right: '15mm' } })
  await page.close()
  rmSync(tmp, { force: true })
  console.log(`Wrote ${out}`)
}
await browser.close()
