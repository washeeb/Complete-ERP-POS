import { describe, expect, it } from 'vitest'
import type { Menu } from './api'
import { adminEquivalent, adminPathFor, appPathFor, classicPathFor, findPages, routeOf, tabOf } from './paths'

const menu: Menu = {
  home: '/admin',
  create: [],
  tabs: [
    { label: 'Sales', icon: '', sections: [{ label: 'Sales', groups: [{ label: 'Till', items: [
      { label: 'Till sales', url: '/admin/sales', icon: null, badge: null },
      { label: 'Till requests', url: '/admin/till-requests', icon: null, badge: '2' },
    ] }] }] },
    { label: 'Stock', icon: '', sections: [{ label: 'Catalog', groups: [{ label: null, items: [
      { label: 'Products', url: '/admin/products', icon: null, badge: null },
    ] }] }] },
  ],
}

describe('addresses', () => {
  it('opens a current back-office page inside the new one, and back', () => {
    expect(appPathFor('/admin/stock-levels?tableSearch=rice')).toBe('/office/a/stock-levels?tableSearch=rice')
    expect(appPathFor('/admin/products/abc/edit')).toBe('/office/a/products/abc/edit')
    expect(appPathFor('/admin')).toBe('/office/')
    expect(appPathFor('https://evil.example/admin')).toBe('/office/') // never another site
    expect(adminPathFor('/office/a/products?tableSearch=rice')).toBe('/admin/products?tableSearch=rice')
    expect(adminPathFor('/office/')).toBeNull()
  })

  it('opens the pages already rebuilt in their new form, and keeps the old one a click away', () => {
    expect(appPathFor('/admin/sales')).toBe('/office/sales')
    expect(appPathFor('/admin/sales?tableFilters=x')).toBe('/office/sales')
    expect(appPathFor('/admin/sales/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toBe('/office/sales/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')
    expect(appPathFor('/admin/sales-orders')).toBe('/office/a/sales-orders') // not the same page
    expect(classicPathFor('/admin/sales')).toBe('/office/a/sales')
    expect(routeOf('/office/sales')).toEqual({ page: 'sales' })
    expect(routeOf('/office/sales/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'sale', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(adminEquivalent({ page: 'sales' })).toBe('/admin/sales')
    expect(appPathFor('/admin/shifts')).toBe('/office/shifts')
    expect(appPathFor('/admin/till-drawer-events')).toBe('/office/drawer')
    expect(routeOf('/office/shifts/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'shift', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(adminEquivalent({ page: 'drawer' })).toBe('/admin/till-drawer-events')
    expect(appPathFor('/admin/products')).toBe('/office/products')
    expect(appPathFor('/admin/products?tableSearch=milo 400')).toBe('/office/products?q=milo%20400') // the search carries over
    expect(appPathFor('/admin/products/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toBe('/office/a/products/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit') // the form stays
    expect(routeOf('/office/products/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'product', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(adminEquivalent({ page: 'product', id: 'x' })).toBe('/admin/products')
  })

  it('knows its own pages', () => {
    expect(routeOf('/office/')).toEqual({ page: 'home' })
    expect(routeOf('/office/todo')).toEqual({ page: 'todo' })
    expect(routeOf('/office/a/sales', '?x=1')).toEqual({ page: 'classic', admin: '/admin/sales?x=1' })
  })
})

describe('the menu', () => {
  it('finds pages by any word of their name or place, names that start with it first', () => {
    expect(findPages(menu, 'till').map((p) => p.label)).toEqual(['Till requests', 'Till sales'])
    expect(findPages(menu, 'catalog prod')[0]).toMatchObject({ label: 'Products', where: 'Stock › Catalog' })
    expect(findPages(menu, '')).toEqual([])
  })

  it('marks the area of the page shown, also for its sub-pages', () => {
    expect(tabOf(menu, '/admin/products/abc/edit')).toBe('Stock')
    expect(tabOf(menu, '/admin/till-requests?id=1')).toBe('Sales')
    expect(tabOf(menu, '/admin/elsewhere')).toBeNull()
  })
})
