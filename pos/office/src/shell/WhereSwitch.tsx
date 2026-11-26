import { Button, makeStyles, Popover, PopoverSurface, PopoverTrigger, Text, Tooltip } from '@fluentui/react-components'
import { BuildingRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import type { Me } from '../api'
import { BranchSwitch } from './BranchSwitch'
import { IndustrySwitch } from './IndustrySwitch'

const useStyles = makeStyles({
  surface: { display: 'grid', gap: '10px', padding: '12px', minWidth: '220px' },
})

const PHONE = '(max-width: 760px)'

/**
 * Which industry and branch the back office shows. On a wide screen the two boxes sit in the top row; on a phone they
 * are behind one button, so the row still fits (search, bell and your name stay in reach).
 */
export function WhereSwitch({ me }: { me: Me }) {
  const s = useStyles()
  const [phone, setPhone] = useState(() => typeof matchMedia === 'function' && matchMedia(PHONE).matches)
  useEffect(() => {
    if (typeof matchMedia !== 'function') return
    const q = matchMedia(PHONE)
    const on = () => setPhone(q.matches)
    q.addEventListener('change', on)
    return () => q.removeEventListener('change', on)
  }, [])
  if (!me.industry && !me.branch) return null
  if (!phone) return <><IndustrySwitch me={me} /><BranchSwitch me={me} /></>
  return (
    <Popover positioning="below-end" trapFocus>
      <PopoverTrigger disableButtonEnhancement>
        <Tooltip content="Industry and branch shown" relationship="label">
          <Button appearance="subtle" icon={<BuildingRegular />} />
        </Tooltip>
      </PopoverTrigger>
      <PopoverSurface className={s.surface}>
        <Text weight="semibold">Show</Text>
        <IndustrySwitch me={me} />
        <BranchSwitch me={me} />
      </PopoverSurface>
    </Popover>
  )
}
