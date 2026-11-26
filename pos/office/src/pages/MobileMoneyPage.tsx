import { Button, Dropdown, Field, Input, MessageBar, MessageBarBody, Option, SearchBox, Switch, Text } from '@fluentui/react-components'
import { KeyRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type MomoData } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, Toggle } from '../form/Fields'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = MomoData['rows'][number]

/**
 * System → Mobile money (part 23): every payment request sent from a till — when, status (and why not paid), amount,
 * network, the customer's number, the sale, the transaction, branch, till and cashier. "Paid, not on a sale" finds money
 * received that no sale used (give it back or ring up the sale). Payment provider: the company's own merchant account;
 * saved secrets are never shown, and an empty box keeps them.
 */
export function MobileMoneyPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.momoCharges, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [gw, setGw] = useState<{ provider: string; active: boolean; credentials: Record<string, string> } | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = str(params, 'status')
  const g = data?.gateway
  const open = () => {
    if (!g) return
    setDone('')
    setGw({ provider: g.provider, active: g.active, credentials: Object.fromEntries(Object.values(g.fields).flat().filter((f) => !f.secret).map((f) => [f.key, f.value])) })
  }
  return (
    <div className={page.page}>
      <PageHead title="Mobile money" hint={data?.about ?? 'Payment requests sent from the tills.'} />
      <div className={s.bar} style={{ alignItems: 'flex-end' }}>
        <SearchBox className={s.search} placeholder="Cashier, number or transaction" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search mobile money" />
        <Dropdown aria-label="Status" value={status ? data?.statuses.find((x) => x.id === status)?.label ?? '' : 'Any status'} selectedOptions={[status]} style={{ minWidth: 180 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          <Option value="">Any status</Option>{(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Field label="From"><Input type="date" value={str(params, 'from')} onChange={(_, d) => set({ from: d.value })} /></Field>
        <Field label="To"><Input type="date" value={str(params, 'to')} onChange={(_, d) => set({ to: d.value })} /></Field>
        <Switch label="Paid, not on a sale" checked={str(params, 'unused') === '1'} onChange={(_, d) => set({ unused: d.checked ? '1' : '' })} />
        <span className={s.grow} />
        {g && <Button icon={<KeyRegular />} onClick={open}>Payment provider</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Mobile money requests" rows={data?.rows ?? []} sort="when" dir="desc" loading={loading} empty="No mobile money requests match." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'when', label: 'When', render: (r) => when(r.when) },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status_label} />{r.message && <Text size={200} block className={s.muted}>{r.message}</Text>}</> },
          { key: 'amount', label: 'Amount', end: true, render: (r) => r.amount },
          { key: 'network', label: 'Network', render: (r) => r.network ?? '—' },
          { key: 'phone', label: 'Customer number', render: (r) => r.phone ?? '—' },
          { key: 'sale', label: 'Sale', render: (r) => r.sale ?? '—' },
          { key: 'transaction', label: 'Transaction', render: (r) => (r.transaction ? <Text font="monospace" size={200}>{r.transaction}</Text> : '—') },
          { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
          { key: 'till', label: 'Till', render: (r) => r.till ?? '—' },
          { key: 'cashier', label: 'Cashier', render: (r) => r.cashier ?? '—' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {gw && g && (
        <Ask title="Payment provider" go="Save" width={620} ready={!!gw.provider} onClose={() => setGw(null)}
          run={() => api.saveMomoGateway({ provider: gw.provider, is_active: gw.active, credentials: gw.credentials }).then((x) => { setGw(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <Text>The company's own merchant account with a payment provider that collects from MTN MoMo, Telecel Cash and AT Money. Money goes to that account and is settled to the
              company's bank or wallet by the provider.</Text>
            <Choose label="Provider" required options={g.providers} value={gw.provider} onChange={(x) => setGw({ ...gw, provider: x, credentials: {} })} />
            {(g.fields[gw.provider] ?? []).map((f) => (
              <TextField key={f.key} label={f.label} type={f.secret ? 'password' : 'text'} value={gw.credentials[f.key] ?? ''} placeholder={f.saved ? 'Saved — leave empty to keep' : undefined}
                onChange={(x) => setGw({ ...gw, credentials: { ...gw.credentials, [f.key]: x } })} />
            ))}
            <Toggle label="Send payment requests through this provider" value={gw.active} onChange={(x) => setGw({ ...gw, active: x })} />
            <Text size={200} className={s.muted}>{g.callback ? `Callback address for the provider's dashboard: ${g.callback}` : 'The callback address appears here after the first save.'}</Text>
          </div>
        </Ask>
      )}
    </div>
  )
}
