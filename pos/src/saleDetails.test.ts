import { describe, expect, it } from 'vitest'
import { detailsMissing, detailsPayload, emptyDetails } from './screens/TillTools'

describe('sale details at the till', () => {
  it('asks only for what the branch requires, and a chosen customer counts', () => {
    const d = emptyDetails()
    expect(detailsMissing('none', d, null)).toEqual([])
    expect(detailsMissing('name_phone', d, null)).toEqual(['name', 'phone'])
    expect(detailsMissing('phone', { ...d, phone: '0241112222' }, null)).toEqual([])
    expect(detailsMissing('name_phone', d, { name: 'Ama', phone: '+233241112222' })).toEqual([])
    expect(detailsMissing('phone', d, { name: 'Ama', phone: null })).toEqual(['phone'])
  })
  it('sends only what was filled in, and walk-in details only without a customer', () => {
    expect(detailsPayload(emptyDetails(), false)).toEqual({})
    const d = { comment: ' Gift wrap ', onReceipt: true, name: 'Kofi', phone: '', extras: { age: '34', sex: '', pregnant: false } }
    expect(detailsPayload(d, false)).toEqual({ comment: 'Gift wrap', comment_on_receipt: true, walk_in: { name: 'Kofi', phone: null }, extras: { age: '34' } })
    expect(detailsPayload(d, true)).not.toHaveProperty('walk_in')
  })
})
