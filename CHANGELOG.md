# Changelog

## [0.1.0] — 2026-10-05 · Phase 1 core platform
### Added
- Company structure: companies, legal entities, branches, warehouses, tills, devices (UUIDv7 ids, origin node and version on every row).
- Access control: security levels L0–L7, 22 seeded roles, grants scoped to company / legal entity / branch / warehouse / till with numeric limits and optional expiry; anti-escalation rules; IT Admin technical-only.
- Tamper-evident audit log (HMAC chain, append-only at the database, secrets redacted) with *Verify chain* and `erp:audit-verify`; transactional outbox for sync.
- Module system (`server/modules/*/module.json`): Core plus 14 planned modules, enabled/reserved/off per branch.
- First run: `erp:audit-key`, `erp:install`, then a back-office setup wizard (deployment mode, company, branches, tills, modules per branch).
- Back office: Branches (warehouses, tills), Users (access grants, POS PIN), Roles, Devices (one-time enrolment codes, revoke), Audit log, Modules, Settings.
- Sign-in security: two-factor required from L3 (authenticator app with recovery codes, or passkeys with user verification); audited profile and 2FA changes.
- Till API: device enrolment, PIN sign-in on enrolled devices with lockout, `me`, logout.
- Docs: security, modules, API, administrator manual.

## [0.0.1] — 2026-10-05 · Phase 0 scaffold
### Added
- Monorepo: `server/` (Laravel 13, Filament 5 back office at `/admin`, Pest on PostgreSQL 17) and `pos/` (React + TypeScript PWA shell with connection status).
- CI workflow: formatting, dependency audits, server and POS tests, POS build.
- Docs: README, CLAUDE.md, STATE, DECISIONS, REQUIREMENTS.
