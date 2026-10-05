# Agent rules (short)

- Spec: `ERP-POS-MASTER-PROMPT.md` (authoritative). Resume from `docs/STATE.md` — read it first, not the whole repo.
- Layout: `server/` Laravel 13 + Filament 5 (API, back office, sync hub) · `pos/` React+TS+Vite PWA (till) · `docs/`.
- Invariants: UUIDv7 ids; money = integer minor units + currency; stock = append-only movement ledger;
  posted docs immutable (reverse, never edit); every mutation = domain service + one transaction + audit row + outbox event.
- IT Admin role gets **no** implicit business permissions. Deny by default; missing scope ≠ global scope.
- Feature flags stay **off** until the feature is built and tested. No orphan permissions/env keys.
- Seeders only add missing rows; one-time backfills are migrations.
- Never commit secrets; dev DB password lives only in `server/.env`.
- Tests: `cd server && php artisan test` (Pest, Postgres `erp_test`) · `cd pos && npm test` (Vitest).
- End of task: update `docs/STATE.md`, `CHANGELOG.md`, `docs/DECISIONS.md`; reply concisely.
- Reference only, never modify: `../Complete ERP/` (earlier Django attempt).
