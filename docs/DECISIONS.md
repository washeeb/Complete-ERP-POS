# Decision log

One line each: what — why. `[+]` = added beyond the spec.

## Phase 0 (2026-10-05)

- Owner answers: demo company (HQ=Wholesale, A=Grocery, B=Restaurant, C=Pharmacy); cloud HQ + branch nodes; single tenant, multi-tenant-ready schema; WooCommerce; Paystack + Hubtel MoMo test keys only; GRA E-VAT sandbox adapter; Claude API capped + Ollama fallback; neutral placeholder brand. Users/tills default: 15 users, 2 tills per branch.
- Monorepo `server/` (Laravel) + `pos/` (PWA); Tauri shell will live in `desktop/` (Phase 13) — one repo keeps API contracts and sync schemas versioned together.
- Laravel 13.34 / Filament 5.9 / Livewire 4.4 / Pest 4.7 — latest stable at scaffold time.
- PostgreSQL 17.11 installed from the EDB installer downloaded directly (signature verified) — `winget` download got HTTP 403.
- Dev uses Laravel Herd's PHP 8.4 (already installed); production nodes use FrankenPHP per spec.
- Queue/cache use the `database` driver in dev; Redis only where present (spec fallback).
- POS PWA precaches only the app shell; API responses are never cached by the service worker — sale data goes to IndexedDB (Phase 3), so stale cached prices can't be shown as live. [+]
- POS distinguishes "no network" from "network OK, server down" — different fixes for staff. [+]
- Reference build mined for: invariants table, permission matrix, offline grant/allocation contract, webhook signature rules (Shopify base64 HMAC-SHA256, Paystack HMAC-SHA512), outbox via `SKIP LOCKED`. These shape Phases 1–4.
- Offline auth follows the reference's stronger rule: enrolled device key + expiring signed grant, not a reusable cached password; a PIN only unlocks a grant. [+]
- Offline card/MoMo payments are recorded as *pending* unless a provider confirms; reconnect never re-charges. [+]
- Tests run against PostgreSQL (`erp_test`), not SQLite — SQLite can't prove locks or concurrency (lesson from reference build). [+]

## Phase 1 (2026-10-05)

- Base users migration rewritten to UUIDv7 before any real data existed (pre-release only; after go-live, schema changes are new migrations).
- Company + first owner are created by `php artisan erp:install`, not a public web page — no unauthenticated setup surface. [+]
- One `Recorder` service writes change + audit row + outbox event in one transaction; audit/outbox writes outside a transaction throw.
- Audit rows: HMAC-SHA256 chain per company, DB trigger blocks update/delete/truncate, secrets redacted, timestamps hashed at second precision (what the column round-trips).
- App DB role currently owns tables (could disable the trigger) → separate migration/runtime roles + off-server signed checkpoints in Phase 13.
- Authorization = level ceiling + role permission + scope path (company → legal entity → branch → warehouse → till) + numeric limits; missing scope never means global.
- Anti-escalation: no self-grant; granter must hold every permission of the role over that scope; role editors can only add permissions they hold company-wide; technical (IT) roles can hold only technical permissions. [+]
- Department and cost-centre scopes deferred to HR/Finance phases (no screens to protect yet).
- Seeder adds new permissions to default roles once; admin removals stick. Releasing a planned module = migration that flips `reserved` → `enabled`.
- Planned modules can only be *reserved* per branch, never "enabled" before they exist. Optional department modules mapped to phases: Storefront 4; Logistics, Assets, Projects, Helpdesk 12. Hospitality and Agriculture manifests deferred until scheduled.
- Two-factor per person (level ≥ setting, default L3) via our own middleware; Filament's switch is panel-wide. Authenticator app (TOTP + recovery codes) built in.
- Passkeys via spatie/laravel-passkeys 1.8 with overrides: user verification required, 32-byte challenges, inactive accounts refused, add/remove/sign-in audited. A passkey sign-in satisfies two-factor; merely owning one does not. [+]
- SimpleWebAuthn 13.3 browser build vendored in `server/public/vendor` (no CDN on admin pages).
- Profile edits and authenticator on/off go through audited services (Filament's default saves directly). [+]
- Till sign-in: username + PIN only on an enrolled device (device = second factor); 12-hour staff token bound to the device; revoking the device kills all its sessions; trivial PINs refused. [+]
- Device approval = admin registers it and gets a one-time, hashed, expiring enrolment code; the device enrols with code + public key.
- Setup wizard steps for tax profile, chart of accounts, hardware and sync arrive with those phases; users are added from the Users screen.
- Settings are code-declared definitions (label, help, range) with per-company values; the Settings page is generated from them. Only settings that something reads exist.
- API errors use `{"error","detail"}` with 401/403/422/423/429 mapping (from the reference build's convention).
