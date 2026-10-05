/**
 * The till's identity key: ECDSA P-256 generated in the browser, private half NON-EXTRACTABLE
 * (script cannot read it, only use it) and kept in IndexedDB. The public half is sent at
 * enrolment. Phase 3 signs offline sync batches with it.
 */
export async function createDeviceKey(): Promise<{ privateKey: CryptoKey; publicKeySpki: string }> {
  const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify'])
  const spki = new Uint8Array(await crypto.subtle.exportKey('spki', pair.publicKey))
  let bin = ''
  spki.forEach((b) => (bin += String.fromCharCode(b)))
  return { privateKey: pair.privateKey, publicKeySpki: btoa(bin) }
}
