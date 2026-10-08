// Builds the installation & setup manual (docs/manual/*.md, in file-name order) into one PDF:
//   docs/manual/Synclytics-ERP-POS-Installation-Guide.pdf
// Run from the pos folder: npm run manual
// Markdown is turned into HTML (marked) and printed to A4 by the Chromium that Playwright already installed for tests.
import { readdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs'
import { join, resolve, dirname } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { marked } from 'marked'
import { chromium } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))
const dir = resolve(here, '../../docs/manual')
const out = join(dir, 'Synclytics-ERP-POS-Installation-Guide.pdf')
const files = readdirSync(dir).filter((f) => /^\d\d-.*\.md$/.test(f)).sort()
if (!files.length) throw new Error(`No chapters found in ${dir}`)

// Callouts: a paragraph starting with **Why:**, **Note:**, **Warning:** or **Tip:** gets its own box.
const slug = (s) => s.toLowerCase().replace(/<[^>]+>/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
const toc = []
marked.use({
  renderer: {
    heading({ tokens, depth }) {
      const text = this.parser.parseInline(tokens)
      const id = slug(text)
      if (depth <= 2) toc.push({ depth, text, id })
      return `<h${depth} id="${id}">${text}</h${depth}>\n`
    },
    paragraph({ tokens }) {
      const html = this.parser.parseInline(tokens)
      const m = html.match(/^<strong>(Why|Note|Warning|Tip|Check):<\/strong>/)
      return m ? `<div class="callout ${m[1].toLowerCase()}"><p>${html}</p></div>\n` : `<p>${html}</p>\n`
    },
  },
})

const version = JSON.parse(readFileSync(resolve(here, '../../server/modules/Core/module.json'), 'utf8')).version ?? ''
const changelog = readFileSync(resolve(here, '../../CHANGELOG.md'), 'utf8')
const release = (changelog.match(/^## \[([^\]]+)\] — (\S+)/m) ?? [])
const chapters = files.map((f) => `<section class="chapter">${marked.parse(readFileSync(join(dir, f), 'utf8'))}</section>`).join('\n')
const tocHtml = toc.map((t) => `<li class="d${t.depth}"><a href="#${t.id}">${t.text}</a></li>`).join('')

const html = `<!doctype html><html><head><meta charset="utf-8"><title>Synclytics ERP + POS — Installation & Setup Guide</title>
<base href="${pathToFileURL(dir + '/').href}">
<style>
  @page { size: A4; margin: 18mm 16mm 20mm; }
  body { font: 10.5pt/1.5 "Segoe UI", Arial, sans-serif; color: #1f2937; }
  h1 { font-size: 20pt; color: #0f3d5e; border-bottom: 3px solid #0f3d5e; padding-bottom: 4px; margin-top: 0; }
  h2 { font-size: 14pt; color: #0f3d5e; margin-top: 22px; break-after: avoid; }
  h3 { font-size: 11.5pt; margin-top: 16px; break-after: avoid; }
  h4 { font-size: 10.5pt; margin-top: 12px; break-after: avoid; }
  .chapter { break-before: page; }
  code { font: 9pt Consolas, "Courier New", monospace; background: #f3f4f6; padding: 0 3px; border-radius: 3px; }
  pre { background: #111827; color: #f9fafb; padding: 8px 10px; border-radius: 6px; white-space: pre-wrap; word-break: break-all; break-inside: avoid; }
  pre code { background: none; color: inherit; padding: 0; }
  table { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 9.5pt; break-inside: auto; }
  th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
  th { background: #e5edf3; }
  tr { break-inside: avoid; }
  .callout { border-left: 4px solid; padding: 4px 10px; margin: 8px 0; border-radius: 4px; break-inside: avoid; }
  .callout p { margin: 4px 0; }
  .why { border-color: #2563eb; background: #eff6ff; } .note, .check { border-color: #6b7280; background: #f9fafb; }
  .warning { border-color: #dc2626; background: #fef2f2; } .tip { border-color: #16a34a; background: #f0fdf4; }
  img { max-width: 100%; border: 1px solid #d1d5db; border-radius: 4px; margin: 6px 0; break-inside: avoid; }
  .cover { height: 250mm; display: flex; flex-direction: column; justify-content: center; text-align: center; }
  .cover h1 { border: none; font-size: 30pt; } .cover .sub { font-size: 15pt; color: #374151; } .cover .meta { margin-top: 40px; color: #6b7280; }
  .toc { break-before: page; } .toc ol { list-style: none; padding: 0; } .toc li.d1 { font-weight: 600; margin-top: 8px; } .toc li.d2 { margin-left: 18px; font-size: 9.5pt; }
  .toc a { color: inherit; text-decoration: none; }
  a { color: #1d4ed8; }
</style></head><body>
<div class="cover"><h1>Synclytics ERP + POS</h1><div class="sub">Installation &amp; Setup Guide</div>
<div class="sub">Requirements · Definitions · Every installation scenario · Modules · Devices · Daily running · Troubleshooting</div>
<div class="meta">Software version ${release[1] ?? version} (${release[2] ?? ''}) · Guide generated ${new Date().toISOString().slice(0, 10)}<br>This guide is updated with every release.</div></div>
<nav class="toc"><h1>Contents</h1><ol>${tocHtml}</ol></nav>
${chapters}
</body></html>`

const htmlFile = join(dir, '.manual.html')
writeFileSync(htmlFile, html)
const browser = await chromium.launch()
const page = await browser.newPage()
await page.goto(pathToFileURL(htmlFile).href, { waitUntil: 'load' })
await page.pdf({ path: out, format: 'A4', printBackground: true, displayHeaderFooter: true,
  headerTemplate: '<div></div>',
  footerTemplate: '<div style="font:8pt Segoe UI,Arial;color:#6b7280;width:100%;padding:0 16mm;display:flex;justify-content:space-between"><span>Synclytics ERP + POS — Installation &amp; Setup Guide</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>',
  margin: { top: '18mm', bottom: '20mm', left: '16mm', right: '16mm' } })
await browser.close()
rmSync(htmlFile, { force: true }) // keep the folder tidy
console.log(`Wrote ${out} (${files.length} chapters)`)
