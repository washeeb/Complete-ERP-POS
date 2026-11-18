## Installer keeps the domain on updates (branch `installer-keep-domain`)
- `installer/ubuntu/install.sh`: without `--domain` (and not `--ci`), an update reads the host of `APP_URL` in the existing `server/.env`; a name (not an IP, not localhost) becomes `DOMAIN`, and `EMAIL` comes from the `email` line of `/etc/caddy/Caddyfile` unless given. New flag `--no-domain` skips this.
- Manual chapter 12 (updates) says so.
