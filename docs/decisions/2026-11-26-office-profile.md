## My profile in the new back office
- **Two-factor and passkeys through the same providers:** Filament's `AppAuthentication` (secret, QR code, code window, hashed recovery codes, one-use codes) and spatie's passkey actions, so the sign-in side and the audit trail stay exactly as they were.
- **Recovery codes are shown only after the code and password are confirmed** (the current page reveals them one step earlier, inside the sealed set-up); they are shown once, with Copy and Download.
- **The set-up is sealed for the person and 15 minutes:** a set-up started by one person, or an old one, cannot be finished.
- **Found and fixed: the new back office did not ask for two-factor set-up.** The "needs two-factor" rule ran only on the classic pages; now `/office` and its API run the same rule. Without it, turning two-factor off on the new page would have left someone who needs it using the back office without it.
- **Both looks on one page:** the new back office's menu (across the top or the side) and the classic back office's look are both personal choices, so both are on the profile.
- **Sign-in pages stay as they are:** signing in and the two-factor challenge and set-up come before the back office opens. "Confirm it's you" is rebuilt next (part 40, owner 2026-11-26).
- **Found and fixed: Recent listed only pages still on the current look,** so with every page rebuilt it would have stayed empty; every page with a title is now recorded.
