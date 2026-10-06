# Requirements and installed tools

## Development machine (owner PC, Windows 11 Pro) — verified 2026-10-05

| Tool | Version | Source | Used for |
|---|---|---|---|
| PHP | 8.4.25 (NTS x64) | Laravel Herd | server; ext: pdo_pgsql, pgsql, intl, bcmath, sodium, gd, zip, redis, mbstring |
| Composer | 2.10.2 | preinstalled | PHP deps |
| Node.js / npm | 24.20.0 / 11.19.0 | preinstalled | POS PWA |
| Git / GitHub CLI | 2.55.0 / 2.98.0 | preinstalled | VCS, CI |
| PostgreSQL | 17.11 | EDB installer (signed, direct download), Windows service, port 5432 | all databases (`erp`, `erp_test`, `erp_test_node`) |
| Memurai Developer | 4.1.2 | winget `Memurai.MemuraiDeveloper`, Windows service, port 6379 (approved 2026-10-06) | Redis-compatible cache/queue/broadcast for Reverb (Phase 4). Developer edition is for development only — production Windows nodes need Memurai Enterprise or Redis on Linux |
| Playwright + Chromium | 1.63.0 / Chromium build 1243 | `pos` dev dependency; browser in `%LOCALAPPDATA%\ms-playwright` (~150 MB) | till browser tests (`npm run e2e`) |

Not yet installed (added in the phase that needs them; owner asked first if system-wide):

| Tool | Phase | Why |
|---|---|---|
| Rust + Tauri CLI | 13 | desktop POS shell |
| Docker Desktop | 13 | cloud/central Compose packaging |
| FrankenPHP | 13 | local-node single-binary server |

## Application packages

Server: Laravel 13.34, Filament 5.9, Livewire 4.4, Sanctum (API tokens), pragmarx/google2fa-qrcode 3 + bacon-qr-code 3 (authenticator app), spatie/laravel-passkeys 1.8 (passkeys), Pest 4.7, Pint 1.32. Browser: @simplewebauthn/browser 13.3 (vendored in `server/public/vendor`).
POS: React 19.2, Vite 8.3, TypeScript 6.0, vite-plugin-pwa 2.0, Dexie 4.4 (IndexedDB), uuid 11 (UUIDv7), Vitest 5.0, Playwright 1.63.

## Runtime targets (from spec §12; per-mode hardware sheets written in Phase 13)

- Till: low-end Windows PC (4 GB RAM) or Android tablet, modern Chromium.
- Branch node: up to 20 tills.
- Cloud HQ: stateless app + queue workers + Reverb, PostgreSQL 17.
