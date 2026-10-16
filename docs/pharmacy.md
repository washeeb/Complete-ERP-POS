# Pharmacy

Switch the module on per branch: **Company → Modules → Pharmacy**. The licence must include it.

## Medicines (Catalog → Products → Medicine)

- **Schedule:** Over the counter, Prescription only (POM) or Controlled drug. Leave empty for anything that is not a medicine.
- **Generic name, strength, form, FDA registration number.**
- **Active ingredients:** used for allergy and interaction warnings. Type each one and press Enter.
- **Units:** switching Pharmacy on adds the units medicines are sold in: Tablet (`TAB`), Strip (`STRIP`), Pack (`PK`), Ampoule (`AMP`), Vial (`VIAL`), Tube (`TUBE`), Bottle (`BTL`), Pessary (`PESS`), Suppository (`SUPP`) and Container (`CONT`). Count stock in the smallest unit you sell (e.g. Tablet) and add the bigger ones as packs of it (a Strip of 10, a Pack of 100), each with its own barcode and price. Rename or add units under Catalog → Units of measure.
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
| **Superintendent Pharmacist** (L4) | Everything a pharmacist can, plus the pharmacy's catalogue, purchasing, stock adjustments, approving receipts, counts and stock requests, and insights |
| **Medicine Counter Assistant (MCA)** (L2) | Till; patient details. Over-the-counter medicines; a pharmacist approves prescription sales |
| **Doctor / Medical Officer**, **Physician Assistant** (L3) | Patient records (allergies, history, NHIS); add and update patients. They do not dispense |
| **Nurse**, **Midwife** (L2) | Till; patient records. They do not dispense |
| **Laboratory Technician** (L2) | Till (tests and test kits); patient records |

L2 to L4 are security levels (staff, supervisor, manager): a role never applies to someone below its level. Only pharmacists (`pharmacy.dispense`) approve prescription-only and controlled medicines. These roles are offered only where the Pharmacy module runs.

The General Manager also has `pharmacy.manage` and `pharmacy.patients`. A Branch Manager has `pharmacy.patients`.
