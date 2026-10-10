# Apache 404 deployment fix

## What was found
The project archive was wrapped in a top-level project directory. If a host extracts the archive into its document root without moving that directory's contents up one level, Apache's document root contains only the folder and cannot find `/index.php`, resulting in an Apache 404 page.

The project also had no root `.htaccess` file declaring the default index document.

## Changes made
- The deployment ZIP is packaged with application files at the archive root (no enclosing project folder).
- Added `.htaccess` with `DirectoryIndex index.php index.html` and directory listing disabled.
- No catch-all rewrite was added, so missing files still return a real 404 rather than silently showing the login page.
- PHP pages, UI assets, module files, SQL/database files, and existing application content are retained.

## Deploy
1. Back up the currently deployed files and database.
2. Set the hosting document root to the directory containing `index.php` (typically `/var/www/html` in the supplied Dockerfile).
3. Upload/extract this ZIP so `index.php`, `auth/`, `includes/`, `modules/`, `style.css`, and `app.js` are directly inside the document root. Do not leave them nested one folder down.
4. Rebuild/redeploy the container so the Dockerfile's `COPY . .` copies these root-level files.
5. Test `/`, `/index.php`, `/auth/login.php`, `/health.php`, and after login `/dashboard.php`.

If `/health.php` works but `/` returns 404, check Apache `DocumentRoot` and whether `index.php` exists in that exact directory. If only a particular dashboard/module URL returns 404, confirm that exact PHP file exists in the deployed release and check the Apache access/error logs for the requested path.

## Additional deep-scan finding
The archive had extra root-level files named `index (1).php` through `index (5).php`, which were flattened copies of module/API entry points. These were removed from the deployment root; the canonical module and API files remain in their proper directories. The Dockerfile now copies directly to `/var/www/html` and fails the build if the required entry points are absent. See `DEPLOYMENT_ROOT_DIAGNOSTICS.md` for the exact post-deploy checks.
