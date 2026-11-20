## New back office, settings and admin part 5: messaging (branch `office-messaging`, stacked on `office-settings`)
- **Server:** `App\Domain\Office\MessagingScreen` (set-up with each credential only as saved or not, templates, log) and `Office\MessagingController` (save provider, test SMS/WhatsApp/e-mail, save/archive/restore template, Try again) through `ProviderSetup`, `Templates`, `Messenger` and `ArchiveService`.
- **Routes:** `office-api/messaging`, POST `messaging/{sms|whatsapp}`, POST `messaging/{sms|whatsapp|email}/test`, `message-templates`, POST `message-templates/{id}` and `/{archive|restore}`, `message-log`, POST `message-log/{id}/retry`.
- **Client:** `pages/MessagingPage.tsx`, `pages/MessageTemplatesPage.tsx`, `pages/MessageLogPage.tsx`. `/admin/messaging`, `/admin/message-templates` and `/admin/message-log` map to the new pages.
- **Tests:** `OfficeMessagingTest` (also checks no saved credential appears in the answer). office-check opens the three from Setup.
- **Next:** settings and admin part 6: devices; then catalog and pricing setup.
