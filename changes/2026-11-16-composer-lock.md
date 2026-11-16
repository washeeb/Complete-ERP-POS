### Fixed
- **"The lock file is not up to date" warning during the server install** (seen while installing on a client server). The library list's checksum was not refreshed after the phone-notification library was added; no library version changed. CI now checks it on every change.
