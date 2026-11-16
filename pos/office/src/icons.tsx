import { createElement } from 'react'
import {
  AddRegular, BoxRegular, BuildingShopRegular, CartRegular, DataTrendingRegular, GridRegular, HomeRegular, MoneyRegular,
  PeopleRegular, SettingsRegular, ShoppingBagRegular, TaskListSquareLtrRegular,
} from '@fluentui/react-icons'
import type { FluentIcon } from '@fluentui/react-icons'

/** Fluent icons for the menu areas (the areas are the current back office's tabs; an industry area takes the shop). */
const AREA: Record<string, FluentIcon> = {
  Home: HomeRegular, 'To-do': TaskListSquareLtrRegular, Insights: DataTrendingRegular, Sales: CartRegular, Purchasing: ShoppingBagRegular,
  Stock: BoxRegular, Finance: MoneyRegular, People: PeopleRegular, Setup: SettingsRegular, More: GridRegular, Create: AddRegular,
}

export function areaIcon(label: string): FluentIcon {
  return AREA[label] ?? BuildingShopRegular
}

/** The icon of a menu area, as a component (keeps one icon component per area, not a new one each time). */
export function AreaIcon({ label, className }: { label: string; className?: string }) {
  return createElement(areaIcon(label), { className, 'aria-hidden': true })
}
