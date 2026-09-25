# BIVAS-1

Visitor and access control for gated estates. Residents invite guests and get a one-time gate code; security verifies the code at the gate and logs every entry and exit.

**Stack:** PHP 8.1+ · MySQL 8 / MariaDB 10.5+ · runs on standard cPanel shared hosting (no Node, no Composer required)

## How it works

1. **Resident** logs in and creates an invite → gets a 6-digit code to share with the visitor
2. **Guard** logs in (phone + PIN) and verifies the code → sees visitor, host and destination unit
3. Guard **admits or denies** → entry is logged; an admitted code is burned (single use)
4. Guard **checks the visitor out** on exit; `inside.php` shows who is currently in the estate

## Security design

- **Access codes are never stored.** Only an HMAC-SHA256 (keyed with a server-side secret) is saved, so a database leak doesn't expose working gate codes.
- **Codes are unique per estate while active** and recycled after use, cancellation or expiry.
- **Brute-force protection:** 5 wrong codes per guard in 10 minutes triggers a lockout; every attempt is logged.
- **Estate isolation:** guards only see and act on their own estate; residents can only invite to their own unit (derived server-side, never from the request).
- **Race-safe check-in:** row locking prevents two guards admitting the same code simultaneously.
- **Passwords/PINs** hashed with `password_hash()`; API tokens stored as SHA-256; login doesn't reveal which phone numbers are registered.
- Visitor ID numbers (NIN etc.) are personal data under the Nigeria Data Protection Act 2023. Encryption at rest is on the roadmap.

## API

| Endpoint | Who | Purpose |
|---|---|---|
| `POST auth/login.php` | anyone | `{as, phone, password \| pin}` → bearer token |
| `POST auth/logout.php` | any | Revoke current token |
| `POST visits/create.php` | resident | Create invite, returns code once |
| `GET visits/mine.php` | resident | Recent invites |
| `POST visits/cancel.php` | resident | Cancel invite, kills code |
| `POST gate/verify.php` | guard | Check a code |
| `POST gate/check-in.php` | guard | Admit / deny, logs entry |
| `POST gate/check-out.php` | guard | Log exit |
| `GET gate/inside.php` | guard | Visitors currently inside |

Send the token as `Authorization: Bearer <token>`. Times are ISO-8601; stored in UTC.

## Setup

```bash
mysql -u root -p bivas < database/migrations/001_schema.sql
mysql -u root -p bivas < database/migrations/002_auth_and_codes.sql
mysql -u root -p bivas < database/seed-demo.sql          # optional demo data
cp api/config.example.php api/config.php                 # fill in DB creds + code_pepper
php tools/set-credential.php resident 08031111111 'Demo-pass1'
php tools/set-credential.php staff    08032222222 123456
php -S 127.0.0.1:8080                                    # local dev
```

**Deploying to cPanel:** upload `api/` into `public_html/`, keep `tools/` and `database/` **outside** `public_html`, and add a cron job every 15 minutes: `php /home/USER/bivas/tools/expire-visits.php`.

## Roadmap

- [x] Schema + ER diagram
- [x] Auth, invites, gate verify / check-in / check-out (tested end to end)
- [ ] Guard gate screen (mobile web)
- [ ] Resident invite screen + WhatsApp share
- [ ] Admin dashboard (`bivas-1-admin-dashboard`): estates, units, residents, staff, entry logs
- [ ] Walk-in visitors, QR codes, recurring invites (cleaners, drivers)
- [ ] Encrypt visitor ID numbers at rest

See `database/er-diagram.md` for the data model.
