import { describe, expect, it } from 'vitest'
import { countTotal } from './VaultCount'

describe('the vault count', () => {
  const ghs = [{ key: 'n100', value: 10000 }, { key: 'n50', value: 5000 }, { key: 'n20', value: 2000 }, { key: 'c50p', value: 50 }]

  it('adds each note and coin by how many were counted', () => {
    expect(countTotal(ghs, { n100: '1', n50: '1', n20: '2', c50p: '3' })).toBe(19150) // GH₵ 191.50
  })

  it('treats a blank box as none, and ignores anything that is not a whole number', () => {
    expect(countTotal(ghs, { n100: '', n50: 'x' })).toBe(0)
    expect(countTotal(ghs, {})).toBe(0)
  })
})
