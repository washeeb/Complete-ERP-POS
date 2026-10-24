import { describe, expect, it } from 'vitest'
import { generics, names, needsPrescription, nhisCover, saleLevel, warnings } from './pharmacy'
import type { Product, TillCustomer, Unit } from './types'

const drug = (id: string, name: string, schedule: 'otc' | 'pom' | 'controlled', generic: string, strength = '500 mg', ingredients: string[] = []): Product => ({
  id, sku: id, name, track_stock: true, on_hand: null, tax: [], units: [], prices: [],
  drug: { schedule, generic_name: generic, strength, dosage_form: 'Tablet', active_ingredients: ingredients },
})
const amox = drug('amx', 'Amoxil 500', 'pom', 'Amoxicillin', '500 mg', ['amoxicillin'])
const amoxGen = drug('amx2', 'Amoxicillin 500 (generic)', 'pom', 'amoxicillin', '500mg')
const warf = drug('wrf', 'Warfarin 5', 'pom', 'warfarin', '5 mg', ['warfarin'])
const para = drug('pcm', 'Paracetamol 500', 'otc', 'paracetamol')

describe('pharmacy rules at the till', () => {
  it('knows which medicines need a prescription', () => {
    expect(needsPrescription(amox)).toBe(true)
    expect(needsPrescription(para)).toBe(false)
    expect(needsPrescription({ ...para, drug: null })).toBe(false)
    expect(names(' Penicillin, AMOXICILLIN ,,penicillin')).toEqual(['penicillin', 'amoxicillin'])
  })

  it('warns about allergies and interactions in both orders, like the server', () => {
    const rules = [{ ingredient_a: 'paracetamol', ingredient_b: 'warfarin', severity: 'moderate' as const, note: 'Check INR' }]
    const w = warnings([amox, warf, para], ['Amoxicillin'], rules)
    expect(w.map((x) => x.kind)).toEqual(['allergy', 'interaction'])
    expect(w[0].message).toBe('Amoxil 500: patient is allergic to amoxicillin.')
    expect(w[1]).toEqual({ kind: 'interaction', severity: 'moderate', message: 'Warfarin 5 + Paracetamol 500: Check INR' })
    expect(warnings([para, warf], [], rules)).toHaveLength(1)
    expect(warnings([warf, warf], [], rules)).toHaveLength(0)
  })

  it('offers generics with the same name and strength', () => {
    expect(generics([amox, amoxGen, warf, para], amox).map((p) => p.id)).toEqual(['amx2'])
    expect(generics([amox, warf], para)).toEqual([])
  })
})

describe('how strictly medicines are sold at this branch', () => {
  const codeine = drug('cod', 'Codeine 30', 'controlled', 'codeine')
  const relaxed = { pom: 'free' as const, controlled: 'details' as const, max_qty: 20000, major_needs_pharmacist: true, after: 'signoff' }
  it('takes the strictest medicine, and needs a pharmacist above the limit or with a major warning', () => {
    expect(saleLevel(relaxed, [{ product: para, qtyBaseMilli: 5000 }], false)).toBeNull()
    expect(saleLevel(relaxed, [{ product: amox, qtyBaseMilli: 5000 }], false)).toBe('free')
    expect(saleLevel(relaxed, [{ product: amox, qtyBaseMilli: 5000 }, { product: codeine, qtyBaseMilli: 10000 }], false)).toBe('details')
    expect(saleLevel(relaxed, [{ product: codeine, qtyBaseMilli: 30000 }], false)).toBe('approve')
    expect(saleLevel(relaxed, [{ product: amox, qtyBaseMilli: 1000 }], true)).toBe('approve')
    expect(saleLevel({ ...relaxed, major_needs_pharmacist: false }, [{ product: amox, qtyBaseMilli: 1000 }], true)).toBe('free')
    expect(saleLevel(undefined, [{ product: amox, qtyBaseMilli: 1000 }], false)).toBe('approve') // older server: strict
  })
})

describe('NHIS at the till', () => {
  const tablet: Unit = { id: 't', uom_code: 'TAB', uom_name: 'Tablet', decimals: 0, factor: '1', is_base: true, barcodes: [] }
  const pack: Unit = { id: 'p', uom_code: 'PK', uom_name: 'Pack of 10', decimals: 0, factor: '10', is_base: false, barcodes: [] }
  const covered = { ...para, drug: { ...para.drug!, nhis_price_minor: 15 } } // 0.15 a tablet
  const patient = { id: 'c', name: 'Ama', nhis_no: '12345678', nhis_expires_on: '2026-12-31' } as TillCustomer

  it('pays the NHIS price per base unit, never more than the line, only on a valid card', () => {
    const lines = [{ product: covered, unit: pack, qtyMilli: 2000, total: 500 }, { product: warf, unit: tablet, qtyMilli: 5000, total: 900 }]
    expect(nhisCover(lines, patient, '2026-10-24')).toEqual({ amount: 300, no: '12345678' }) // 20 tablets × 0.15; warfarin is not on the list
    expect(nhisCover([{ product: covered, unit: pack, qtyMilli: 2000, total: 200 }], patient, '2026-10-24')?.amount).toBe(200)
    expect(nhisCover(lines, patient, '2027-01-01')).toBeNull() // card expired
    expect(nhisCover(lines, { ...patient, nhis_no: null }, '2026-10-24')).toBeNull()
    expect(nhisCover([lines[1]], patient, '2026-10-24')).toBeNull()
  })
})
