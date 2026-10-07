import { describe, expect, it } from 'vitest'
import { generics, names, needsPrescription, warnings } from './pharmacy'
import type { Product } from './types'

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
