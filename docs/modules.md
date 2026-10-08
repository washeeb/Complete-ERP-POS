# Modules

## What is installed where

- **Core** is always on for every branch: company, branches, warehouses, tills, users, roles,
  devices, audit log, modules, settings.
- **Industry and optional modules** are chosen **per branch** — in the setup wizard, and later in
  *System → Modules*. A branch can run several (e.g. Grocery + Restaurant food court).
- A module is **Available** (released) or **Planned** (arrives in a later phase). A planned module
  can only be **Reserved** for a branch: the choice is recorded and the module switches on when its
  phase ships. Nothing is shown as working before it exists.
- **Off** hides a module and blocks new work; it never deletes data.

| Module | Kind | Status |
|---|---|---|
| Core | core | Available — platform, catalog, prices/tax, stock, POS, purchasing, sales, finance, HR |
| Retail & Wholesale | industry | Planned (wholesale customers, credit and B2B orders are in Core since Phase 6) |
| Built-in Online Storefront | department | Available — enable per branch, then add the shop under Online → Channels |
| Grocery / Supermarket | industry | Available (Phase 9a) — see grocery.md |
| Pharmacy | industry | Available (Phase 9b) — see pharmacy.md |
| Restaurant / Café / Bar | industry | Available (Phases 9c–9d) — see restaurant.md |
| Electronics / Technology | industry | Available (Phase 10a) — see electronics.md |
| Fashion & Boutique | industry | Available (Phase 10b) — see fashion.md |
| Services & Packages | industry | Available (Phase 10c) — see services.md |
| Sales & Distribution | industry | Planned · Phase 10d |
| Manufacturing | industry | Planned · Phase 10e |
| Logistics & Delivery | department | Planned · Phase 12 |
| Assets & Maintenance | department | Planned · Phase 12 |
| Projects & Tasks | department | Planned · Phase 12 |
| Helpdesk | department | Planned · Phase 12 |

Who can change modules: anyone with `admin.modules.manage` over that branch (Owner, IT Admin by
default). Every change is audited with its reason.

## Building a module

Each module lives in `server/modules/<Name>/module.json`:

```json
{
  "key": "grocery",
  "name": "Grocery / Supermarket",
  "version": "1.0.0",
  "kind": "industry",
  "stage": "available",
  "description": "One line shown in the wizard and the Modules page.",
  "requires": ["core"],
  "permissions": [
    {"key": "grocery.markdowns.approve", "label": "Approve near-expiry markdowns", "min_level": 3}
  ]
}
```

Rules:

1. `stage` stays `planned` until the module's screens, services and tests exist. Only `available`
   modules seed permissions — a permission is never seeded over something that doesn't exist.
2. Permissions use `<module>.<area>.<action>`. Mark admin-only ones `"technical": true`.
3. Add default role mappings for new permissions in `app/Security/RoleCatalog.php`; the seeder
   attaches each new permission to its default roles once.
4. Check `ModuleService::isEnabled($branch, 'key')` before any write the module performs.
5. Releasing a planned module: set `stage` to `available` and ship a **migration** that turns that
   module's `reserved` rows into `enabled` (a migration runs once; a seeder would re-enable modules
   an admin later switched off).
