# Deploying BIVAS-1 to cPanel shared hosting (shared database)

A step-by-step for hosting BIVAS in a sub-folder (for example `public_html/bivas/`) of a site that already uses the one MySQL database on the plan. It was written from the first real deployment. If you have your own database, the README's Setup and Deploying sections are all you need.

Placeholders: `USER` is your cPanel username, `DB` is your database name, `APPUSER` is the restricted database user BIVAS will connect as.

## 0. Before you touch anything

1. cPanel → **MultiPHP Manager** → the domain must run **PHP 8.1 or newer**.
2. phpMyAdmin → your database → **Export** → Quick → Go. The database also holds the other app's data, so take a backup before adding tables.

## 1. Database (phpMyAdmin, as the main cPanel user)

1. **SQL tab** → paste `database/deploy/shared-db-preflight.sql` → Go. It must return **zero rows**. If it returns any, stop: either an earlier partial install or another app uses that name.
2. **Import** `database/deploy/shared-db-schema.sql`. It reports 22 queries and creates 13 tables.
3. *(Optional)* **Import** `database/seed-demo.sql`. It creates a demo estate (estate 1), two units, one fake resident and one fake guard, **with no passwords set**.

Why not the plain migrations: `001_schema.sql` creates `vehicles` and `incident_reports`, which no code uses yet but which can collide with the other app's tables. The deploy schema creates them as `bivas_vehicles` and `bivas_incident_reports`. It is otherwise migrations 001, 002 and 003 combined.

The restricted user can't run the import because it has no `CREATE` or `ALTER`. That is why this step uses phpMyAdmin's main login. Create `APPUSER` in cPanel → MySQL Databases and give it only **SELECT, INSERT, UPDATE and DELETE** on the database.

## 2. Upload

Zip `admin/`, `gate/`, `resident/`, `api/`, `tools/`, `index.html` and `.htaccess` together inside one top-level `bivas/` folder. Do **not** include `database/`, `deploy/` or `README.md`, and never include an `api/config.php`.

1. File Manager → `public_html` → **Upload** the zip → right-click → **Extract** → delete the zip.
2. Turn on **Show hidden files** in Settings so you can see the `.htaccess` files.
3. Check `public_html/bivas/` contains `admin`, `api`, `gate`, `resident`, `tools`, `index.html` and `.htaccess`.

## 3. config.php

1. On your own computer, generate the code pepper: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
2. File Manager → `public_html/bivas/api/` → **Copy** `config.example.php` to `config.php`. Make sure the editor's tab title says `config.php`, not `config.example.php`, before you type anything.
3. Fill in `db_host` (`localhost`), `db_name` (`DB`), `db_user` (`APPUSER`), `db_pass` and `code_pepper`. Set the file's permissions to **640**.

Never paste the pepper or the password into chat, tickets or Git. Changing the pepper later invalidates every active gate code.

## 4. First estate admin

Without a terminal, on your own computer:

```
php tools/make-admin-sql.php 1 08031234567 "Your Name"
```

Type the password at the hidden prompt (10+ characters). It prints one `INSERT`; paste it into phpMyAdmin's SQL tab and run it. With a terminal on the server, `php tools/create-admin.php ...` does the same. After that, add residents and guards from the admin dashboard; it generates temporary passwords and PINs and shows each one once.

## 5. Cron (expires old invites)

cPanel → **Cron Jobs** → every 15 minutes:

```
php /home/USER/public_html/bivas/tools/expire-visits.php
```

Use the PHP path cPanel suggests, and make sure that CLI is 8.1 or newer.

## 6. Smoke test

| Open | Expect |
|---|---|
| `/bivas/` | The BIVAS landing page |
| `/bivas/api/auth/me.php` | A small JSON error saying you're not signed in (not a blank page or a 500) |
| `/bivas/api/config.php` | **403 Forbidden** |
| `/bivas/tools/expire-visits.php` | **403 Forbidden** |
| The host site's home page and its own API | Unchanged |

Then the full loop: sign in at `/bivas/admin/`, add a resident (copy the temporary password), sign in at `/bivas/resident/` on a phone, create an invite, sign in at `/bivas/gate/` as the guard, verify the code and let the visitor in, and confirm it appears in the admin overview.

If the landing page loads but `/bivas/api/` pages show the host site's home page, the host's root `.htaccess` is rewriting requests. Check its rewrite conditions let existing files and folders through.

## 7. While it lives on someone else's domain

- Use demo people only. Real visitor names and phone numbers are personal data under the Nigeria Data Protection Act 2023.
- Every page is `noindex`, so none of it shows up in search results.

## Removing it later

Delete `public_html/bivas`, then in phpMyAdmin:

```sql
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS admin_audit_log, estate_admins, verify_attempts, api_tokens,
  bivas_incident_reports, bivas_vehicles, gate_entry_logs, visit_requests,
  visitors, security_staff, residents, units, estates;
SET FOREIGN_KEY_CHECKS = 1;
```

Then remove `APPUSER`. Export first if you want to keep the data. None of those names touch the other app's tables.
