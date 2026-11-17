import { describe, expect, it } from 'vitest'
import { fileName } from './ExportMenu'

describe('the export file name', () => {
  it('takes the name the server gave, or a plain one', () => {
    expect(fileName('attachment; filename="Stock ledger 2026-11-17.csv"', 'movements.csv')).toBe('Stock ledger 2026-11-17.csv')
    expect(fileName(null, 'movements.csv')).toBe('movements.csv')
  })
})
