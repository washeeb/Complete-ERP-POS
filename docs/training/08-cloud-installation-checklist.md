# Cloud installation checklist (SyncLytics staff)

For installing a client's server on a rented cloud server. Tick each line. The full story with reasons is in the installation guide, Chapter 19 (case study) and Chapter 5 (details).

## Before

- [ ] The client signed, and the first payment is received.
- [ ] One server for this client (the standard), or a shared server for a small client starting out (Chapter 5.0).
- [ ] The client's internet name chosen, for example `erp.clientdomain.com`, and access to its DNS (Namecheap or other).
- [ ] The client's release package built and checked (`release:build --customer=… --with-pos`): signature valid, protected files unchanged.

## Rent the server (Contabo)

- [ ] Cloud VPS 6 (or larger for many branches; see the sizing table in Chapter 3).
- [ ] Storage: **100 GB NVMe**, not the default SSD.
- [ ] Region: European Union.
- [ ] Image: Ubuntu **24.04**.
- [ ] Auto Backup: **on** (the default is off).
- [ ] Password: 20+ characters, saved in the password manager. Never sent by chat or e-mail.
- [ ] IP address received by e-mail.

## Internet name

- [ ] DNS **A record**: host `erp`, value = the IP address.
- [ ] `nslookup erp.clientdomain.com 8.8.8.8` answers with the IP address.

## Install

- [ ] `scp` the release ZIP and `install.sh` to `/root/` (first time: type `yes`; paste the password with a right-click). Both files reach 100%.
- [ ] `ssh root@<IP>`. Do **not** run `do-release-upgrade`.
- [ ] `timedatectl set-timezone Africa/Accra`
- [ ] `sudo bash /root/install.sh --zip /root/<release>.zip --domain <name> --email info@synclyticsenterprisesystems.com`
- [ ] **Backup passphrase** written down and stored with the key backup.
- [ ] Company and owner account created.

## Licence and checks

- [ ] `https://<name>/admin` opens with a padlock; owner signs in.
- [ ] Installation code (`SYNREQ-…`) sent to SyncLytics; licence key issued for what was bought; activated.
- [ ] Set-up wizard, last page: the demo branches replaced by the client's real shops (one head office; one industry module each; tills within the licence).
- [ ] One registered company? *System → Settings → Finance → Separate books for each industry* switched **off** before the first posting.
- [ ] System → Health all OK, including *PHP code cache: On*.
- [ ] Second backup destination outside the provider added.
- [ ] `ufw status`: only 22, 80, 443.
- [ ] Remote login moved to an SSH key.
- [ ] Tills paired and a test sale made in the practice company.

## If something goes wrong

| What you see | Do this |
|---|---|
| The script ends at step 9 with no message (releases before 14 October 2026) | Finish by hand: the four commands in the installation guide, Chapter 19.6. Newer releases do not stop there. |
| *Connection closed* after the password | Try again; paste with a right-click. Still failing: sign in through Contabo's VNC console to check the password, or reset it in the panel. |
| The name does not answer | Check the A record and that the nameservers are the registrar's own; wait 5–30 minutes. |
| The script stops with **Stopped:** | Copy the message and the lines above it (never passwords) to the SyncLytics developer. Running the script again is safe. |
| No padlock / certificate error | The name did not point to the server during the install: fix DNS, then run the script again. |
