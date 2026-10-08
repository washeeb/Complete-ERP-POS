# 10. Branch servers and several sites (setup E)

A **branch server** is a second copy of the server at a branch. The branch's tills, back office, kitchen screens and handhelds use it over the local network, so the branch keeps working fully when the internet to head office is down. It exchanges changes with head office every minute when the link is up.

**Why:** in setup D, tills sell offline during an outage, but kitchen screens, shared restaurant bills, handhelds and stock look-ups need the server. A branch server puts a server inside the branch.

## 10.1 What flows where

| Information | Direction | Who decides |
|---|---|---|
| Company, branches, tills, modules, settings, licence | HQ → branches | Head office |
| Users, roles, access, PINs | HQ → branches | Head office |
| Products, units, barcodes, prices, tax, promotions | HQ → branches | Head office |
| Sales, shifts, devices, review items | Branches → HQ | The branch |
| Stock movements, transfers, counts, purchase orders | Both ways | Where it happened |
| Customers, balances, bookings, passes, memberships | Both ways | Field by field / where it happened |

**Note:** on a branch server, master data (products, prices, users) is **read-only**: change it at head office and it arrives within a minute. Nothing is ever overwritten — sales and stock movements are only added.

## 10.2 Install the branch server

1. Head office must already be installed and activated (setup C or D).
2. At **head office**: **System → Sync Center → Add branch server**. Enter the branch server's **id** (e.g. `B01`, unique per server) and a name. Note the **command** and the **secret** that are shown **once**.
   **Why:** the secret proves to head office that the branch server is yours; every exchange is signed with it.
3. Install the branch server on its PC or small server with **Chapter 4** (Windows) or **Chapter 5** (Ubuntu), with these differences:
   - In `.env`: `ERP_NODE_ROLE=branch`, `ERP_NODE_ID=B01` (the id from step 2), and `APP_URL` = the branch server's own network address.
   - **Do not run** `php artisan erp:install` (the company comes from head office).
   - `php artisan migrate --seed --force` on a branch server only installs the permissions.
4. Join head office (on the branch server):

   ```
   php artisan erp:node:join https://<hq-address> <HQ node id> <company id>
   ```

   Paste the secret when asked. The exact command, with the head office id and company id filled in, is the one shown in step 2.
   **Why:** this downloads everything the branch needs: company, people, products, prices and stock.
5. Make sure the scheduler runs on the branch server (Chapter 4.8 or 5.9). It runs the sync every minute.
6. Register and enrol **that branch's devices on the branch server's own back office** (`http://<branch-server>/admin` → System → Devices), and point the tills at the branch server's address (Chapter 9).

**Check:**
- Branch server back office → **System → Sync Center**: head office shows **OK** and a recent *Last sync*.
- Sell on a branch till; within a minute the sale appears at head office under **Sales → Till sales**.
- Change a price at head office; within a minute it appears on the branch till.

**Note:** two-factor sign-in secrets and passkeys are not copied to branch servers (each server has its own encryption key). Managers who use the branch server's back office set up two-factor sign-in there once.

## 10.3 The Sync Center (System → Sync Center)

| What you see / do | Use it when |
|---|---|
| Status per server: last sync, waiting, failed, last error | Daily glance |
| **Sync now** | You want changes across immediately |
| **Choose what syncs** per group: Automatic, Manual (preview first), Off | Normally leave everything on *Automatic* |
| **Review incoming** | A group is on *Manual* |
| **Export / Import sync file (USB)** | The link is down for days: carry the changes on a USB stick |
| **Full resync from this peer** | A branch seems out of step; safe to repeat |
| **Import till file** | A till was replaced before it could send its sales |

## 10.4 Keeping servers in step

- **Upgrade head office first**, then branch servers. Changes a branch server cannot apply yet wait as *failed — update this node* and apply by themselves after the upgrade.
- Server clocks must agree within 5 minutes (automatic time on every server).
- To stop a branch server (closed branch, replaced PC): at head office **Sync Center → (row menu) → Disable this peer**. Nothing is deleted. Keep a final backup of the branch server.
