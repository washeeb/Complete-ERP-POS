# Branding

Two brands meet on every screen:

| | Whose | Where it is set | Where it shows |
|---|---|---|---|
| **Product brand** | The software (currently **ZionEX**) | `branding/product/` + `server/config/brand.php` (`BRAND_NAME`, `BRAND_VENDOR`, `BRAND_WEBSITE`) | Sign-in page of a multi-company server, "Powered by …", favicons and app icons, the desktop till app, installers, the guide covers, licence messages |
| **Client brand** | Each company using it | Back office → **Company → Branding**, or the setup wizard's "Your brand" step | Back office (logo, icon, colour), tills (top-bar icon, accent, receipt logo — printed as dithered black-and-white on thermal printers), invoices/statements/prints, PDF exports, online shop |

The client's brand leads; a small "Powered by <product>" stays unless the licence names the `white_label` feature (`php artisan license:issue … --features=finance,hr,white_label`).

## The ZionEX logo

The product is **ZionEX** — *The central intelligence of business* — **powered by SES Labs**, the SyncLytics division that builds it (`brand.division`; renamed from SES Labs on 2026-11-01). Receipts and prints say *ZionEX · Powered by SES Labs*; beside the logo it is *Powered by SES Labs*. The logo is a
first wordmark made in-house; a designer's version can replace it by the steps below.

- `product/mark.svg` — the icon: a white **Z** on a navy rounded tile (`#031E4C`), its stroke running through a teal
  node (`#06AAAA`) at the centre, with teal nodes at two corners: the business's central intelligence, linked like a
  network. Readable at 16 px.
- `product/logo.svg` — the mark and the name, drawn as strokes (no font needed): *Zion* in navy, *EX* in teal.
- `product/logo-dark.svg` — the same for dark backgrounds (*Zion* light, the tile edged in light teal).
- `product/original/` — the earlier SES Labs artwork, kept for the record only.

**Names that did not change with the rename, on purpose** (changing them would need every server moved and every
desktop till reinstalled): the Ubuntu folder and services (`/var/www/seslabs`, `seslabs-*`), the Windows folder,
scheduled tasks and firewall rule (`C:\SESLabs`, *SES Labs Web/PHP/Scheduler/Live*), the local HTTPS certificate names,
the desktop till's identifier (`app.seslabs.till`, pinned in `brand.json` as `tillIdentifier`, so settings and unsent
sales survive), and the short name in release and backup file names (`brand.slug`, `zionex`). The product became
**ZionEX Suite** on 2026-11-07: the till is *ZionEX Till* (`tillProductName`) and the office app *ZionEX Suite*; their
installers remove the copies under the earlier names (`installer-hooks.nsh`). An older server's `.env` with `BRAND_NAME="SES Labs"` shows ZionEX.

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
