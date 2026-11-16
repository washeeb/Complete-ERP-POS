import {
  Avatar, Button, Menu, MenuDivider, MenuGroup, MenuGroupHeader, MenuItem, MenuItemRadio, MenuList, MenuPopover, MenuTrigger, Text,
} from '@fluentui/react-components'
import { ArrowExitRegular, PersonRegular, WindowArrowUpRegular } from '@fluentui/react-icons'
import { api, type Me, type Prefs } from '../api'
import { navigate } from '../nav'

/** The person, top right: their profile, the look (menu across the top or at the side), the classic back office, sign out. */
export function UserMenu({ me, onLook, dark = false }: { me: Me; onLook: (look: Prefs['office_look']) => void; dark?: boolean }) {
  return (
    <Menu checkedValues={{ look: [me.prefs.office_look] }} onCheckedValueChange={(_, d) => d.name === 'look' && onLook(d.checkedItems[0] as Prefs['office_look'])}>
      <MenuTrigger disableButtonEnhancement>
        <Button appearance="subtle" aria-label={`${me.user.name}, your menu`} style={dark ? { color: 'inherit' } : undefined}
          icon={<Avatar name={me.user.name} image={me.user.photo ? { src: me.user.photo } : undefined} size={28} color="colorful" />}>
          <span className="zx-user-text"><Text weight="semibold" block truncate wrap={false}>{me.user.name}</Text>
            <Text size={200} block truncate wrap={false}>{me.user.role}</Text></span>
        </Button>
      </MenuTrigger>
      <MenuPopover>
        <MenuList>
          <MenuItem icon={<PersonRegular />} onClick={() => navigate('/admin/profile')}>My profile and settings</MenuItem>
          <MenuDivider />
          <MenuGroup>
            <MenuGroupHeader>Menu</MenuGroupHeader>
            <MenuItemRadio name="look" value="top">Across the top</MenuItemRadio>
            <MenuItemRadio name="look" value="side">At the side</MenuItemRadio>
          </MenuGroup>
          <MenuDivider />
          <MenuItem icon={<WindowArrowUpRegular />} onClick={() => location.assign(me.classic)}>Classic back office</MenuItem>
          <MenuItem icon={<ArrowExitRegular />} onClick={() => void api.signOut()}>Sign out</MenuItem>
        </MenuList>
      </MenuPopover>
    </Menu>
  )
}
