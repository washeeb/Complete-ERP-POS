## Installer: PHP from packages.sury.org (branch `installer-php-source`, base `main`)
- `installer/ubuntu/install.sh` step 1 removes any `ondrej/php` source (files in `/etc/apt/sources.list.d/`, lines in `sources.list`) before the first `apt-get update`, installs `debsuryorg-archive-keyring.deb` and writes `/etc/apt/sources.list.d/php.list` (`https://packages.sury.org/php/ ${VERSION_CODENAME} main`). `software-properties-common` is no longer needed.
- CI (`packaging.yml`, ubuntu-script) adds the old PPA first, as an older server has it, and checks it is gone and `php.list` points to sury.
- Manual chapter 5.4 uses the new source.
