# Security operations checklist

Keep this file in the project repository. The root `.htaccess` blocks direct web access to it.

## Before deployment

1. Rotate credentials that have been shared or may have been exposed:
   - Change the database user's password in the hosting control panel, then update the protected env file outside the web root or the deployment-specific `api/db.php` settings.
   - Generate a new SMTP credential with the mail provider, then update the protected mail configuration.
   - Do not commit `.env`, `api/db.php`, database exports, or SMTP credentials. Set `PORTAL_ENV_FILE` to the absolute path of the protected env file when it is not at the default location.
2. Apply the SQL migration supplied with the release before deploying PHP that reads the new account-version columns.
3. Upload the current project files over HTTPS. `api/db.php` is deployment-specific and Git-ignored, so upload its intended changes separately while retaining the correct database settings.

## cPanel backups

1. In cPanel, open **Backup** or **Backup Wizard** and create a full account backup when that feature is available.
2. Also export the production MySQL database and back up the private upload directory configured for the site. The upload directory contains residency proofs, disclosure attachments, and profile photos.
3. Download a copy to a separate, access-controlled location outside the hosting account. Encrypt the copy before storing it in general-purpose cloud storage.
4. Periodically restore the database and files to a local or staging environment and confirm that the restored site can read its records and private uploads.
5. If the cPanel account provides ImunifyAV or another malware scanner, scan the upload directory on a schedule. Do not rely on file extensions alone; the application already checks file contents and MIME types before accepting uploads.

## Queued email delivery

- Web requests save email jobs to `portal_email_queue` and return without waiting for SMTP. Each job uses one delivery path: FastCGI sends after flushing the response, or non-FastCGI servers start a detached PHP CLI worker.
- If the host disables process-launch functions and does not provide FastCGI response finishing, configure a cPanel Cron Job to run `api/process_mail_queue.php` at least once per minute. Use the hosting account's PHP CLI path and the site's absolute filesystem path, for example: `*/1 * * * * /path/to/php /home/account/public_html/api/process_mail_queue.php >/dev/null 2>&1`.
- Run the worker from the command line, not by exposing a public cron URL. It retries temporary delivery failures and processes up to 20 due messages per run.

## After deployment

- Confirm the new `/admin-access/` form accepts the private key, and that admin sign-in still requires MFA.
- Confirm registration, login, logout, email verification, password reset, and the admin actions work with CSRF protection enabled.
- Review cPanel error logs and the portal audit log after the checks. Never copy reset URLs, passwords, MFA codes, session IDs, or credentials into issue reports.

## Suspected personal-data incident

1. Tell the project lead and the person responsible for privacy as soon as possible. Preserve relevant logs and record when the incident was first discovered.
2. Contain the issue where safe to do so: disable exposed links or accounts, restrict the affected file or endpoint, and rotate credentials that may be compromised. Do not erase evidence needed to understand the incident.
3. Determine what information and how many people may be affected, when exposure began, who could access it, and whether the data was acquired or misused. Keep a written record of the assessment and response.
4. The responsible personal information controller, with qualified privacy or legal advice, must decide whether NPC and affected-person notification is required. For a breach that meets the notification criteria, the applicable NPC rules generally require notice within 72 hours of knowledge or reasonable belief. Follow the current NPC rules for the threshold, required content, and submission method.
5. Give affected people practical steps to protect themselves when notification is required. Document decisions, notices, remediation, and steps to prevent recurrence.

This prototype does not identify a formally appointed privacy officer or incident lead. A real operator must assign those responsibilities and keep their contact details current before processing operational personal information.
