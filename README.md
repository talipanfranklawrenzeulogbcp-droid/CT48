# Great Solomon Manpower Services Inc. Core Transaction 4 — PHP/MySQL Backend

This package is a database-backed PHP application for Core Transaction 4.

## Structure
- `auth/` — login and logout
- `modules/health_safety/` — Health, Safety & Welfare
- `modules/legal_compliance/` — Legal & Compliance
- `modules/system_admin_security/` — System Administration & Security
- `dashboard.php` — Reports, Analysis & Dashboard (central tracking for the four modules)
- `modules/asset_equipment/` — Asset & Equipment Issuance
- `includes/` — database, authentication and helper code
- `database/database.sql` — MySQL database and tables
- `app.js` — JavaScript
- `style.css` — CSS


## Localhost / XAMPP Install (automatic database setup)
1. Extract this folder into your XAMPP `htdocs` directory (for example `C:\xampp\htdocs\core4-master`).
2. Start **Apache** and **MySQL** in XAMPP.
3. Open `http://localhost/core4-master/`.
4. On the first request, the application automatically:
   - connects to MySQL on `127.0.0.1:3306`;
   - creates the `great_solomon_ct4` database if it does not exist;
   - imports `database/database.sql`;
   - creates the default Administrator and Staff seed accounts;
   - preserves the database on later requests instead of importing the seed data again.
5. If your local MySQL `root` account has a password, create `.env` from `.env.example` and set `GSMS_DB_PASS=your_password`.

### Local database defaults
- Host: `127.0.0.1`
- Port: `3306`
- Database: `great_solomon_ct4`
- User: `root`
- Password: blank by default (standard XAMPP setup)

**Manual phpMyAdmin import is no longer required for a new local installation.**

## Default login
- Name: `Admin`
- Email: `adminct4@gmail.com`
- Password: use the password already configured in your installation; change any known default password immediately.

## Login flow
The login uses the account password followed by a 6-digit Gmail OTP before the dashboard opens.

Passwords are stored using PHP `password_hash()` and verified with `password_verify()`.

## Notes
The application uses the shared authentication and helper files across all modules. Logging out destroys the current session.


## Two-Step Login (Gmail OTP)

The login now uses password + a 6-digit OTP before opening the dashboard.

- Sender: `governancesafety21@gmail.com`
- SMTP: Gmail on port 587 with STARTTLS
- OTP validity: 10 minutes
- Maximum OTP attempts: 5
- Resend OTP is available on the verification screen.
- Administrator seed account: `adminct4@gmail.com`
- Administrator name: `Admin`
- Administrator password: use the password already configured in your installation. Change any known default password immediately.

For production, move the Gmail App Password from `includes/config.php` to environment variables:
`GSMS_MAIL_USERNAME` and `GSMS_MAIL_PASSWORD`.


---

## Microservices update

This version separates the four Core Transaction 4 modules into service boundaries under `services/`. See `MICROSERVICES.md` for the service map, API gateway, connection flow, and deployment notes.




## CT4 UI updates
- Health, Safety & Welfare now supports date filtering with calendar controls for Health Records and Safety Incident Reports.
- Login History displays only date/time, email, user, and role.
- User Accounts includes a centered Edit User modal for name, email, and optional password changes.
- Asset Issuance History includes Returned / Not Returned actions and a detectable status badge.
- The top-right user name opens Feedback, Terms and Conditions, and Logout. Feedback is emailed to the first active Administrator account.


## Gemini AI System Assistant
The sidebar now includes **AI System Assistant**. It uses a server-side Gemini API call and supplies Gemini with authorized CT4 context such as module descriptions, documentation, live record counts, recent audit metadata, login totals, and the current signed-in user's name/role.

For security, passwords/password hashes, OTPs, SMTP credentials, API keys, session tokens and database credentials are not sent to Gemini. The browser never receives the Gemini API key.

### Server environment
Set these variables on the PHP server (not in JavaScript or a public file):
- `GEMINI_API_KEY` — your Gemini API key
- `GEMINI_MODEL` — defaults to `gemini-3.8-flash`

See `.env.example` for the names only. For the supplied local build, the Gemini key is stored in the server-only `.env` file and is never sent to browser JavaScript. Rotate the supplied key after testing because it was shared during setup.


## Gmail OTP delivery troubleshooting

For Gmail OTP delivery, copy `.env.example` to `.env` and set `GSMS_MAIL_USERNAME` to the sending Gmail address and `GSMS_MAIL_PASSWORD` to that account's **Google App Password** (not its normal sign-in password). Google App Passwords require 2-Step Verification to be enabled on the account. Keep `.env` private and never commit or upload it.

The SMTP sender address is aligned with the authenticated Gmail account because Gmail may reject a different envelope sender. The mailer uses STARTTLS on port 587, verifies the TLS certificate, and logs server-side diagnostic details without exposing credentials to the browser. If delivery fails on hosting, verify outbound TCP 587, DNS resolution for `smtp.gmail.com`, PHP OpenSSL support, and the PHP error log. The application UI and database schema are unchanged by the mailer fix.

## CT4 Authentication and Gmail OTP
Configure Gmail SMTP using environment variables. Do not distribute real SMTP credentials, API keys, or the local `.env` file. For deployment, set `GSMS_MAIL_USERNAME`, `GSMS_MAIL_PASSWORD`, `GSMS_MAIL_FROM_EMAIL`, and `GSMS_OTP_SENDER_EMAIL` as server environment variables.

The database seed provisions the main administrator as:
- Email: `adminct4@gmail.com`
- Role: `Administrator`

Import `database/database.sql` into MySQL before first use. PHP must have OpenSSL and cURL enabled. If SMTP is blocked by the hosting provider, allow outbound SMTP/TLS traffic on port 587.

## Role-based access
Staff accounts can access Reports, Analysis & Dashboard, AI System Assistant, Health, Safety & Welfare, Legal & Compliance, and Asset & Equipment Issuance. System Administration & Security is administrator-only. Administrator dashboards include staff activity tracking for the Health, Safety & Welfare, Legal & Compliance, and Asset & Equipment Issuance modules through audit records.


## Added workflow features
- Tasks & Approvals page with task creation, assignment, priority, due dates, status tracking, overdue indicators, and administrator approval/rejection for staff-created task requests.
- Role-aware task visibility and administrator-only task deletion.
- Dashboard panels for upcoming and overdue compliance obligations.
- Additive `workflow_tasks` schema migration that preserves existing records.
- Archive listing, recovery, and permanent deletion restricted to administrators.
- CSRF protection for task create/update/delete actions.

## Upgrade safety
The distributable ZIP intentionally excludes `.env`. Copy `.env.example` to `.env` and configure local credentials privately. Existing databases are not reset or reseeded when the `users` table already exists. Back up the database before deployment.

## Complete upgrade notes

This package includes the additive Tasks & Approvals workflow, dashboard deadline panels, improved responsive task UI, and additional request protections. Existing business tables and user records are not dropped or reset by the upgrade. Back up the database before first launch so schema changes can be rolled back if your local MySQL/MariaDB version differs.

### Security and deployment checklist

- Keep `.env` out of the ZIP, web root downloads, and source control. Configure real credentials in your server environment or a private local `.env` file copied from `.env.example`.
- Rotate any database, SMTP, or AI credentials that were ever shared in a ZIP or chat.
- Change all default account passwords immediately and create unique accounts for staff. Do not use seed credentials on a public server.
- Disable localhost OTP display fallback on any non-local deployment with `GSMS_LOCAL_OTP_FALLBACK=false`.
- Use HTTPS in production and ensure server backups and uploaded documents are stored outside public access where possible.
- Test login, OTP, task approval, notifications, archive recovery, and asset issuance against a backup or staging database before production use.

### Tasks & Approvals

Administrators can create and assign tasks, approve or reject staff-created requests, and remove tasks. Staff can create task requests and update only tasks they created or are assigned to after approval. Status, priority, creator, assignee, and due-date information are stored in the additive `workflow_tasks` table.


## Health records and recruitment document compliance
- The Health, Safety & Welfare page now focuses on employee health records and safety incidents; the old pending-request/release interface and its actions have been removed.
- The existing `health_safety_files` table is intentionally retained in the database schema so existing records are not destroyed.
- The health-record schema is extended with employee ID, vitals, allergies, medical history, medication, test results, restrictions, clinician and review-date fields. The bootstrap applies additive schema changes on startup.
- Saving a new health record creates a linked recruitment document checklist in `employee_document_records`. The Legal & Compliance module also backfills checklists for existing health records without duplicating entries.
- The checklist includes common medical, identity, recruitment, employment, qualification, deployment and clearance documents. Actual requirements vary by destination, employer and role; confirm applicable rules before treating an item as mandatory.
- Document statuses, issue/expiry dates, reference numbers and notes can be updated in Legal & Compliance. This is a checklist/metadata tracker; it does not claim that a document has been externally verified or submitted.


## Restricted Records Entry account

The application provisions one dedicated, restricted entry account on database connection if it does not already exist:

- Email: `records.entry@greatsolomonmpservices.com`
- Initial password: `EntryDesk@CT4-2026!`
- Role: `Records Entry`

This account signs in without OTP and is restricted server-side to the Records Entry landing page, Add Employee Health Record, Register Equipment / Asset, and Employee Document Upload. The document upload form submits to the existing legal-compliance handler only for the document-update action; the restricted account cannot browse the full Legal & Compliance module. Existing users, tables, and records are preserved. Change the initial password before using this account in a production environment.


## Feedback Management upgrade

The Feedback Management module is available at `modules/feedback/index.php` for active Administrator and Staff accounts. It adds category, priority, lifecycle status, administrator assignment, due dates, conversation history, audit history, staff satisfaction ratings, reopen requests, CSV export, and optional evidence attachments (PNG, JPG, PDF, TXT; maximum 5 MB).

The database upgrade is additive: the application creates `feedback_cases`, `feedback_history`, and `feedback_attachments` if they do not exist, and imports existing non-deleted `feedback` notifications into the case table without deleting or rewriting original notification records. Keep a backup before deployment. The attachment directory is protected from direct web access and files are served through the authenticated `modules/feedback/attachment.php` endpoint.

To verify after deployment: sign in as Administrator and Staff separately; submit a test feedback record; change its status and assignment; send and read a reply; resolve it; submit a rating; test attachment upload/download; and export the CSV report. Confirm existing notification and business records remain present.


## Deployment validation (updated)

- The distributable package excludes `.env` and all real credentials. Configure secrets in the hosting provider's environment-variable panel.
- Preferred database variables: `GSMS_DB_HOST`, `GSMS_DB_PORT`, `GSMS_DB_NAME`, `GSMS_DB_USER`, `GSMS_DB_PASS`. `DATABASE_URL` is also supported, as are common `DB_HOST`, `DB_PORT`, `DB_DATABASE`/`DB_NAME`, `DB_USERNAME`/`DB_USER`, and `DB_PASSWORD`/`DB_PASS` aliases.
- A database hostname must resolve from the application container. If logs show `getaddrinfo ... No such host is known`, replace the stale/unreachable hostname with the current database endpoint supplied by the host; PHP code cannot repair provider DNS or provision a database service.
- `/health.php` now returns HTTP 503 when the configured database cannot be reached, and does not expose credentials.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and `GSMS_LOCAL_OTP_FALLBACK=false` in production. Set Gmail credentials using `GSMS_MAIL_USERNAME` and `GSMS_MAIL_PASSWORD` (Google App Password). Configure `GEMINI_API_KEY` server-side if using the AI assistant.
- **Credential safety:** if a previous `.env` was shared or deployed, rotate its database password, Gmail App Password, and Gemini API key. Never upload `.env` to the deployment ZIP or commit it to source control.
