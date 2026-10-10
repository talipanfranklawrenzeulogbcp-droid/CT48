# Core Transaction 4 — deployment root diagnostics

This release includes a build-time guard against the common Apache 404 cause: the image being built from a folder that does not contain the application entry point.

## Expected container layout

The configured Apache `DocumentRoot` is `/var/www/html`. These files must exist directly inside that directory:

- `/var/www/html/index.php`
- `/var/www/html/auth/login.php`
- `/var/www/html/health.php`
- `/var/www/html/.htaccess`
- `/var/www/html/style.css`
- `/var/www/html/app.js`

The Dockerfile now checks those files and validates Apache's configuration during image build. If the build succeeds, it confirms that the build context contained the required root files. It does not prove the hosting platform is serving that newly built image.

## Required redeployment steps

1. In HostForge, make sure the service is deploying this ZIP/repository revision using its **Dockerfile**, not a different old source/build configuration.
2. Set the build/root directory to the folder that contains this Dockerfile and `index.php` together. Do not point it at the parent folder if the project is nested in a subfolder.
3. Rebuild/redeploy a fresh image; do not merely restart the old container. Check build logs for the required-file guard.
4. Test these exact URLs after redeployment:
   - `/index.php` — should redirect to login/dashboard.
   - `/auth/login.php` — should display the login page.
   - `/health.php` — should return JSON; `200` means app+DB reachable, `503` means PHP ran but database configuration/connectivity needs attention.
5. If `/index.php` and `/auth/login.php` still show the **plain Apache 404 page**, the request is not reaching the app files in this image. Check the HostForge service's active deployment, build context/root directory, domain-to-service binding, and Apache access/error logs. PHP redirects and `.htaccess` cannot fix a document root that points to a different directory or an old image.

## Data safety

No SQL was executed and no database tables or rows were changed. Configure secrets through the hosting environment; do not put production credentials in the ZIP.
