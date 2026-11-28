## Till new look, part 5a: sign-in (branch `till-new-look-5a`, base `till-new-look-4`)
- **`screens/SignInSide.tsx`** wraps `Login` (App.tsx): reads the cached `bootstrap` (company, branch, till, brand, timezone) and the cached logo (`cachedImage`, dark logo first); a till that never started shows the card alone. Region (`aside`) named after the company.
- **CSS:** `.signin-layout` (two columns, one on a narrow screen), `.signin-brand` on `--colorBrandBackground` with `--colorNeutralForegroundOnBrand` text, all at full strength (dimmed text failed axe).
- **Tests:** the daily-screens a11y test signs out and checks the sign-in with the company side, light and dark.
- Till settings, open shift and the lock screen keep their layout (already clear); they have the new colours since part 1.
- **Next:** part 5b, the industry screens (restaurant, pharmacy, fashion, electronics, services, van, handheld).
