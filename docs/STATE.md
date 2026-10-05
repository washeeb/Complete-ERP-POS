# State (resume file)

**Phase:** 1 — Core platform · **Status:** done on branch `phase-1-core` (PR to `main`); next is Phase 2
**Updated:** 2026-10-05 · Repo: https://github.com/washeeb/Complete-ERP-POS (private)

## Phase 1 — done (67 Pest tests on Postgres; browser-verified)
- Done-when met: wizard installs modules per branch (verified in browser: 4 branches, 2 tills each, Core enabled, industry modules reserved); RBAC tests pass.
- Domain: `app/Domain/{Support,Access,Org,Modules,Devices}`, `app/Security` (Authorizer, levels, scopes, RoleCatalog, AccessCatalogSync, Passkeys).
- Back office (Filament, `app/Filament`): Setup wizard, Branches (+warehouses, tills), Users (+grants, PIN), Roles, Devices, Audit log (+verify), Modules, Settings, Profile (+passkeys). All writes go through domain services via `Erp::run`.
- Auth: 2FA from L3 (TOTP + recovery codes, or passkey sign-in); till PIN sign-in on enrolled devices.
- API v1: devices/enroll, pos/login, pos/me, pos/logout (`docs/api.md`). Verified over real HTTP.
- Docs: `docs/security.md`, `docs/modules.md`, `docs/api.md`, `docs/admin-manual.md`, DECISIONS, CHANGELOG 0.1.0.

## Dev environment notes
- Dev DB has "Synclytics Demo Ltd" set up via the wizard; owner + cashier credentials, TOTP secret and PIN in `.local-secrets/dev-owner.txt` (git-ignored).
- PHP is Herd's; it is not on Git Bash PATH — run artisan from PowerShell. Inline `tinker --execute` breaks on PowerShell quoting: use a script file.
- Browser pane is often hidden (0×0); drive Livewire forms via `Livewire.all()…$wire` and read with get_page_text.

## Next — Phase 2 (catalog + inventory ledger + basic online POS)
1. Release Retail & Wholesale module (manifest `available` + migration reserved→enabled).
2. Catalog: products, variants, UOMs + conversions, multiple barcodes per UOM, categories/brands, tax categories; Filament resources.
3. Price lists (retail/wholesale/tiers/branch), integer minor units + currency.
4. Stock ledger: append-only movements per warehouse, derived on-hand, opening balance + adjustment with reason/approval.
5. POS PWA: device enrolment screen, PIN sign-in, product search/scan, cart, cash + MoMo (manual reference) payment, sale posted through a SaleService (stock movement + audit + outbox), receipt (print view + per-device number `HQ-T01-000001`).
6. Shift open/close minimal (float, cash-up) if needed for cash sales; X/Z later.
7. Done-when: owner completes a sale on the POS and sees stock drop.

## Blockers / owner decisions
- Merge PR `phase-1-core` → `main` (asked in chat).
