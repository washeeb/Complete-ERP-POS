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
    expect(appPathFor('/admin/waybills?tableSearch=rice')).toBe('/office/a/waybills?tableSearch=rice')
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
    expect(appPathFor('/admin/products/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toBe('/office/products/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')
    expect(appPathFor('/admin/products/create')).toBe('/office/products/new')
    expect(appPathFor('/admin/stock-levels?tableSearch=milo')).toBe('/office/stock?q=milo')
    expect(appPathFor('/admin/stock-movements')).toBe('/office/stock/movements')
    expect(appPathFor('/admin/stock-adjustments/create')).toBe('/office/adjustments/new')
    expect(appPathFor('/admin/stock-counts')).toBe('/office/counts')
    expect(appPathFor('/admin/stock-transfers/create')).toBe('/office/transfers/new')
    expect(appPathFor('/admin/stock-requests')).toBe('/office/requests')
    expect(appPathFor('/admin/stock-requests/create')).toBe('/office/requests/new')
    expect(routeOf('/office/requests/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'request', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(routeOf('/office/requests/new')).toEqual({ page: 'request-form' })
    expect(appPathFor('/admin/customers?tableSearch=kofi')).toBe('/office/customers?q=kofi')
    expect(appPathFor('/admin/customers/create')).toBe('/office/customers/new')
    expect(appPathFor('/admin/customers/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toBe('/office/customers/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')
    expect(routeOf('/office/customers/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'customer', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(routeOf('/office/customers/new')).toEqual({ page: 'customer-form', id: null })
    expect(adminEquivalent({ page: 'customer', id: 'x' })).toBe('/admin/customers')
    expect(appPathFor('/admin/invoices?tableSearch=INV-0001')).toBe('/office/invoices?q=INV-0001')
    expect(appPathFor('/admin/invoices/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toBe('/office/invoices/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')
    expect(routeOf('/office/invoices')).toEqual({ page: 'invoices' })
    expect(adminEquivalent({ page: 'invoice', id: 'x' })).toBe('/admin/invoices')
    expect(appPathFor('/admin/suppliers?tableSearch=ernest')).toBe('/office/suppliers?q=ernest')
    expect(appPathFor('/admin/suppliers/create')).toBe('/office/suppliers/new')
    expect(appPathFor('/admin/suppliers/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toBe('/office/suppliers/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')
    expect(routeOf('/office/suppliers/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toEqual({ page: 'supplier-form', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(adminEquivalent({ page: 'supplier', id: 'x' })).toBe('/admin/suppliers')
    expect(appPathFor('/admin/purchase-orders/create')).toBe('/office/purchase-orders/new')
    expect(appPathFor('/admin/purchase-orders/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toBe('/office/purchase-orders/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')
    expect(routeOf('/office/purchase-orders/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'purchase-order', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(adminEquivalent({ page: 'purchase-order-form', id: null })).toBe('/admin/purchase-orders')
    expect(appPathFor('/admin/goods-receipts?tableSearch=PO-HQ-000001')).toBe('/office/goods-received?q=PO-HQ-000001')
    expect(routeOf('/office/goods-received/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'goods-receipt', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(adminEquivalent({ page: 'goods-receipt', id: 'x' })).toBe('/admin/goods-receipts')
    expect(appPathFor('/admin/direct-purchases/create')).toBe('/office/direct-purchases/new')
    expect(routeOf('/office/direct-purchases/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toEqual({ page: 'direct-purchase-form', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(adminEquivalent({ page: 'direct-purchase', id: 'x' })).toBe('/admin/direct-purchases')
    expect(appPathFor('/admin/supplier-invoices')).toBe('/office/supplier-invoices')
    expect(routeOf('/office/consignment-to-pay')).toEqual({ page: 'consignment-to-pay' })
    expect(adminEquivalent({ page: 'supplier-invoices' })).toBe('/admin/supplier-invoices')
    expect(appPathFor('/admin/reorder-suggestions')).toBe('/office/what-to-order')
    expect(routeOf('/office/reorder-levels')).toEqual({ page: 'reorder-levels' })
    expect(adminEquivalent({ page: 'suggested-levels' })).toBe('/admin/suggested-levels')
    expect(appPathFor('/admin/expense-claims/create')).toBe('/office/expense-claims/new')
    expect(routeOf('/office/expense-claims/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'expense-claim', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(adminEquivalent({ page: 'expense-claim-form' })).toBe('/admin/expense-claims')
    expect(appPathFor('/admin/supplier-bills/create?invoice=0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toBe('/office/supplier-bills/new?invoice=0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')
    expect(appPathFor('/admin/supplier-bills/create?scan=0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toBe('/office/supplier-bills/new?scan=0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')
    expect(routeOf('/office/supplier-bills/new')).toEqual({ page: 'supplier-bill-form' })
    expect(appPathFor('/admin/aged-payables')).toBe('/office/aged-payables')
    expect(adminEquivalent({ page: 'supplier-payments' })).toBe('/admin/supplier-payments')
    expect(appPathFor('/admin/withholding-tax-return')).toBe('/office/withholding-tax')
    expect(appPathFor('/admin/report-builder?report=0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toBe('/office/report-builder?report=0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')
    expect(appPathFor('/admin/saved-reports')).toBe('/office/saved-reports')
    expect(appPathFor('/admin/insights?tab=stock')).toBe('/office/insights?tab=stock')
    expect(appPathFor('/admin/financial-reports?report=pl&from=2026-10-01')).toBe('/office/financial-reports?report=pl&from=2026-10-01')
    expect(routeOf('/office/transfers/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toEqual({ page: 'transfer-form', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(routeOf('/office/counts/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'count', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(appPathFor('/admin/stock-adjustments/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toBe('/office/adjustments/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')
    expect(routeOf('/office/adjustments/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toEqual({ page: 'adjustment-form', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(routeOf('/office/adjustments/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f')).toEqual({ page: 'adjustment', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
    expect(routeOf('/office/stock/movements')).toEqual({ page: 'movements' })
    expect(adminEquivalent({ page: 'stock' })).toBe('/admin/stock-levels')
    expect(routeOf('/office/products/new')).toEqual({ page: 'product-form', id: null })
    expect(routeOf('/office/products/0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f/edit')).toEqual({ page: 'product-form', id: '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f' })
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
