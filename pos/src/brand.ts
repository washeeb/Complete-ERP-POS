import { db, type CacheRecord } from './db'
import type { Bootstrap } from './types'

/**
 * The company's brand on the till: the logo on screen and on the printed receipt, the brand colour, and the small
 * "Powered by" line. Logos are kept in the till's cache as data URLs so receipts keep their logo while offline.
 * For thermal printers the logo is turned into black-and-white dots (Floyd–Steinberg dithering) at the printer's width.
 */

export type Raster = { width: number; height: number; bytesPerRow: number; data: Uint8Array }

type BrandCache = Extract<CacheRecord, { id: `brand:${string}` }>

/** The image at `url` as a data URL, from the network (and then cached) or from the cache when offline. */
export async function cachedImage(url: string | null | undefined): Promise<string | null> {
  if (!url) return null
  const key = `brand:${url.split('?')[0]}` as const
  const cached = (await db.cache.get(key)) as BrandCache | undefined
  if (cached && cached.url === url) return cached.dataUrl
  try {
    const res = await fetch(url, { cache: 'force-cache' })
    if (!res.ok) return cached?.dataUrl ?? null
    const blob = await res.blob()
    const dataUrl = await new Promise<string>((resolve, reject) => {
      const r = new FileReader()
      r.onload = () => resolve(String(r.result))
      r.onerror = () => reject(r.error)
      r.readAsDataURL(blob)
    })
    await db.cache.put({ id: key, url, dataUrl, savedAt: new Date().toISOString() })
    return dataUrl
  } catch {
    return cached?.dataUrl ?? null
  }
}

/** Apply the brand colour to the till (a thin accent; the till's own colours keep their contrast). */
export function applyBrand(boot: Bootstrap | null): void {
  const c = boot?.brand?.color
  const root = document.documentElement
  if (c && /^#[0-9A-Fa-f]{6}$/.test(c)) root.style.setProperty('--brand', c)
  else root.style.removeProperty('--brand')
}

/**
 * Grey levels (0 = black … 255 = white, row by row) → printer dots, dithered so photos and gradients still read.
 * Transparent pixels must already be white. Width is padded to whole bytes; 1 bit = a black dot.
 */
export function dither(grey: Uint8ClampedArray | number[], width: number, height: number): Raster {
  const g = Float32Array.from(grey)
  const bytesPerRow = Math.ceil(width / 8)
  const data = new Uint8Array(bytesPerRow * height)
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = y * width + x
      const old = g[i]
      const black = old < 128
      const err = old - (black ? 0 : 255)
      if (black) data[y * bytesPerRow + (x >> 3)] |= 0x80 >> (x & 7)
      if (x + 1 < width) g[i + 1] += (err * 7) / 16
      if (y + 1 < height) {
        if (x > 0) g[i + width - 1] += (err * 3) / 16
        g[i + width] += (err * 5) / 16
        if (x + 1 < width) g[i + width + 1] += err / 16
      }
    }
  }
  return { width, height, bytesPerRow, data }
}

/** The logo for the receipt printer: at most `maxDots` wide (384 ≈ 48 mm, centred on 58 and 80 mm paper) and 160 dots high. */
export async function receiptRaster(boot: Bootstrap, maxDots = 384): Promise<Raster | null> {
  if (!boot.brand?.receipt_logo) return null
  const src = await cachedImage(boot.brand.logo_url)
  if (!src) return null
  const img = new Image()
  img.src = src
  try {
    await img.decode()
  } catch {
    return null
  }
  const w0 = img.naturalWidth || 300
  const h0 = img.naturalHeight || 100
  const scale = Math.min(maxDots / w0, 160 / h0, 1.5)
  const width = Math.max(8, Math.round(w0 * scale))
  const height = Math.max(8, Math.round(h0 * scale))
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  if (!ctx) return null
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, width, height)
  ctx.drawImage(img, 0, 0, width, height)
  const px = ctx.getImageData(0, 0, width, height).data
  const grey = new Uint8ClampedArray(width * height)
  for (let i = 0; i < grey.length; i++) grey[i] = 0.299 * px[i * 4] + 0.587 * px[i * 4 + 1] + 0.114 * px[i * 4 + 2]
  return dither(grey, width, height)
}
