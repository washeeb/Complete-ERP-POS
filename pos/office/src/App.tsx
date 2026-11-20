import {
  Button, FluentProvider, makeStyles, MessageBar, MessageBarBody, MessageBarTitle, Spinner, Toast, Toaster, ToastTitle, tokens,
  useId, useToastController,
} from '@fluentui/react-components'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, type Me, type Menu, type Prefs } from './api'
import { useRoute } from './nav'
import { AgedPayablesPage } from './pages/AgedPayablesPage'
import { ClassicPage } from './pages/ClassicPage'
import { HomePage } from './pages/HomePage'
import { ConsignmentPage } from './pages/ConsignmentPage'
import { CustomerFormPage } from './pages/CustomerFormPage'
import { CustomerPage } from './pages/CustomerPage'
import { CustomersPage } from './pages/CustomersPage'
import { DirectPurchaseFormPage } from './pages/DirectPurchaseFormPage'
import { DirectPurchasePage } from './pages/DirectPurchasePage'
import { DirectPurchasesPage } from './pages/DirectPurchasesPage'
import { ExpenseClaimFormPage } from './pages/ExpenseClaimFormPage'
import { ExpenseClaimPage } from './pages/ExpenseClaimPage'
import { ExpenseClaimsPage } from './pages/ExpenseClaimsPage'
import { GoodsReceiptPage } from './pages/GoodsReceiptPage'
import { GoodsReceiptsPage } from './pages/GoodsReceiptsPage'
import { InvoicePage } from './pages/InvoicePage'
import { InvoicesPage } from './pages/InvoicesPage'
import { PurchaseOrderFormPage } from './pages/PurchaseOrderFormPage'
import { PurchaseOrderPage } from './pages/PurchaseOrderPage'
import { PurchaseOrdersPage } from './pages/PurchaseOrdersPage'
import { SupplierBillFormPage } from './pages/SupplierBillFormPage'
import { SupplierBillPage } from './pages/SupplierBillPage'
import { SupplierBillsPage } from './pages/SupplierBillsPage'
import { SupplierFormPage } from './pages/SupplierFormPage'
import { SupplierPaymentsPage } from './pages/SupplierPaymentsPage'
import { SuggestedLevelsPage } from './pages/SuggestedLevelsPage'
import { SupplierInvoicesPage } from './pages/SupplierInvoicesPage'
import { SupplierPage } from './pages/SupplierPage'
import { SuppliersPage } from './pages/SuppliersPage'
import { DrawerPage } from './pages/DrawerPage'
import { ProductFormPage } from './pages/ProductFormPage'
import { ProductPage } from './pages/ProductPage'
import { ProductsPage } from './pages/ProductsPage'
import { SalePage } from './pages/SalePage'
import { ShiftPage } from './pages/ShiftPage'
import { ShiftsPage } from './pages/ShiftsPage'
import { MovementsPage } from './pages/MovementsPage'
import { AdjustmentFormPage } from './pages/AdjustmentFormPage'
import { AdjustmentPage } from './pages/AdjustmentPage'
import { AdjustmentsPage } from './pages/AdjustmentsPage'
import { CountPage } from './pages/CountPage'
import { CountsPage } from './pages/CountsPage'
import { ReorderLevelsPage } from './pages/ReorderLevelsPage'
import { RequestFormPage } from './pages/RequestFormPage'
import { RequestPage } from './pages/RequestPage'
import { RequestsPage } from './pages/RequestsPage'
import { TransferFormPage } from './pages/TransferFormPage'
import { TransferPage } from './pages/TransferPage'
import { TransfersPage } from './pages/TransfersPage'
import { WhatToOrderPage } from './pages/WhatToOrderPage'
import { WithholdingTaxPage } from './pages/WithholdingTaxPage'
import { FinancialReportsPage } from './pages/FinancialReportsPage'
import { InsightsPage } from './pages/InsightsPage'
import { ReportBuilderPage } from './pages/ReportBuilderPage'
import { SavedReportsPage } from './pages/SavedReportsPage'
import { ShrinkPage } from './pages/ShrinkPage'
import { SlowStockPage } from './pages/SlowStockPage'
import { BatchesPage } from './pages/BatchesPage'
import { ReturnsPage } from './pages/ReturnsPage'
import { JournalsPage } from './pages/JournalsPage'
import { JournalPage } from './pages/JournalPage'
import { AccountsPage } from './pages/AccountsPage'
import { MonthEndPage } from './pages/MonthEndPage'
import { BudgetsPage } from './pages/BudgetsPage'
import { BankAccountsPage } from './pages/BankAccountsPage'
import { ReconcilePage } from './pages/ReconcilePage'
import { MoneyMovedPage } from './pages/MoneyMovedPage'
import { FixedAssetsPage } from './pages/FixedAssetsPage'
import { FixedAssetPage } from './pages/FixedAssetPage'
import { AssetKindsPage } from './pages/AssetKindsPage'
import { AccountingExportPage } from './pages/AccountingExportPage'
import { EvatPage } from './pages/EvatPage'
import { EvatReportPage } from './pages/EvatReportPage'
import { UsersPage } from './pages/UsersPage'
import { UserPage } from './pages/UserPage'
import { RolesPage } from './pages/RolesPage'
import { RolePage } from './pages/RolePage'
import { AccessRequestsPage } from './pages/AccessRequestsPage'
import { SettingsPage } from './pages/SettingsPage'
import { ModulesPage } from './pages/ModulesPage'
import { MessagingPage } from './pages/MessagingPage'
import { MessageTemplatesPage } from './pages/MessageTemplatesPage'
import { MessageLogPage } from './pages/MessageLogPage'
import { DevicesPage } from './pages/DevicesPage'
import { ComputersPage } from './pages/ComputersPage'
import { CompanyWidePage } from './pages/CompanyWidePage'
import { AccessReviewPage } from './pages/AccessReviewPage'
import { ItActivityPage } from './pages/ItActivityPage'
import { StaffCardsPage } from './pages/StaffCardsPage'
import { StockPage } from './pages/StockPage'
import { SalesPage } from './pages/SalesPage'
import { TodoPage } from './pages/TodoPage'
import { adminEquivalent, adminPathFor, tabOf } from './paths'
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
  useEffect(() => { // Fluent draws its own colours; the charts' colours follow the same light or dark
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
    document.documentElement.dataset.theme = dark ? 'dark' : 'light'
  }, [dark])

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

  const LIST_TITLES: Partial<Record<typeof route.page, string>> = { home: 'Home', todo: 'To-do', sales: 'Till sales', shifts: 'Till shifts', drawer: 'Till drawer', products: 'Products', stock: 'Stock on hand', movements: 'Stock ledger', adjustments: 'Stock adjustments', counts: 'Stock counts', transfers: 'Transfers', requests: 'Stock requests', customers: 'Customers', invoices: 'Invoices', suppliers: 'Suppliers', 'purchase-orders': 'Purchase orders', 'goods-received': 'Goods received', 'direct-purchases': 'Direct purchases', 'supplier-invoices': 'Invoices from suppliers', 'consignment-to-pay': 'Consignment to pay', 'reorder-levels': 'Reorder levels', 'what-to-order': 'What to order', 'suggested-levels': 'Suggested levels', 'expense-claims': 'Expense claims', 'supplier-bills': 'Supplier bills', 'supplier-payments': 'Supplier payments', 'aged-payables': 'Aged payables', 'withholding-tax': 'Withholding tax', 'financial-reports': 'Financial reports', insights: 'Insights', 'report-builder': 'Report builder', 'saved-reports': 'Saved reports', shrink: 'Shrink', 'slow-stock': 'Slow & dead stock', batches: 'Batches & expiry', returns: 'Returns', journals: 'Journals', accounts: 'Chart of accounts', 'month-end': 'Month-end', budgets: 'Budgets', 'bank-accounts': 'Bank & cash', 'money-moved': 'Money moved', 'fixed-assets': 'Fixed assets', 'asset-kinds': 'Kinds of asset', 'accounting-export': 'Export to another accounting program', 'gra-evat': 'GRA E-VAT', users: 'Users', roles: 'Roles', 'access-requests': 'Access requests', settings: 'Settings', modules: 'Modules', messaging: 'Messaging', 'message-templates': 'Message templates', 'message-log': 'Message log', devices: 'Devices', computers: 'Computers', 'company-wide-access': 'Company-wide access', 'who-can-do-what': 'Who can do what', 'it-activity': 'IT and support activity', 'staff-cards': 'Staff cards' }
  const shown = LIST_TITLES[route.page] ?? title
  useEffect(() => { document.title = shown ? `${shown} · ${me.company.name}` : me.company.name }, [shown, me.company.name])

  const onLook = useCallback((look: Prefs['office_look']) => {
    const before = me
    setMe({ ...me, prefs: { ...me.prefs, office_look: look } })
    api.setLook(look).then((r) => setMe({ ...me, prefs: r.prefs })).catch((e: unknown) => {
      setMe(before)
      dispatchToast(<Toast><ToastTitle>{e instanceof Error ? e.message : 'Not saved'}</ToastTitle></Toast>, { intent: 'error' })
    })
  }, [me, setMe, dispatchToast])

  // How many wait for this person's decision: the number on the To-do icon, fresh every half minute.
  const [todo, setTodo] = useState(0)
  useEffect(() => {
    const read = () => { api.todoCount().then((r) => setTodo(r.count)).catch(() => undefined) }
    read()
    const t = setInterval(read, 30000)
    return () => clearInterval(t)
  }, [])

  const admin = route.page === 'classic' ? route.admin : menu.home
  const same = adminEquivalent(route) // the current back office's page this one stands for: its menu area, its star
  const current = same ? tabOf(menu, same) : null
  const Look = me.prefs.office_look === 'side' ? SideLook : TopLook
  // The page on screen, for the star (add it to favourites): where the frame is now, which may be past the menu click.
  const page = route.page === 'classic' ? { url: adminPathFor(location.pathname + location.search) ?? route.admin, label: title || 'This page' }
    : same && LIST_TITLES[route.page] ? { url: same, label: LIST_TITLES[route.page]! } : null

  return (
    <>
      <Look me={me} menu={menu} current={current} page={page} todo={todo} atTodo={route.page === 'todo'} onLook={onLook} onFavourites={(favourites) => setMe({ ...me, favourites })}>
        {/* Pages rebuilt so far are the new ones; every other page is the current back office's, framed. */}
        {route.page === 'home' ? <HomePage me={me} menu={menu} />
          : route.page === 'todo' ? <TodoPage onCount={setTodo} />
            : route.page === 'sales' ? <SalesPage />
              : route.page === 'sale' ? <SalePage id={route.id} onTitle={setTitle} />
                : route.page === 'shifts' ? <ShiftsPage />
                  : route.page === 'shift' ? <ShiftPage id={route.id} onTitle={setTitle} />
                    : route.page === 'drawer' ? <DrawerPage />
                      : route.page === 'products' ? <ProductsPage />
                        : route.page === 'product' ? <ProductPage id={route.id} onTitle={setTitle} />
                          : route.page === 'product-form' ? <ProductFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                            : route.page === 'stock' ? <StockPage />
                              : route.page === 'movements' ? <MovementsPage />
                                : route.page === 'adjustments' ? <AdjustmentsPage />
                                  : route.page === 'adjustment' ? <AdjustmentPage id={route.id} onTitle={setTitle} />
                                    : route.page === 'adjustment-form' ? <AdjustmentFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                      : route.page === 'counts' ? <CountsPage />
                                        : route.page === 'count' ? <CountPage id={route.id} onTitle={setTitle} />
                                          : route.page === 'transfers' ? <TransfersPage />
                                            : route.page === 'transfer' ? <TransferPage id={route.id} onTitle={setTitle} />
                                              : route.page === 'transfer-form' ? <TransferFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                : route.page === 'requests' ? <RequestsPage />
                                                  : route.page === 'request' ? <RequestPage id={route.id} onTitle={setTitle} />
                                                    : route.page === 'request-form' ? <RequestFormPage onTitle={setTitle} />
                                                      : route.page === 'customers' ? <CustomersPage />
                                                        : route.page === 'customer' ? <CustomerPage id={route.id} onTitle={setTitle} />
                                                          : route.page === 'customer-form' ? <CustomerFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                            : route.page === 'invoices' ? <InvoicesPage />
                                                              : route.page === 'invoice' ? <InvoicePage id={route.id} onTitle={setTitle} />
                                                                : route.page === 'suppliers' ? <SuppliersPage />
                                                                  : route.page === 'supplier' ? <SupplierPage id={route.id} onTitle={setTitle} />
                                                                    : route.page === 'supplier-form' ? <SupplierFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'purchase-orders' ? <PurchaseOrdersPage />
                                                                        : route.page === 'purchase-order' ? <PurchaseOrderPage id={route.id} onTitle={setTitle} />
                                                                          : route.page === 'purchase-order-form' ? <PurchaseOrderFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                            : route.page === 'goods-received' ? <GoodsReceiptsPage />
                                                                              : route.page === 'goods-receipt' ? <GoodsReceiptPage id={route.id} onTitle={setTitle} />
                                                                                : route.page === 'direct-purchases' ? <DirectPurchasesPage />
                                                                                  : route.page === 'direct-purchase' ? <DirectPurchasePage id={route.id} onTitle={setTitle} />
                                                                                    : route.page === 'direct-purchase-form' ? <DirectPurchaseFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                                      : route.page === 'supplier-invoices' ? <SupplierInvoicesPage />
                                                                                        : route.page === 'consignment-to-pay' ? <ConsignmentPage />
                                                                                          : route.page === 'reorder-levels' ? <ReorderLevelsPage />
                                                                                            : route.page === 'what-to-order' ? <WhatToOrderPage />
                                                                                              : route.page === 'suggested-levels' ? <SuggestedLevelsPage />
                                                                                                : route.page === 'expense-claims' ? <ExpenseClaimsPage />
                                                                                                  : route.page === 'expense-claim' ? <ExpenseClaimPage id={route.id} onTitle={setTitle} />
                                                                                                    : route.page === 'expense-claim-form' ? <ExpenseClaimFormPage onTitle={setTitle} />
                                                                                                      : route.page === 'supplier-bills' ? <SupplierBillsPage />
                                                                                                        : route.page === 'supplier-bill' ? <SupplierBillPage id={route.id} onTitle={setTitle} />
                                                                                                          : route.page === 'supplier-bill-form' ? <SupplierBillFormPage onTitle={setTitle} />
                                                                                                            : route.page === 'supplier-payments' ? <SupplierPaymentsPage />
                                                                                                              : route.page === 'aged-payables' ? <AgedPayablesPage />
                                                                                                                : route.page === 'withholding-tax' ? <WithholdingTaxPage />
                                                                                                                : route.page === 'financial-reports' ? <FinancialReportsPage />
                                                                                                                : route.page === 'insights' ? <InsightsPage />
                                                                                                                : route.page === 'report-builder' ? <ReportBuilderPage />
                                                                                                                : route.page === 'saved-reports' ? <SavedReportsPage />
                                                                                                                : route.page === 'shrink' ? <ShrinkPage />
                                                                                                                : route.page === 'slow-stock' ? <SlowStockPage />
                                                                                                                : route.page === 'batches' ? <BatchesPage />
                                                                                                                : route.page === 'returns' ? <ReturnsPage />
                                                                                                                : route.page === 'journals' ? <JournalsPage />
                                                                                                                : route.page === 'journal' ? <JournalPage id={route.id} />
                                                                                                                : route.page === 'accounts' ? <AccountsPage />
                                                                                                                : route.page === 'month-end' ? <MonthEndPage />
                                                                                                                : route.page === 'budgets' ? <BudgetsPage />
                                                                                                                : route.page === 'bank-accounts' ? <BankAccountsPage />
                                                                                                                : route.page === 'reconcile' ? <ReconcilePage id={route.id} />
                                                                                                                : route.page === 'money-moved' ? <MoneyMovedPage />
                                                                                                                : route.page === 'fixed-assets' ? <FixedAssetsPage />
                                                                                                                : route.page === 'fixed-asset' ? <FixedAssetPage id={route.id} />
                                                                                                                : route.page === 'asset-kinds' ? <AssetKindsPage />
                                                                                                                : route.page === 'accounting-export' ? <AccountingExportPage />
                                                                                                                : route.page === 'gra-evat' ? <EvatPage />
                                                                                                                : route.page === 'gra-evat-report' ? <EvatReportPage id={route.id} />
                                                                                                                : route.page === 'users' ? <UsersPage />
                                                                                                                : route.page === 'user' ? <UserPage id={route.id} />
                                                                                                                : route.page === 'roles' ? <RolesPage />
                                                                                                                : route.page === 'role' ? <RolePage key={route.id ?? 'new'} id={route.id} />
                                                                                                                : route.page === 'access-requests' ? <AccessRequestsPage />
                                                                                                                : route.page === 'settings' ? <SettingsPage />
                                                                                                                : route.page === 'modules' ? <ModulesPage />
                                                                                                                : route.page === 'messaging' ? <MessagingPage />
                                                                                                                : route.page === 'message-templates' ? <MessageTemplatesPage />
                                                                                                                : route.page === 'message-log' ? <MessageLogPage />
                                                                                                                : route.page === 'devices' ? <DevicesPage />
                                                                                                                : route.page === 'computers' ? <ComputersPage />
                                                                                                                : route.page === 'company-wide-access' ? <CompanyWidePage />
                                                                                                                : route.page === 'who-can-do-what' ? <AccessReviewPage />
                                                                                                                : route.page === 'it-activity' ? <ItActivityPage />
                                                                                                                : route.page === 'staff-cards' ? <StaffCardsPage />
                      : <ClassicPage admin={admin} onTitle={setTitle} />}
      </Look>
      <Toaster toasterId={toaster} position="top-end" />
    </>
  )
}
