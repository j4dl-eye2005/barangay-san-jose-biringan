# Barangay San Jose Digital Portal

A PHP and MySQL web portal for resident account registration, document requests, administrator review, and public bulletin disclosures. The frontend is a vanilla JavaScript single-page app with a standalone public-record category page.

## Requirements

- PHP with PDO MySQL enabled
- MySQL or MariaDB
- A local web server such as XAMPP
- A modern browser

## Run locally with XAMPP

1. Place the project in the web root (for example, `C:\xampp\htdocs\barangay_portal_new`).
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Open phpMyAdmin and import `schema.sql`. This creates the `barangay_portal` database and its tables.
4. For local development, the defaults expect MySQL at `127.0.0.1:3306`, database `barangay_portal`, and a local `root` account with no password. Production must use environment variables; see Configuration below.
5. Configure SMTP through environment variables if email notifications are needed. Never store real credentials in PHP source files.
6. Open `http://localhost/barangay_portal_new/`.

## Administrator sign-in

Admin accounts use the separate administrator login and the `user` database table; resident credentials cannot be used there. To create an administrator, generate a password hash with PHP:

Admin sign-in is available only after visiting the unguessable path configured by `ADMIN_ENTRY_KEY`. Keep that path private and share it only with administrators. The path is an additional barrier; every administrator must still use an Admin account and complete authenticator-app MFA. MFA seeds are encrypted at rest using `ADMIN_MFA_ENCRYPTION_KEY`, which must remain stable and secret.

For an existing database, apply `admin_mfa_migration.sql` once before deploying the MFA-enabled PHP files. On the first sign-in, each administrator must enroll an authenticator app using the displayed setup key and confirm a current 6-digit code. If an administrator loses their authenticator, a database administrator must reset that account's MFA columns before it can be enrolled again.

For an existing database, also apply `profile_enhancements_migration.sql` once before deploying the expanded My profile features. It adds profile photos, administrator details and notification settings, password-change dates, active-session tracking, and MFA recovery-code storage.

```sh
php -r "echo password_hash('Choose-a-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

In phpMyAdmin, select the `barangay_portal` database, open the SQL tab, and insert an account using the generated hash:

```sql
INSERT INTO user (first_name, last_name, email, password_hash, role)
VALUES ('Admin', 'User', 'admin@example.com', 'PASTE_GENERATED_HASH_HERE', 'Admin');
```

Then sign in through `http://localhost/barangay_portal_new/index.php#admin-login`. Use the email and original password you chose. The login checks the stored hash; it does not accept a resident account. The legacy `index.html` URL forwards to `index.php`.

For an existing database, run `admin_workspace_migration.sql`, `resident_payment_migration.sql`, and `security_hardening_migration.sql` once in the `barangay_portal` database. These add request notes, payment status, email verification, and rate-limit storage. Existing residents are marked as previously verified by the security migration; new registrations must verify their email.

For an older local database that does not have `resident.archived_at`, run `resident_archive_migration.sql` once. The current login and account APIs expect this nullable column.

## Main areas

- **Resident portal:** registration, login, document requests, and request status tracking.
- **Admin workspace:** overview, requests queue and detail, pending resident review, disclosure publishing, and activity log.
- **Public records:** bulletin categories and published disclosures (`bulletin.html`).

The main interface and routing are in `index.php` and `script.js`. Admin sections have direct PHP routes at the project root (`admin_overview.php`, `admin_requests.php`, `admin_residents.php`, `admin_disclosures.php`, and `admin_activity.php`). Styles are split under `css/` into shared, home, authentication, admin, public-records, and responsive files. Shared page markup and admin sections are organized under `includes/shared/` and `includes/admin/`. API endpoints are in `api/`; database setup is in `schema.sql` and `admin_workspace_migration.sql`.

## Database tables

The schema defines administrator and resident accounts, document types and requests, request status history, admin-only request notes, disclosure categories and posts, attachments, email verification, rate limits, and an audit log. Uploaded proofs and disclosure attachments are stored outside the web root by default in a sibling `barangay_portal_private` directory. Set `APP_PRIVATE_STORAGE` to another directory outside the public web root when deploying.

## Configuration and security

- Set `APP_ENV=production`, `APP_URL` (HTTPS), `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` in the host environment. Give the database account only the permissions the application needs, including the migration-created tables.
- Set `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION` (`tls` or `ssl`), `MAIL_FROM_EMAIL`, and optionally `MAIL_FROM_NAME` for verification and notification email.
- Keep `APP_PRIVATE_STORAGE` outside the web root and writable by PHP. Do not expose `.env`, SQL files, upload directories, or configuration files.
- Use HTTPS at the host/proxy. Apache rules redirect HTTP and set transport/security headers; confirm the host supports `.htaccess` rewrite and header modules.
- Existing account passwords are not forcibly reset by the migration. Reset any legacy passwords that do not meet the 12-character policy, and rotate any database or SMTP credential that has ever been committed or shared.
- The `lib/PHPMailer` directory is vendored (currently 6.9.1); there is no Composer or npm manifest. Review and update this copy through a trusted release process when network access is available.

