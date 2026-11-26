import { Button, CounterBadge, makeStyles, mergeClasses, Popover, PopoverSurface, PopoverTrigger, Spinner, Text, tokens, Tooltip } from '@fluentui/react-components'
import { AlertRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactElement } from 'react'
import { api, type NotificationItem } from '../api'
import { navigate } from '../nav'

const useStyles = makeStyles({
  surface: { width: '380px', maxWidth: 'calc(100vw - 24px)', padding: 0 },
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', padding: '10px 12px', borderBottom: `1px solid ${tokens.colorNeutralStroke2}` },
  list: { maxHeight: '60vh', overflowY: 'auto' },
  item: { display: 'grid', gap: '2px', width: '100%', textAlign: 'left', padding: '10px 12px', border: 'none', borderBottom: `1px solid ${tokens.colorNeutralStroke2}`,
    backgroundColor: 'transparent', cursor: 'pointer', color: 'inherit', ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover } },
  unread: { backgroundColor: tokens.colorBrandBackground2, ':hover': { backgroundColor: tokens.colorBrandBackground2Hover } },
  muted: { color: tokens.colorNeutralForeground3 },
  empty: { padding: '18px 12px', color: tokens.colorNeutralForeground3, textAlign: 'center' },
  badged: { position: 'relative', display: 'inline-flex' },
  counter: { position: 'absolute', top: '-6px', right: '-10px' },
})

/**
 * The bell (part 43), as the current back office's: how many notifications are unread (fresh every half minute), and
 * the latest ones — newest first, unread marked; one opens its page and is marked read; Mark all read; Clear.
 */
export function Bell({ trigger }: { trigger?: (count: number) => ReactElement }) {
  const s = useStyles()
  const [count, setCount] = useState(0)
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<NotificationItem[] | null>(null)
  useEffect(() => {
    const read = () => { api.notificationCount().then((r) => setCount(r.unread)).catch(() => undefined) }
    read()
    const t = setInterval(read, 30000)
    return () => clearInterval(t)
  }, [])
  const load = useCallback(() => { api.notifications().then((r) => { setItems(r.items); setCount(r.unread) }).catch(() => setItems([])) }, [])
  const pick = (n: NotificationItem) => {
    if (!n.read) void api.notificationRead(n.id).then((r) => setCount(r.unread)).catch(() => undefined)
    setOpen(false)
    if (n.url) navigate(n.url)
  }
  const button = trigger ? trigger(count) : (
    <Tooltip content={count ? `Notifications: ${count} unread` : 'Notifications'} relationship="label">
      <Button appearance="transparent" style={{ color: 'inherit' }}
        icon={<span className={s.badged}><AlertRegular />{count > 0 && <CounterBadge className={s.counter} count={count} overflowCount={99} size="small" color="danger" />}</span>} />
    </Tooltip>
  )
  return (
    <Popover open={open} onOpenChange={(_, d) => { setOpen(d.open); if (d.open) { setItems(null); load() } }} positioning="below-end" trapFocus>
      <PopoverTrigger disableButtonEnhancement>{button}</PopoverTrigger>
      <PopoverSurface className={s.surface} aria-label="Notifications">
        <div className={s.head}>
          <Text weight="semibold">Notifications</Text>
          <span>
            <Button size="small" appearance="subtle" disabled={!count} onClick={() => api.notificationsReadAll().then(() => { setCount(0); load() })}>Mark all read</Button>
            <Button size="small" appearance="subtle" disabled={!items?.length} onClick={() => api.notificationsClear().then(() => { setCount(0); setItems([]) })}>Clear</Button>
          </span>
        </div>
        <div className={s.list}>
          {items === null ? <div className={s.empty}><Spinner size="tiny" /></div>
            : items.length === 0 ? <div className={s.empty}>No notifications.</div>
            : items.map((n) => (
              <button key={n.id} type="button" className={mergeClasses(s.item, !n.read && s.unread)} onClick={() => pick(n)}>
                <Text weight={n.read ? 'regular' : 'semibold'}>{n.title}</Text>
                {n.body && <Text size={200}>{n.body}</Text>}
                <Text size={100} className={s.muted}>{n.at}{n.url ? ' · Open' : ''}</Text>
              </button>
            ))}
        </div>
      </PopoverSurface>
    </Popover>
  )
}
