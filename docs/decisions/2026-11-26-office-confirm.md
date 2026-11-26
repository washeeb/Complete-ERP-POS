## "Confirm it's you" in the new back office
- **A passkey confirms without signing in again:** the current page ran a full passkey sign-in and came back; here the passkey's answer is checked against a challenge made for confirming (user verification required, used once), and it must be one of the signed-in person's own passkeys. A sign-in is not repeated, so the session and the passkey-verified flag stay as they were.
- **Same rules as the current page:** the code from the app; a sent code only after one was sent; the password only for someone with neither an app nor a passkey; five tries a minute; it lasts five minutes and is audited.
- **The dialog keeps its props:** the seven pages that ask for it are unchanged; its button turns on once confirmed, so the step is not tried again before.
- **The classic page stays** for the classic look and its "Confirm it's you" note; the server's answer still carries its address, which the new look now opens as the new page.
