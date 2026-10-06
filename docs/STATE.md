# State (resume file)

**Phase:** 3 — Offline POS + sync engine · **Status:** done on branch `phase-3-offline-sync` (PR to `main`); next is Phase 4
**Updated:** 2026-10-07 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–2 merged (PRs #1, #2)

## Phase 3 — done (133 Pest incl. two-database node tests + 18 Vitest; browser-verified offline)
- Done-when met: §4.3 tests pass (OfflineSyncTest, NodeSyncTest); in the browser a till sold offline with the server stopped (HQ-T01B-000002), signed in offline (wrong PIN refused), and the sale synced on reconnect (offline=true, stock 46→43, ledger + audit verify).
- Till ⇄ server: `app/Domain/Devices/{OfflineGrant,DeviceSignature}`, `app/Domain/Pos/{PosSyncService,TillFileImport}`, SaleService online/offline modes, ShiftService client ids, `POST /pos/sync`, catalog ETag.
- Server ⇄ server: `app/Domain/Sync/{Replication,ReplicationApplier,SyncServer,SyncEngine,SyncSigner,SyncFile,NodeSetup,ReviewQueue}`, `Transport/{SyncTransport,HttpSyncTransport}`, `/api/v1/sync/{hello,push,pull}`, `erp:node:add-branch|join`, `erp:sync` (scheduled every minute), HQ-wins guard in `Recorder`.
- Back office: Sync Center (SyncPeerResource), Review queue (ReviewItemResource).
- Till (`pos/`): `offline.ts` (grants, PBKDF2, queue sender, export), `till.ts` (online-or-queue rule), cached catalog/bootstrap, offline banners.
- Docs: sync.md, install/branch-node.md, cashier offline section, api (queue + node sync), security (offline grants, signatures), DECISIONS Phase 3, CHANGELOG 0.3.0.

## Owner decisions pending
- Install Playwright (downloads Chromium ~150 MB) for automated till browser tests? Recommended before Phase 13.
- Accountant check of the Ghana tax set-up (before go-live).

## Dev environment notes
- Test DBs: `erp_test` (HQ) and `erp_test_node` (branch server in NodeSyncTest).
- Dev creds/TOTP/PIN in `.local-secrets/dev-owner.txt`. Browser till "HQ till 1 — browser POS" (series B).
- PHP is Herd's (not on Git Bash PATH); use script files with `php artisan tinker <file>`; PowerShell mangles complex inline strings — prefer Write/Edit tools.
- Filament closures must name the value `$state`; after API calls in tests use `actingAs($u, 'web')`.

## Next — Phase 4 (real-time + online store)
1. Laravel Reverb (WebSockets) + till/back-office live updates (stock, prices, orders); SSE/poll fallback. Redis/Memurai install needs owner OK.
2. Store connector framework: generic REST + webhook connector, WooCommerce connector (owner's platform), Shopify; signed idempotent webhooks, dead-letter, reconciliation report; outbox-driven dispatcher (SKIP LOCKED).
3. Stock reservations + per-channel safety stock; online order → sales order with reservation/fulfilment; "online order during branch outage" test.
4. Built-in storefront module (optional, flag off until done) — maybe Phase 4b.
5. Done-when: a price change appears on the POS and the store live.

## Blockers
- Merge PR `phase-3-offline-sync` → `main` (asked in chat).
