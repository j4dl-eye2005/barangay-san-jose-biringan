# Security operations checklist

Keep this file in the project repository. The root `.htaccess` blocks direct web access to it.

## Before deployment

1. Rotate credentials that have been shared or may have been exposed:
   - Change the database user's password in the hosting control panel, then update the protected `.env` or the deployment-specific `api/db.php` settings.
   - Generate a new SMTP credential with the mail provider, then update the protected mail configuration.
   - Do not commit `.env`, `api/db.php`, database exports, or SMTP credentials.
2. Apply the SQL migration supplied with the release before deploying PHP that reads the new account-version columns.
3. Upload the current project files over HTTPS. `api/db.php` is deployment-specific and Git-ignored, so upload its intended changes separately while retaining the correct database settings.

## cPanel backups

1. In cPanel, open **Backup** or **Backup Wizard** and create a full account backup when that feature is available.
2. Also export the production MySQL database and back up the private upload directory configured for the site. The upload directory contains residency proofs and disclosure attachments.
3. Download a copy to a separate, access-controlled location outside the hosting account. Encrypt the copy before storing it in general-purpose cloud storage.
4. Periodically restore the database and files to a local or staging environment and confirm that the restored site can read its records and private uploads.
5. If the cPanel account provides ImunifyAV or another malware scanner, scan the upload directory on a schedule. Do not rely on file extensions alone; the application already checks file contents and MIME types before accepting uploads.

## After deployment

- Confirm the new `/admin-access/` form accepts the private key, and that admin sign-in still requires MFA.
- Confirm registration, login, logout, email verification, password reset, and the admin actions work with CSRF protection enabled.
- Review cPanel error logs and the portal audit log after the checks. Never copy reset URLs, passwords, MFA codes, session IDs, or credentials into issue reports.
