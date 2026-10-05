# State (resume file)

**Phase:** 0 — Discovery & scaffold · **Status:** done locally; CI pending a GitHub remote (owner decision)
**Updated:** 2026-10-05

## Done
- Spec + reference build (`../Complete ERP/`) read; invariants, permission matrix, offline grant contract and webhook rules carried into `docs/DECISIONS.md`.
- Phase-0 owner answers recorded (all defaults) — `docs/DECISIONS.md`.
- Tools: PostgreSQL 17.11 installed (service `postgresql-x64-17`, port 5432); role `erp` (non-superuser), DBs `erp`, `erp_test`. Superuser password in `.local-secrets/` (git-ignored) for the owner to move to a password manager.
- `server/`: Laravel 13.34, Filament 5.9 panel at `/admin`, Pest 4, Postgres-only tests. 4 tests pass.
- `pos/`: React+TS Vite PWA shell (app-shell precache, no API caching), connection indicator (online / server down / offline). 4 Vitest tests pass; build OK.
- CI workflow `.github/workflows/ci.yml` (Pint, composer audit, Pest on Postgres 17, npm audit, Vitest, build).
- Browser-verified: `/admin/login` renders; POS shows "Connected" and flips to "server not answering" when server stops.

## Next — Phase 1 (core platform)
1. UUIDv7 primary keys + base model traits (`tenant_id`, `branch_id`, `origin_node`, `version`, device/server times).
2. Tenancy: tenant, legal entities, branches, warehouses, tills, devices; node role config (`hq|branch|standalone`).
3. Users, security levels L0–L7, seeded roles, scoped grants + numeric limits, SoD rules; IT Admin without business permissions.
4. MFA (TOTP) for L3+, passkeys; PIN login for POS tied to named user.
5. Audit hash chain (HMAC) + outbox table written in the same transaction by domain services.
6. Module system (`modules/<Name>/module.json`), per-branch enable/disable, setup wizard.
7. Settings layer (seeded defaults, editable in admin; locale Ghana/GHS/Africa/Accra).

## Blockers / owner decisions
- GitHub: create a private repo and push so CI runs? (outward action — needs owner yes)
