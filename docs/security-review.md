# Security review (Phase 13, 2026-10-29)

Scope: server (Laravel + Filament), till PWA, desktop till, sync, public API, installers. Method: review of each control below against the code, automated checks in CI, and targeted scans. Findings fixed in this phase are marked **Fixed**.

| Area | Control | Status |
|---|---|---|
| Authentication | Back office: password + TOTP (required by security level), recovery codes, passkeys; tills: device token + staff PIN (PBKDF2, lockout), offline grants signed and time-limited | OK |
| Authorisation | Deny by default; every domain service checks the permission at the right scope; IT Admin has no business permissions; branch-scoped grants filter lists, reports, insights, copilot and the API; tests cover cashier/branch-manager denials per module | OK |
| API keys | Sanctum tokens hashed at rest, shown once; scopes + "acts as" person; 120 req/min; revocation immediate | OK |
| Webhooks in | Signature over the raw body (HMAC), stored once, idempotent | OK |
| Webhooks out | HMAC-SHA256 with timestamp, stable event id, back-off, dead-letter | OK |
| Node sync | HMAC-signed, per-peer secrets, rate-limited | OK |
| SQL injection | Query builder everywhere; raw SQL only from fixed fragments (report datasets, analytics), values bound; scanned for interpolated input — none | OK |
| XSS | Blade escaping; the few `{!! !!}` outputs are pre-escaped (`nl2br(e())`) or system-generated SVG/meta; AI answers rendered escaped | OK |
| CSRF / clickjacking | Filament/Laravel CSRF; **Fixed:** `SecurityHeaders` middleware — X-Frame-Options SAMEORIGIN, CSP `frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'`, nosniff, Referrer-Policy, Permissions-Policy (camera/geolocation self only), COOP, HSTS on HTTPS | Fixed |
| Secrets | `.env` only (never in git); provider credentials, channel keys, backup destinations, webhook secrets stored `encrypted:` with APP_KEY; audit records "changed", never values; installer passwords in an admin-only file | OK |
| Uploads | Private disk, MIME/size checks, served through authorised controllers | OK |
| Backups | **New:** Argon2id + XChaCha20-Poly1305 (libsodium secretstream), tamper detection, passphrase outside the server, weekly restore check | Fixed |
| Audit | Hash-chained audit log, sealed with ERP_AUDIT_KEY; `erp:audit-verify` | OK |
| Licensing | Signed manifest of protected files, watermarking | OK |
| Desktop till | Window may only navigate to the configured server or its own set-up page; hardware commands reachable only from those pages | OK |
| AI | Off by default; read-only fixed tools with the person's permissions; masking; usage log; never posts | OK |
| Dependencies | `composer audit` and `npm audit --audit-level=high` in CI on every push | OK |
| Transport | HTTPS required for internet-facing servers (health check warns); `SESSION_SECURE_COOKIE=true` set by the Ubuntu script with HTTPS | OK |

Residual risks / follow-ups (Phase 14): penetration test by a third party before the first internet-facing pilot; a full CSP for the back office (Filament/Livewire need inline scripts today); rate limiting of back-office sign-in per IP is Laravel's default (5/min).
