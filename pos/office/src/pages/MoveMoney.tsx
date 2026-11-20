import { Dropdown, Field, Input, makeStyles, Option, Text } from '@fluentui/react-components'
import { useState } from 'react'
import { api, type BankForms } from '../api'
import { Ask } from '../form/Ask'

const useStyles = makeStyles({
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  two: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' },
})

/** Move money between your own accounts (banking the till cash, topping up petty cash, sweeping MoMo to the bank). */
export function MoveMoney({ forms, onClose, onDone }: { forms: BankForms; onClose: () => void; onDone: (message: string) => void }) {
  const s = useStyles()
  const today = new Date().toISOString().slice(0, 10)
  const [from, setFrom] = useState('')
  const [fromBranch, setFromBranch] = useState(forms.hq)
  const [to, setTo] = useState('')
  const [toBranch, setToBranch] = useState(forms.hq)
  const [amount, setAmount] = useState('')
  const [date, setDate] = useState(today)
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const pick = (label: string, value: string, set: (v: string) => void, list: { id: string; label: string }[]) => (
    <Field label={label} required>
      <Dropdown value={list.find((x) => x.id === value)?.label ?? ''} selectedOptions={[value]} onOptionSelect={(_, d) => set(d.optionValue ?? '')}>
        {list.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
      </Dropdown>
    </Field>
  )
  return (
    <Ask title="Move money" go="Move the money" ready={!!from && !!to && from !== to && Number(amount) > 0 && date !== ''} onClose={onClose} width={620}
      run={async () => onDone((await api.moveMoney({ from_account_id: from, branch_id: fromBranch, to_account_id: to, to_branch_id: toBranch, amount, moved_on: date,
        reference: reference || null, note: note || null })).message)}>
      <div className={s.form}>
        <Text>Between your own accounts: banking the till cash, topping up petty cash, sweeping MoMo to the bank.</Text>
        <div className={s.two}>
          {pick('From', from, setFrom, forms.money_accounts)}
          {pick('From branch', fromBranch, setFromBranch, forms.branches)}
          {pick('To', to, setTo, forms.money_accounts)}
          {pick('To branch', toBranch, setToBranch, forms.branches)}
          <Field label="Amount" required><Input type="number" min={0} value={amount} onChange={(_, d) => setAmount(d.value)} /></Field>
          <Field label="Date" required><Input type="date" value={date} max={today} onChange={(_, d) => setDate(d.value)} /></Field>
          <Field label="Deposit slip / reference"><Input value={reference} maxLength={80} onChange={(_, d) => setReference(d.value)} /></Field>
          <Field label="Note"><Input value={note} maxLength={255} onChange={(_, d) => setNote(d.value)} /></Field>
        </div>
        {from && from === to && <Text style={{ color: 'var(--zx-bad)' }}>Choose two different accounts.</Text>}
      </div>
    </Ask>
  )
}
