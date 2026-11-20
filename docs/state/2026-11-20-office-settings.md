## New back office, settings and admin part 4: settings and modules (branch `office-settings`, stacked on `office-access-pages`)
- **Server:** `App\Domain\Office\SettingsScreen` (settings by group from `Settings::DEFINITIONS`, hiding industry and add-on groups as now; modules of one branch), `Office\SettingsController` (save only changed settings this person may change, "confirm it's you" for the payment-confirmation limit; modules enabled before turned off) through `Settings` and `ModuleService`.
- **Routes:** `office-api/settings` (GET, POST), `office-api/modules` (GET with `?branch=`, POST).
- **Client:** `pages/SettingsPage.tsx`, `pages/ModulesPage.tsx`. `/admin/manage-settings` and `/admin/manage-modules` map to the new pages.
- **Tests:** `OfficeSettingsTest`. office-check opens both from Setup and searches the settings.
- **Next:** settings and admin part 5: messaging, devices, then catalog and pricing setup.
