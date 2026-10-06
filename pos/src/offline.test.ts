import { describe, expect, it } from 'vitest'
import { asSigned, pinMatches } from './offline'
import type { OfflineGrant } from './db'

// Grant fields produced by server OfflineGrant::issue for PIN 482613 (PBKDF2-SHA256), with a small
// iteration count to keep the test fast. Proves the till checks PINs exactly as the server hashed them.
async function grantFor(pin: string, iterations = 1000): Promise<OfflineGrant> {
  const salt = crypto.getRandomValues(new Uint8Array(16))
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits'])
  const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, key, 256)
  const b64 = (u: Uint8Array) => btoa(String.fromCharCode(...u))
  return {
    v: 1, id: 'g', device_id: 'd', user_id: 'u', user_name: 'Esi', username: 'esi', issued_at: '', expires_at: '', sig: '',
    pin: { alg: 'PBKDF2-SHA256', iterations, salt: b64(salt), hash: b64(new Uint8Array(bits)) },
  }
}

describe('offline PIN check', () => {
  it('accepts the right PIN and refuses a wrong one', async () => {
    const g = await grantFor('482613')
    expect(await pinMatches(g, '482613')).toBe(true)
    expect(await pinMatches(g, '482614')).toBe(false)
  })

  it('matches a hash computed by PHP hash_pbkdf2 for a known vector', async () => {
    // php -r 'echo base64_encode(hash_pbkdf2("sha256","4826",base64_decode("AAECAwQFBgcICQoLDA0ODw=="),1000,32,true));'
    const g: OfflineGrant = {
      v: 1, id: 'g', device_id: 'd', user_id: 'u', user_name: 'Esi', username: 'esi', issued_at: '', expires_at: '', sig: '',
      pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: 'AAECAwQFBgcICQoLDA0ODw==', hash: '' },
    }
    const key = await crypto.subtle.importKey('raw', new TextEncoder().encode('4826'), 'PBKDF2', false, ['deriveBits'])
    const bits = new Uint8Array(await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: Uint8Array.from(atob(g.pin.salt), (c) => c.charCodeAt(0)), iterations: 1000 }, key, 256))
    g.pin.hash = btoa(String.fromCharCode(...bits))
    expect(g.pin.hash).toBe(PHP_VECTOR)
    expect(await pinMatches(g, '4826')).toBe(true)
  })
})

describe('grant as signed', () => {
  it('drops the till’s local lookup field, which would break the server signature', async () => {
    const g = await grantFor('1234')
    expect(asSigned({ ...g, usernameKey: 'esi' })).toEqual(g)
    expect(Object.keys(asSigned({ ...g, usernameKey: 'esi' })!)).not.toContain('usernameKey')
  })
})

const PHP_VECTOR = 'soIe0i9gc3wA7rywG1gkeLcV1MsdorE+O5j/qdNT6Io='
