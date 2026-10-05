# Requirements and installed tools

## Development machine (owner PC, Windows 11 Pro) — verified 2026-10-05

| Tool | Version | Source | Used for |
|---|---|---|---|
| PHP | 8.4.25 (NTS x64) | Laravel Herd | server; ext: pdo_pgsql, pgsql, intl, bcmath, sodium, gd, zip, redis, mbstring |
| Composer | 2.10.2 | preinstalled | PHP deps |
| Node.js / npm | 24.20.0 / 11.19.0 | preinstalled | POS PWA |
| Git / GitHub CLI | 2.55.0 / 2.98.0 | preinstalled | VCS, CI |
| PostgreSQL | 17.11 | EDB installer (signed, direct download), Windows service, port 5432 | all databases |

Not yet installed (added in the phase that needs them; owner asked first if system-wide):

| Tool | Phase | Why |
|---|---|---|
| Redis (or Memurai on Windows) | 4 | Reverb scaling / queues; `database` driver until then |
| Rust + Tauri CLI | 13 | desktop POS shell |
| Docker Desktop | 13 | cloud/central Compose packaging |
| FrankenPHP | 13 | local-node single-binary server |

## Application packages

Server: Laravel 13.34, Filament 5.9, Livewire 4.4, Pest 4.7, Pint 1.32.
POS: React 19.2, Vite 8.3, TypeScript 6.0, vite-plugin-pwa 2.0, Vitest 5.0.

## Runtime targets (from spec §12; per-mode hardware sheets written in Phase 13)

- Till: low-end Windows PC (4 GB RAM) or Android tablet, modern Chromium.
- Branch node: up to 20 tills.
- Cloud HQ: stateless app + queue workers + Reverb, PostgreSQL 17.
