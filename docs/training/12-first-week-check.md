# First-week check at a new client

A visit (or remote session) about one week after go-live, with the owner or general manager. It takes about two hours. Bring the client's licence details and this checklist; tick each box. Do it outside the busiest hours.

## 1. The server is healthy

- [ ] **System → Health:** no red line. Note any amber line and what it says.
- [ ] **Backups:** a good backup every night since go-live (System → Backups). The newest *restore check* passed.
- [ ] A copy of the backups goes **somewhere other than the server's own provider** (a second destination: Google Drive, another server or a USB disk). If not, add it now (manual 12.2).
- [ ] The **backup passphrase** is written down and kept away from the server (ask the owner where).
- [ ] **Disk space** above 20 % free; **memory** and **server load** green.
- [ ] Health alerts reach the right people: the owner and IT Admin get the bell and an e-mail when something turns red (System → Health shows the checks that alert).

**Why:** a business only finds out that backups were failing on the day it needs one.

## 2. Tills and handhelds

- [ ] **System → Devices:** every till shows *OK* in the Health column and was seen today. Revoke devices that are no longer used.
- [ ] Each till's **receipt** prints the right company, branch, phone and TIN/VAT.
- [ ] **Sales waiting to be sent:** none older than a few minutes (Health column, or the till's status bar).
- [ ] Staff sign in with **their own** username and PIN — nobody shares a PIN. Ask two cashiers.
- [ ] Handhelds (if any): scanning works without tapping the box first; the beep sounds.

## 3. Money

- [ ] **Sales → Till shifts:** every shift since go-live is closed. Look at **over / short** per cashier: any pattern (same person, same day, same network)?
- [ ] Mobile money per network and the card terminal were counted at each close (MoMo & card column).
- [ ] **Cash → Shifts and vaults:** shift times match the shop's hours.
- [ ] **Payment alerts** (if wanted): the owner gets each MoMo / card payment or the daily summary.
- [ ] **Daily summary:** switch on with the owner (System → Settings → Daily summary, 20:00), and check the first one arrives.

## 4. Stock

- [ ] **Stock → Batches & expiry:** expired stock is not on the shelves (Expiry & returns lists it for disposal or return).
- [ ] **What to order:** levels make sense for the top 10 products; suppliers are set on them.
- [ ] **Stock → My day** (stock controller): the daily cycle count is being done.
- [ ] **Losses:** look at adjustments since go-live — reasons given, photos where required.

## 5. Pharmacy (if the client is a pharmacy)

- [ ] Medicines have **schedule**, **batches and expiry** and **dosage** filled in; dosage labels print.
- [ ] **Pharmacy → Sales to sign off:** nothing left unsigned from earlier days.
- [ ] **Refill reminders:** with the owner's agreement, fill **Used a day** on long-term medicines, switch the reminders on, and ask cashiers to record the customer's consent to SMS / WhatsApp.

## 6. Messages and outside services

- [ ] **System → Messaging:** SMS (and WhatsApp if used) send a test message; e-mail sends a test e-mail.
- [ ] **Mobile money requests** (only with a Paystack / Hubtel / other gateway account): a real 1.00 payment to the owner's phone works end to end (manual 11.6).
- [ ] **GRA E-VAT** (VAT-registered clients, once GRA has given the details): *GRA E-VAT* is green on Health and a test receipt shows GRA's signature block (manual 11.5).

## 7. People and access

- [ ] **People & access → Users:** nobody who left is still active; each person has the role they need and no more.
- [ ] Managers' sign-in uses the **authenticator app** or a code (not password only).
- [ ] **Support access** is closed unless support is working on something (System → Support access).

## 8. Ask the staff

- [ ] What is slow or awkward? (Write down the screen and what they were doing.)
- [ ] What do they do on paper that the system could do?
- [ ] Anything they were not trained on?

## After the visit

- [ ] Send the owner a short note: what is fine, what was fixed, what they need to do (with dates).
- [ ] Send SyncLytics support anything that needs a change in the system (**Report a problem**, with a picture of the screen).
- [ ] Book the next check (one month after go-live).
