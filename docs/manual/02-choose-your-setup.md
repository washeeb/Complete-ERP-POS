# 2. Choose your setup

There are five ways to run Synclytics. They use the **same software**; only where the server sits changes. You can start small and grow later (for example, start with one shop and add a cloud head office when you open a second branch).

## The five setups at a glance

| | Setup | Best for | Server | Where tills connect |
|---|---|---|---|---|
| **A** | **One computer** | A small shop with one till | The till PC itself (Windows) | The same PC (`http://localhost:8010/pos/`) |
| **B** | **One shop, several tills** | A shop, pharmacy or restaurant with 2–20 tills and devices | One PC or small server in the shop (Windows or Ubuntu) | Over the shop's network (LAN / Wi-Fi) |
| **C** | **Central server for several branches** | Branches joined by a private network (VPN) or in one building | A server at head office (Ubuntu recommended) | Over the private network |
| **D** | **Cloud head office** | Several branches in different towns, owner wants to see everything from anywhere | A rented cloud server with a domain name and HTTPS (Ubuntu) | Over the internet |
| **E** | **Cloud or central HQ + branch servers** | Branches with unreliable internet, busy restaurants, supermarkets | HQ as in C or D, plus a branch server at each such branch | Tills use their **branch** server over the LAN; branch servers sync with HQ |

**Why:** a till can sell offline in every setup, but the back office, kitchen screens, handhelds, shared restaurant bills and stock look-ups need the server. If the server is in the cloud and the internet is down, those stop until it returns. A branch server (setup E) keeps them working on the local network.

## Which one should I choose?

Answer these questions in order:

1. **Do you have only one location?**
   - One till only → **A**.
   - Several tills, kitchen screens or handhelds → **B**.
2. **Several locations?**
   - Do all branches have reliable internet (or a private network)? → **D** (cloud) or **C** (central server with VPN).
   - Do some branches have unreliable internet, or need kitchen screens, handhelds or shared restaurant bills to keep working during outages? → **E** (add a branch server at those branches).

**Tip:** Most businesses with 2–5 branches in Ghana do well with **D** for head office plus a branch server (**E**) only at branches with weak internet or a restaurant.

## What each setup needs (and the chapters to follow)

### A — One computer

- One Windows 10/11 PC (the till) with a receipt printer and barcode scanner.
- Chapters: 3 → **4 (Windows)** → 6 → 7 → 8 → 9 → 12.
- The till opens at `http://localhost:8010/pos/` on the same PC, so no extra network set-up is needed.

### B — One shop, several tills

- One PC or small server that stays switched on during trading hours, with a **fixed IP address** on the shop network.
- Till PCs/tablets on the same network (cable or Wi-Fi).
- Chapters: 3 → **4 (Windows)** or **5 (Ubuntu)** → 6 → 7 → 8 → 9 (including 9.3, *Trust the server's network address*, on every device) → 12.

**Note:** With the server on Windows today, the web server built into PHP answers one request at a time. That is fine for about 1–4 tills. For more tills, kitchen screens and handhelds, use an Ubuntu server (Chapter 5) until the Phase 13 Windows installer arrives.

### C — Central server for several branches

- One Ubuntu server at head office, reachable from the branches through a VPN (your network provider can set this up) or within one building.
- Chapters: 3 → **5 (Ubuntu)** → 6 → 7 → 8 → 9 → 10 (optional branch servers) → 12.

### D — Cloud head office

- A cloud server (VPS) running Ubuntu 24.04, a **domain name** (for example `erp.yourshop.com`) and a free HTTPS certificate (set up in Chapter 5).
- Chapters: 3 → **5 (Ubuntu, including HTTPS)** → 6 → 7 → 8 → 9 → 11 (online stores) → 12.

### E — HQ plus branch servers

- HQ as in C or D.
- At each chosen branch: a PC or small server installed like setup B, then **paired** with HQ.
- Chapters: first set up HQ (C or D). Then, for each branch server: 4 or 5 → **10 (Branch servers)** → 9 → 12.

## Moving between setups later

| From → To | What to do |
|---|---|
| A → B | Give the PC a fixed IP address and set up the other tills (Chapter 9). Nothing is reinstalled. |
| A/B → D | Install a cloud server, then move the database to it (Chapter 12, *Moving to a new server*). The licence must be re-activated on the new server. |
| D → E | Install a branch server at the branch and pair it (Chapter 10). Re-enrol that branch's tills on the branch server. |
