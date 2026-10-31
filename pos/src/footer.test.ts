import { describe, expect, it } from 'vitest'
import { footerLines } from './footer'

describe('footerLines', () => {
  it('puts each sentence and each typed line of the receipt message on its own line', () => {
    expect(footerLines('Thank you for shopping with us. Goods sold are not returnable.')).toEqual(['Thank you for shopping with us.', 'Goods sold are not returnable.'])
    expect(footerLines('Thank you!\nGoods sold are not returnable')).toEqual(['Thank you!', 'Goods sold are not returnable'])
    expect(footerLines('Call Tel. 0244 000 111 or No. 5 Main St.')).toEqual(['Call Tel. 0244 000 111 or No. 5 Main St.'])
    expect(footerLines('')).toEqual([])
  })
})
