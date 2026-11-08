import { afterEach, describe, expect, it, vi } from 'vitest'

describe('clientHeader', () => {
  afterEach(() => { delete (globalThis as { __TAURI__?: unknown }).__TAURI__; vi.resetModules() })

  it('is left out in a browser', async () => {
    const { clientHeader } = await import('./clientInfo')
    expect(await clientHeader()).toBeNull()
  })

  it('sends what the desktop app reports, without empty values, asking the app once', async () => {
    const invoke = vi.fn().mockResolvedValue({ computer: 'FRONT-PC', mac: 'A4:BB:6D:01:02:03', local_ip: '', app: 'ZionEX Till 0.25.0' })
    ;(globalThis as { __TAURI__?: unknown }).__TAURI__ = { core: { invoke } }
    const { clientHeader } = await import('./clientInfo')
    expect(JSON.parse((await clientHeader())!)).toEqual({ computer: 'FRONT-PC', mac: 'A4:BB:6D:01:02:03', app: 'ZionEX Till 0.25.0' })
    await clientHeader()
    expect(invoke).toHaveBeenCalledTimes(1)
  })

  it('sends only characters a header can carry, keeping the name exact', async () => {
    const invoke = vi.fn().mockResolvedValue({ computer: 'KOFI’S-CAISSE-ÉTÉ', app: 'ZionEX Till 0.30.3' })
    ;(globalThis as { __TAURI__?: unknown }).__TAURI__ = { core: { invoke } }
    const { clientHeader } = await import('./clientInfo')
    const header = (await clientHeader())!
    expect(/^[\x20-\x7e]*$/.test(header)).toBe(true)
    expect(() => new Headers({ 'X-SES-Client': header })).not.toThrow()
    expect(JSON.parse(header).computer).toBe('KOFI’S-CAISSE-ÉTÉ')
  })
})
