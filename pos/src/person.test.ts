import { describe, expect, it } from 'vitest'
import { cardKey, isStaffCard, personColor } from './person'

describe('person', () => {
  it('gives each person a steady colour of their own', () => {
    expect(personColor('01a18bff-e8bf-7000-a000-000000000001')).toBe(personColor('01a18bff-e8bf-7000-a000-000000000001'))
    expect(personColor('01a18bff-e8bf-7000-a000-000000000001')).not.toBe(personColor('01a18bff-e8bf-7000-a000-000000000002'))
  })

  it('tells a scanned staff card from a typed username, and hashes it as the server does', async () => {
    expect(isStaffCard('SLB1-ABCDEFGHJKLMNPQRSTUV')).toBe(true)
    expect(isStaffCard(' slb1-abc')).toBe(true)
    expect(isStaffCard('alberta')).toBe(false)
    // sha256("SLB1-ABC"), the same as PHP hash('sha256', strtoupper(trim($code)))
    expect(await cardKey(' slb1-abc ')).toBe(await cardKey('SLB1-ABC'))
    expect(await cardKey('SLB1-ABC')).toMatch(/^[0-9a-f]{64}$/)
  })
})
