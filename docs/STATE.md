# State (resume file)

**Phase:** 4 — Real-time + online store · **Status:** done on branch `phase-4-realtime-store` (PR to `main`); next is Phase 5
**Updated:** 2026-10-08 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–3 merged (PRs #1–#4)

## Phase 4 — done (167 Pest + 18 Vitest + 1 Playwright; browser-verified live)
- Done-when met. In the browser:
  - the egg price was changed in the back-office service (75.00 → 79.00 → 1,250.00) and a crate was written off;
  - the till ("Connected · live") and the shop (`/shop/demo-shop`, green Live dot) both showed the new price and stock within seconds, with no reload;
  - the price was set back to 75.00 afterwards; `erp:stock-check` is clean.
- Relay: `app/Domain/Channels/{OutboxConsumers,LiveRelay,ChannelSync,WebhookIntake,Reconciler}`, `Events/LiveUpdate`, `erp:relay` (every minute), `erp:channels:reconcile` (every 15 min), `routes/channels.php`, `POST /api/v1/pos/broadcasting/auth`.
- Connectors: `app/Domain/Channels/Connectors/*` (storefront, WooCommerce, Shopify, REST; marketplaces planned); `ChannelAdmin` (secrets, activate/pause, retry, unique shop address, needs the Storefront module).
- Orders: `OnlineOrderService` (ingest/ready/fulfil/cancel), `Inventory/StockAvailability` (reservations, safety stock).
- Back office: Online → Channels (`SalesChannelResource`), Online → Online orders (`SalesOrderResource`).
- Shop: `StorefrontController`, `resources/views/storefront/*`, `resources/js/storefront.js` (Vite), Storefront module released (0.4.0).
- Till: `pos/src/live.ts` (Echo + Pusher), polling fallback in `App.tsx`, `realtime` in bootstrap.
- Docs: online-store.md, api.md (webhooks, live), DECISIONS Phase 4, CHANGELOG 0.4.0, REQUIREMENTS (Reverb, Echo).

## Owner decisions pending
- Accountant check of the Ghana tax set-up (before go-live).
- WooCommerce/Shopify: connect a real store only when the owner provides its keys (none created by us).

## Dev environment notes
- Test DBs: `erp_test` (HQ) and `erp_test_node` (branch server in NodeSyncTest).
- Dev creds/TOTP/PIN are in `.local-secrets/dev-owner.txt`. Browser tills:
  - "HQ till 1 — browser POS" (series B);
  - "Demo till (live test)" (enrolled in the in-app browser).
- Dev shop: channel "Demo Shop" (HQ, Retail, safety stock 1) at `/shop/demo-shop`; Storefront module enabled for HQ.
- Live demo processes (`.claude/launch.json`): `server` (8000), `reverb` (8080), `relay`, `pos` (5173). After `git pull`, run `migrate` and `db:seed` (new permissions).
- PHP is Herd's (not on the Git Bash PATH). Use script files with `php artisan tinker <file>`. PowerShell mangles complex inline strings, and Bash heredocs containing `{{ route(` were truncated, so prefer the Write/Edit tools.
- Filament closures must name the value `$state`. After API calls in tests use `actingAs($u, 'web')`. Storefront tests call `withoutVite()`.

## Next — Phase 5 (Purchasing + WMS)
- Suppliers, purchase orders, goods received notes (with landed cost basis), transfers between warehouses (in transit), stock counts (blind, variance approval), bins/locations with put-away, a handheld PWA for receiving and counting, and reorder suggestions (min/max, lead time).
- Done-when: receive → put-away → transfer → count end-to-end.
- Owner questions to batch at the start of Phase 5: whether to use bins at every branch, and approval limits for POs and count variances.

## Blockers
- Merge PR `phase-4-realtime-store` → `main` (asked in chat).
