# Security

How access works today (Phase 1). Each later phase adds its own permissions to the same model.

## Who can do what

**Allowed** only when *all* of these hold — otherwise denied:

1. the account is active;
2. the person holds a role assignment that is not revoked, has started and has not expired;
3. the role's level is at or below the person's **security level** (their ceiling);
4. the role contains the permission, and the permission's minimum level is at or below the person's level;
5. the assignment's **scope** covers the thing being acted on;
6. for amounts (discounts, refunds…): the amount is within the assignment's **limit**.

A missing scope is never treated as "everywhere". A company-wide grant is needed for company-wide actions.

### Security levels (ceiling)

| Level | Meaning |
|---|---|
| L7 | Platform operator (SaaS) — no business data by default; cannot be granted in a tenant |
| L6 | Account owner / director |
| L5 | Executive / finance controller |
| L4 | Branch or department manager, IT Admin |
| L3 | Supervisor |
| L2 | Staff / cashier / storekeeper |
| L1 | Restricted / auditor (read-only) |
| L0 | External portal users (no back office) |

### Scopes

Company → legal entity → branch → warehouse → till. A grant at a level covers everything inside it
(a branch grant covers that branch's warehouses and tills); a till grant covers only that till.
Department and cost-centre scopes arrive with HR and Finance (Phases 7–8).

### Rules that prevent privilege escalation (enforced in `AccessService`, tested)

- Nobody can grant access to themselves, change their own level, or deactivate themselves.
- Nobody can create, edit or grant anything above their own level.
- A granter must already hold **every** permission of the role, over the scope being granted.
- A role editor can only add permissions they hold company-wide.
- **IT Admin is technical only**: technical roles can hold only technical permissions
  (users, roles, devices, modules, settings, audit verification) and therefore can never hand out
  business access such as till sign-in or company setup.
- Temporary access needs an expiry and a reason; it stops working at the expiry automatically.
- Revoking access needs a reason. Deactivating a user signs them out everywhere, including tills.

### Seeded roles

Owner (L6, everything) · General Manager (L5) · Finance Manager (L5) · Accountant (L4) · Auditor (L1)
· HR Manager (L4) · Payroll Officer (L3) · Purchasing Officer (L2) · Purchase Approver (L4) ·
Warehouse Manager (L4) · Storekeeper (L2) · Picker/Packer (L2) · Branch Manager (L4) · Supervisor (L3)
· Cashier (L2) · Sales Rep (L2) · Van Sales Rep (L2) · Customer Service (L2) · Marketing (L3) ·
E-commerce Manager (L4) · Delivery Rider (L2) · IT Admin (L4, technical).

Roles for departments not built yet start with no permissions and gain them in their phase.
Re-running the seeder adds **new** permissions to their default roles once; a permission an
administrator removed from a role is never put back.

### Phase 1 permissions

| Permission | Min level | Technical | Default roles |
|---|---|---|---|
| `setup.company.manage` | L5 | | Owner, General Manager |
| `audit.view` | L1 | | Owner, GM, Finance Manager, Auditor, Branch Manager |
| `pos.access` | L2 | | Owner, GM, Branch Manager, Supervisor, Cashier |
| `admin.users.view` | L3 | ✓ | Owner, IT Admin |
| `admin.users.manage` | L4 | ✓ | Owner, IT Admin |
| `admin.roles.manage` | L4 | ✓ | Owner, IT Admin |
| `admin.devices.manage` | L4 | ✓ | Owner, IT Admin |
| `admin.modules.manage` | L4 | ✓ | Owner, IT Admin |
| `admin.settings.manage` | L4 | ✓ | Owner, IT Admin |
| `audit.verify` | L4 | ✓ | Owner, IT Admin |

## Signing in

- **Back office:** email + password, then two-factor. Everyone at or above *Settings → Require
  two-factor sign-in from security level* (default **L3**) must use either an authenticator app
  (with one-time recovery codes) or a **passkey**. Passkeys require the device to verify the person
  (PIN, fingerprint, face). Owning a passkey does not exempt a password sign-in — the person must
  sign in *with* the passkey, or have the authenticator app.
- Passkeys are bound to the site's host name (`APP_URL`). Set the real domain before staff enrol.
- **Tills:** username + PIN, only on an **enrolled device**, only for people with `pos.access`
  over that till. The device is the second factor. PINs lock after *N* wrong attempts (Settings).
  Trivial PINs (1234, 0000…) are refused.

## Devices

1. An admin registers the device (branch, till, kind) and receives a **one-time enrolment code**,
   shown once, valid for the configured hours.
2. The device enrols with the code and its public key and receives a device token.
3. **Revoke** (lost, stolen, replaced) immediately ends the device's token and every staff session
   opened on it.

## Audit log

- Every change made through the system writes an audit row in the **same database transaction**
  as the change: who (name snapshot), source (back office, POS, API, command line), what changed
  (before → after), reason, device, node, time. Secrets (passwords, PINs, 2FA secrets, keys) are
  recorded as "changed", never their value.
- Rows are chained: each row's hash is an HMAC over the previous hash and the row, keyed by
  `ERP_AUDIT_KEY`. The database refuses updates, deletes and truncation of audit rows.
- **Verify:** *System → Audit log → Verify chain*, or `php artisan erp:audit-verify`.
- **Back up `ERP_AUDIT_KEY`** with your other secrets. Never change it: existing rows would fail
  verification.

### Known limits (closed in Phase 13)

- The application's database role owns the tables, so someone with that role could disable the
  trigger; verification would still detect edits unless they also hold the audit key. Production
  will use a separate migration role and the runtime role will not own tables.
- Full-rewrite protection (someone with database access *and* the key) needs external checkpoints
  (signed chain heads stored off the server).

## If verification fails (incident response)

1. Do not "fix" the data. Take a database backup immediately and keep it.
2. Note the first bad row number and the problem shown.
3. Restrict access: revoke sessions of database/IT administrators until reviewed.
4. Compare with the most recent good backup to find what changed.
5. Record the incident, findings and actions in writing; inform the owner.
