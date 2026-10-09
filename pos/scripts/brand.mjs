// Product brand: turns the master artwork in branding/product/ (mark.svg, logo.svg, logo-dark.svg, brand.json) into every
// file the product needs — back office and till favicons, home-screen icons (incl. maskable), the Windows icon for the
// installer, the desktop till's icons, and the logo used on the guide covers. Run from the pos folder: npm run brand.
// To change the product logo: replace the three SVGs (keep the mark square), adjust brand.json, run this, commit.
import { copyFileSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'
import { chromium } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../..')
const src = join(root, 'branding/product')
const brand = JSON.parse(readFileSync(join(src, 'brand.json'), 'utf8'))
const mark = readFileSync(join(src, 'mark.svg'), 'utf8')
const out = { server: join(root, 'server/public/brand'), pos: join(root, 'pos/public'), tauri: join(root, 'pos/src-tauri'), docs: join(root, 'docs/manual/img') }
for (const d of Object.values(out)) mkdirSync(d, { recursive: true })

const browser = await chromium.launch()
const page = await browser.newPage()

/** Render SVG markup to a PNG of size×size; `pad` (0–0.5) shrinks the art onto a solid background (maskable icons). */
async function png(svg, size, { pad = 0, background = 'transparent' } = {}) {
  const inner = Math.round(size * (1 - 2 * pad))
  await page.setViewportSize({ width: size, height: size })
  await page.setContent(`<html><body style="margin:0;width:${size}px;height:${size}px;display:grid;place-items:center;background:${background}">
    <div style="width:${inner}px;height:${inner}px">${svg.replace('<svg ', `<svg width="${inner}" height="${inner}" `)}</div></body></html>`)
  return page.screenshot({ omitBackground: background === 'transparent', type: 'png' })
}

/** A Windows .ico holding PNG images (supported since Windows Vista). */
function ico(images) {
  const header = Buffer.alloc(6 + 16 * images.length)
  header.writeUInt16LE(0, 0); header.writeUInt16LE(1, 2); header.writeUInt16LE(images.length, 4)
  let offset = header.length
  images.forEach(([size, data], i) => {
    const e = 6 + 16 * i
    header.writeUInt8(size >= 256 ? 0 : size, e); header.writeUInt8(size >= 256 ? 0 : size, e + 1)
    header.writeUInt16LE(1, e + 4); header.writeUInt16LE(32, e + 6); header.writeUInt32LE(data.length, e + 8); header.writeUInt32LE(offset, e + 12)
    offset += data.length
  })
  return Buffer.concat([header, ...images.map(([, d]) => d)])
}

// Back office and server-served pages.
for (const f of ['mark.svg', 'logo.svg', 'logo-dark.svg']) copyFileSync(join(src, f), join(out.server, f))
copyFileSync(join(src, 'mark.svg'), join(out.server, 'favicon.svg'))
writeFileSync(join(out.server, 'favicon-32.png'), await png(mark, 32))
writeFileSync(join(out.server, 'apple-touch-icon.png'), await png(mark, 180, { pad: 0.06, background: brand.tile }))
writeFileSync(join(out.server, 'icon-192.png'), await png(mark, 192))
writeFileSync(join(out.server, 'icon-512.png'), await png(mark, 512))
writeFileSync(join(out.server, 'icon-maskable-512.png'), await png(mark, 512, { pad: 0.1, background: brand.tile }))
const icoFile = ico([[16, await png(mark, 16)], [32, await png(mark, 32)], [48, await png(mark, 48)], [256, await png(mark, 256)]])
writeFileSync(join(root, 'server/public/favicon.ico'), icoFile)
writeFileSync(join(root, 'installer/windows/app.ico'), icoFile)

// The till (PWA): its own copies, so it works offline and from the Vite dev server.
copyFileSync(join(src, 'mark.svg'), join(out.pos, 'favicon.svg'))
copyFileSync(join(src, 'logo.svg'), join(out.pos, 'brand-logo.svg'))
copyFileSync(join(src, 'logo-dark.svg'), join(out.pos, 'brand-logo-dark.svg'))
writeFileSync(join(out.pos, 'icon-192.png'), await png(mark, 192))
writeFileSync(join(out.pos, 'icon-512.png'), await png(mark, 512))
writeFileSync(join(out.pos, 'icon-maskable-512.png'), await png(mark, 512, { pad: 0.1, background: brand.tile }))

// Guide and training covers.
copyFileSync(join(src, 'logo.svg'), join(out.docs, 'product-logo.svg'))

// Desktop till: its name and publisher come from brand.json (window title, Start menu, installer). The publisher is
// the company (as Windows shows "Publisher" in Apps & features), the product name stays on everything else.
const slug = brand.name.toLowerCase().replace(/[^a-z0-9]+/g, '')
const confPath = join(out.tauri, 'tauri.conf.json')
const conf = JSON.parse(readFileSync(confPath, 'utf8'))
Object.assign(conf, { productName: `${brand.name} Till`, identifier: `app.${slug}.till` })
const company = brand.company ?? brand.name
Object.assign(conf.bundle, { publisher: company, copyright: `Copyright © ${company}`, shortDescription: `${brand.name} till`,
  longDescription: `The ${brand.name} till with silent receipt printing (ESC/POS), cash drawer and scale support. It opens the till from your business server.` })
writeFileSync(confPath, JSON.stringify(conf, null, 2) + '\n')

// Desktop till: Tauri generates every platform icon from one 1024 px PNG.
writeFileSync(join(out.tauri, 'app-icon.png'), await png(mark, 1024))
await browser.close()
try {
  execFileSync(process.platform === 'win32' ? 'npx.cmd tauri icon app-icon.png' : 'npx tauri icon app-icon.png', { cwd: out.tauri, stdio: 'inherit', shell: true })
  rmSync(join(out.tauri, 'icons/android'), { recursive: true, force: true }) // Windows only: no phone icons
  rmSync(join(out.tauri, 'icons/ios'), { recursive: true, force: true })
} catch {
  console.warn('tauri icon failed — run "npx tauri icon app-icon.png" in pos/src-tauri')
}
console.log(`Product brand "${brand.name}" written to server/public/brand, pos/public, pos/src-tauri/icons, installer/windows/app.ico`)
