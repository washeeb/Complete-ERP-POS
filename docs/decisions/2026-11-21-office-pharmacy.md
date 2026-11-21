## Pharmacy records in the new back office
- **Split in two parts:** the records (prescriptions, refill reminders, interactions, controlled-drug register) first; the claim and compliance screens (NHIS claims, sign-offs, temperature log, markdowns, expiry returns) next, so each PR stays reviewable.
- **A prescription opens in a dialog, not a page:** it is read only and short; the address keeps `?open=id` so a link from the classic screen or global search lands on it.
- **Refill reminder Send now and Cancel check `customers.manage` in the controller,** because `RefillReminders::sendNow` itself does not (the classic screen hid the buttons); a nurse (customers.view only) still sees the list, as before.
- **Print register and NHIS claims stay the existing print routes;** the page links to them with the chosen warehouse and period.
