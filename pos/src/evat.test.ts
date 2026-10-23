import { describe, expect, it } from 'vitest'
import { sdcLines } from './evat'

describe('GRA E-VAT signature on the receipt', () => {
  it('prints GRA’s signature block, each part on one receipt line', () => {
    const lines = sdcLines({ sdc_id: 'EV-260623-001', receipt: '83NS', internal: 'NUGABIDWABF5MRRN2EXDUSJ7M4', signature: 'E4OFBGZX2MT6YBTQ', mrc: '2b60f37f175c7c', time: '2023/07/24 09:09:36' })
    expect(lines).toEqual(['SDC ID: EV-260623-001  Rec: 83NS', 'SDC time: 2023/07/24 09:09:36', 'Int. data: NUGABIDWABF5MRRN2EXDUSJ7M4', 'Signature: E4OFBGZX2MT6YBTQ', 'MRC: 2b60f37f175c7c'])
    expect(lines.every((l) => l.length <= 48)).toBe(true)
    expect(sdcLines(null)).toEqual([])
  })
})
