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
4. Check the database connection settings in `api/db.php`. The defaults expect MySQL at `127.0.0.1:3306`, database `barangay_portal`, and a local `root` account with no password.
5. Configure mail credentials in the ignored `api/mail_config.php` file if email notifications are needed. Do not commit real credentials.
6. Open `http://localhost/barangay_portal_new/`.

## Administrator sign-in

Admin accounts use the separate administrator login and the `user` database table; resident credentials cannot be used there. To create an administrator, generate a password hash with PHP:

```sh
php -r "echo password_hash('Choose-a-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

In phpMyAdmin, select the `barangay_portal` database, open the SQL tab, and insert an account using the generated hash:

```sql
INSERT INTO user (first_name, last_name, email, password_hash, role)
VALUES ('Admin', 'User', 'admin@example.com', 'PASTE_GENERATED_HASH_HERE', 'Admin');
```

Then sign in through `http://localhost/barangay_portal_new/index.html#admin-login`. Use the email and original password you chose. The login checks the stored hash; it does not accept a resident account.

For an existing database, run `admin_workspace_migration.sql` in the `barangay_portal` database to add the administrator-only request notes table. The schema already includes the request status history and audit log tables.

## Main areas

- **Resident portal:** registration, login, document requests, and request status tracking.
- **Admin workspace:** overview, requests queue and detail, pending resident review, disclosure publishing, and activity log.
- **Public records:** bulletin categories and published disclosures (`bulletin.html`).

The main interface and routing are in `index.html`, `script.js`, and `style.css`. API endpoints are in `api/`; database setup is in `schema.sql` and `admin_workspace_migration.sql`.

## Database tables

The schema defines administrator and resident accounts, document types and requests, request status history, admin-only request notes, disclosure categories and posts, attachments, and an audit log. Uploaded proofs and disclosure attachments are stored under `uploads/` and should remain private to the deployment environment.

## Configuration and security

- Keep mail credentials and other secrets out of source control.
- Use HTTPS and a least-privilege database account outside local development.
- Review upload permissions and access controls before deploying publicly.
- The local `create_admin.php` seeding utility is excluded from source control because it outputs a plaintext password. Do not expose it on a public server.

