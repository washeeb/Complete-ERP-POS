# Pharmacy

Switch the module on per branch: **Company → Modules → Pharmacy**. The licence must include it.

## Medicines (Catalog → Products → Medicine)

- **Schedule:** Over the counter, Prescription only (POM) or Controlled drug. Leave empty for anything that is not a medicine.
- **Generic name, strength, form, FDA registration number.**
- **Active ingredients:** used for allergy and interaction warnings. Type each one and press Enter.
- Every medicine must have **Batches and expiry dates** switched on. It is received with its batch and expiry, sold earliest expiry first, and expired stock cannot be sold.

## Patients (Sales → Customers → Patient details)

A patient is a customer with:

- allergies (ingredient names);
- conditions;
- date of birth and sex;
- NHIS membership number and expiry date.

Only pharmacy staff can see or change these. Only their tills receive allergies and NHIS numbers.

## Selling a prescription-only or controlled medicine

When the cart holds one and you press **Pay**, the till asks for:

1. **The patient.** Choose the customer.
2. **The prescription:**
   - prescriber's name (required);
   - Medical & Dental Council registration number;
   - hospital or clinic;
   - prescription number;
   - date written (required; not in the future).
3. **Warnings.** The till shows the patient's allergies to an ingredient and any interactions between the medicines in the cart, from Pharmacy → Drug interactions. A major warning changes the button to "Dispense despite warnings". The pharmacist decides.
4. **Generic substitutes.** For each medicine, **Swap for generic…** lists products with the same generic name and strength.
5. **The pharmacist's approval:** their username and PIN. Leave both empty if a pharmacist is signed in on the till.

Each such sale creates a **prescription** with an RX number, linked to the sale and its medicine lines. It records the warnings the pharmacist saw.

**Offline:** the pharmacist's PIN is checked against their offline sign-in on the till. The sale is never refused once the goods have gone. If there was no pharmacist, patient or prescription, it is flagged under **Reviews** (`pharmacy_unapproved`).

Over-the-counter medicines need none of this. Neither does a branch where the Pharmacy module is off.

## Pharmacy menu

- **Prescriptions:** every prescription dispensed, with the patient, prescriber, pharmacist, medicines and warnings. Prescriptions are never edited.
- **Drug interactions:** the local table of ingredient pairs, each with a severity and what to do.
  - The superintendent pharmacist maintains it.
  - A rule that no longer applies is set to minor rather than deleted, so past warnings stay explainable.
- **Controlled-drug register:**
  - every movement of controlled drugs in a warehouse over a period, with a running balance;
  - for sales, the patient, prescriber, RX number and pharmacist;
  - read straight from the stock ledger, so it always agrees with the stock;
  - **Print register** gives an A4 copy with the premises licence and superintendent pharmacist from Settings → Pharmacy;
  - **NHIS claims (CSV)** lists the prescription lines dispensed to NHIS members over the period, ready for the claims return.

## Roles

| Role | Can |
|---|---|
| **Pharmacist** | Dispense and approve prescription medicines (`pharmacy.dispense`); patient details; interaction table, register and NHIS claims (`pharmacy.manage`); till; receiving and counts |
| **Pharmacy Technician** | Till; patient details; receiving and counts. A pharmacist approves their prescription sales |

The General Manager also has `pharmacy.manage` and `pharmacy.patients`. A Branch Manager has `pharmacy.patients`.
