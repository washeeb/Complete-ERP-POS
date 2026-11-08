// Copies the ZionEX loader (branding/product/loader.svg) to every place that shows it, so there is one source:
//   - server/public/brand/loader.svg      the back office inlines it (resources/views/branding/loader.blade.php)
//   - pos/src/assets/loader.svg           the till inlines it (LogoLoader.tsx imports it as text)
//   - pos/index.html                      shown while the till's code loads (between the zx-loader markers)
//   - desktop/src-tauri/setup/index.html  ZionEX Suite's opening screen (between the zx-loader markers)
//   - pos/src-tauri/setup/index.html      ZionEX Till's opening screen (between the zx-loader markers)
// Run from the pos folder: npm run brand:loader (npm run brand runs it too).
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const svg = readFileSync(join(root, 'branding/product/loader.svg'), 'utf8').trim()
// Pages get it without the comment, on fewer lines.
const inline = svg.replace(/<!--[\s\S]*?-->\s*/g, '').replace(/\n\s*/g, ' ')

writeFileSync(join(root, 'server/public/brand/loader.svg'), svg + '\n')
writeFileSync(join(root, 'pos/src/assets/loader.svg'), svg + '\n')
for (const page of ['pos/index.html', 'desktop/src-tauri/setup/index.html', 'pos/src-tauri/setup/index.html']) {
  const file = join(root, page)
  const html = readFileSync(file, 'utf8')
  const re = /(<!-- zx-loader -->)[\s\S]*?(<!-- \/zx-loader -->)/
  if (!re.test(html)) throw new Error(`${page}: no <!-- zx-loader --> … <!-- /zx-loader --> markers`)
  writeFileSync(file, html.replace(re, (_, a, b) => `${a}${inline}${b}`))
}
console.log('Loader copied to the back office, the till and ZionEX Suite')
