## New back office, part 12: NHIS claims and sales to sign off (branch `office-pharmacy-claims`, base `main`)
- **Server:** `App\Domain\Office\NhisClaimsScreen` (claims list, unclaimed preview, claim with paged sales, sign-off list from `PharmacyRules::unapproved`) and `Office\PharmacyClaimsController` (prepare, submit, settle, withdraw through `NhisClaims`; sign through `PharmacySignoffs`; the sign-off CSV). Who may see and do what is the classic screens' (`NhisClaimResource`, `MedicineSignoffs`).
- **Routes:** `office-api/nhis-claims`, `nhis-claims/unclaimed`, POST `nhis-claims`, `nhis-claims/{id}`, POST `nhis-claims/{id}/{submit|settle|withdraw}`, `medicine-signoffs`, `medicine-signoffs/csv`, POST `medicine-signoffs`.
- **Fix:** `Sale::customer()` added; the classic claim page's sales list (`LinesRelationManager`) eager-loads `sale.customer` and failed for any claim with sales.
- **Client:** `pages/NhisClaimsPage.tsx` (with Prepare a claim), `NhisClaimPage.tsx`, `SignoffsPage.tsx`; `/admin/nhis-claims`, `/admin/nhis-claims/{uuid}`, `/admin/medicine-signoffs` map to them.
- **Tests:** `OfficePharmacyClaimsTest` (including the classic claim page's sales). office-check opens both from Industries → Pharmacy.
- **Next:** part 13: near-expiry markdowns (grocery and pharmacy together) and expiry returns. The temperature log goes with the Sensors screens.
