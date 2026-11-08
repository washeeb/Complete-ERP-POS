import { afterEach, describe, expect, it, vi } from 'vitest'
import { timeoutSignal } from './api'

describe('timeoutSignal', () => {
  const original = AbortSignal.timeout
  afterEach(() => { AbortSignal.timeout = original; vi.useRealTimers() })

  it('gives up after the time also on an old WebView2 without AbortSignal.timeout', () => {
    vi.useFakeTimers()
    ;(AbortSignal as { timeout?: unknown }).timeout = undefined
    const signal = timeoutSignal(1000)
    expect(signal.aborted).toBe(false)
    vi.advanceTimersByTime(1000)
    expect(signal.aborted).toBe(true)
    expect((signal.reason as DOMException).name).toBe('TimeoutError')
  })

  it('uses the browser\'s own timeout where there is one', () => {
    const spy = vi.fn(original.bind(AbortSignal))
    AbortSignal.timeout = spy
    timeoutSignal(500)
    expect(spy).toHaveBeenCalledWith(500)
  })
})
