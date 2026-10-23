# New features: switch on and check

A short session with the owner (or general manager) and one teller, after installing an update. Tick each box. It takes about an hour. Do it outside the busiest hours, on one till first.

## Before you start

- [ ] The update is installed and **System → Health** shows everything green.
- [ ] You can sign in to the back office with your **username** (not only your e-mail).
- [ ] Your user account has a **Mobile (WhatsApp) number** (People & access → Users → your name), for alerts and sign-in codes.

## 1. Receipts show the right company

1. **Company → Legal entities:** the pharmacy's company name (e.g. *Asante Pharmacy Ltd*), TIN and VAT number are right.
2. **Company → Industries → Pharmacy:** its legal entity is that company. (Or set it on the branch: Company → Branches.)
3. On the till: **sign out and in again**, make a small test sale, print the receipt.

- [ ] The receipt top shows the pharmacy's company, then *branch · phone*, then *TIN / VAT*. No group name and no address.

**Why:** customers and the Ghana Revenue Authority see the company that actually sold.

## 2. Dosage on medicines

1. Open two or three common medicines (Catalog → Products) and fill in **Usual dosage**, e.g. *1 TABLET 3 TIMES DAILY AFTER MEALS FOR 5 DAYS*. Many at once: download the products file, fill the `dosage` column, import it (System → Import data (CSV / Excel) → Products → Update products).
2. On the till (refresh or sign in again): sell one of them. The dosage shows under the medicine; change it for this patient if the prescription says otherwise.
3. Pay. The dosage labels print by themselves on the desktop till (one per medicine, each cut). In a browser press **Print dosage labels**.

- [ ] The dosage shows on the sale and can be changed.
- [ ] The label shows the pharmacy, patient (if typed under **Details**), medicine, quantity, dosage, date and receipt number.
- [ ] The receipt shows *Dosage: …* under the medicine.

**Tip:** type the patient's name under **Details** before paying: it goes on every label.

## 3. Mobile money and card alerts

1. **System → Settings → Payment alerts:** choose *Each payment and a daily summary*; *Mobile money and card*; leave *Single alerts only from* at 0 for the test. Tick **Also by SMS** or **WhatsApp** only if an SMS or WhatsApp provider is set up (System → Messaging); e-mail needs the server's e-mail settings.
2. Check who gets them: Owner, General Manager, Finance Manager and Accountant have *Receive mobile money and card payment alerts*. Give or remove it under People & access → Roles.
3. Sell something on the till paid by **MoMo**.

- [ ] The bell (top right of the back office) shows *MTN MoMo GH₵ … received* within a minute.
- [ ] The SMS / WhatsApp / e-mail arrives, if switched on (Messages → Message log shows each one).
- [ ] After the summary time (default 21:00) the daily summary arrives.

Then set *Single alerts only from* to an amount that suits you (e.g. 50000 = GH₵ 500), so small payments only show in the summary.

## 4. Automatic shifts

1. **Cash → Shifts and vaults:** the shifts are *Morning 07:00–15:00* and *Afternoon 14:00–23:00* (change them if your hours differ; shifts may overlap by one hour at most).
2. **System → Settings → Tills and cash:** switch on **Automatic shifts**; check **Till trading hours** (default 07:00-23:00) and **Straight day from** (10 hours).
3. Give managers who may allow exceptions the permission *Let a till open outside trading hours…* (Branch Manager has it).
4. On a till: open a shift, sell, close it. Open it again.

- [ ] The second opening asks for a **manager's username, PIN and reason**, and opens once they are given.
- [ ] **Sales → Till shifts** shows the shift name (*Morning*, *Afternoon* or *Straight day*) and who allowed the second opening.
- [ ] Before 07:00 or after 23:00 the till asks for a manager to open (closing and counting are always allowed).

**Why:** every session belongs to the right shift for the reports, and nobody reopens a counted till without a manager knowing.

## 5. Sign-in for each person

On each user (People & access → Users), choose **How the person signs in**:

| Who | Suggested |
|---|---|
| Owner, managers, accountant | Password and the authenticator app |
| Staff without a smartphone app | Password and a code by WhatsApp or e-mail |
| Tellers and storekeepers who only look things up in the back office | Password only (allowed up to supervisors) |

- [ ] One person with *code by WhatsApp or e-mail* signs in and receives the code.

## 6. Finding things and asking for access

- [ ] **Search the menu** (top of the side menu, or press `/`): type *expiry*, *momo* or *po* and the right page shows.
- [ ] A teller who opens a manager's page sees **why** and an **Ask for access** button; the owner gets the request on the bell.

## 7. Batches and expiry

- [ ] **Stock → Batches & expiry → All batches on hand → Correct expiry** on one batch changes its date (with a reason).
- [ ] **Add or move a batch** makes a draft stock adjustment to check and post.

## 8. Refill reminders (pharmacy)

1. On two or three medicines taken every day (blood pressure, diabetes), fill in **Used a day (refill reminders)**: *1* for one tablet a day, *2* for twice a day.
2. **System → Settings → Refill reminders:** switch on; keep *3 days before* and *09:00*. An SMS or WhatsApp provider must be set up (System → Messaging).
3. Make a test customer with your own phone number and tick **SMS** (or WhatsApp) consent. On the till choose that customer (F4) and sell one of the medicines.

- [ ] Within 15 minutes **Pharmacy → Refill reminders** shows the reminder with the date it runs out.
- [ ] **Send now** sends the message to your phone; it names the medicine and the branch's phone number.
- [ ] Customers without consent show as *No phone or consent*: ask them at the next visit.

**Why:** patients on long-term medicine come back before they run out.

## 9. Mobile money payment requests

Only with a Paystack or Hubtel merchant account.

1. **System → Mobile money → Payment provider:** choose the provider, paste the keys, tick *Send payment requests*; copy the callback address into the provider's dashboard (Paystack).
2. **System → Settings → Mobile money:** *Payment request; typing the ID still allowed*.
3. On the till (sign in again): sell a small item → Pay → **Mobile money** → network → your own number → **Send payment request**. Approve on your phone with your PIN.

- [ ] The till shows *Waiting for the customer…*, then completes the sale by itself.
- [ ] **Sales → the sale** shows the MoMo payment as *verified* with the provider's transaction ID.
- [ ] **System → Mobile money** lists the request as *Paid*.

When staff are used to it, switch to **Payment request only** so a made-up transaction ID can no longer be typed (still possible when the till is offline).

## 10. GRA E-VAT (VAT-registered companies)

Only after GRA has given the company reference and security key (manual 11.5).

- [ ] **System → Health** shows *GRA E-VAT: GRA's VSDC answers*.
- [ ] A test sale's receipt prints the GRA block (SDC ID, signature, MRC) and the QR code; **Finance → GRA E-VAT** shows it *Accepted by GRA*.

## After the session

- [ ] Tell the staff what changed: dosage labels, shifts, sign-in, refill reminders, mobile money requests.
- [ ] Note anything that did not work as expected and send it to SyncLytics support (**Report a problem**, with a picture of the screen).
