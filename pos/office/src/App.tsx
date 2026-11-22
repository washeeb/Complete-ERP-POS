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
import { ReferenceListPage } from './pages/ReferenceListPage'
import { PricingRulesPage } from './pages/PricingRulesPage'
import { PricesToReviewPage } from './pages/PricesToReviewPage'
import { PromotionsPage } from './pages/PromotionsPage'
import { PriceListPage } from './pages/PriceListPage'
import { PrescriptionsPage } from './pages/PrescriptionsPage'
import { ExpiryPage } from './pages/ExpiryPage'
import { AgedReceivablesPage } from './pages/AgedReceivablesPage'
import { GiftCardsPage } from './pages/GiftCardsPage'
import { BinsPage } from './pages/BinsPage'
import { EmployeeFormPage } from './pages/EmployeeFormPage'
import { EmployeesPage } from './pages/EmployeesPage'
import { LeavePage } from './pages/LeavePage'
import { LoansPage } from './pages/LoansPage'
import { MyHrPage } from './pages/MyHrPage'
import { PayrollPage } from './pages/PayrollPage'
import { PayrollRunPage } from './pages/PayrollRunPage'
import { TimesheetsPage } from './pages/TimesheetsPage'
import { CrmCampaignPage } from './pages/CrmCampaignPage'
import { CrmCampaignsPage } from './pages/CrmCampaignsPage'
import { CrmDealPage } from './pages/CrmDealPage'
import { CrmDealsPage } from './pages/CrmDealsPage'
import { CrmFollowUpsPage } from './pages/CrmFollowUpsPage'
import { CrmLeadPage } from './pages/CrmLeadPage'
import { CrmLeadsPage } from './pages/CrmLeadsPage'
import { CrmPipelinePage } from './pages/CrmPipelinePage'
import { CrmStagesPage } from './pages/CrmStagesPage'
import { AppointmentsPage } from './pages/AppointmentsPage'
import { CommissionsPage } from './pages/CommissionsPage'
import { LicenceKeysPage } from './pages/LicenceKeysPage'
import { MembershipPlansPage } from './pages/MembershipPlansPage'
import { MembershipsPage } from './pages/MembershipsPage'
import { ServicePassesPage } from './pages/ServicePassesPage'
import { StaffCalendarPage } from './pages/StaffCalendarPage'
import { GoLivePage } from './pages/GoLivePage'
import { ImportDataPage } from './pages/ImportDataPage'
import { ApiClientsPage } from './pages/ApiClientsPage'
import { MobileMoneyPage } from './pages/MobileMoneyPage'
import { SupportAccessPage } from './pages/SupportAccessPage'
import { SyncCenterPage } from './pages/SyncCenterPage'
import { AuditLogPage } from './pages/AuditLogPage'
import { BackupsPage } from './pages/BackupsPage'
import { IntegrationsPage } from './pages/IntegrationsPage'
import { LicencePage } from './pages/LicencePage'
import { ProblemReportsPage } from './pages/ProblemReportsPage'
import { SystemHealthPage } from './pages/SystemHealthPage'
import { BranchesPage } from './pages/BranchesPage'
import { BranchPage } from './pages/BranchPage'
import { BrandingPage } from './pages/BrandingPage'
import { HealthChecksPage } from './pages/HealthChecksPage'
import { IndustriesPage } from './pages/IndustriesPage'
import { LegalEntitiesPage } from './pages/LegalEntitiesPage'
import { ScaleItemsPage } from './pages/ScaleItemsPage'
import { CostRunFormPage } from './pages/CostRunFormPage'
import { CostRunPage } from './pages/CostRunPage'
import { CostRunsPage } from './pages/CostRunsPage'
import { EstimatedCostsPage } from './pages/EstimatedCostsPage'
import { LandedCostsPage } from './pages/LandedCostsPage'
import { SpendAnalyticsPage } from './pages/SpendAnalyticsPage'
import { AgreementFormPage } from './pages/AgreementFormPage'
import { AgreementPage } from './pages/AgreementPage'
import { AgreementsPage } from './pages/AgreementsPage'
import { PurchaseRequestFormPage } from './pages/PurchaseRequestFormPage'
import { PurchaseRequestPage } from './pages/PurchaseRequestPage'
import { PurchaseRequestsPage } from './pages/PurchaseRequestsPage'
import { RfqFormPage } from './pages/RfqFormPage'
import { RfqPage } from './pages/RfqPage'
import { RfqsPage } from './pages/RfqsPage'
import { MyDayPage } from './pages/MyDayPage'
import { SupplierReturnPage } from './pages/SupplierReturnPage'
import { SupplierReturnsPage } from './pages/SupplierReturnsPage'
import { WaybillFormPage } from './pages/WaybillFormPage'
import { WaybillPage } from './pages/WaybillPage'
import { WaybillsPage } from './pages/WaybillsPage'
import { OfficeReturnPage } from './pages/OfficeReturnPage'
import { OfficeReturnsPage } from './pages/OfficeReturnsPage'
import { ReviewQueuePage } from './pages/ReviewQueuePage'
import { TillRequestsPage } from './pages/TillRequestsPage'
import { CustomerOrderFormPage } from './pages/CustomerOrderFormPage'
import { CustomerOrderPage } from './pages/CustomerOrderPage'
import { CustomerOrdersPage } from './pages/CustomerOrdersPage'
import { CustomerPaymentsPage } from './pages/CustomerPaymentsPage'
import { CashDeskPage } from './pages/CashDeskPage'
import { CashSetupPage } from './pages/CashSetupPage'
import { CloseDayPage } from './pages/CloseDayPage'
import { IncomeControlPage } from './pages/IncomeControlPage'
import { NhisClaimsPage } from './pages/NhisClaimsPage'
import { NhisClaimPage } from './pages/NhisClaimPage'
import { SignoffsPage } from './pages/SignoffsPage'
import { RefillRemindersPage } from './pages/RefillRemindersPage'
import { DrugInteractionsPage } from './pages/DrugInteractionsPage'
import { ControlledRegisterPage } from './pages/ControlledRegisterPage'
import { SharedProductsPage } from './pages/SharedProductsPage'
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

  const LIST_TITLES: Partial<Record<typeof route.page, string>> = { home: 'Home', todo: 'To-do', sales: 'Till sales', shifts: 'Till shifts', drawer: 'Till drawer', products: 'Products', stock: 'Stock on hand', movements: 'Stock ledger', adjustments: 'Stock adjustments', counts: 'Stock counts', transfers: 'Transfers', requests: 'Stock requests', customers: 'Customers', invoices: 'Invoices', suppliers: 'Suppliers', 'purchase-orders': 'Purchase orders', 'goods-received': 'Goods received', 'direct-purchases': 'Direct purchases', 'supplier-invoices': 'Invoices from suppliers', 'consignment-to-pay': 'Consignment to pay', 'reorder-levels': 'Reorder levels', 'what-to-order': 'What to order', 'suggested-levels': 'Suggested levels', 'expense-claims': 'Expense claims', 'supplier-bills': 'Supplier bills', 'supplier-payments': 'Supplier payments', 'aged-payables': 'Aged payables', 'withholding-tax': 'Withholding tax', 'financial-reports': 'Financial reports', insights: 'Insights', 'report-builder': 'Report builder', 'saved-reports': 'Saved reports', shrink: 'Shrink', 'slow-stock': 'Slow & dead stock', batches: 'Batches & expiry', markdowns: 'Near-expiry markdowns', expiry: 'Expiry & returns', 'customer-orders': 'Quotes & orders', 'customer-payments': 'Payments received', 'aged-receivables': 'Aged receivables', 'till-requests': 'Till requests', 'office-returns': 'Back-office returns', 'review-queue': 'Review queue', 'gift-cards': 'Gift cards & credit', 'supplier-returns': 'Returns to suppliers', waybills: 'Waybills', 'waybill-form': 'New waybill', bins: 'Bins', 'my-day': 'My day', 'purchase-requests': 'Purchase requests', 'purchase-request-form': 'New purchase request', rfqs: 'Requests for quotation', 'rfq-form': 'New request for quotation', agreements: 'Contracts and blanket orders', 'landed-costs': 'Landed costs', 'cost-runs': 'Cost runs', 'cost-run-form': 'New cost run', 'estimated-costs': 'Estimated against real costs', 'spend-analytics': 'Spend analytics', branches: 'Branches', industries: 'Industries', 'legal-entities': 'Legal entities', branding: 'Your brand', 'health-checks': 'Health checks', 'scale-items': 'Scale items', employees: 'Employees', timesheets: 'Timesheets', leave: 'Leave', payroll: 'Payroll', loans: 'Loans & advances', 'my-hr': 'My leave & payslips', 'system-health': 'System health', backups: 'Backups', licence: 'Licence', 'audit-log': 'Audit log', 'problem-reports': 'Problem reports', integrations: 'Integrations', 'api-clients': 'API clients', 'mobile-money': 'Mobile money', 'support-access': 'Support access', 'sync-center': 'Sync Center', 'go-live': 'Going live', 'svc-calendar': 'Staff calendar', 'svc-appointments': 'Appointments', 'svc-passes': 'Prepaid passes', 'svc-memberships': 'Memberships', 'svc-plans': 'Membership plans', 'svc-commissions': 'Commissions', 'svc-keys': 'Licence keys', 'crm-pipeline': 'Pipeline', 'crm-leads': 'Leads', 'crm-deals': 'Deals', 'crm-follow-ups': 'Follow-ups', 'crm-campaigns': 'Campaigns', 'crm-stages': 'Pipeline stages', import: 'Import data', 'close-day': 'Close the day', 'cash-desk': 'Cash desk', 'income-control': 'Income control', 'cash-setup': 'Shifts and vaults', returns: 'Returns', journals: 'Journals', accounts: 'Chart of accounts', 'month-end': 'Month-end', budgets: 'Budgets', 'bank-accounts': 'Bank & cash', 'money-moved': 'Money moved', 'fixed-assets': 'Fixed assets', 'asset-kinds': 'Kinds of asset', 'accounting-export': 'Export to another accounting program', 'gra-evat': 'GRA E-VAT', users: 'Users', roles: 'Roles', 'access-requests': 'Access requests', settings: 'Settings', modules: 'Modules', messaging: 'Messaging', 'message-templates': 'Message templates', 'message-log': 'Message log', devices: 'Devices', computers: 'Computers', 'reference-list': 'Lists', 'pricing-rules': 'Pricing rules', 'prices-to-review': 'Prices to review', promotions: 'Promotions', 'price-list': 'Price list', prescriptions: 'Prescriptions', 'nhis-claims': 'NHIS claims', 'medicine-signoffs': 'Sales to sign off', 'refill-reminders': 'Refill reminders', 'drug-interactions': 'Drug interactions', 'controlled-drug-register': 'Controlled-drug register', 'shared-products': 'Shared products', 'company-wide-access': 'Company-wide access', 'who-can-do-what': 'Who can do what', 'it-activity': 'IT and support activity', 'staff-cards': 'Staff cards' }
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
                                                                      : route.page === 'customer-orders' ? <CustomerOrdersPage />
                                                                      : route.page === 'customer-order' ? <CustomerOrderPage id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'customer-order-form' ? <CustomerOrderFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'customer-payments' ? <CustomerPaymentsPage />
                                                                      : route.page === 'aged-receivables' ? <AgedReceivablesPage />
                                                                      : route.page === 'till-requests' ? <TillRequestsPage />
                                                                      : route.page === 'office-returns' ? <OfficeReturnsPage />
                                                                      : route.page === 'office-return' ? <OfficeReturnPage id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'review-queue' ? <ReviewQueuePage />
                                                                      : route.page === 'gift-cards' ? <GiftCardsPage />
                                                                      : route.page === 'supplier-returns' ? <SupplierReturnsPage />
                                                                      : route.page === 'supplier-return' ? <SupplierReturnPage id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'waybills' ? <WaybillsPage />
                                                                      : route.page === 'waybill' ? <WaybillPage id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'waybill-form' ? <WaybillFormPage />
                                                                      : route.page === 'bins' ? <BinsPage />
                                                                      : route.page === 'my-day' ? <MyDayPage />
                                                                      : route.page === 'purchase-requests' ? <PurchaseRequestsPage />
                                                                      : route.page === 'purchase-request' ? <PurchaseRequestPage id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'purchase-request-form' ? <PurchaseRequestFormPage />
                                                                      : route.page === 'rfqs' ? <RfqsPage />
                                                                      : route.page === 'rfq' ? <RfqPage id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'rfq-form' ? <RfqFormPage />
                                                                      : route.page === 'agreements' ? <AgreementsPage />
                                                                      : route.page === 'agreement' ? <AgreementPage id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'agreement-form' ? <AgreementFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'landed-costs' ? <LandedCostsPage />
                                                                      : route.page === 'cost-runs' ? <CostRunsPage />
                                                                      : route.page === 'cost-run' ? <CostRunPage key={route.id} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'cost-run-form' ? <CostRunFormPage />
                                                                      : route.page === 'estimated-costs' ? <EstimatedCostsPage />
                                                                      : route.page === 'spend-analytics' ? <SpendAnalyticsPage />
                                                                      : route.page === 'branches' ? <BranchesPage />
                                                                      : route.page === 'branch' ? <BranchPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'industries' ? <IndustriesPage />
                                                                      : route.page === 'legal-entities' ? <LegalEntitiesPage />
                                                                      : route.page === 'branding' ? <BrandingPage />
                                                                      : route.page === 'health-checks' ? <HealthChecksPage />
                                                                      : route.page === 'scale-items' ? <ScaleItemsPage />
                                                                      : route.page === 'employees' ? <EmployeesPage />
                                                                      : route.page === 'employee' ? <EmployeeFormPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'timesheets' ? <TimesheetsPage />
                                                                      : route.page === 'leave' ? <LeavePage />
                                                                      : route.page === 'payroll' ? <PayrollPage />
                                                                      : route.page === 'payroll-run' ? <PayrollRunPage key={route.id} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'loans' ? <LoansPage />
                                                                      : route.page === 'my-hr' ? <MyHrPage />
                                                                      : route.page === 'system-health' ? <SystemHealthPage />
                                                                      : route.page === 'backups' ? <BackupsPage />
                                                                      : route.page === 'licence' ? <LicencePage />
                                                                      : route.page === 'audit-log' ? <AuditLogPage />
                                                                      : route.page === 'problem-reports' ? <ProblemReportsPage />
                                                                      : route.page === 'integrations' ? <IntegrationsPage />
                                                                      : route.page === 'api-clients' ? <ApiClientsPage />
                                                                      : route.page === 'mobile-money' ? <MobileMoneyPage />
                                                                      : route.page === 'support-access' ? <SupportAccessPage />
                                                                      : route.page === 'sync-center' ? <SyncCenterPage />

                                                                      : route.page === 'crm-pipeline' ? <CrmPipelinePage />
                                                                      : route.page === 'crm-leads' ? <CrmLeadsPage />
                                                                      : route.page === 'crm-lead' ? <CrmLeadPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'crm-deals' ? <CrmDealsPage />
                                                                      : route.page === 'crm-deal' ? <CrmDealPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'crm-follow-ups' ? <CrmFollowUpsPage />
                                                                      : route.page === 'crm-campaigns' ? <CrmCampaignsPage />
                                                                      : route.page === 'crm-campaign' ? <CrmCampaignPage key={route.id ?? 'new'} id={route.id} onTitle={setTitle} />
                                                                      : route.page === 'crm-stages' ? <CrmStagesPage />
                                                                      : route.page === 'svc-calendar' ? <StaffCalendarPage />
                                                                      : route.page === 'svc-appointments' ? <AppointmentsPage />
                                                                      : route.page === 'svc-passes' ? <ServicePassesPage />
                                                                      : route.page === 'svc-memberships' ? <MembershipsPage />
                                                                      : route.page === 'svc-plans' ? <MembershipPlansPage />
                                                                      : route.page === 'svc-commissions' ? <CommissionsPage />
                                                                      : route.page === 'svc-keys' ? <LicenceKeysPage />
                                                                      : route.page === 'go-live' ? <GoLivePage />
                                                                      : route.page === 'import' ? <ImportDataPage />
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
                                                                                                                : route.page === 'expiry' ? <ExpiryPage />
                                                                                                                : route.page === 'close-day' ? <CloseDayPage />
                                                                                                                : route.page === 'cash-desk' ? <CashDeskPage />
                                                                                                                : route.page === 'income-control' ? <IncomeControlPage />
                                                                                                                : route.page === 'cash-setup' ? <CashSetupPage />
                                                                                                                : route.page === 'markdowns' ? <BatchesPage key={route.industry} industry={route.industry} />
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
                                                                                                                : route.page === 'reference-list' ? <ReferenceListPage key={route.kind} kind={route.kind} />
                                                                                                                : route.page === 'pricing-rules' ? <PricingRulesPage />
                                                                                                                : route.page === 'prices-to-review' ? <PricesToReviewPage />
                                                                                                                : route.page === 'promotions' ? <PromotionsPage />
                                                                                                                : route.page === 'price-list' ? <PriceListPage />
                                                                                                                : route.page === 'prescriptions' ? <PrescriptionsPage />
                                                                                                                : route.page === 'nhis-claims' ? <NhisClaimsPage />
                                                                                                                : route.page === 'nhis-claim' ? <NhisClaimPage id={route.id} onTitle={setTitle} />
                                                                                                                : route.page === 'medicine-signoffs' ? <SignoffsPage />
                                                                                                                : route.page === 'refill-reminders' ? <RefillRemindersPage />
                                                                                                                : route.page === 'drug-interactions' ? <DrugInteractionsPage />
                                                                                                                : route.page === 'controlled-drug-register' ? <ControlledRegisterPage />
                                                                                                                : route.page === 'shared-products' ? <SharedProductsPage />
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
