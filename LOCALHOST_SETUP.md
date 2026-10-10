# Localhost Automatic Database Setup

This build is configured for XAMPP/WAMP-style localhost use.

## Start
1. Put `core4-master` inside `htdocs`.
2. Start Apache and MySQL.
3. Open `http://localhost/core4-master/`.

## Automatic first run
The application will automatically create:
- MySQL database: `great_solomon_ct4`
- all tables from `database/database.sql`
- the existing seed Administrator and Staff accounts

No phpMyAdmin import is required on a new local installation.

## If MySQL root has a password
Create a file named `.env` in the project root using `.env.example` and set:

`GSMS_DB_PASS=YOUR_MYSQL_ROOT_PASSWORD`

The default XAMPP configuration normally uses a blank root password.

## Existing database
If `great_solomon_ct4` already exists and contains the `users` table, the bootstrap does not re-import the seed SQL. Existing data is preserved.

## Important
Gmail OTP still requires valid SMTP/App Password settings in `.env` for actual email delivery. This database/localhost change does not disable or replace the existing OTP workflow.


## Gmail OTP on localhost (updated)
The configured Gmail sender is `governancesafety21@gmail.com` and the Gmail App Password is loaded from the local `.env` file.

When the localhost computer has DNS and outbound TCP 587 access, the application sends the OTP through Gmail using STARTTLS.

If the localhost computer cannot resolve `smtp.gmail.com` or cannot reach TCP 587, the login no longer becomes permanently blocked. The application stores the OTP hash normally and, **only when the request is actually from localhost**, displays the generated 6-digit OTP on the verification page for local testing. This fallback is not enabled automatically for production hosts.

This is a development fallback, not Gmail delivery. Real email delivery still requires the machine running Apache/PHP to have working DNS and outbound TCP 587 access.

Optional SMTP connection override variables:
- `GSMS_MAIL_CONNECT_HOST` — direct connection host/IP when a network administrator provides one.
- `GSMS_MAIL_TLS_PEER_NAME=smtp.gmail.com` — keeps Gmail TLS certificate verification tied to Gmail even when connecting to a direct host/IP.


## Updated build security and workflow
- The ZIP does not include `.env` or live credentials. Copy `.env.example` to `.env` and configure values on your own machine.
- New installations create the task workflow table. Existing installations add it with a non-destructive `CREATE TABLE IF NOT EXISTS` migration.
- Existing feedback/notification records are preserved; this version does not run a feedback cleanup migration.
- Tasks & Approvals is available from the sidebar. Dashboard panels show upcoming and overdue compliance obligations.
- Archive access is restricted to administrators.
