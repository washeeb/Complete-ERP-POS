import { Button, Menu, MenuGroup, MenuGroupHeader, MenuItem, MenuList, MenuPopover, MenuTrigger } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import type { ReactElement } from 'react'
import type { Menu as MenuData } from '../api'
import { navigate } from '../nav'

/** "Create": a new sale, invoice, product, supplier… whatever this person may add, grouped as in the menu. */
export function CreateMenu({ menu, trigger }: { menu: MenuData; trigger?: ReactElement }) {
  if (!menu.create.length) return null
  return (
    <Menu positioning={trigger ? 'after-top' : 'below-end'}>
      <MenuTrigger disableButtonEnhancement>{trigger ?? <Button appearance="primary" icon={<AddRegular />}>Create</Button>}</MenuTrigger>
      <MenuPopover style={{ maxHeight: '70vh', overflowY: 'auto' }}>
        <MenuList>
          {menu.create.map((g) => (
            <MenuGroup key={g.label}>
              <MenuGroupHeader>{g.label}</MenuGroupHeader>
              {g.items.map((i) => <MenuItem key={i.url} onClick={() => navigate(i.url)}>{i.label}</MenuItem>)}
            </MenuGroup>
          ))}
        </MenuList>
      </MenuPopover>
    </Menu>
  )
}
