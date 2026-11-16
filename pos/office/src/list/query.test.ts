import { describe, expect, it } from 'vitest'
import { list, parse, patch, serialize, str } from './query'

describe('a list in the address', () => {
  it('reads and writes search, filters and several values for one filter', () => {
    const p = parse('?q=A-104&period=today&methods[]=cash&methods[]=momo')
    expect(str(p, 'q')).toBe('A-104')
    expect(list(p, 'methods')).toEqual(['cash', 'momo'])
    expect(serialize(p)).toBe('q=A-104&period=today&methods%5B%5D=cash&methods%5B%5D=momo')
  })

  it('goes back to page 1 when a filter changes, and drops empty values', () => {
    expect(patch({ q: 'x', page: '3' }, { period: 'today' })).toEqual({ q: 'x', period: 'today' })
    expect(patch({ q: 'x', page: '3' }, { page: '4' })).toEqual({ q: 'x', page: '4' })
    expect(patch({ q: 'x', methods: ['cash'] }, { q: '', methods: [] })).toEqual({})
  })
})
