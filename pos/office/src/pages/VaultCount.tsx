import { useRef } from 'react'
import { api, type ShiftDetail } from '../api'
import { BlindCount } from './BlindCount'

export { countTotal } from './BlindCount'

/**
 * The manager counts a closed vault (personal vaults), blind: note by note and coin by coin, with the total as it is
 * typed and never the figure it is checked against. When the count differs from the teller's the first time, the form
 * clears and asks for a count from the start; the second count stands and is booked.
 */
export function VaultCount({ shift, onDone, onCancel }: { shift: ShiftDetail; onDone: (s: ShiftDetail) => void; onCancel: () => void }) {
  const last = useRef<ShiftDetail>(shift)
  return (
    <BlindCount title={`Count the vault: ${shift.vault} (${shift.opened_by})`} currency={shift.currency} exponent={shift.exponent}
      denominations={shift.denominations} recount={shift.vault_recounts > 0}
      intro="Count everything in the tray, float included, before looking at anything else. Your count is compared with the teller's afterwards; it is the one booked."
      recountText="Your count does not match the teller's. Count the tray again from the start and enter it once more. The second count stands."
      submit={(body) => api.countVault(shift.id, body).then((r) => { last.current = r.shift; return r })}
      onDone={() => onDone(last.current)} onCancel={onCancel} />
  )
}
