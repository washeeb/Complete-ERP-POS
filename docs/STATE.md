# State (resume file)

**Phase:** 2 — Catalog + inventory ledger + basic POS · **Status:** done on branch `phase-2-catalog-pos` (PR to `main`); next is Phase 3
**Updated:** 2026-10-06 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phase 1 merged (PR #1)

## Phase 2 — done (106 Pest + 15 Vitest; browser-verified end to end)
- Done-when met: till sale HQ-T01-000001 (2 Milo, 1 carton paste, sugar; MoMo + cash, change GH₵100) → stock fell 48→46 and 96→72 in the back office; blind close showed GH₵5 short correctly.
- Server: `app/Domain/{Catalog,Pricing,Inventory,Pos}`, `app/Support/{Money,Qty}`, `TenantBaseline` (starter units/tax/price lists), `erp:demo-catalog`, `erp:stock-check`.
- Schema: uoms, categories, brands, tax_rates/groups, products, product_uoms, barcodes, price_lists, prices, stock_movements (append-only), stock_levels, shifts, sales/lines/payments (immutable), origin_node everywhere.
- API: `/pos/bootstrap`, `/pos/catalog`, `/pos/shifts/open|close`, `/pos/sales` (idempotent), `/pos/sales/{id}`; enrol by code only.
- Back office: Products, Categories, Brands, Units, Price lists, Tax groups (accountant check), Tax rates, Stock on hand (+opening/adjustment), Stock ledger, Till sales, Till shifts; settings for receipt footer and negative-stock blocking.
- Till PWA (`pos/`): enrol, PIN, open shift, scan/search, units, list switch, split pay, receipt print, blind close, unsent-sale retry. `money.ts`/`cart.ts` mirror server maths.
- Docs: pricing-and-tax, user-manual/cashier, user-manual/catalog-and-stock, api (till), security (permissions), modules, DECISIONS, CHANGELOG 0.2.0.

## Owner action before go-live (not blocking development)
- Accountant must verify the Ghana tax set-up (Pricing & tax → Tax groups → Record accountant check).

## Dev environment notes
- Dev DB "Synclytics Demo Ltd": 21 sample products in 4 warehouses; owner/cashier creds, TOTP secret, PIN in `.local-secrets/dev-owner.txt`. Browser till enrolled as "HQ till 1 — browser POS".
- PHP is Herd's (not on Git Bash PATH) — run artisan from PowerShell; use script files instead of `tinker --execute`.
- Browser pane often 0×0: drive Livewire via `Livewire.all()…$wire`; read with get_page_text.
- After API calls in a test, log into the back office with `actingAs($u, 'web')` (sanctum switches the default guard).

## Next — Phase 3 (offline POS + sync engine)
1. Till: cache catalog in IndexedDB (versioned, incremental `since=`), sell fully offline, queue sales; signed offline grant (expiry, limits, per-SKU allocation) from server; device-key signatures on batches.
2. Server: accept offline sales against the price version the till had (not "current price"), with review queue for revoked/expired grants.
3. Node sync engine: outbox → peer inbox, per-node sequences, batching/compression, idempotent apply, selective/auto/manual sync per entity group, conflict policy + conflict queue, Sync Center UI, USB export.
4. §4.3 tests: two tills last unit offline, HQ price change during branch sale, duplicate replay, partial batch failure, clock skew, till reinstall mid-queue, 7 days offline, online order during outage.
5. Playwright for the till offline flows (needs browser download — ask before installing).

## Blockers / owner decisions
- Merge PR `phase-2-catalog-pos` → `main` (asked in chat).
