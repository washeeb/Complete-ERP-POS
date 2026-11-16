import {
  Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Field, Input, makeStyles, MessageBar, MessageBarBody, Text, tokens,
} from '@fluentui/react-components'
import { useMemo, useState } from 'react'
import { api, type ShiftDetail } from '../api'
import { money } from '../home/format'

const useStyles = makeStyles({
  group: { border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusLarge, padding: '8px 12px 12px', margin: '10px 0 0' },
  legend: { fontWeight: tokens.fontWeightSemibold, padding: '0 4px' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: '8px', '@media (max-width: 600px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' } },
  box: { minWidth: 0, width: '100%' },
  total: { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginTop: '12px', fontSize: '18px', fontWeight: tokens.fontWeightBold },
})

/** How many of each note and coin make what total (a whole number of each; blank is none). */
export function countTotal(denoms: { key: string; value: number }[], counts: Record<string, string>): number {
  return denoms.reduce((sum, d) => sum + (Number.parseInt(counts[d.key] ?? '', 10) || 0) * d.value, 0)
}

/**
 * The manager counts a closed vault (personal vaults), blind: note by note and coin by coin, with the total as it is
 * typed and never the figure it is checked against. When the count differs from the teller's the first time, the form
 * clears and asks for a count from the start; the second count stands and is booked.
 */
export function VaultCount({ shift, onDone, onCancel }: { shift: ShiftDetail; onDone: (s: ShiftDetail) => void; onCancel: () => void }) {
  const s = useStyles()
  const [counts, setCounts] = useState<Record<string, string>>({})
  const [amount, setAmount] = useState('')
  const [recount, setRecount] = useState(shift.vault_recounts > 0)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const list = shift.denominations
  const total = useMemo(() => countTotal(list, counts), [list, counts])
  const m = (v: number) => money(v, shift.currency, shift.exponent)

  const save = () => {
    setBusy(true)
    setError('')
    const body = list.length
      ? { denoms: Object.fromEntries(list.map((d) => [d.key, Number.parseInt(counts[d.key] ?? '', 10) || 0])) }
      : { amount }
    api.countVault(shift.id, body).then((r) => {
      if (r.status === 'recount') {
        setCounts({})
        setAmount('')
        setRecount(true)
      } else onDone(r.shift)
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }

  const group = (title: string, kind: 'note' | 'coin') => (
    <fieldset className={s.group}>
      <legend className={s.legend}>{title}</legend>
      <div className={s.grid}>
        {list.filter((d) => d.kind === kind).map((d) => (
          <Field key={d.key} label={d.label} size="small">
            <Input className={s.box} type="number" min={0} step={1} inputMode="numeric" placeholder="0" value={counts[d.key] ?? ''}
              onChange={(_, v) => setCounts({ ...counts, [d.key]: v.value.replace(/[^0-9]/g, '') })} />
          </Field>
        ))}
      </div>
    </fieldset>
  )

  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onCancel()}>
      <DialogSurface style={{ maxWidth: 640 }}>
        <DialogBody>
          <DialogTitle>Count the vault: {shift.vault} ({shift.opened_by})</DialogTitle>
          <DialogContent>
            {recount ? (
              <MessageBar intent="warning"><MessageBarBody>
                Your count does not match the teller's. Count the tray again from the start and enter it once more. The second count stands.
              </MessageBarBody></MessageBar>
            ) : (
              <Text block>Count everything in the tray, float included, before looking at anything else. Your count is compared with the teller's afterwards; it is the one booked.</Text>
            )}
            {list.length ? <>{group('Notes', 'note')}{group('Coins', 'coin')}</> : (
              <Field label="Amount counted" required style={{ marginTop: 10 }}><Input type="number" min={0} value={amount} onChange={(_, v) => setAmount(v.value)} /></Field>
            )}
            {list.length > 0 && <div className={s.total}><span>Total counted</span><span>{m(total)}</span></div>}
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onCancel}>Cancel</Button>
            <Button appearance="primary" disabled={busy || (list.length ? total <= 0 : amount === '')} onClick={save}>{busy ? 'Saving…' : 'Save my count'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
