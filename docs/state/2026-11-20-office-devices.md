## New back office, settings and admin part 6: devices and computers (branch `office-devices`, stacked on `office-messaging`)
- **Server:** `App\Domain\Office\DevicesScreen` (devices through `DeviceResource::getEloquentQuery`, options for Register, fixes history, computers) and `Office\DevicesController` (register, new code, revoke, fix, archive/restore, name a computer) through `DeviceService`, `DeviceHealth`, `ArchiveService` and `Recorder`. The enrolment code is only in the answer to Register or New code.
- **Routes:** `office-api/devices`, `devices/options`, POST `devices`, POST `devices/{id}/new-code|revoke|fix|archive|restore`, `devices/{id}/fixes`, `computers`, POST `computers/{id}/name`.
- **Client:** `pages/DevicesPage.tsx` (also `?register=1`), `pages/ComputersPage.tsx`. `/admin/devices`, `/admin/devices/create` and `/admin/computers` map to the new pages. "What it did" opens the audit log (classic, in the frame).
- **Tests:** `OfficeDevicesTest`. office-check opens Devices (and the Register dialog, left) and Computers.
- **Next:** catalog and pricing setup.
