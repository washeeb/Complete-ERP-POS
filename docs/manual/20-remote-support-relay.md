# 20. Remote support relay on any VPS

The **remote support relay** lets SyncLytics support, and a client's own IT with the *Remote access* add-on, see or control a till, a back-office computer or a handheld from anywhere. Each device connects **out** to the relay, so nothing is opened on the shop's network or router. The relay is **MeshCentral** (free, open source); one relay serves every client, each in its own device group.

**Why a separate server:** the relay carries live screens for many clients. Keeping it apart from any client's business server means a client's server never holds another client's connections, and the relay can be updated or moved without touching a business server.

## 20.1 What you need

| Item | Recommended | Why |
|---|---|---|
| A VPS | 2 cores, 2–4 GB memory, 40 GB disk, from any provider (Contabo, Hetzner, DigitalOcean, Vultr, AWS Lightsail…) or a server in your office with a public address | The relay passes screens through; it needs little power. Recordings take the disk space. |
| System | **Ubuntu 24.04** or 22.04, or Debian 12 | What the install script is built and tested for. |
| An internet name | e.g. `remote.yourdomain.com` | The free HTTPS certificate is issued for a name, not an IP address. |
| Ports | 80 and 443 open to the internet | 443 carries the connections; 80 is used once to issue the certificate and then redirects to 443. |

## 20.2 Point the name at the server

At your domain provider, add an **A record**: name `remote`, value the VPS's public IP address. Wait until `ping remote.yourdomain.com` answers from that address (usually minutes, sometimes an hour). **Why:** the certificate is only issued once the name reaches this server.

## 20.3 Install the relay

From the PC, copy the script up and sign in (the address is an example):

```
scp "E:\…\Complete-ERP-POS\installer\remote-relay\install-relay.sh" root@203.0.113.20:/root/
ssh root@203.0.113.20
```

On the server:

```
sudo bash /root/install-relay.sh --domain remote.yourdomain.com --email you@yourdomain.com
```

It installs Node.js and MeshCentral, writes a safe configuration (nobody can create an account by themselves, two-factor sign-in is required, every session is recorded), starts it as a service that restarts by itself, and opens only ports 22, 80 and 443 in the firewall. At the end it prints the first account (**support**) and a **temporary password — shown once**; write it down.

Running the script again later **updates** the relay and keeps the configuration, accounts, device groups and recordings.

## 20.4 First sign-in

1. Open `https://remote.yourdomain.com` and sign in as **support** with the temporary password.
2. Change the password (12 characters or more) and set up **two-factor sign-in** with an authenticator app. **Why:** whoever signs in here can reach every client's tills.
3. **My Account → Users:** add one account per technician (never share an account), each with two-factor sign-in. Give technicians rights only to the device groups of the clients they look after.

## 20.5 One device group per client

For each client: **My Devices → Add Device Group**, named after the client (e.g. *Asante Pharmacy Ltd*).

- **User consent:** tick *Prompt for user consent* for desktop, terminal and files when the client wants every connection accepted at the device. Leave it off when the client chose unattended connections; the ERP still shows the person at the device what the branch rule is.
- **Session recording** is on for every group (the script set it); recordings stay in `/opt/meshcentral/meshcentral-recordings` on the relay.

Copy the group's **invitation link**: *Add Agent* → *Invite* → choose *link does not expire*, and copy it.

## 20.6 Link the client's ERP to the relay

On the client's **business server**:

1. Add the relay's address to the server's settings file (`.env`) — on Ubuntu `/var/www/<company>/server/.env`, on Windows `server\.env` in the install folder, in Docker the environment of the app container:
   ```
   REMOTE_RELAY_URL=https://remote.yourdomain.com
   ```
   Then run `php artisan config:cache` (Ubuntu and Windows) or restart the container. **Why:** until this is set, the ERP shows no remote buttons, so a client without the relay sees nothing new.
2. In the back office: **System → Settings → Remote access → Remote support agent install link**: paste the group's invitation link.
3. The owner chooses the **Remote connections** rule (Settings → Remote access, or per branch): unattended outside trading hours (the default), always unattended, or always ask.

## 20.7 Install the agent on each device

In the back office, **System → Devices → Install remote support** shows the link and these steps:

- **Windows tills and back-office PCs:** open the link on the computer, download the agent and choose **Install**. It runs in the background and starts with the computer.
- **Android handhelds and tablets:** install **MeshAgent** from Google Play, scan the QR code shown on the link page, and allow screen sharing when asked.
- Open the till once on each device, so the ERP learns its computer name.

The device then appears in the client's group on the relay, and on **System → Devices** with **View screen** and **Take control**.

## 20.8 Connecting

On **System → Devices**, **View screen** or **Take control** opens the device on the relay in a new tab:

- **Who may:** the client's own staff with the *Remote access* add-on and the *see* or *control devices* permission at that branch; **SyncLytics support** only while the owner's **Support access** is in force (System → Support access), and only at its level (see only, or see and control).
- The ERP first shows what the person at the device will see (asked to accept, or a notice), then opens the relay, which asks the technician to sign in.
- **Every connection** is written to the audit log (who, which device, when), and the relay records the session.

## 20.9 Looking after the relay

| Task | How | Why |
|---|---|---|
| Update | Run the install script again | Security fixes; settings and recordings are kept |
| Status | `systemctl status meshcentral` | Should say *active (running)* |
| Back up | Copy `/opt/meshcentral/meshcentral-data` somewhere else weekly | It holds the accounts, groups and the relay's identity; without it every agent must be reinstalled |
| Recordings | Delete recordings older than the agreed period (e.g. 90 days) | They fill the disk; keep them only as long as agreed with the clients |
| A technician leaves | Remove their account on the relay the same day | The relay is the door to every client's devices |

**If a device does not appear:** check the agent is running on it (Windows: *Services → SyncLyticsSupport*), that it can reach `https://remote.yourdomain.com`, and that it was installed from **this client's** link.
