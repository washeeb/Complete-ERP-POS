#!/usr/bin/env bash
# Checks install.sh's decision to carry on when only PHP's package source (packages.sury.org) fails, against what apt
# really printed (a client update, 2026-11-21). Runs anywhere with bash: `bash installer/ubuntu/test-apt-update.sh`.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
eval "$(sed -n '/^only_php_source_failed() {/,/^}/p' "$here/install.sh")"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
fail=0
check() { # $1: expected (yes|no), $2: name, $3: apt output
  printf '%s\n' "$3" > "$tmp/log"
  if only_php_source_failed "$tmp/log"; then got=yes; else got=no; fi
  if [ "$got" = "$1" ]; then echo "ok   $2"; else echo "FAIL $2 (expected $1, got $got)"; fail=1; fi
}

check yes "PHP's source mid-update (size mismatch)" "Get:8 https://packages.sury.org/php noble/main amd64 Packages [144 kB]
Err:8 https://packages.sury.org/php noble/main amd64 Packages
  File has unexpected size (143708 != 143716). Mirror sync in progress? [IP: 146.75.119.52 443]
Reading package lists...
E: Failed to fetch https://packages.sury.org/php/dists/noble/main/binary-amd64/Packages.xz  File has unexpected size (143708 != 143716). Mirror sync in progress? [IP: 146.75.119.52 443]
   Hashes of expected file:
    - Filesize:143716 [weak]
   Release file created at: Sat, 21 Nov 2026 05:16:07 +0000
E: Some index files failed to download. They have been ignored, or old ones used instead."

check no "another source failed too" "E: Failed to fetch https://packages.sury.org/php/dists/noble/main/binary-amd64/Packages.xz  File has unexpected size (143708 != 143716). Mirror sync in progress?
E: Failed to fetch https://apt.postgresql.org/pub/repos/apt/dists/noble-pgdg/main/binary-amd64/Packages.bz2  File has unexpected size (1 != 2). Mirror sync in progress?
E: Some index files failed to download. They have been ignored, or old ones used instead."

check no "the frozen Launchpad PPA's renamed label" "Reading package lists...
E: Repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease' changed its 'Label' value from 'PPA for PHP' to 'PPA for PHP (deprecated)'"

check no "no errors at all" "Hit:1 http://archive.ubuntu.com/ubuntu noble InRelease
Reading package lists..."

exit "$fail"
