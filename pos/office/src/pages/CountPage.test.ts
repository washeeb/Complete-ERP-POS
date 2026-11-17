import { describe, expect, it } from 'vitest'
import { diffColour, repeated } from './CountPage'

describe('entering counts', () => {
  it('points out a product counted twice in one go (only the later count would stand)', () => {
    expect(repeated([{ product_id: 'milo' }, { product_id: 'sugar' }, { product_id: 'milo' }])).toEqual([3])
    expect(repeated([{ product_id: 'milo' }, { product_id: '' }, { product_id: '' }])).toEqual([])
  })
})

describe('a count difference', () => {
  it('is red when short, amber when over, green when exact, and plain when not counted', () => {
    expect(diffColour('-2')).toBe('var(--zx-bad)')
    expect(diffColour('3')).toBe('var(--zx-warn)')
    expect(diffColour('0')).toBe('var(--zx-good)')
    expect(diffColour(null)).toBeUndefined()
  })
})
