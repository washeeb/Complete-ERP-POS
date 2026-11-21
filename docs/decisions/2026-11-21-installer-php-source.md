## PHP package source moved to packages.sury.org
- **Found:** a client server re-install stopped at step 1: apt refused `ppa:ondrej/php` because its Label changed to "PPA for PHP (deprecated)". The Launchpad page says the PPA is frozen and Ubuntu PHP packages are now published at packages.sury.org/php.
- **Decided:** use packages.sury.org with its keyring package, as its README says, rather than `--allow-releaseinfo-change` (that only silences apt and leaves the server on a source that gets no PHP security updates).
- **Existing servers:** the old PPA entry is removed before apt reads it, so a re-run of the installer is the whole fix; the same package names come from the new source.
