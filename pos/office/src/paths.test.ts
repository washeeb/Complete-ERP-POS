import { describe, expect, it } from 'vitest'
import type { Menu } from './api'
import { adminPathFor, appPathFor, findPages, routeOf, tabOf } from './paths'

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
    expect(appPathFor('/admin/products?tableSearch=rice')).toBe('/office/a/products?tableSearch=rice')
    expect(appPathFor('/admin/products/abc/edit')).toBe('/office/a/products/abc/edit')
    expect(appPathFor('/admin')).toBe('/office/')
    expect(appPathFor('https://evil.example/admin')).toBe('/office/') // never another site
    expect(adminPathFor('/office/a/products?tableSearch=rice')).toBe('/admin/products?tableSearch=rice')
    expect(adminPathFor('/office/')).toBeNull()
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
