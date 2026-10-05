# State (resume file)

**Phase:** 1 — Core platform · **Status:** in progress on branch `phase-1-core` (domain layer done, admin UI not started)
**Updated:** 2026-10-05 · Repo: https://github.com/washeeb/Complete-ERP-POS (private). CI green on `main`.

## Phase 0 — done
Laravel 13 + Filament 5 (`server/`), React PWA shell (`pos/`), PostgreSQL 17 local, CI.

## Phase 1 — done so far (47 Pest tests passing on Postgres)
- Schema: UUIDv7 users and tenancy (tenants, legal entities, branches, warehouses, tills, devices), permissions/roles/role_assignments (scope + limits + expiry), audit_events (HMAC chain, DB trigger append-only), outbox_events (node seq), branch_modules, settings.
- `app/Domain/Support`: Actor, AuditLog, AuditVerifier, Outbox, Recorder (mutation + audit + outbox in one transaction).
- `app/Security`: SecurityLevel L0–L7, ScopeType, Authorizer (deny by default, ceiling, scope path, limits), RoleCatalog (22 seeded roles), AccessCatalogSync (seeder never re-adds removed permissions).
- `app/Domain`: AccessService (no self-grant, level ceilings, granter must hold the permissions they grant, IT Admin technical-only, temp access needs reason), OrgService (setup wizard logic, branches/warehouses/tills), ModuleService (core always on; planned modules only "reserved"), DeviceService (register → one-time code → enrol → revoke kills all sessions), PosAuthService (PIN login, lockout).
- `modules/*/module.json`: Core (available) + 14 planned modules with release phase.
- API: `POST /api/v1/devices/enroll`, `POST /api/v1/pos/login`, `GET /api/v1/pos/me`, `POST /api/v1/pos/logout`.
- Commands: `erp:install`, `erp:audit-key`, `erp:audit-verify`. Settings registry (MFA level, PIN attempts/lockout, enrolment hours).
- MFA: Filament TOTP contracts on User + `EnsureMfaForLevel` middleware written — **not yet wired into the panel**.

## Next (remaining Phase 1)
1. Wire AdminPanelProvider: `->multiFactorAuthentication([AppAuthentication::make()->recoverable()])`, `requiresMultiFactorAuthentication(true)` + `multiFactorAuthenticationRequiredMiddlewareName('erp.mfa')`; login by email.
2. Filament UI: Setup wizard page (prefilled demo: HQ Wholesale, A Grocery, B Restaurant, C Pharmacy), Branches (+warehouses/tills), Users (+grants), Roles, Devices (show code once, revoke), Audit log (+verify), Modules per branch, Settings page. All writes via domain services.
3. Tests: MFA redirect for L3+, wizard UI, Filament access denial per permission.
4. Passkeys (check spatie/laravel-passkeys on Laravel 13) or feature flag off + DECISIONS line.
5. Docs: DECISIONS (Phase 1 lines below), `docs/security.md`, `docs/modules.md`, CHANGELOG; browser-verify; merge to main.

## Decisions to log in DECISIONS.md when Phase 1 closes
- Base users migration rewritten to UUID (pre-release, no data). Owner created by CLI `erp:install` (no unauthenticated web setup).
- Audit timestamps hashed at second precision; app DB role can still disable triggers → separate migration/runtime DB roles + external checkpoints in Phase 13.
- Scopes in Phase 1: tenant/legal entity/branch/warehouse/till; department/cost-centre scopes arrive with HR/Finance.
- PIN login needs an enrolled device (device = second factor); no TOTP on tills.
- Wizard steps for tax profile, chart of accounts, hardware, sync are added in their phases.

## Blockers / owner decisions
- None.
