## Installer: carry on when only PHP's source is mid-update (branch `installer-php-tolerant`, base `main`)
- `install.sh`: `only_php_source_failed` (every `E:` line from `apt-get update`, apart from "Some index files failed", names packages.sury.org); `apt_update` retries four times, then carries on when that is true and `php${PHP_V}-fpm` is installed, otherwise stops.
- `installer/ubuntu/test-apt-update.sh` checks the decision against the apt output from a client update (and three cases that must stop or need nothing); CI runs it, and runs an update with packages.sury.org unreachable.
