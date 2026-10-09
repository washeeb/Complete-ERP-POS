import { beforeEach, describe, expect, it } from 'vitest'
import { clearErrors, problemContext, recentErrors, rememberError } from './problems'

describe('problem reports from the till', () => {
  beforeEach(() => clearErrors())

  it('keeps only the last ten errors, shortened and on one line', () => {
    for (let i = 1; i <= 12; i++) rememberError(`Error ${i}\n  at line ${i}`, new Date(2026, 9, 30, 10, i))
    rememberError('   ')
    rememberError('x'.repeat(500))
    const list = recentErrors()
    expect(list).toHaveLength(10)
    expect(list[0].message).toBe('Error 4 at line 4') // 13 remembered, the newest 10 kept
    expect(list[9].message).toHaveLength(300)
  })

  it('sends the screen, the connection and the errors with the report', () => {
    rememberError('Printer not found')
    const c = problemContext({ screen: 'receipt', online: false, queued: 3 })
    expect(c).toMatchObject({ screen: 'receipt', online: false, queued_sales: 3 })
    expect((c.errors as { message: string }[])[0].message).toBe('Printer not found')
  })
})
