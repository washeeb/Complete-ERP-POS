# Synclytics ERP + POS

Modular, offline-first ERP + POS for retail/wholesale (Ghana-first, multi-country by configuration),
with per-branch industry modules and real-time online-store sync. Spec: [ERP-POS-MASTER-PROMPT.md](ERP-POS-MASTER-PROMPT.md).
Current progress: [docs/STATE.md](docs/STATE.md).

| Folder | What |
|---|---|
| `server/` | Laravel 13 + Filament 5: API, back office (`/admin`), sync hub |
| `pos/` | React + TypeScript PWA: till, works offline |
| `docs/` | State, decisions, requirements, install guides, manuals |

## Quick start (development, Windows)

Prerequisites and versions: [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md).

```bash
cd server
composer install
copy .env.example .env      # then set DB_PASSWORD
php artisan key:generate
php artisan migrate
php artisan serve           # http://127.0.0.1:8000/admin
```

```bash
cd pos
npm install
npm run dev                 # http://localhost:5173 (proxies /api to :8000)
```

Tests: `cd server && php artisan test` · `cd pos && npm test`.

Production install guides (standalone, branch node, central server, cloud, POS devices) are written
in the phases that make each mode runnable; see `docs/STATE.md`.
