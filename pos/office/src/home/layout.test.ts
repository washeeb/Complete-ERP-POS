import { describe, expect, it } from 'vitest'
import { change, money, moneyShort } from './format'
import { column, move, normalize, nudge, standardLayout, withCols } from './layout'

describe('Home layout', () => {
  it('fills in what the server did not keep, and drops what it does not know', () => {
    const l = normalize({ cols: 3, panels: [{ id: 'trend', col: 0, hidden: false }, { id: 'evil' as never, col: 1, hidden: false }, { id: 'trend', col: 2, hidden: true }] })
    expect(l.panels[0]).toEqual({ id: 'trend', col: 0, hidden: false })
    expect(l.panels.map((p) => p.id)).toHaveLength(7)
    expect(normalize(null)).toEqual(standardLayout())
  })

  it('moves a panel before another, or to the end of a column, by mouse or keyboard', () => {
    const l = standardLayout()
    const a = move(l, 'meter', 0, 'shortcuts')
    expect(column(a, 0).map((p) => p.id)).toEqual(['reminders', 'meter', 'shortcuts'])
    const b = move(l, 'reminders', 1, null)
    expect(column(b, 1).map((p) => p.id)).toEqual(['tiles', 'kpis', 'trend', 'reminders'])
    expect(column(nudge(l, 'kpis', 'up'), 1).map((p) => p.id)).toEqual(['kpis', 'tiles', 'trend'])
    expect(column(nudge(l, 'kpis', 'down'), 1).map((p) => p.id)).toEqual(['tiles', 'trend', 'kpis'])
    expect(column(nudge(l, 'tiles', 'up'), 1).map((p) => p.id)).toEqual(['tiles', 'kpis', 'trend']) // already first
    expect(nudge(l, 'meter', 'right').panels.find((p) => p.id === 'meter')?.col).toBe(2) // no column further right
  })

  it('folds a third column into the second when going to two', () => {
    expect(column(withCols(standardLayout(), 2), 1).map((p) => p.id)).toEqual(['tiles', 'kpis', 'trend', 'meter', 'top_items'])
  })
})

describe('figures', () => {
  it('shows money in the company currency, whole and short, and the change on the period before', () => {
    expect(money(645_000, 'GHS', 2)).toMatch(/6,450\.00/)
    expect(moneyShort(640_000_000, 'GHS', 2)).toMatch(/6\.4M/)
    expect(change(6.94, 100)).toBe('+6.9 %')
    expect(change(-12, 100)).toBe('−12.0 %')
    expect(change(null, 0)).toBe('new')
    expect(change(null, null)).toBe('—')
  })
})
