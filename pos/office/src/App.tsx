import {
  Button, FluentProvider, makeStyles, MessageBar, MessageBarBody, MessageBarTitle, Spinner, Toast, Toaster, ToastTitle, tokens,
  useId, useToastController,
} from '@fluentui/react-components'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, type Me, type Menu, type Prefs } from './api'
import { useRoute } from './nav'
import { ClassicPage } from './pages/ClassicPage'
import { tabOf } from './paths'
import { SideLook } from './shell/SideLook'
import { TopLook } from './shell/TopLook'
import { themes } from './theme'

const useStyles = makeStyles({
  center: { minHeight: '100vh', display: 'grid', placeItems: 'center', padding: '16px', backgroundColor: tokens.colorNeutralBackground2 },
})

function useDark(pref: Prefs['theme'] | undefined): boolean {
  const query = typeof matchMedia === 'function' ? matchMedia('(prefers-color-scheme: dark)') : null
  const [system, setSystem] = useState(() => query?.matches ?? false)
  useEffect(() => {
    if (!query) return
    const on = () => setSystem(query.matches)
    query.addEventListener('change', on)
    return () => query.removeEventListener('change', on)
  }, [query])
  return pref === 'dark' || (pref !== 'light' && system)
}

export function App() {
  const [me, setMe] = useState<Me | null>(null)
  const [menu, setMenu] = useState<Menu | null>(null)
  const [error, setError] = useState('')
  const dark = useDark(me?.prefs.theme)
  const theme = useMemo(() => themes(me?.company.color ?? '#0f5c8c'), [me?.company.color])
  const s = useStyles()

  const load = useCallback(() => {
    setError('')
    Promise.all([api.me(), api.menu()]).then(([m, n]) => { setMe(m); setMenu(n) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])
  useEffect(load, [load])
  useEffect(() => { document.documentElement.style.colorScheme = dark ? 'dark' : 'light' }, [dark])

  return (
    <FluentProvider theme={dark ? theme.dark : theme.light} style={{ minHeight: '100vh' }}>
      {me && menu ? <Shell me={me} menu={menu} setMe={setMe} /> : (
        <div className={s.center}>
          {error ? (
            <MessageBar intent="error" layout="multiline">
              <MessageBarBody><MessageBarTitle>The back office could not open</MessageBarTitle>{error}</MessageBarBody>
              <Button onClick={load}>Try again</Button>
            </MessageBar>
          ) : <Spinner label="Opening the back office…" />}
        </div>
      )}
    </FluentProvider>
  )
}

function Shell({ me, menu, setMe }: { me: Me; menu: Menu; setMe: (m: Me) => void }) {
  const route = useRoute()
  const toaster = useId('toaster')
  const { dispatchToast } = useToastController(toaster)
  const [title, setTitle] = useState('')

  useEffect(() => { document.title = title ? `${title} · ${me.company.name}` : me.company.name }, [title, me.company.name])

  const onLook = useCallback((look: Prefs['office_look']) => {
    const before = me
    setMe({ ...me, prefs: { ...me.prefs, office_look: look } })
    api.setLook(look).then((r) => setMe({ ...me, prefs: r.prefs })).catch((e: unknown) => {
      setMe(before)
      dispatchToast(<Toast><ToastTitle>{e instanceof Error ? e.message : 'Not saved'}</ToastTitle></Toast>, { intent: 'error' })
    })
  }, [me, setMe, dispatchToast])

  const admin = route.page === 'classic' ? route.admin : menu.home
  const current = route.page === 'classic' ? tabOf(menu, route.admin) : null
  const Look = me.prefs.office_look === 'side' ? SideLook : TopLook

  return (
    <>
      <Look me={me} menu={menu} current={current} onLook={onLook}>
        {/* Until the new Home and To-do pages arrive, they show the current back office's dashboard. */}
        <ClassicPage admin={admin} onTitle={setTitle} />
      </Look>
      <Toaster toasterId={toaster} position="top-end" />
    </>
  )
}
