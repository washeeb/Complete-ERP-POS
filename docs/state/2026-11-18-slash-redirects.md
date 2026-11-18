## /office and /pos redirect loop fixed (branch `fix-slash-redirects`)
- `routes/web.php`: `/office` and `/pos` redirect with `redirect()->away(<full address>/office/)`; `OfficeController::signIn` likewise for a signed-in person.
- `SlashRedirectTest` (2).
