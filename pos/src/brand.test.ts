import { describe, expect, it } from 'vitest'
import { dither } from './brand'
import { EscPos } from './escpos'

describe('the company logo on thermal receipts', () => {
  it('turns grey levels into printer dots, packing 8 dots a byte and padding each row', () => {
    // 10 × 2: first row black then white, second row all white.
    const grey = [0, 0, 0, 0, 0, 255, 255, 255, 255, 255, ...Array(10).fill(255)]
    const r = dither(grey, 10, 2)
    expect(r.bytesPerRow).toBe(2)
    expect(Array.from(r.data.slice(0, 2))).toEqual([0b11111000, 0])
    expect(Array.from(r.data.slice(2, 4))).toEqual([0, 0])
  })

  it('keeps mid-grey readable by dithering it to about half the dots', () => {
    const size = 32
    const r = dither(Array(size * size).fill(128), size, size)
    const dots = Array.from(r.data).reduce((n, b) => n + b.toString(2).split('1').length - 1, 0)
    expect(dots / (size * size)).toBeGreaterThan(0.4)
    expect(dots / (size * size)).toBeLessThan(0.6)
  })

  it('sends the image as an ESC/POS raster (GS v 0) with its size', () => {
    const bytes = new EscPos().image({ width: 16, height: 3, bytesPerRow: 2, data: new Uint8Array(6).fill(0xff) }).toBytes()
    const at = bytes.indexOf(0x76)
    expect(Array.from(bytes.slice(at - 1, at + 7))).toEqual([0x1d, 0x76, 0x30, 0, 2, 0, 3, 0])
    expect(Array.from(bytes.slice(at + 7, at + 13))).toEqual([0xff, 0xff, 0xff, 0xff, 0xff, 0xff])
  })
})
