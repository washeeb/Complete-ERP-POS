import { afterEach, describe, expect, it, vi } from 'vitest'

describe('clientHeader', () => {
  afterEach(() => { delete (globalThis as { __TAURI__?: unknown }).__TAURI__; vi.resetModules() })

  it('is left out in a browser', async () => {
    const { clientHeader } = await import('./clientInfo')
    expect(await clientHeader()).toBeNull()
  })

  it('sends what the desktop app reports, without empty values, asking the app once', async () => {
    const invoke = vi.fn().mockResolvedValue({ computer: 'FRONT-PC', mac: 'A4:BB:6D:01:02:03', local_ip: '', app: 'SES Labs Till 0.25.0' })
    ;(globalThis as { __TAURI__?: unknown }).__TAURI__ = { core: { invoke } }
    const { clientHeader } = await import('./clientInfo')
    expect(JSON.parse((await clientHeader())!)).toEqual({ computer: 'FRONT-PC', mac: 'A4:BB:6D:01:02:03', app: 'SES Labs Till 0.25.0' })
    await clientHeader()
    expect(invoke).toHaveBeenCalledTimes(1)
  })
})
