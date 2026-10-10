# HostForge deployment settings — CT4

This application runs PHP 8.3 with Apache in the supplied Dockerfile.

## HostForge Advanced settings

- **Application/container port:** `80`
- **Health-check path:** `/health.php`
- **Health-check timeout:** `5` seconds (if configurable)

The Dockerfile declares `EXPOSE 80` and includes a Docker health check for `/health.php`.

## Employee document uploads and HTTP 413

The Docker image allows request bodies up to 32 MiB at Apache/PHP, and PHP accepts individual uploads up to 16 MiB. The application intentionally validates employee documents at **10 MiB per file** (PDF, JPG, PNG, DOC or DOCX), leaving room for multipart form overhead and other form fields. These settings are applied from the Dockerfile, so rebuild and redeploy the image after changing them.

If the browser still receives a plain HTTP **413** response before the application displays its normal upload error, the request is being rejected by an upstream ingress/reverse proxy or hosting-level body-size policy. Raise that platform-level maximum to at least **32 MiB** for this service; a PHP or Apache setting inside the container cannot override a smaller limit enforced before the request reaches the container. No database migration is needed for this fix.

## Required environment variables

Configure these in HostForge's service environment settings. Do not commit a real `.env` file or bake secrets into the image.

- `APP_ENV=production`
- `APP_DEBUG=false`
- `GSMS_DB_HOST` — the database hostname supplied by HostForge (must be reachable from this app service; do not use `127.0.0.1` for a separate database service)
- `GSMS_DB_PORT=3306` (use the provider's actual port)
- `GSMS_DB_NAME`
- `GSMS_DB_USER`
- `GSMS_DB_PASS`
- `GSMS_LOCAL_OTP_FALLBACK=false`
- `GSMS_MAIL_USERNAME`
- `GSMS_MAIL_PASSWORD` — Gmail App Password
- `GSMS_MAIL_FROM_EMAIL`
- `GSMS_OTP_SENDER_EMAIL`
- `GEMINI_API_KEY` (if using the AI assistant)

If HostForge provides a `DATABASE_URL`, the app also supports it, provided `GSMS_DB_HOST` is not set. Prefer the exact connection values shown in HostForge's database panel.

## Verify after deployment

Open `https://YOUR-DOMAIN/health.php`. HTTP 200 with JSON `"status":"ok"` indicates the health endpoint and database connection are working. HTTP 503 with `"status":"degraded"` means the web server is responding but the database connection failed; inspect the app logs and correct the database hostname/credentials.

The ZIP does not contain a real `.env` file. Set credentials as HostForge environment variables and rotate any credentials that were previously shared in an archive or chat.

## Login/dashboard recovery notes (patch)

- The PHP application listens on container port **80** and the health endpoint is `/health.php`.
- Database connection variables are read from `GSMS_DB_*` first, then `DATABASE_URL`, then common provider aliases (`DB_HOST`, `MYSQLHOST`, `MARIADB_HOST`, etc.). Configure these in the hosting service's runtime environment; do not put production credentials in the ZIP or commit a `.env` file.
- When login fails with the generic message, check the container's PHP/Apache error log for `[CT4 LOGIN]`. The browser intentionally does not display database credentials or raw exception details.
- The patch does not drop tables, truncate rows, or replace the existing database. Existing database records and the UI/theme are retained.
