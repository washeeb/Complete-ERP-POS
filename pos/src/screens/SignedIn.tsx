/**
 * Who is signed in on this till, shown wherever a manager types their PIN, so the manager sees whose action they allow
 * (and spots a till still signed in as someone else). Set by App when a person signs in or out.
 */
let current = ''

export function setSignedInName(name: string) {
  current = name
}

export function SignedIn() {
  if (!current) return null
  return <p className="signed-in">Signed in on this till: <strong>{current}</strong></p>
}
