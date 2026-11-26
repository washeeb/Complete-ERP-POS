import type { Menu, MenuItem, MenuTab } from './api'

/**
 * Addresses. The new back office lives at /office; a page not yet rebuilt is the current back office's page (/admin/…)
 * opened inside it, at /office/a/… so the address bar, Back and bookmarks work as for any page.
 */
export const APP = '/office'

/**
 * Pages already rebuilt in the new back office (screen by screen, owner decision 2026-11-16): a link to the current
 * back office's page opens the new one instead. Each still opens the old way at /office/a/… ("classic version").
 */
const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
const REBUILT: { admin: RegExp; app: (m: RegExpMatchArray, query: URLSearchParams) => string }[] = [
  // Signing in and two-factor set-up (part 41) have their own pages.
  { admin: /^\/admin\/login\/?$/, app: () => `${APP}/sign-in` },
  { admin: /^\/admin\/multi-factor-authentication\/set-up\/?$/, app: () => `${APP}/two-factor-setup` },
  { admin: /^\/admin\/today\/?$/, app: (_, q) => `${APP}/today${q.get('yesterday') === '1' ? '?yesterday=1' : ''}` },
  { admin: /^\/admin\/departments\/?$/, app: () => `${APP}/departments` },
  { admin: /^\/admin\/payroll-rules\/?$/, app: () => `${APP}/payroll-rules` },
  { admin: /^\/admin\/setup-wizard\/?$/, app: () => `${APP}/setup-wizard` },
  { admin: /^\/admin\/profile\/?$/, app: () => `${APP}/profile` },
  { admin: /^\/admin\/confirm-its-you\/?$/, app: () => `${APP}/confirm-its-you` },
  { admin: /^\/admin\/sales\/?$/, app: () => `${APP}/sales` },
  { admin: new RegExp(`^/admin/sales/(${UUID})$`), app: (m) => `${APP}/sales/${m[1]}` },
  { admin: /^\/admin\/shifts\/?$/, app: () => `${APP}/shifts` },
  { admin: /^\/admin\/till-drawer-events\/?$/, app: () => `${APP}/drawer` },
  { admin: /^\/admin\/stock-levels\/?$/, app: (_, q) => `${APP}/stock${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/stock-movements\/?$/, app: () => `${APP}/stock/movements` },
  { admin: /^\/admin\/stock-transfers\/?$/, app: () => `${APP}/transfers` },
  { admin: /^\/admin\/stock-transfers\/create\/?$/, app: () => `${APP}/transfers/new` },
  { admin: new RegExp(`^/admin/stock-transfers/(${UUID})$`), app: (m) => `${APP}/transfers/${m[1]}` },
  { admin: new RegExp(`^/admin/stock-transfers/(${UUID})/edit$`), app: (m) => `${APP}/transfers/${m[1]}/edit` },
  { admin: /^\/admin\/stock-requests\/?$/, app: () => `${APP}/requests` },
  { admin: /^\/admin\/stock-requests\/create\/?$/, app: () => `${APP}/requests/new` },
  { admin: new RegExp(`^/admin/stock-requests/(${UUID})$`), app: (m) => `${APP}/requests/${m[1]}` },
  { admin: /^\/admin\/customers\/?$/, app: (_, q) => `${APP}/customers${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/customers\/create\/?$/, app: () => `${APP}/customers/new` },
  { admin: new RegExp(`^/admin/customers/(${UUID})$`), app: (m) => `${APP}/customers/${m[1]}` },
  { admin: new RegExp(`^/admin/customers/(${UUID})/edit$`), app: (m) => `${APP}/customers/${m[1]}/edit` },
  { admin: /^\/admin\/invoices\/?$/, app: (_, q) => `${APP}/invoices${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/invoices/(${UUID})$`), app: (m) => `${APP}/invoices/${m[1]}` },
  { admin: /^\/admin\/suppliers\/?$/, app: (_, q) => `${APP}/suppliers${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/suppliers\/create\/?$/, app: () => `${APP}/suppliers/new` },
  { admin: new RegExp(`^/admin/suppliers/(${UUID})/edit$`), app: (m) => `${APP}/suppliers/${m[1]}` },
  { admin: /^\/admin\/customer-orders\/?$/, app: (_, q) => `${APP}/customer-orders${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/customer-orders\/create\/?$/, app: () => `${APP}/customer-orders/new` },
  { admin: new RegExp(`^/admin/customer-orders/(${UUID})$`), app: (m) => `${APP}/customer-orders/${m[1]}` },
  { admin: new RegExp(`^/admin/customer-orders/(${UUID})/edit$`), app: (m) => `${APP}/customer-orders/${m[1]}/edit` },
  { admin: /^\/admin\/customer-payments\/?$/, app: (_, q) => `${APP}/customer-payments${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/aged-receivables\/?$/, app: () => `${APP}/aged-receivables` },
  { admin: /^\/admin\/till-requests\/?$/, app: (_, q) => `${APP}/till-requests${q.get('id') ? `?id=${encodeURIComponent(q.get('id')!)}` : ''}` },
  { admin: /^\/admin\/office-returns\/?$/, app: () => `${APP}/office-returns` },
  { admin: new RegExp(`^/admin/office-returns/(${UUID})$`), app: (m) => `${APP}/office-returns/${m[1]}` },
  { admin: /^\/admin\/review-items\/?$/, app: () => `${APP}/review-queue` },
  { admin: /^\/admin\/stored-values\/?$/, app: () => `${APP}/gift-cards` },
  { admin: /^\/admin\/supplier-returns\/?$/, app: (_, q) => `${APP}/supplier-returns${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/supplier-returns/(${UUID})$`), app: (m) => `${APP}/supplier-returns/${m[1]}` },
  { admin: /^\/admin\/waybills\/?$/, app: (_, q) => `${APP}/waybills${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/waybills\/create\/?$/, app: (_, q) => `${APP}/waybills/new${q.get('kind') ? `?kind=${encodeURIComponent(q.get('kind')!)}&source=${encodeURIComponent(q.get('source') ?? '')}` : ''}` },
  { admin: new RegExp(`^/admin/waybills/(${UUID})$`), app: (m) => `${APP}/waybills/${m[1]}` },
  { admin: /^\/admin\/bins\/?$/, app: () => `${APP}/bins` },
  { admin: /^\/admin\/stock\/my-day\/?$/, app: () => `${APP}/my-day` },
  { admin: /^\/admin\/purchase-requests\/?$/, app: (_, q) => `${APP}/purchase-requests${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/purchase-requests\/create\/?$/, app: () => `${APP}/purchase-requests/new` },
  { admin: new RegExp(`^/admin/purchase-requests/(${UUID})$`), app: (m) => `${APP}/purchase-requests/${m[1]}` },
  { admin: /^\/admin\/rfqs\/?$/, app: (_, q) => `${APP}/rfqs${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/rfqs\/create\/?$/, app: () => `${APP}/rfqs/new` },
  { admin: new RegExp(`^/admin/rfqs/(${UUID})$`), app: (m) => `${APP}/rfqs/${m[1]}` },
  { admin: /^\/admin\/purchase-agreements\/?$/, app: (_, q) => `${APP}/agreements${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/purchase-agreements\/create\/?$/, app: () => `${APP}/agreements/new` },
  { admin: new RegExp(`^/admin/purchase-agreements/(${UUID})$`), app: (m) => `${APP}/agreements/${m[1]}` },
  { admin: new RegExp(`^/admin/purchase-agreements/(${UUID})/edit$`), app: (m) => `${APP}/agreements/${m[1]}/edit` },
  { admin: /^\/admin\/landed-costs\/?$/, app: (_, q) => `${APP}/landed-costs${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/stock-cost-runs\/?$/, app: (_, q) => `${APP}/cost-runs${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/stock-cost-runs\/create\/?$/, app: () => `${APP}/cost-runs/new` },
  { admin: new RegExp(`^/admin/stock-cost-runs/(${UUID})$`), app: (m) => `${APP}/cost-runs/${m[1]}` },
  { admin: /^\/admin\/estimated-costs\/?$/, app: () => `${APP}/estimated-costs` },
  { admin: /^\/admin\/spend-analytics\/?$/, app: () => `${APP}/spend-analytics` },
  { admin: /^\/admin\/branches\/?$/, app: (_, q) => `${APP}/branches${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/branches\/create\/?$/, app: () => `${APP}/branches/new` },
  { admin: new RegExp(`^/admin/branches/(${UUID})(/edit)?$`), app: (m) => `${APP}/branches/${m[1]}` },
  { admin: /^\/admin\/industries\/?$/, app: () => `${APP}/industries` },
  { admin: /^\/admin\/legal-entities\/?$/, app: () => `${APP}/legal-entities` },
  { admin: /^\/admin\/branding\/?$/, app: () => `${APP}/branding` },
  { admin: /^\/admin\/health-checks\/?$/, app: () => `${APP}/health-checks` },
  { admin: /^\/admin\/grocery\/scale-items\/?$/, app: () => `${APP}/scale-items` },
  { admin: /^\/admin\/employees\/?$/, app: (_, q) => `${APP}/employees${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/employees\/create\/?$/, app: () => `${APP}/employees/new` },
  { admin: new RegExp(`^/admin/employees/(${UUID})(/edit)?$`), app: (m) => `${APP}/employees/${m[1]}` },
  { admin: /^\/admin\/timesheets\/?$/, app: (_, q) => `${APP}/timesheets${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/leave-requests\/?$/, app: () => `${APP}/leave` },
  { admin: /^\/admin\/payroll-runs\/?$/, app: () => `${APP}/payroll` },
  { admin: new RegExp(`^/admin/payroll-runs/(${UUID})$`), app: (m) => `${APP}/payroll/${m[1]}` },
  { admin: /^\/admin\/employee-loans\/?$/, app: () => `${APP}/loans` },
  { admin: /^\/admin\/my-hr\/?$/, app: () => `${APP}/my-hr` },
  { admin: /^\/admin\/health\/?$/, app: () => `${APP}/system-health` },
  { admin: /^\/admin\/backups\/?$/, app: () => `${APP}/backups` },
  { admin: /^\/admin\/licence\/?$/, app: () => `${APP}/licence` },
  { admin: /^\/admin\/audit-events\/?$/, app: (_, q) => `${APP}/audit-log${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/problem-reports\/?$/, app: () => `${APP}/problem-reports` },
  { admin: /^\/admin\/integrations\/?$/, app: () => `${APP}/integrations` },
  { admin: /^\/admin\/api-clients\/?$/, app: () => `${APP}/api-clients` },
  { admin: /^\/admin\/momo-charges\/?$/, app: () => `${APP}/mobile-money` },
  { admin: /^\/admin\/support-access-grants\/?$/, app: () => `${APP}/support-access` },
  { admin: /^\/admin\/sync-peers\/?$/, app: () => `${APP}/sync-center` },

  { admin: /^\/admin\/crm-pipeline\/?$/, app: () => `${APP}/crm/pipeline` },
  { admin: /^\/admin\/crm-leads\/?$/, app: (_, q) => `${APP}/crm/leads${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/crm-leads\/create\/?$/, app: () => `${APP}/crm/leads/new` },
  { admin: new RegExp(`^/admin/crm-leads/(${UUID})(/edit)?$`), app: (m) => `${APP}/crm/leads/${m[1]}` },
  { admin: /^\/admin\/crm-deals\/?$/, app: (_, q) => `${APP}/crm/deals${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/crm-deals\/create\/?$/, app: (_, q) => `${APP}/crm/deals/new${q.get('customer') ? `?customer=${encodeURIComponent(q.get('customer')!)}` : ''}` },
  { admin: new RegExp(`^/admin/crm-deals/(${UUID})(/edit)?$`), app: (m) => `${APP}/crm/deals/${m[1]}` },
  { admin: /^\/admin\/crm-follow-ups\/?$/, app: () => `${APP}/crm/follow-ups` },
  { admin: /^\/admin\/crm-campaigns\/?$/, app: () => `${APP}/crm/campaigns` },
  { admin: /^\/admin\/crm-campaigns\/create\/?$/, app: () => `${APP}/crm/campaigns/new` },
  { admin: new RegExp(`^/admin/crm-campaigns/(${UUID})(/edit)?$`), app: (m) => `${APP}/crm/campaigns/${m[1]}` },
  { admin: /^\/admin\/crm-stages\/?$/, app: () => `${APP}/crm/stages` },
  { admin: /^\/admin\/vans\/?$/, app: (_, q) => `${APP}/distribution/vans${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/vans/(${UUID})/edit$`), app: () => `${APP}/distribution/vans` },
  { admin: /^\/admin\/van-settlements\/?$/, app: () => `${APP}/distribution/settlements` },
  { admin: new RegExp(`^/admin/van-settlements/(${UUID})$`), app: (m) => `${APP}/distribution/settlements/${m[1]}` },
  { admin: /^\/admin\/routes\/?$/, app: (_, q) => `${APP}/distribution/routes${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/visits\/?$/, app: () => `${APP}/distribution/visits` },
  { admin: /^\/admin\/rep-targets\/?$/, app: () => `${APP}/distribution/targets` },
  { admin: /^\/admin\/rep-performance\/?$/, app: (_, q) => `${APP}/distribution/performance${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/work-orders\/?$/, app: (_, q) => `${APP}/manufacturing/orders${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/work-orders/(${UUID})$`), app: (m) => `${APP}/manufacturing/orders/${m[1]}` },
  { admin: /^\/admin\/boms\/?$/, app: (_, q) => `${APP}/manufacturing/boms${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/boms\/create\/?$/, app: () => `${APP}/manufacturing/boms/new` },
  { admin: new RegExp(`^/admin/boms/(${UUID})$`), app: (m) => `${APP}/manufacturing/boms/${m[1]}` },
  { admin: new RegExp(`^/admin/boms/(${UUID})/edit$`), app: (m) => `${APP}/manufacturing/boms/${m[1]}/edit` },
  { admin: /^\/admin\/production-planning\/?$/, app: () => `${APP}/manufacturing/planning` },
  { admin: /^\/admin\/work-centres\/?$/, app: () => `${APP}/manufacturing/centres` },
  { admin: /^\/admin\/styles\/?$/, app: (_, q) => `${APP}/fashion/styles${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/styles/(${UUID})$`), app: (m) => `${APP}/fashion/styles/${m[1]}` },
  { admin: /^\/admin\/alterations\/?$/, app: (_, q) => `${APP}/fashion/alterations${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/fashion-report\/?$/, app: (_, q) => `${APP}/fashion/sell-through${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/repair-jobs\/?$/, app: (_, q) => `${APP}/electronics/repairs${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/repair-jobs/(${UUID})$`), app: (m) => `${APP}/electronics/repairs/${m[1]}` },
  { admin: /^\/admin\/serial-numbers\/?$/, app: (_, q) => `${APP}/electronics/serials${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/serial-numbers/(${UUID})$`), app: (m) => `${APP}/electronics/serials/${m[1]}` },
  { admin: /^\/admin\/sales-orders\/?$/, app: (_, q) => `${APP}/online/orders${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/sales-orders/(${UUID})$`), app: (m) => `${APP}/online/orders/${m[1]}` },
  { admin: /^\/admin\/sales-channels\/?$/, app: () => `${APP}/online/channels` },
  { admin: /^\/admin\/sales-channels\/create\/?$/, app: () => `${APP}/online/channels/new` },
  { admin: new RegExp(`^/admin/sales-channels/(${UUID})(/edit)?$`), app: (m) => `${APP}/online/channels/${m[1]}` },
  { admin: /^\/admin\/whatsapp-orders\/?$/, app: (_, q) => `${APP}/online/whatsapp${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/whatsapp-orders/(${UUID})$`), app: (m) => `${APP}/online/whatsapp/${m[1]}` },
  { admin: /^\/admin\/sensors\/?$/, app: (_, q) => `${APP}/sensors${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/sensors\/create\/?$/, app: () => `${APP}/sensors/new` },
  { admin: new RegExp(`^/admin/sensors/(${UUID})(/edit)?$`), app: (m) => `${APP}/sensors/${m[1]}` },
  { admin: /^\/admin\/iot-alarms\/?$/, app: () => `${APP}/sensors/alarms` },
  { admin: /^\/admin\/temperature-log\/?$/, app: (_, q) => `${APP}/sensors/log${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/iot-clouds\/?$/, app: () => `${APP}/sensors/clouds` },
  { admin: /^\/admin\/equipment\/?$/, app: (_, q) => `${APP}/maintenance/equipment${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/equipment\/create\/?$/, app: () => `${APP}/maintenance/equipment/new` },
  { admin: new RegExp(`^/admin/equipment/(${UUID})(/edit)?$`), app: (m) => `${APP}/maintenance/equipment/${m[1]}` },
  { admin: /^\/admin\/maintenance-orders\/?$/, app: (_, q) => `${APP}/maintenance/orders${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/maintenance-orders\/create\/?$/, app: (_, q) => `${APP}/maintenance/orders/new${q.get('asset') ? `?asset=${encodeURIComponent(q.get('asset')!)}` : ''}` },
  { admin: new RegExp(`^/admin/maintenance-orders/(${UUID})$`), app: (m) => `${APP}/maintenance/orders/${m[1]}` },
  { admin: /^\/admin\/reservations\/?$/, app: (_, q) => `${APP}/restaurant/bookings${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/dining-orders\/?$/, app: (_, q) => `${APP}/restaurant/bills${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/dining-orders/(${UUID})$`), app: (m) => `${APP}/restaurant/bills?bill=${m[1]}` },
  { admin: /^\/admin\/dining-tables\/?$/, app: () => `${APP}/restaurant/tables` },
  { admin: /^\/admin\/kitchen-stations\/?$/, app: () => `${APP}/restaurant/stations` },
  { admin: /^\/admin\/modifier-groups\/?$/, app: () => `${APP}/restaurant/modifiers` },
  { admin: /^\/admin\/restaurant-report\/?$/, app: (_, q) => `${APP}/restaurant/tips-voids${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/copilot\/?$/, app: () => `${APP}/ask` },
  { admin: /^\/admin\/alerts\/?$/, app: (_, q) => `${APP}/alerts${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/ai-usage\/?$/, app: () => `${APP}/ai-usage` },
  { admin: /^\/admin\/tickets\/?$/, app: (_, q) => `${APP}/tickets${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/tickets\/create\/?$/, app: () => `${APP}/tickets/new` },
  { admin: new RegExp(`^/admin/tickets/(${UUID})$`), app: (m) => `${APP}/tickets/${m[1]}` },
  { admin: /^\/admin\/service-levels\/?$/, app: () => `${APP}/service-levels` },
  { admin: /^\/admin\/projects\/?$/, app: (_, q) => `${APP}/projects${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/projects\/create\/?$/, app: (_, q) => `${APP}/projects/new${q.get('customer') ? `?customer=${encodeURIComponent(q.get('customer')!)}` : ''}` },
  { admin: new RegExp(`^/admin/projects/(${UUID})(/edit)?$`), app: (m) => `${APP}/projects/${m[1]}` },
  { admin: /^\/admin\/my-time\/?$/, app: (_, q) => `${APP}/my-time${q.get('week') ? `?week=${encodeURIComponent(q.get('week')!)}` : ''}` },
  { admin: /^\/admin\/time-approvals\/?$/, app: () => `${APP}/time-approvals` },
  { admin: /^\/admin\/project-rates\/?$/, app: () => `${APP}/project-rates` },
  { admin: /^\/admin\/staff-calendar\/?$/, app: (_, q) => `${APP}/services/calendar${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/appointments\/?$/, app: () => `${APP}/services/appointments` },
  { admin: /^\/admin\/service-passes\/?$/, app: () => `${APP}/services/passes` },
  { admin: /^\/admin\/memberships\/?$/, app: () => `${APP}/services/memberships` },
  { admin: /^\/admin\/membership-plans\/?$/, app: () => `${APP}/services/plans` },
  { admin: /^\/admin\/commissions\/?$/, app: (_, q) => `${APP}/services/commissions${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/licence-keys\/?$/, app: () => `${APP}/services/keys` },
  { admin: /^\/admin\/go-live\/?$/, app: () => `${APP}/go-live` },
  { admin: /^\/admin\/import-data\/?$/, app: (_, q) => `${APP}/import${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/purchase-orders\/?$/, app: (_, q) => `${APP}/purchase-orders${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/purchase-orders\/create\/?$/, app: () => `${APP}/purchase-orders/new` },
  { admin: new RegExp(`^/admin/purchase-orders/(${UUID})$`), app: (m) => `${APP}/purchase-orders/${m[1]}` },
  { admin: new RegExp(`^/admin/purchase-orders/(${UUID})/edit$`), app: (m) => `${APP}/purchase-orders/${m[1]}/edit` },
  { admin: /^\/admin\/goods-receipts\/?$/, app: (_, q) => `${APP}/goods-received${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/goods-receipts/(${UUID})$`), app: (m) => `${APP}/goods-received/${m[1]}` },
  { admin: /^\/admin\/direct-purchases\/?$/, app: (_, q) => `${APP}/direct-purchases${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/direct-purchases\/create\/?$/, app: () => `${APP}/direct-purchases/new` },
  { admin: new RegExp(`^/admin/direct-purchases/(${UUID})$`), app: (m) => `${APP}/direct-purchases/${m[1]}` },
  { admin: new RegExp(`^/admin/direct-purchases/(${UUID})/edit$`), app: (m) => `${APP}/direct-purchases/${m[1]}/edit` },
  { admin: /^\/admin\/supplier-invoices\/?$/, app: () => `${APP}/supplier-invoices` },
  { admin: /^\/admin\/consignment-to-pay\/?$/, app: () => `${APP}/consignment-to-pay` },
  { admin: /^\/admin\/reorder-rules\/?$/, app: (_, q) => `${APP}/reorder-levels${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/reorder-suggestions\/?$/, app: () => `${APP}/what-to-order` },
  { admin: /^\/admin\/suggested-levels\/?$/, app: () => `${APP}/suggested-levels` },
  { admin: /^\/admin\/expense-claims\/?$/, app: (_, q) => `${APP}/expense-claims${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/expense-claims\/create\/?$/, app: () => `${APP}/expense-claims/new` },
  { admin: new RegExp(`^/admin/expense-claims/(${UUID})$`), app: (m) => `${APP}/expense-claims/${m[1]}` },
  { admin: /^\/admin\/supplier-bills\/?$/, app: (_, q) => `${APP}/supplier-bills${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/supplier-bills\/create\/?$/, app: (_, q) => `${APP}/supplier-bills/new${q.get('scan') ? `?scan=${encodeURIComponent(q.get('scan')!)}` : q.get('invoice') ? `?invoice=${encodeURIComponent(q.get('invoice')!)}` : ''}` },
  { admin: new RegExp(`^/admin/supplier-bills/(${UUID})$`), app: (m) => `${APP}/supplier-bills/${m[1]}` },
  { admin: /^\/admin\/supplier-payments\/?$/, app: (_, q) => `${APP}/supplier-payments${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/aged-payables\/?$/, app: () => `${APP}/aged-payables` },
  { admin: /^\/admin\/withholding-tax-return\/?$/, app: () => `${APP}/withholding-tax` },
  { admin: /^\/admin\/report-builder\/?$/, app: (_, q) => `${APP}/report-builder${q.get('report') ? `?report=${encodeURIComponent(q.get('report')!)}` : ''}` },
  { admin: /^\/admin\/saved-reports\/?$/, app: () => `${APP}/saved-reports` },
  { admin: /^\/admin\/fixed-assets\/?$/, app: (_, q) => `${APP}/fixed-assets${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/fixed-assets\/create\/?$/, app: () => `${APP}/fixed-assets?register=1` },
  { admin: new RegExp(`^/admin/fixed-assets/(${UUID})$`), app: (m) => `${APP}/fixed-assets/${m[1]}` },
  { admin: /^\/admin\/asset-classes\/?$/, app: () => `${APP}/asset-kinds` },
  { admin: /^\/admin\/accounting-export\/?$/, app: () => `${APP}/accounting-export` },
  { admin: /^\/admin\/company-wide-access\/?$/, app: () => `${APP}/company-wide-access` },
  { admin: /^\/admin\/access-review\/?$/, app: () => `${APP}/who-can-do-what` },
  { admin: /^\/admin\/it-activity\/?$/, app: () => `${APP}/it-activity` },
  { admin: /^\/admin\/staff-cards\/?$/, app: () => `${APP}/staff-cards` },
  { admin: /^\/admin\/manage-settings\/?$/, app: () => `${APP}/settings` },
  { admin: /^\/admin\/manage-modules\/?$/, app: () => `${APP}/modules` },
  { admin: /^\/admin\/messaging\/?$/, app: () => `${APP}/messaging` },
  { admin: /^\/admin\/message-templates\/?$/, app: () => `${APP}/message-templates` },
  { admin: /^\/admin\/message-log\/?$/, app: (_, q) => `${APP}/message-log${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/devices\/?$/, app: (_, q) => `${APP}/devices${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/devices\/create\/?$/, app: () => `${APP}/devices?register=1` },
  { admin: /^\/admin\/computers\/?$/, app: () => `${APP}/computers` },
  { admin: /^\/admin\/categories\/?$/, app: (_, q) => `${APP}/categories${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/brands\/?$/, app: (_, q) => `${APP}/brands${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/uoms\/?$/, app: (_, q) => `${APP}/units${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/tax-rates\/?$/, app: (_, q) => `${APP}/tax-rates${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/tax-groups\/?$/, app: (_, q) => `${APP}/tax-groups${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/price-lists\/?$/, app: (_, q) => `${APP}/price-lists${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/nhis-claims\/?$/, app: () => `${APP}/nhis-claims` },
  { admin: new RegExp(`^/admin/nhis-claims/(${UUID})$`), app: (m) => `${APP}/nhis-claims/${m[1]}` },
  { admin: /^\/admin\/medicine-signoffs\/?$/, app: () => `${APP}/medicine-signoffs` },
  { admin: /^\/admin\/prescriptions\/?$/, app: () => `${APP}/prescriptions` },
  { admin: new RegExp(`^/admin/prescriptions/(${UUID})$`), app: (m) => `${APP}/prescriptions?open=${m[1]}` },
  { admin: /^\/admin\/refill-reminders\/?$/, app: () => `${APP}/refill-reminders` },
  { admin: /^\/admin\/drug-interactions\/?$/, app: () => `${APP}/drug-interactions` },
  { admin: /^\/admin\/controlled-drug-register\/?$/, app: () => `${APP}/controlled-drug-register` },
  { admin: /^\/admin\/price-list\/?$/, app: (_, q) => `${APP}/price-list${q.get('only') === '1' ? '?only=1' : ''}` },
  { admin: /^\/admin\/shared-products\/?$/, app: () => `${APP}/shared-products` },
  { admin: /^\/admin\/promotions\/?$/, app: () => `${APP}/promotions` },
  { admin: /^\/admin\/promotions\/create\/?$/, app: () => `${APP}/promotions?new=1` },
  { admin: new RegExp(`^/admin/promotions/(${UUID})/edit$`), app: (m) => `${APP}/promotions?edit=${m[1]}` },
  { admin: /^\/admin\/pricing-rules\/?$/, app: () => `${APP}/pricing-rules` },
  { admin: /^\/admin\/prices-to-review\/?$/, app: () => `${APP}/prices-to-review` },
  { admin: /^\/admin\/roles\/?$/, app: (_, q) => `${APP}/roles${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/roles\/create\/?$/, app: () => `${APP}/roles/new` },
  { admin: new RegExp(`^/admin/roles/(${UUID})(/edit)?$`), app: (m) => `${APP}/roles/${m[1]}` },
  { admin: /^\/admin\/access-requests\/?$/, app: () => `${APP}/access-requests` },
  { admin: /^\/admin\/users\/?$/, app: (_, q) => `${APP}/users${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/users\/create\/?$/, app: () => `${APP}/users?add=1` },
  { admin: new RegExp(`^/admin/users/(${UUID})(/edit)?$`), app: (m) => `${APP}/users/${m[1]}` },
  { admin: /^\/admin\/gra-evat\/?$/, app: (_, q) => `${APP}/gra-evat${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/bank-accounts\/?$/, app: () => `${APP}/bank-accounts` },
  { admin: /^\/admin\/bank-reconciliation\/?$/, app: (_, q) => (q.get('bank') ? `${APP}/bank-accounts/${encodeURIComponent(q.get('bank')!)}/reconcile` : `${APP}/bank-accounts`) },
  { admin: /^\/admin\/money-transfers\/?$/, app: () => `${APP}/money-moved` },
  { admin: /^\/admin\/accounts\/?$/, app: (_, q) => `${APP}/accounts${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/fiscal-periods\/?$/, app: () => `${APP}/month-end` },
  { admin: /^\/admin\/budgets\/?$/, app: (_, q) => `${APP}/budgets${q.toString() ? `?${q}` : ''}` },
  { admin: /^\/admin\/journal-entries\/?$/, app: (_, q) => `${APP}/journals${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/journal-entries/(${UUID})$`), app: (m) => `${APP}/journals/${m[1]}` },
  { admin: /^\/admin\/returns\/?$/, app: (_, q) => `${APP}/returns${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/(grocery|pharmacy)\/markdowns\/?$/, app: (m, q) => `${APP}/${m[1]}/markdowns${q.get('warehouse') ? `?warehouse=${encodeURIComponent(q.get('warehouse')!)}` : ''}` },
  { admin: /^\/admin\/expiry-returns\/?$/, app: () => `${APP}/expiry` },
  { admin: /^\/admin\/cash\/close-day\/?$/, app: (_, q) => `${APP}/close-day${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/cash-desk\/?$/, app: () => `${APP}/cash-desk` },
  { admin: /^\/admin\/income-control\/?$/, app: () => `${APP}/income-control` },
  { admin: /^\/admin\/cash-setup\/?$/, app: () => `${APP}/cash-setup` },
  { admin: /^\/admin\/batches-expiry\/?$/, app: (_, q) => `${APP}/batches${q.get('warehouse') ? `?warehouse=${encodeURIComponent(q.get('warehouse')!)}` : ''}` },
  { admin: /^\/admin\/shrink-report\/?$/, app: (_, q) => `${APP}/shrink${q.toString() ? `?${q}` : ''}` },
  { admin: /^\/admin\/stock\/slow-stock\/?$/, app: (_, q) => `${APP}/slow-stock${q.toString() ? `?${q}` : ''}` },
  { admin: /^\/admin\/insights\/?$/, app: (_, q) => `${APP}/insights${q.toString() ? `?${q}` : ''}` }, // the tab and warehouse carry over
  { admin: /^\/admin\/financial-reports\/?$/, app: (_, q) => `${APP}/financial-reports${q.toString() ? `?${q}` : ''}` }, // the report and dates carry over
  { admin: /^\/admin\/stock-counts\/?$/, app: () => `${APP}/counts` },
  { admin: new RegExp(`^/admin/stock-counts/(${UUID})$`), app: (m) => `${APP}/counts/${m[1]}` },
  { admin: /^\/admin\/stock-adjustments\/?$/, app: () => `${APP}/adjustments` },
  { admin: /^\/admin\/stock-adjustments\/create\/?$/, app: () => `${APP}/adjustments/new` },
  { admin: new RegExp(`^/admin/stock-adjustments/(${UUID})$`), app: (m) => `${APP}/adjustments/${m[1]}` },
  { admin: new RegExp(`^/admin/stock-adjustments/(${UUID})/edit$`), app: (m) => `${APP}/adjustments/${m[1]}/edit` },
  { admin: /^\/admin\/products\/create\/?$/, app: () => `${APP}/products/new` },
  { admin: new RegExp(`^/admin/products/(${UUID})/edit$`), app: (m) => `${APP}/products/${m[1]}/edit` },
  { admin: /^\/admin\/products\/?$/, app: (_, q) => `${APP}/products${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` }, // a search carries over
]

/** /admin/products?x=1 → /office/a/products?x=1; /admin → /office/ (home); a rebuilt page → its new address. Anything else is not ours: home. */
export function appPathFor(adminPath: string): string {
  if (/^\/office(\/|$)/.test(adminPath)) return adminPath // already a new-look address (the server sends these now, part 42)
  const m = adminPath.match(/^\/admin(\/[^?#]*)?(\?[^#]*)?/)
  if (!m) return `${APP}/`
  const path = `/admin${m[1] ?? ''}`
  for (const r of REBUILT) {
    const hit = path.match(r.admin)
    if (hit) return r.app(hit, new URLSearchParams(m[2] ?? ''))
  }
  const rest = (m[1] ?? '').replace(/\/$/, '')
  return rest === '' ? `${APP}/${m[2] ?? ''}` : `${APP}/a${rest}${m[2] ?? ''}`
}

/** The inverse: which current back-office page an /office address shows (null = a page of the new back office itself). */
export function adminPathFor(appPath: string): string | null {
  const m = appPath.match(/^\/office\/a(\/[^?#]*)(\?[^#]*)?/)
  return m ? `/admin${m[1]}${m[2] ?? ''}` : null
}

/** The new back office's own pages. */
export type Route = { page: 'home' } | { page: 'todo' } | { page: 'sales' } | { page: 'sale'; id: string } | { page: 'shifts' } | { page: 'shift'; id: string }
  | { page: 'drawer' } | { page: 'products' } | { page: 'product'; id: string } | { page: 'product-form'; id: string | null } | { page: 'stock' } | { page: 'movements' }
  | { page: 'adjustments' } | { page: 'adjustment'; id: string } | { page: 'adjustment-form'; id: string | null }
  | { page: 'counts' } | { page: 'count'; id: string }
  | { page: 'customers' } | { page: 'customer'; id: string } | { page: 'customer-form'; id: string | null }
  | { page: 'invoices' } | { page: 'invoice'; id: string }
  | { page: 'suppliers' } | { page: 'supplier'; id: string } | { page: 'supplier-form'; id: string | null }
  | { page: 'purchase-orders' } | { page: 'purchase-order'; id: string } | { page: 'purchase-order-form'; id: string | null }
  | { page: 'customer-orders' } | { page: 'customer-order'; id: string } | { page: 'customer-order-form'; id: string | null } | { page: 'customer-payments' } | { page: 'aged-receivables' }
  | { page: 'till-requests' } | { page: 'office-returns' } | { page: 'office-return'; id: string } | { page: 'review-queue' } | { page: 'gift-cards' }
  | { page: 'supplier-returns' } | { page: 'supplier-return'; id: string } | { page: 'waybills' } | { page: 'waybill'; id: string } | { page: 'waybill-form' } | { page: 'bins' } | { page: 'my-day' }
  | { page: 'purchase-requests' } | { page: 'purchase-request'; id: string } | { page: 'purchase-request-form' }
  | { page: 'rfqs' } | { page: 'rfq'; id: string } | { page: 'rfq-form' }
  | { page: 'agreements' } | { page: 'agreement'; id: string } | { page: 'agreement-form'; id: string | null }
  | { page: 'landed-costs' } | { page: 'cost-runs' } | { page: 'cost-run'; id: string } | { page: 'cost-run-form' } | { page: 'estimated-costs' } | { page: 'spend-analytics' }
  | { page: 'branches' } | { page: 'branch'; id: string | null } | { page: 'industries' } | { page: 'legal-entities' } | { page: 'branding' } | { page: 'health-checks' } | { page: 'scale-items' }
  | { page: 'employees' } | { page: 'employee'; id: string | null } | { page: 'timesheets' } | { page: 'leave' } | { page: 'payroll' } | { page: 'payroll-run'; id: string } | { page: 'loans' } | { page: 'my-hr' }
  | { page: 'system-health' } | { page: 'backups' } | { page: 'licence' } | { page: 'audit-log' } | { page: 'problem-reports' } | { page: 'integrations' }
  | { page: 'api-clients' } | { page: 'mobile-money' } | { page: 'support-access' } | { page: 'sync-center' }
  | { page: 'go-live' } | { page: 'import' }
  | { page: 'svc-calendar' } | { page: 'svc-appointments' } | { page: 'svc-passes' } | { page: 'svc-memberships' } | { page: 'svc-plans' } | { page: 'svc-commissions' } | { page: 'svc-keys' }
  | { page: 'projects' } | { page: 'project'; id: string | null } | { page: 'my-time' } | { page: 'time-approvals' } | { page: 'project-rates' }
  | { page: 'tickets' } | { page: 'ticket-new' } | { page: 'ticket'; id: string } | { page: 'service-levels' }
  | { page: 'ask' } | { page: 'alerts' } | { page: 'ai-usage' } | { page: 'today' } | { page: 'departments' } | { page: 'payroll-rules' } | { page: 'setup-wizard' } | { page: 'profile' } | { page: 'confirm-its-you' }
  | { page: 'rest-bookings' } | { page: 'rest-bills' } | { page: 'rest-tables' } | { page: 'rest-stations' } | { page: 'rest-modifiers' } | { page: 'rest-report' }
  | { page: 'mnt-equipment' } | { page: 'mnt-asset'; id: string | null } | { page: 'mnt-orders' } | { page: 'mnt-order-new' } | { page: 'mnt-order'; id: string }
  | { page: 'iot-sensors' } | { page: 'iot-sensor'; id: string | null } | { page: 'iot-alarms' } | { page: 'iot-log' } | { page: 'iot-clouds' }
  | { page: 'onl-orders' } | { page: 'onl-order'; id: string } | { page: 'onl-channels' } | { page: 'onl-channel'; id: string | null } | { page: 'onl-whatsapp' } | { page: 'onl-message'; id: string }
  | { page: 'dist-vans' } | { page: 'dist-settlements' } | { page: 'dist-settlement'; id: string } | { page: 'dist-routes' } | { page: 'dist-visits' } | { page: 'dist-targets' }
  | { page: 'dist-performance' }
  | { page: 'mfg-orders' } | { page: 'mfg-order'; id: string } | { page: 'mfg-boms' } | { page: 'mfg-bom'; id: string } | { page: 'mfg-bom-form'; id: string | null }
  | { page: 'mfg-planning' } | { page: 'mfg-centres' }
  | { page: 'fsh-styles' } | { page: 'fsh-style'; id: string } | { page: 'fsh-style-form'; id: string | null } | { page: 'fsh-alterations' }
  | { page: 'fsh-sell-through' } | { page: 'ele-repairs' } | { page: 'ele-repair'; id: string } | { page: 'ele-serials' } | { page: 'ele-serial'; id: string }
  | { page: 'crm-pipeline' } | { page: 'crm-leads' } | { page: 'crm-lead'; id: string | null } | { page: 'crm-deals' } | { page: 'crm-deal'; id: string | null }
  | { page: 'crm-follow-ups' } | { page: 'crm-campaigns' } | { page: 'crm-campaign'; id: string | null } | { page: 'crm-stages' }
  | { page: 'goods-received' } | { page: 'goods-receipt'; id: string }
  | { page: 'direct-purchases' } | { page: 'direct-purchase'; id: string } | { page: 'direct-purchase-form'; id: string | null }
  | { page: 'supplier-invoices' } | { page: 'consignment-to-pay' } | { page: 'reorder-levels' } | { page: 'what-to-order' } | { page: 'suggested-levels' }
  | { page: 'expense-claims' } | { page: 'expense-claim'; id: string } | { page: 'expense-claim-form' }
  | { page: 'supplier-bills' } | { page: 'supplier-bill'; id: string } | { page: 'supplier-bill-form' } | { page: 'supplier-payments' } | { page: 'aged-payables' } | { page: 'withholding-tax' } | { page: 'financial-reports' } | { page: 'insights' } | { page: 'report-builder' } | { page: 'saved-reports' } | { page: 'shrink' } | { page: 'slow-stock' } | { page: 'batches' } | { page: 'expiry' } | { page: 'close-day' } | { page: 'cash-desk' } | { page: 'income-control' } | { page: 'cash-setup' } | { page: 'markdowns'; industry: 'grocery' | 'pharmacy' } | { page: 'returns' } | { page: 'journals' } | { page: 'accounts' } | { page: 'month-end' } | { page: 'budgets' } | { page: 'bank-accounts' } | { page: 'reconcile'; id: string } | { page: 'money-moved' } | { page: 'fixed-assets' } | { page: 'fixed-asset'; id: string } | { page: 'asset-kinds' } | { page: 'accounting-export' } | { page: 'gra-evat' } | { page: 'gra-evat-report'; id: string } | { page: 'users' } | { page: 'user'; id: string } | { page: 'roles' } | { page: 'role'; id: string | null } | { page: 'access-requests' } | { page: 'settings' } | { page: 'modules' } | { page: 'messaging' } | { page: 'message-templates' } | { page: 'message-log' } | { page: 'devices' } | { page: 'computers' } | { page: 'pricing-rules' } | { page: 'promotions' } | { page: 'price-list' } | { page: 'prescriptions' } | { page: 'nhis-claims' } | { page: 'nhis-claim'; id: string } | { page: 'medicine-signoffs' } | { page: 'refill-reminders' } | { page: 'drug-interactions' } | { page: 'controlled-drug-register' } | { page: 'shared-products' } | { page: 'prices-to-review' } | { page: 'reference-list'; kind: 'categories' | 'brands' | 'units' | 'tax-rates' | 'tax-groups' | 'price-lists' } | { page: 'company-wide-access' } | { page: 'who-can-do-what' } | { page: 'it-activity' } | { page: 'staff-cards' } | { page: 'journal'; id: string }
  | { page: 'requests' } | { page: 'request'; id: string } | { page: 'request-form' }
  | { page: 'transfers' } | { page: 'transfer'; id: string } | { page: 'transfer-form'; id: string | null } | { page: 'classic'; admin: string }

export function routeOf(path: string, search = ''): Route {
  const admin = adminPathFor(path + search)
  if (admin) return { page: 'classic', admin }
  if (/^\/office\/todo\/?$/.test(path)) return { page: 'todo' }
  if (/^\/office\/sales\/?$/.test(path)) return { page: 'sales' }
  const sale = path.match(new RegExp(`^/office/sales/(${UUID})/?$`))
  if (sale) return { page: 'sale', id: sale[1] }
  if (/^\/office\/shifts\/?$/.test(path)) return { page: 'shifts' }
  const shift = path.match(new RegExp(`^/office/shifts/(${UUID})/?$`))
  if (shift) return { page: 'shift', id: shift[1] }
  if (/^\/office\/drawer\/?$/.test(path)) return { page: 'drawer' }
  if (/^\/office\/products\/?$/.test(path)) return { page: 'products' }
  if (/^\/office\/stock\/?$/.test(path)) return { page: 'stock' }
  if (/^\/office\/stock\/movements\/?$/.test(path)) return { page: 'movements' }
  if (/^\/office\/transfers\/?$/.test(path)) return { page: 'transfers' }
  if (/^\/office\/transfers\/new\/?$/.test(path)) return { page: 'transfer-form', id: null }
  const tr = path.match(new RegExp(`^/office/transfers/(${UUID})(/edit)?/?$`))
  if (tr) return tr[2] ? { page: 'transfer-form', id: tr[1] } : { page: 'transfer', id: tr[1] }
  if (/^\/office\/customers\/?$/.test(path)) return { page: 'customers' }
  if (/^\/office\/customers\/new\/?$/.test(path)) return { page: 'customer-form', id: null }
  const cust = path.match(new RegExp(`^/office/customers/(${UUID})(/edit)?/?$`))
  if (cust) return cust[2] ? { page: 'customer-form', id: cust[1] } : { page: 'customer', id: cust[1] }
  if (/^\/office\/invoices\/?$/.test(path)) return { page: 'invoices' }
  const inv = path.match(new RegExp(`^/office/invoices/(${UUID})/?$`))
  if (inv) return { page: 'invoice', id: inv[1] }
  if (/^\/office\/suppliers\/?$/.test(path)) return { page: 'suppliers' }
  if (/^\/office\/suppliers\/new\/?$/.test(path)) return { page: 'supplier-form', id: null }
  const sup = path.match(new RegExp(`^/office/suppliers/(${UUID})(/edit)?/?$`))
  if (sup) return sup[2] ? { page: 'supplier-form', id: sup[1] } : { page: 'supplier', id: sup[1] }
  if (/^\/office\/customer-orders\/?$/.test(path)) return { page: 'customer-orders' }
  if (/^\/office\/customer-orders\/new\/?$/.test(path)) return { page: 'customer-order-form', id: null }
  const co = path.match(new RegExp(`^/office/customer-orders/(${UUID})(/edit)?/?$`))
  if (co) return co[2] ? { page: 'customer-order-form', id: co[1] } : { page: 'customer-order', id: co[1] }
  if (/^\/office\/customer-payments\/?$/.test(path)) return { page: 'customer-payments' }
  if (/^\/office\/aged-receivables\/?$/.test(path)) return { page: 'aged-receivables' }
  if (/^\/office\/till-requests\/?$/.test(path)) return { page: 'till-requests' }
  if (/^\/office\/office-returns\/?$/.test(path)) return { page: 'office-returns' }
  const oret = path.match(new RegExp(`^/office/office-returns/(${UUID})/?$`))
  if (oret) return { page: 'office-return', id: oret[1] }
  if (/^\/office\/review-queue\/?$/.test(path)) return { page: 'review-queue' }
  if (/^\/office\/gift-cards\/?$/.test(path)) return { page: 'gift-cards' }
  if (/^\/office\/supplier-returns\/?$/.test(path)) return { page: 'supplier-returns' }
  const sret = path.match(new RegExp(`^/office/supplier-returns/(${UUID})/?$`))
  if (sret) return { page: 'supplier-return', id: sret[1] }
  if (/^\/office\/waybills\/?$/.test(path)) return { page: 'waybills' }
  if (/^\/office\/waybills\/new\/?$/.test(path)) return { page: 'waybill-form' }
  const wb = path.match(new RegExp(`^/office/waybills/(${UUID})/?$`))
  if (wb) return { page: 'waybill', id: wb[1] }
  if (/^\/office\/bins\/?$/.test(path)) return { page: 'bins' }
  if (/^\/office\/my-day\/?$/.test(path)) return { page: 'my-day' }
  if (/^\/office\/purchase-requests\/?$/.test(path)) return { page: 'purchase-requests' }
  if (/^\/office\/purchase-requests\/new\/?$/.test(path)) return { page: 'purchase-request-form' }
  const preq = path.match(new RegExp(`^/office/purchase-requests/(${UUID})/?$`))
  if (preq) return { page: 'purchase-request', id: preq[1] }
  if (/^\/office\/rfqs\/?$/.test(path)) return { page: 'rfqs' }
  if (/^\/office\/rfqs\/new\/?$/.test(path)) return { page: 'rfq-form' }
  const rfq = path.match(new RegExp(`^/office/rfqs/(${UUID})/?$`))
  if (rfq) return { page: 'rfq', id: rfq[1] }
  if (/^\/office\/agreements\/?$/.test(path)) return { page: 'agreements' }
  if (/^\/office\/agreements\/new\/?$/.test(path)) return { page: 'agreement-form', id: null }
  const agr = path.match(new RegExp(`^/office/agreements/(${UUID})(/edit)?/?$`))
  if (agr) return agr[2] ? { page: 'agreement-form', id: agr[1] } : { page: 'agreement', id: agr[1] }
  if (/^\/office\/landed-costs\/?$/.test(path)) return { page: 'landed-costs' }
  if (/^\/office\/cost-runs\/?$/.test(path)) return { page: 'cost-runs' }
  if (/^\/office\/cost-runs\/new\/?$/.test(path)) return { page: 'cost-run-form' }
  const crun = path.match(new RegExp(`^/office/cost-runs/(${UUID})/?$`))
  if (crun) return { page: 'cost-run', id: crun[1] }
  if (/^\/office\/estimated-costs\/?$/.test(path)) return { page: 'estimated-costs' }
  if (/^\/office\/spend-analytics\/?$/.test(path)) return { page: 'spend-analytics' }
  if (/^\/office\/branches\/?$/.test(path)) return { page: 'branches' }
  if (/^\/office\/branches\/new\/?$/.test(path)) return { page: 'branch', id: null }
  const brn = path.match(new RegExp(`^/office/branches/(${UUID})/?$`))
  if (brn) return { page: 'branch', id: brn[1] }
  if (/^\/office\/industries\/?$/.test(path)) return { page: 'industries' }
  if (/^\/office\/legal-entities\/?$/.test(path)) return { page: 'legal-entities' }
  if (/^\/office\/branding\/?$/.test(path)) return { page: 'branding' }
  if (/^\/office\/health-checks\/?$/.test(path)) return { page: 'health-checks' }
  if (/^\/office\/scale-items\/?$/.test(path)) return { page: 'scale-items' }
  if (/^\/office\/employees\/?$/.test(path)) return { page: 'employees' }
  if (/^\/office\/employees\/new\/?$/.test(path)) return { page: 'employee', id: null }
  const emp = path.match(new RegExp(`^/office/employees/(${UUID})/?$`))
  if (emp) return { page: 'employee', id: emp[1] }
  if (/^\/office\/timesheets\/?$/.test(path)) return { page: 'timesheets' }
  if (/^\/office\/leave\/?$/.test(path)) return { page: 'leave' }
  if (/^\/office\/payroll\/?$/.test(path)) return { page: 'payroll' }
  const prun = path.match(new RegExp(`^/office/payroll/(${UUID})/?$`))
  if (prun) return { page: 'payroll-run', id: prun[1] }
  if (/^\/office\/loans\/?$/.test(path)) return { page: 'loans' }
  if (/^\/office\/my-hr\/?$/.test(path)) return { page: 'my-hr' }
  if (/^\/office\/system-health\/?$/.test(path)) return { page: 'system-health' }
  if (/^\/office\/backups\/?$/.test(path)) return { page: 'backups' }
  if (/^\/office\/licence\/?$/.test(path)) return { page: 'licence' }
  if (/^\/office\/audit-log\/?$/.test(path)) return { page: 'audit-log' }
  if (/^\/office\/problem-reports\/?$/.test(path)) return { page: 'problem-reports' }
  if (/^\/office\/integrations\/?$/.test(path)) return { page: 'integrations' }
  if (/^\/office\/api-clients\/?$/.test(path)) return { page: 'api-clients' }
  if (/^\/office\/mobile-money\/?$/.test(path)) return { page: 'mobile-money' }
  if (/^\/office\/support-access\/?$/.test(path)) return { page: 'support-access' }
  if (/^\/office\/sync-center\/?$/.test(path)) return { page: 'sync-center' }

  if (/^\/office\/crm\/pipeline\/?$/.test(path)) return { page: 'crm-pipeline' }
  if (/^\/office\/crm\/leads\/?$/.test(path)) return { page: 'crm-leads' }
  if (/^\/office\/crm\/leads\/new\/?$/.test(path)) return { page: 'crm-lead', id: null }
  const crl = path.match(new RegExp(`^/office/crm/leads/(${UUID})/?$`))
  if (crl) return { page: 'crm-lead', id: crl[1] }
  if (/^\/office\/crm\/deals\/?$/.test(path)) return { page: 'crm-deals' }
  if (/^\/office\/crm\/deals\/new\/?$/.test(path)) return { page: 'crm-deal', id: null }
  const crd = path.match(new RegExp(`^/office/crm/deals/(${UUID})/?$`))
  if (crd) return { page: 'crm-deal', id: crd[1] }
  if (/^\/office\/crm\/follow-ups\/?$/.test(path)) return { page: 'crm-follow-ups' }
  if (/^\/office\/crm\/campaigns\/?$/.test(path)) return { page: 'crm-campaigns' }
  if (/^\/office\/crm\/campaigns\/new\/?$/.test(path)) return { page: 'crm-campaign', id: null }
  const crc = path.match(new RegExp(`^/office/crm/campaigns/(${UUID})/?$`))
  if (crc) return { page: 'crm-campaign', id: crc[1] }
  if (/^\/office\/crm\/stages\/?$/.test(path)) return { page: 'crm-stages' }
  if (/^\/office\/distribution\/vans\/?$/.test(path)) return { page: 'dist-vans' }
  if (/^\/office\/distribution\/settlements\/?$/.test(path)) return { page: 'dist-settlements' }
  const dset = path.match(new RegExp(`^/office/distribution/settlements/(${UUID})/?$`))
  if (dset) return { page: 'dist-settlement', id: dset[1] }
  if (/^\/office\/distribution\/routes\/?$/.test(path)) return { page: 'dist-routes' }
  if (/^\/office\/distribution\/visits\/?$/.test(path)) return { page: 'dist-visits' }
  if (/^\/office\/distribution\/targets\/?$/.test(path)) return { page: 'dist-targets' }
  if (/^\/office\/distribution\/performance\/?$/.test(path)) return { page: 'dist-performance' }
  if (/^\/office\/manufacturing\/orders\/?$/.test(path)) return { page: 'mfg-orders' }
  const mfo = path.match(new RegExp(`^/office/manufacturing/orders/(${UUID})/?$`))
  if (mfo) return { page: 'mfg-order', id: mfo[1] }
  if (/^\/office\/manufacturing\/boms\/?$/.test(path)) return { page: 'mfg-boms' }
  if (/^\/office\/manufacturing\/boms\/new\/?$/.test(path)) return { page: 'mfg-bom-form', id: null }
  const mfb = path.match(new RegExp(`^/office/manufacturing/boms/(${UUID})(/edit)?/?$`))
  if (mfb) return mfb[2] ? { page: 'mfg-bom-form', id: mfb[1] } : { page: 'mfg-bom', id: mfb[1] }
  if (/^\/office\/manufacturing\/planning\/?$/.test(path)) return { page: 'mfg-planning' }
  if (/^\/office\/manufacturing\/centres\/?$/.test(path)) return { page: 'mfg-centres' }
  if (/^\/office\/fashion\/styles\/?$/.test(path)) return { page: 'fsh-styles' }
  if (/^\/office\/fashion\/styles\/new\/?$/.test(path)) return { page: 'fsh-style-form', id: null }
  const fst = path.match(new RegExp(`^/office/fashion/styles/(${UUID})(/edit)?/?$`))
  if (fst) return fst[2] ? { page: 'fsh-style-form', id: fst[1] } : { page: 'fsh-style', id: fst[1] }
  if (/^\/office\/fashion\/alterations\/?$/.test(path)) return { page: 'fsh-alterations' }
  if (/^\/office\/fashion\/sell-through\/?$/.test(path)) return { page: 'fsh-sell-through' }
  if (/^\/office\/electronics\/repairs\/?$/.test(path)) return { page: 'ele-repairs' }
  const ere = path.match(new RegExp(`^/office/electronics/repairs/(${UUID})/?$`))
  if (ere) return { page: 'ele-repair', id: ere[1] }
  if (/^\/office\/electronics\/serials\/?$/.test(path)) return { page: 'ele-serials' }
  const ese = path.match(new RegExp(`^/office/electronics/serials/(${UUID})/?$`))
  if (ese) return { page: 'ele-serial', id: ese[1] }
  if (/^\/office\/online\/orders\/?$/.test(path)) return { page: 'onl-orders' }
  const onlo = path.match(new RegExp(`^/office/online/orders/(${UUID})/?$`))
  if (onlo) return { page: 'onl-order', id: onlo[1] }
  if (/^\/office\/online\/channels\/?$/.test(path)) return { page: 'onl-channels' }
  if (/^\/office\/online\/channels\/new\/?$/.test(path)) return { page: 'onl-channel', id: null }
  const onlc = path.match(new RegExp(`^/office/online/channels/(${UUID})/?$`))
  if (onlc) return { page: 'onl-channel', id: onlc[1] }
  if (/^\/office\/online\/whatsapp\/?$/.test(path)) return { page: 'onl-whatsapp' }
  const onlw = path.match(new RegExp(`^/office/online/whatsapp/(${UUID})/?$`))
  if (onlw) return { page: 'onl-message', id: onlw[1] }
  if (/^\/office\/sensors\/?$/.test(path)) return { page: 'iot-sensors' }
  if (/^\/office\/sensors\/new\/?$/.test(path)) return { page: 'iot-sensor', id: null }
  if (/^\/office\/sensors\/alarms\/?$/.test(path)) return { page: 'iot-alarms' }
  if (/^\/office\/sensors\/log\/?$/.test(path)) return { page: 'iot-log' }
  if (/^\/office\/sensors\/clouds\/?$/.test(path)) return { page: 'iot-clouds' }
  const iot = path.match(new RegExp(`^/office/sensors/(${UUID})/?$`))
  if (iot) return { page: 'iot-sensor', id: iot[1] }
  if (/^\/office\/maintenance\/equipment\/?$/.test(path)) return { page: 'mnt-equipment' }
  if (/^\/office\/maintenance\/equipment\/new\/?$/.test(path)) return { page: 'mnt-asset', id: null }
  const mas = path.match(new RegExp(`^/office/maintenance/equipment/(${UUID})/?$`))
  if (mas) return { page: 'mnt-asset', id: mas[1] }
  if (/^\/office\/maintenance\/orders\/?$/.test(path)) return { page: 'mnt-orders' }
  if (/^\/office\/maintenance\/orders\/new\/?$/.test(path)) return { page: 'mnt-order-new' }
  const mor = path.match(new RegExp(`^/office/maintenance/orders/(${UUID})/?$`))
  if (mor) return { page: 'mnt-order', id: mor[1] }
  if (/^\/office\/restaurant\/bookings\/?$/.test(path)) return { page: 'rest-bookings' }
  if (/^\/office\/restaurant\/bills\/?$/.test(path)) return { page: 'rest-bills' }
  if (/^\/office\/restaurant\/tables\/?$/.test(path)) return { page: 'rest-tables' }
  if (/^\/office\/restaurant\/stations\/?$/.test(path)) return { page: 'rest-stations' }
  if (/^\/office\/restaurant\/modifiers\/?$/.test(path)) return { page: 'rest-modifiers' }
  if (/^\/office\/restaurant\/tips-voids\/?$/.test(path)) return { page: 'rest-report' }
  if (/^\/office\/ask\/?$/.test(path)) return { page: 'ask' }
  if (/^\/office\/today\/?$/.test(path)) return { page: 'today' }
  if (/^\/office\/departments\/?$/.test(path)) return { page: 'departments' }
  if (/^\/office\/payroll-rules\/?$/.test(path)) return { page: 'payroll-rules' }
  if (/^\/office\/setup-wizard\/?$/.test(path)) return { page: 'setup-wizard' }
  if (/^\/office\/profile\/?$/.test(path)) return { page: 'profile' }
  if (/^\/office\/confirm-its-you\/?$/.test(path)) return { page: 'confirm-its-you' }
  if (/^\/office\/alerts\/?$/.test(path)) return { page: 'alerts' }
  if (/^\/office\/ai-usage\/?$/.test(path)) return { page: 'ai-usage' }
  if (/^\/office\/tickets\/?$/.test(path)) return { page: 'tickets' }
  if (/^\/office\/tickets\/new\/?$/.test(path)) return { page: 'ticket-new' }
  const tkt = path.match(new RegExp(`^/office/tickets/(${UUID})/?$`))
  if (tkt) return { page: 'ticket', id: tkt[1] }
  if (/^\/office\/service-levels\/?$/.test(path)) return { page: 'service-levels' }
  if (/^\/office\/projects\/?$/.test(path)) return { page: 'projects' }
  if (/^\/office\/projects\/new\/?$/.test(path)) return { page: 'project', id: null }
  const prj = path.match(new RegExp(`^/office/projects/(${UUID})/?$`))
  if (prj) return { page: 'project', id: prj[1] }
  if (/^\/office\/my-time\/?$/.test(path)) return { page: 'my-time' }
  if (/^\/office\/time-approvals\/?$/.test(path)) return { page: 'time-approvals' }
  if (/^\/office\/project-rates\/?$/.test(path)) return { page: 'project-rates' }
  if (/^\/office\/services\/calendar\/?$/.test(path)) return { page: 'svc-calendar' }
  if (/^\/office\/services\/appointments\/?$/.test(path)) return { page: 'svc-appointments' }
  if (/^\/office\/services\/passes\/?$/.test(path)) return { page: 'svc-passes' }
  if (/^\/office\/services\/memberships\/?$/.test(path)) return { page: 'svc-memberships' }
  if (/^\/office\/services\/plans\/?$/.test(path)) return { page: 'svc-plans' }
  if (/^\/office\/services\/commissions\/?$/.test(path)) return { page: 'svc-commissions' }
  if (/^\/office\/services\/keys\/?$/.test(path)) return { page: 'svc-keys' }
  if (/^\/office\/go-live\/?$/.test(path)) return { page: 'go-live' }
  if (/^\/office\/import\/?$/.test(path)) return { page: 'import' }
  if (/^\/office\/purchase-orders\/?$/.test(path)) return { page: 'purchase-orders' }
  if (/^\/office\/purchase-orders\/new\/?$/.test(path)) return { page: 'purchase-order-form', id: null }
  const po = path.match(new RegExp(`^/office/purchase-orders/(${UUID})(/edit)?/?$`))
  if (po) return po[2] ? { page: 'purchase-order-form', id: po[1] } : { page: 'purchase-order', id: po[1] }
  if (/^\/office\/goods-received\/?$/.test(path)) return { page: 'goods-received' }
  const grn = path.match(new RegExp(`^/office/goods-received/(${UUID})/?$`))
  if (grn) return { page: 'goods-receipt', id: grn[1] }
  if (/^\/office\/direct-purchases\/?$/.test(path)) return { page: 'direct-purchases' }
  if (/^\/office\/direct-purchases\/new\/?$/.test(path)) return { page: 'direct-purchase-form', id: null }
  const dp = path.match(new RegExp(`^/office/direct-purchases/(${UUID})(/edit)?/?$`))
  if (dp) return dp[2] ? { page: 'direct-purchase-form', id: dp[1] } : { page: 'direct-purchase', id: dp[1] }
  if (/^\/office\/supplier-invoices\/?$/.test(path)) return { page: 'supplier-invoices' }
  if (/^\/office\/consignment-to-pay\/?$/.test(path)) return { page: 'consignment-to-pay' }
  if (/^\/office\/reorder-levels\/?$/.test(path)) return { page: 'reorder-levels' }
  if (/^\/office\/what-to-order\/?$/.test(path)) return { page: 'what-to-order' }
  if (/^\/office\/suggested-levels\/?$/.test(path)) return { page: 'suggested-levels' }
  if (/^\/office\/expense-claims\/?$/.test(path)) return { page: 'expense-claims' }
  if (/^\/office\/expense-claims\/new\/?$/.test(path)) return { page: 'expense-claim-form' }
  const ec = path.match(new RegExp(`^/office/expense-claims/(${UUID})/?$`))
  if (ec) return { page: 'expense-claim', id: ec[1] }
  if (/^\/office\/supplier-bills\/?$/.test(path)) return { page: 'supplier-bills' }
  if (/^\/office\/supplier-bills\/new\/?$/.test(path)) return { page: 'supplier-bill-form' }
  const sb = path.match(new RegExp(`^/office/supplier-bills/(${UUID})/?$`))
  if (sb) return { page: 'supplier-bill', id: sb[1] }
  if (/^\/office\/supplier-payments\/?$/.test(path)) return { page: 'supplier-payments' }
  if (/^\/office\/aged-payables\/?$/.test(path)) return { page: 'aged-payables' }
  if (/^\/office\/withholding-tax\/?$/.test(path)) return { page: 'withholding-tax' }
  if (/^\/office\/financial-reports\/?$/.test(path)) return { page: 'financial-reports' }
  if (/^\/office\/insights\/?$/.test(path)) return { page: 'insights' }
  if (/^\/office\/report-builder\/?$/.test(path)) return { page: 'report-builder' }
  if (/^\/office\/saved-reports\/?$/.test(path)) return { page: 'saved-reports' }
  if (/^\/office\/shrink\/?$/.test(path)) return { page: 'shrink' }
  if (/^\/office\/slow-stock\/?$/.test(path)) return { page: 'slow-stock' }
  if (/^\/office\/batches\/?$/.test(path)) return { page: 'batches' }
  if (/^\/office\/expiry\/?$/.test(path)) return { page: 'expiry' }
  if (/^\/office\/close-day\/?$/.test(path)) return { page: 'close-day' }
  if (/^\/office\/cash-desk\/?$/.test(path)) return { page: 'cash-desk' }
  if (/^\/office\/income-control\/?$/.test(path)) return { page: 'income-control' }
  if (/^\/office\/cash-setup\/?$/.test(path)) return { page: 'cash-setup' }
  const markdowns = path.match(/^\/office\/(grocery|pharmacy)\/markdowns\/?$/)
  if (markdowns) return { page: 'markdowns', industry: markdowns[1] as 'grocery' | 'pharmacy' }
  if (/^\/office\/returns\/?$/.test(path)) return { page: 'returns' }
  if (/^\/office\/journals\/?$/.test(path)) return { page: 'journals' }
  if (/^\/office\/accounts\/?$/.test(path)) return { page: 'accounts' }
  if (/^\/office\/month-end\/?$/.test(path)) return { page: 'month-end' }
  if (/^\/office\/budgets\/?$/.test(path)) return { page: 'budgets' }
  if (/^\/office\/bank-accounts\/?$/.test(path)) return { page: 'bank-accounts' }
  const rec = path.match(new RegExp(`^/office/bank-accounts/(${UUID})/reconcile/?$`))
  if (rec) return { page: 'reconcile', id: rec[1] }
  if (/^\/office\/money-moved\/?$/.test(path)) return { page: 'money-moved' }
  if (/^\/office\/fixed-assets\/?$/.test(path)) return { page: 'fixed-assets' }
  const fa = path.match(new RegExp(`^/office/fixed-assets/(${UUID})/?$`))
  if (fa) return { page: 'fixed-asset', id: fa[1] }
  if (/^\/office\/asset-kinds\/?$/.test(path)) return { page: 'asset-kinds' }
  if (/^\/office\/accounting-export\/?$/.test(path)) return { page: 'accounting-export' }
  if (/^\/office\/gra-evat\/?$/.test(path)) return { page: 'gra-evat' }
  if (/^\/office\/users\/?$/.test(path)) return { page: 'users' }
  if (/^\/office\/roles\/?$/.test(path)) return { page: 'roles' }
  if (/^\/office\/roles\/new\/?$/.test(path)) return { page: 'role', id: null }
  const ro = path.match(new RegExp(`^/office/roles/(${UUID})/?$`))
  if (ro) return { page: 'role', id: ro[1] }
  if (/^\/office\/access-requests\/?$/.test(path)) return { page: 'access-requests' }
  if (/^\/office\/settings\/?$/.test(path)) return { page: 'settings' }
  if (/^\/office\/modules\/?$/.test(path)) return { page: 'modules' }
  if (/^\/office\/messaging\/?$/.test(path)) return { page: 'messaging' }
  if (/^\/office\/message-templates\/?$/.test(path)) return { page: 'message-templates' }
  if (/^\/office\/message-log\/?$/.test(path)) return { page: 'message-log' }
  if (/^\/office\/devices\/?$/.test(path)) return { page: 'devices' }
  if (/^\/office\/computers\/?$/.test(path)) return { page: 'computers' }
  if (/^\/office\/pricing-rules\/?$/.test(path)) return { page: 'pricing-rules' }
  if (/^\/office\/promotions\/?$/.test(path)) return { page: 'promotions' }
  if (/^\/office\/price-list\/?$/.test(path)) return { page: 'price-list' }
  if (/^\/office\/prescriptions\/?$/.test(path)) return { page: 'prescriptions' }
  if (/^\/office\/nhis-claims\/?$/.test(path)) return { page: 'nhis-claims' }
  const nhisClaim = path.match(new RegExp(`^/office/nhis-claims/(${UUID})/?$`))
  if (nhisClaim) return { page: 'nhis-claim', id: nhisClaim[1] }
  if (/^\/office\/medicine-signoffs\/?$/.test(path)) return { page: 'medicine-signoffs' }
  if (/^\/office\/refill-reminders\/?$/.test(path)) return { page: 'refill-reminders' }
  if (/^\/office\/drug-interactions\/?$/.test(path)) return { page: 'drug-interactions' }
  if (/^\/office\/controlled-drug-register\/?$/.test(path)) return { page: 'controlled-drug-register' }
  if (/^\/office\/shared-products\/?$/.test(path)) return { page: 'shared-products' }
  if (/^\/office\/prices-to-review\/?$/.test(path)) return { page: 'prices-to-review' }
  const ref = path.match(/^\/office\/(categories|brands|units|tax-rates|tax-groups|price-lists)\/?$/)
  if (ref) return { page: 'reference-list', kind: ref[1] as 'categories' }
  if (/^\/office\/company-wide-access\/?$/.test(path)) return { page: 'company-wide-access' }
  if (/^\/office\/who-can-do-what\/?$/.test(path)) return { page: 'who-can-do-what' }
  if (/^\/office\/it-activity\/?$/.test(path)) return { page: 'it-activity' }
  if (/^\/office\/staff-cards\/?$/.test(path)) return { page: 'staff-cards' }
  const us = path.match(new RegExp(`^/office/users/(${UUID})/?$`))
  if (us) return { page: 'user', id: us[1] }
  const ev = path.match(new RegExp(`^/office/gra-evat/(${UUID})/?$`))
  if (ev) return { page: 'gra-evat-report', id: ev[1] }
  const jr = path.match(new RegExp(`^/office/journals/(${UUID})/?$`))
  if (jr) return { page: 'journal', id: jr[1] }
  if (/^\/office\/requests\/?$/.test(path)) return { page: 'requests' }
  if (/^\/office\/requests\/new\/?$/.test(path)) return { page: 'request-form' }
  const req = path.match(new RegExp(`^/office/requests/(${UUID})/?$`))
  if (req) return { page: 'request', id: req[1] }
  if (/^\/office\/counts\/?$/.test(path)) return { page: 'counts' }
  const count = path.match(new RegExp(`^/office/counts/(${UUID})/?$`))
  if (count) return { page: 'count', id: count[1] }
  if (/^\/office\/adjustments\/?$/.test(path)) return { page: 'adjustments' }
  if (/^\/office\/adjustments\/new\/?$/.test(path)) return { page: 'adjustment-form', id: null }
  const adj = path.match(new RegExp(`^/office/adjustments/(${UUID})(/edit)?/?$`))
  if (adj) return adj[2] ? { page: 'adjustment-form', id: adj[1] } : { page: 'adjustment', id: adj[1] }
  if (/^\/office\/products\/new\/?$/.test(path)) return { page: 'product-form', id: null }
  const edit = path.match(new RegExp(`^/office/products/(${UUID})/edit/?$`))
  if (edit) return { page: 'product-form', id: edit[1] }
  const product = path.match(new RegExp(`^/office/products/(${UUID})/?$`))
  if (product) return { page: 'product', id: product[1] }
  return { page: 'home' }
}

/** The current back office's page a route stands for (to mark its menu area, and for the favourites star). */
export function adminEquivalent(route: Route): string | null {
  switch (route.page) {
    case 'classic': return route.admin
    case 'sales': return '/admin/sales'
    case 'sale': return `/admin/sales/${route.id}`
    case 'shifts': case 'shift': return '/admin/shifts'
    case 'drawer': return '/admin/till-drawer-events'
    case 'products': case 'product': case 'product-form': return '/admin/products'
    case 'stock': return '/admin/stock-levels'
    case 'movements': return '/admin/stock-movements'
    case 'adjustments': case 'adjustment': case 'adjustment-form': return '/admin/stock-adjustments'
    case 'counts': case 'count': return '/admin/stock-counts'
    case 'transfers': case 'transfer': case 'transfer-form': return '/admin/stock-transfers'
    case 'requests': case 'request': case 'request-form': return '/admin/stock-requests'
    case 'customers': case 'customer': case 'customer-form': return '/admin/customers'
    case 'invoices': case 'invoice': return '/admin/invoices'
    case 'suppliers': case 'supplier': case 'supplier-form': return '/admin/suppliers'
    case 'purchase-orders': case 'purchase-order': case 'purchase-order-form': return '/admin/purchase-orders'
    case 'customer-orders': return '/admin/customer-orders'
    case 'customer-order': return `/admin/customer-orders/${route.id}`
    case 'customer-order-form': return route.id ? `/admin/customer-orders/${route.id}/edit` : '/admin/customer-orders/create'
    case 'customer-payments': return '/admin/customer-payments'
    case 'aged-receivables': return '/admin/aged-receivables'
    case 'till-requests': return '/admin/till-requests'
    case 'office-returns': return '/admin/office-returns'
    case 'office-return': return `/admin/office-returns/${route.id}`
    case 'review-queue': return '/admin/review-items'
    case 'gift-cards': return '/admin/stored-values'
    case 'supplier-returns': return '/admin/supplier-returns'
    case 'supplier-return': return `/admin/supplier-returns/${route.id}`
    case 'waybills': return '/admin/waybills'
    case 'waybill': return `/admin/waybills/${route.id}`
    case 'waybill-form': return '/admin/waybills/create'
    case 'bins': return '/admin/bins'
    case 'my-day': return '/admin/stock/my-day'
    case 'purchase-requests': return '/admin/purchase-requests'
    case 'purchase-request': return `/admin/purchase-requests/${route.id}`
    case 'purchase-request-form': return '/admin/purchase-requests/create'
    case 'rfqs': return '/admin/rfqs'
    case 'rfq': return `/admin/rfqs/${route.id}`
    case 'rfq-form': return '/admin/rfqs/create'
    case 'agreements': return '/admin/purchase-agreements'
    case 'agreement': return `/admin/purchase-agreements/${route.id}`
    case 'agreement-form': return route.id ? `/admin/purchase-agreements/${route.id}/edit` : '/admin/purchase-agreements/create'
    case 'landed-costs': return '/admin/landed-costs'
    case 'cost-runs': return '/admin/stock-cost-runs'
    case 'cost-run': return `/admin/stock-cost-runs/${route.id}`
    case 'cost-run-form': return '/admin/stock-cost-runs/create'
    case 'estimated-costs': return '/admin/estimated-costs'
    case 'spend-analytics': return '/admin/spend-analytics'
    case 'branches': return '/admin/branches'
    case 'branch': return route.id ? `/admin/branches/${route.id}/edit` : '/admin/branches/create'
    case 'industries': return '/admin/industries'
    case 'legal-entities': return '/admin/legal-entities'
    case 'branding': return '/admin/branding'
    case 'health-checks': return '/admin/health-checks'
    case 'scale-items': return '/admin/grocery/scale-items'
    case 'employees': return '/admin/employees'
    case 'employee': return route.id ? `/admin/employees/${route.id}/edit` : '/admin/employees/create'
    case 'timesheets': return '/admin/timesheets'
    case 'leave': return '/admin/leave-requests'
    case 'payroll': return '/admin/payroll-runs'
    case 'payroll-run': return `/admin/payroll-runs/${route.id}`
    case 'loans': return '/admin/employee-loans'
    case 'my-hr': return '/admin/my-hr'
    case 'system-health': return '/admin/health'
    case 'backups': return '/admin/backups'
    case 'licence': return '/admin/licence'
    case 'audit-log': return '/admin/audit-events'
    case 'problem-reports': return '/admin/problem-reports'
    case 'integrations': return '/admin/integrations'
    case 'api-clients': return '/admin/api-clients'
    case 'mobile-money': return '/admin/momo-charges'
    case 'support-access': return '/admin/support-access-grants'
    case 'sync-center': return '/admin/sync-peers'

    case 'crm-pipeline': return '/admin/crm-pipeline'
    case 'crm-leads': return '/admin/crm-leads'
    case 'crm-lead': return route.id ? `/admin/crm-leads/${route.id}/edit` : '/admin/crm-leads/create'
    case 'crm-deals': return '/admin/crm-deals'
    case 'crm-deal': return route.id ? `/admin/crm-deals/${route.id}/edit` : '/admin/crm-deals/create'
    case 'crm-follow-ups': return '/admin/crm-follow-ups'
    case 'crm-campaigns': return '/admin/crm-campaigns'
    case 'crm-campaign': return route.id ? `/admin/crm-campaigns/${route.id}/edit` : '/admin/crm-campaigns/create'
    case 'crm-stages': return '/admin/crm-stages'
    case 'dist-vans': return '/admin/vans'
    case 'dist-settlements': return '/admin/van-settlements'
    case 'dist-settlement': return `/admin/van-settlements/${route.id}`
    case 'dist-routes': return '/admin/routes'
    case 'dist-visits': return '/admin/visits'
    case 'dist-targets': return '/admin/rep-targets'
    case 'dist-performance': return '/admin/rep-performance'
    case 'mfg-orders': return '/admin/work-orders'
    case 'mfg-order': return `/admin/work-orders/${route.id}`
    case 'mfg-boms': return '/admin/boms'
    case 'mfg-bom': return `/admin/boms/${route.id}`
    case 'mfg-bom-form': return route.id ? `/admin/boms/${route.id}/edit` : '/admin/boms/create'
    case 'mfg-planning': return '/admin/production-planning'
    case 'mfg-centres': return '/admin/work-centres'
    case 'fsh-styles': return '/admin/styles'
    case 'fsh-style': return `/admin/styles/${route.id}`
    case 'fsh-style-form': return route.id ? `/admin/styles/${route.id}` : '/admin/styles'
    case 'fsh-alterations': return '/admin/alterations'
    case 'fsh-sell-through': return '/admin/fashion-report'
    case 'ele-repairs': return '/admin/repair-jobs'
    case 'ele-repair': return `/admin/repair-jobs/${route.id}`
    case 'ele-serials': return '/admin/serial-numbers'
    case 'ele-serial': return `/admin/serial-numbers/${route.id}`
    case 'onl-orders': return '/admin/sales-orders'
    case 'onl-order': return `/admin/sales-orders/${route.id}`
    case 'onl-channels': return '/admin/sales-channels'
    case 'onl-channel': return route.id ? `/admin/sales-channels/${route.id}/edit` : '/admin/sales-channels/create'
    case 'onl-whatsapp': return '/admin/whatsapp-orders'
    case 'onl-message': return `/admin/whatsapp-orders/${route.id}`
    case 'iot-sensors': return '/admin/sensors'
    case 'iot-sensor': return route.id ? `/admin/sensors/${route.id}/edit` : '/admin/sensors/create'
    case 'iot-alarms': return '/admin/iot-alarms'
    case 'iot-log': return '/admin/temperature-log'
    case 'iot-clouds': return '/admin/iot-clouds'
    case 'mnt-equipment': return '/admin/equipment'
    case 'mnt-asset': return route.id ? `/admin/equipment/${route.id}/edit` : '/admin/equipment/create'
    case 'mnt-orders': return '/admin/maintenance-orders'
    case 'mnt-order-new': return '/admin/maintenance-orders/create'
    case 'mnt-order': return `/admin/maintenance-orders/${route.id}`
    case 'rest-bookings': return '/admin/reservations'
    case 'rest-bills': return '/admin/dining-orders'
    case 'rest-tables': return '/admin/dining-tables'
    case 'rest-stations': return '/admin/kitchen-stations'
    case 'rest-modifiers': return '/admin/modifier-groups'
    case 'rest-report': return '/admin/restaurant-report'
    case 'ask': return '/admin/copilot'
    case 'today': return '/admin/today'
    case 'departments': return '/admin/departments'
    case 'payroll-rules': return '/admin/payroll-rules'
    case 'setup-wizard': return '/admin/setup-wizard'
    case 'profile': return '/admin/profile'
    case 'confirm-its-you': return '/admin/confirm-its-you'
    case 'alerts': return '/admin/alerts'
    case 'ai-usage': return '/admin/ai-usage'
    case 'tickets': return '/admin/tickets'
    case 'ticket-new': return '/admin/tickets/create'
    case 'ticket': return `/admin/tickets/${route.id}`
    case 'service-levels': return '/admin/service-levels'
    case 'projects': return '/admin/projects'
    case 'project': return route.id ? `/admin/projects/${route.id}/edit` : '/admin/projects/create'
    case 'my-time': return '/admin/my-time'
    case 'time-approvals': return '/admin/time-approvals'
    case 'project-rates': return '/admin/project-rates'
    case 'svc-calendar': return '/admin/staff-calendar'
    case 'svc-appointments': return '/admin/appointments'
    case 'svc-passes': return '/admin/service-passes'
    case 'svc-memberships': return '/admin/memberships'
    case 'svc-plans': return '/admin/membership-plans'
    case 'svc-commissions': return '/admin/commissions'
    case 'svc-keys': return '/admin/licence-keys'
    case 'go-live': return '/admin/go-live'
    case 'import': return '/admin/import-data'
    case 'goods-received': case 'goods-receipt': return '/admin/goods-receipts'
    case 'direct-purchases': case 'direct-purchase': case 'direct-purchase-form': return '/admin/direct-purchases'
    case 'supplier-invoices': return '/admin/supplier-invoices'
    case 'consignment-to-pay': return '/admin/consignment-to-pay'
    case 'reorder-levels': return '/admin/reorder-rules'
    case 'what-to-order': return '/admin/reorder-suggestions'
    case 'suggested-levels': return '/admin/suggested-levels'
    case 'expense-claims': case 'expense-claim': case 'expense-claim-form': return '/admin/expense-claims'
    case 'supplier-bills': case 'supplier-bill': case 'supplier-bill-form': return '/admin/supplier-bills'
    case 'supplier-payments': return '/admin/supplier-payments'
    case 'aged-payables': return '/admin/aged-payables'
    case 'withholding-tax': return '/admin/withholding-tax-return'
    case 'financial-reports': return '/admin/financial-reports'
    case 'insights': return '/admin/insights'
    case 'report-builder': return '/admin/report-builder'
    case 'saved-reports': return '/admin/saved-reports'
    case 'shrink': return '/admin/shrink-report'
    case 'slow-stock': return '/admin/stock/slow-stock'
    case 'batches': return '/admin/batches-expiry'
    case 'expiry': return '/admin/expiry-returns'
    case 'close-day': return '/admin/cash/close-day'
    case 'cash-desk': return '/admin/cash-desk'
    case 'income-control': return '/admin/income-control'
    case 'cash-setup': return '/admin/cash-setup'
    case 'markdowns': return `/admin/${route.industry}/markdowns`
    case 'returns': return '/admin/returns'
    case 'journals': case 'journal': return '/admin/journal-entries'
    case 'accounts': return '/admin/accounts'
    case 'month-end': return '/admin/fiscal-periods'
    case 'budgets': return '/admin/budgets'
    case 'bank-accounts': case 'reconcile': return '/admin/bank-accounts'
    case 'money-moved': return '/admin/money-transfers'
    case 'fixed-assets': case 'fixed-asset': return '/admin/fixed-assets'
    case 'asset-kinds': return '/admin/asset-classes'
    case 'accounting-export': return '/admin/accounting-export'
    case 'gra-evat': case 'gra-evat-report': return '/admin/gra-evat'
    case 'users': case 'user': return '/admin/users'
    case 'roles': case 'role': return '/admin/roles'
    case 'access-requests': return '/admin/access-requests'
    case 'settings': return '/admin/manage-settings'
    case 'modules': return '/admin/manage-modules'
    case 'messaging': return '/admin/messaging'
    case 'message-templates': return '/admin/message-templates'
    case 'message-log': return '/admin/message-log'
    case 'devices': return '/admin/devices'
    case 'computers': return '/admin/computers'
    case 'pricing-rules': return '/admin/pricing-rules'
    case 'promotions': return '/admin/promotions'
    case 'price-list': return '/admin/price-list'
    case 'prescriptions': return '/admin/prescriptions'
    case 'nhis-claims': return '/admin/nhis-claims'
    case 'nhis-claim': return `/admin/nhis-claims/${route.id}`
    case 'medicine-signoffs': return '/admin/medicine-signoffs'
    case 'refill-reminders': return '/admin/refill-reminders'
    case 'drug-interactions': return '/admin/drug-interactions'
    case 'controlled-drug-register': return '/admin/controlled-drug-register'
    case 'shared-products': return '/admin/shared-products'
    case 'prices-to-review': return '/admin/prices-to-review'
    case 'reference-list': return `/admin/${route.kind === 'units' ? 'uoms' : route.kind}`
    case 'company-wide-access': return '/admin/company-wide-access'
    case 'who-can-do-what': return '/admin/access-review'
    case 'it-activity': return '/admin/it-activity'
    case 'staff-cards': return '/admin/staff-cards'
    default: return null
  }
}

/** Every page in the menu, flat, with where it sits ("Sales › Till"), for search and for showing where you are. */
export function flatMenu(menu: Menu): (MenuItem & { where: string; tab: string })[] {
  return menu.tabs.flatMap((t: MenuTab) => t.sections.flatMap((s) => s.groups.flatMap((g) => g.items.map((i) => ({
    ...i, tab: t.label, where: [t.label, s.label !== t.label ? s.label : null, g.label].filter(Boolean).join(' › '),
  })))))
}

/** Menu pages whose name (or place) holds every word typed, best first: names starting with the words before the rest. */
export function findPages(menu: Menu, query: string, limit = 8) {
  const words = query.toLowerCase().split(/\s+/).filter(Boolean)
  if (!words.length) return []
  return flatMenu(menu)
    .filter((i) => words.every((w) => `${i.label} ${i.where}`.toLowerCase().includes(w)))
    .sort((a, b) => Number(!a.label.toLowerCase().startsWith(words[0])) - Number(!b.label.toLowerCase().startsWith(words[0])) || a.label.localeCompare(b.label))
    .slice(0, limit)
}

/** The menu area (tab) holding a page, to mark it as the current one. */
export function tabOf(menu: Menu, adminPath: string | null): string | null {
  if (!adminPath) return null
  const path = adminPath.split('?')[0]
  let best: { tab: string; len: number } | null = null
  for (const i of flatMenu(menu)) {
    const p = i.url.split('?')[0]
    if ((path === p || path.startsWith(`${p}/`)) && (!best || p.length > best.len)) best = { tab: i.tab, len: p.length }
  }
  return best?.tab ?? null
}
