# Agent rules (short)

- Spec: `ERP-POS-MASTER-PROMPT.md` (authoritative). Resume from `docs/STATE.md` plus the newer notes in `docs/state/`
  — read those first, not the whole repo.
- Layout: `server/` Laravel 13 + Filament 5 (API, back office, sync hub) · `pos/` React+TS+Vite PWA (till) · `docs/`.
- Invariants: UUIDv7 ids; money = integer minor units + currency; stock = append-only movement ledger;
  posted docs immutable (reverse, never edit); every mutation = domain service + one transaction + audit row + outbox event.
- Index every foreign-key column (PostgreSQL does not; `DatabaseIndexesTest` fails otherwise).
- IT Admin role gets **no** implicit business permissions. Deny by default; missing scope ≠ global scope.
- Feature flags stay **off** until the feature is built and tested. No orphan permissions/env keys.
- Seeders only add missing rows; one-time backfills are migrations.
- Never commit secrets; dev DB password lives only in `server/.env`.
- Tests: `cd server && php artisan test` (Pest, Postgres `erp_test`) · `cd pos && npm test` (Vitest).
- After any change to the look, menus, lists or page layout: `cd pos && npm run check:layout` (dev server on 8010,
  Redis running). It checks every look (tabs, side menu, classic) at two sizes; never ship a layout change it fails.
- End of task: add **new files**, never edit the shared ones (two open PRs editing the same lines always collide):
  `changes/YYYY-MM-DD-topic.md` (changelog entry), `docs/state/YYYY-MM-DD-topic.md` (resume note),
  `docs/decisions/YYYY-MM-DD-topic.md` (what was found, decided and why); see `changes/README.md`. Update the affected
  chapters of the installation guide (`docs/manual/*.md`, lay-person wording with the reason for each step) and rebuild
  the PDF locally (`cd pos && npm run manual`; not in git; screenshots `npm run manual:screens` with the dev server on
  8010); reply concisely. `CHANGELOG.md`, `docs/STATE.md`, `docs/DECISIONS.md` change only through `npm run notes`
  (pos folder, on main, its own PR, before a release).
- Reference only, never modify: `../Complete ERP/` (earlier Django attempt).
