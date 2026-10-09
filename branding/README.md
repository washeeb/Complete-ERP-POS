# Branding

Two brands meet on every screen:

| | Whose | Where it is set | Where it shows |
|---|---|---|---|
| **Product brand** | The software (currently **SES Labs**) | `branding/product/` + `server/config/brand.php` (`BRAND_NAME`, `BRAND_VENDOR`, `BRAND_WEBSITE`) | Sign-in page of a multi-company server, "Powered by …", favicons and app icons, the desktop till app, installers, the guide covers, licence messages |
| **Client brand** | Each company using it | Back office → **Company → Branding**, or the setup wizard's "Your brand" step | Back office (logo, icon, colour), tills (top-bar icon, accent, receipt logo — printed as dithered black-and-white on thermal printers), invoices/statements/prints, PDF exports, online shop |

The client's brand leads; a small "Powered by <product>" stays unless the licence names the `white_label` feature (`php artisan license:issue … --features=finance,hr,white_label`).

## The SES Labs logo

`product/ses-lettermark.svg` is the SES lettermark (SES Labs), **traced as vector shapes** from the owner's logo (navy `#031E4C`, teal `#06AAAA`). `product/compose.py` builds `logo.svg` (light backgrounds), `logo-dark.svg` (navy turned light, for dark backgrounds) and `mark.svg` (the full SES on a white rounded tile — the app icon) from it. The owner's original artwork goes in `product/original/` as the master copy; check the trace against it.

## Changing the product's logo or name

1. Replace the placeholders in `branding/product/`:
   - `mark.svg` — the symbol, **square** (512 × 512 viewBox), readable at 16 px.
   - `logo.svg` — symbol + name for light backgrounds (about 4:1).
   - `logo-dark.svg` — the same for dark backgrounds.
   - `brand.json` — `name`, `tagline`, `color` (main), `accent`, `tile` (icon background / theme colour).
2. In `pos/`: `npm run brand`. It writes the favicons, home-screen icons (incl. maskable), `server/public/favicon.ico`, `installer/windows/app.ico`, the desktop till's icons and its name/publisher (`tauri.conf.json`), and the guide-cover logo.
3. A new **name** also goes in `server/config/brand.php` (default) or `BRAND_NAME` in `.env`, and in the Windows installer (`/DBrand=` or the default in `installer/windows/server-setup.iss`; `-Brand` for `install.ps1`) and the Ubuntu script (`SLUG=`). Then rebuild the guides (`npm run manual`, `npm run training`).
4. Commit the generated files with the artwork.

Identifiers that are **not** renamed on purpose: the till's local database (`synclytics-till`, renaming it would strand sales queued offline), the backup format (`.synbak`, `SYNBAK1`) and the licence code prefixes (`SYN1-`, `SYNREQ-`) — formats, not names. Old till export files (`synclytics-till-queue-1`) are still accepted.

## Samples

`samples/demo-client-logo.svg` — a made-up client ("Adom Mart") for demos and the guide's screenshots. Never ship it as a real client's logo.
