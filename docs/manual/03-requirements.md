# 3. What you need

## Hardware

### The server

| Setup | Minimum | Recommended |
|---|---|---|
| A (one PC, also the till) | Windows 10/11 64-bit, 4-core CPU, 8 GB RAM, 256 GB SSD | 16 GB RAM |
| B (shop server) | Windows 10/11 Pro or Ubuntu 24.04, 4 cores, 8 GB RAM, 256 GB SSD | 16 GB RAM, Ubuntu for more than 4 devices |
| C / D (HQ, central or cloud) | Ubuntu 24.04, 2 vCPU, 4 GB RAM, 80 GB SSD | 4 vCPU, 8 GB RAM, 160 GB SSD; scale up with branches |
| E (branch server) | as B | as B |

**Why:** the database works best on an **SSD** (not a spinning hard disk). Memory lets the database keep frequently used data in RAM, which keeps the tills fast.

Also needed for an on-site server:
- A **UPS** (battery backup) so a power cut does not switch the server off mid-write. At least 15 minutes, with enough time to shut down safely.
- The **correct date and time**, set automatically from the internet. Wrong clocks cause sync refusals.
- A place where it will not be unplugged by accident, and is not open to customers.

### Tills and other devices

| Device | Requirement |
|---|---|
| Till PC | Windows 10/11 with Google Chrome or Microsoft Edge (current version), 4 GB RAM, screen at least 1280 × 720 (touch screen optional) |
| Till tablet | Android 10 or later with Chrome, 10" screen recommended |
| Receipt printer | 80 mm thermal receipt printer installed as a normal Windows/Android printer (USB, network or Bluetooth). Receipts are printed through the browser's print window. A4 printers also work. |
| Cash drawer | One that opens from the receipt printer (RJ11 cable to the printer), with the printer driver set to *open drawer* when printing |
| Barcode scanner | Any USB or Bluetooth scanner that acts as a **keyboard** (most do). It must send **Enter** after each code. |
| Handheld (warehouse) | Android phone or rugged scanner with Chrome and a built-in scanner or camera |
| Kitchen screen | Any PC, tablet or TV stick with Chrome; touch screen helpful |
| Label scale (grocery) | A scale that prints EAN-13 labels with prefix 21 (see Chapter 8, Grocery) |

### Network

- **Setup A:** no network needed for selling. Internet is needed for the daily licence check-in (it tolerates 30 days without).
- **Setup B/C/E:** a router with cable or reliable Wi-Fi. Give the server a **fixed (reserved) IP address** in the router so tills always find it.
- **Setup D:** a reliable internet connection at every branch (4G backup router recommended). Tills keep selling during outages.
- **Internet for head office:** needed once a day for the licence check-in, and for online stores, SMS and delivery apps.

## Software

All software below is free. Download only from the official addresses listed.

| Software | Version | Needed on | Official download |
|---|---|---|---|
| PostgreSQL | 17 | Server | `https://www.postgresql.org/download/` (Windows: the EDB installer) |
| PHP | 8.4 (8.3 minimum), 64-bit, *Non Thread Safe* on Windows | Server | `https://windows.php.net/download/` (Windows); Ubuntu: Ondřej Surý's PPA, see Chapter 5 |
| PHP extensions | `pdo_pgsql`, `pgsql`, `intl`, `bcmath`, `sodium`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `gd` | Server | Included with PHP |
| Composer | 2.x | Server (installation and updates) | `https://getcomposer.org/download/` |
| Node.js (with npm) | 22 LTS or later | Server (installation and updates only) | `https://nodejs.org/` |
| Git | any recent | Server (to download and update the software), optional | `https://git-scm.com/` |
| Google Chrome or Microsoft Edge | current | Every till, handheld, kitchen screen, and your own PC for the back office | `https://www.google.com/chrome/` |
| Authenticator app | any (Google Authenticator, Microsoft Authenticator, Authy…) | Phones of managers | Phone app store |
| Nginx, Certbot | from Ubuntu | Linux servers only | installed by commands in Chapter 5 |

**Why Node.js on the server?** It is used once during installation (and after each update) to build the till app. It does not run while you trade.

## Information to prepare

Write these down before you start (keep the sheet in a safe place, not on the server):

| Item | Example | Used in |
|---|---|---|
| Company trading name | Kofi Stores | Chapter 7 |
| Registered name, TIN, VAT number | Kofi Stores Ltd, C0012345678 | Chapter 7 |
| Branches: code, name, number of tills, modules | HQ · Head Office · 2 tills · Grocery | Chapter 7 |
| Owner: full name, username, email, password (12+ characters) | | Chapter 4/5 |
| Database password (long and random) | | Chapter 4/5 |
| Server IP address or domain | 192.168.1.10 / erp.kofistores.com | Chapters 4, 5, 9 |
| Your tax set-up, checked by your accountant | VAT 15 %, NHIL 2.5 %, GETFund 2.5 % | Chapter 7 |
| Products list with prices, barcodes and opening stock | | Chapter 7 |
| Staff list with roles | | Chapter 7 |

**Warning:** Never use real customer data for practice or testing. Use made-up names and numbers until you go live.
