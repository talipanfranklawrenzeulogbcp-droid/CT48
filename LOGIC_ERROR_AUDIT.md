# Core Transaction 4 — Logic Error Audit

## Corrections applied
- Fixed root-level `bootstrap.php` database include path so it loads `includes/db.php` from the application root.
- Converted root-level `login.php` into a compatibility redirect to the canonical `/auth/login.php` flow. This removes an older duplicate implementation with incorrect relative paths and inconsistent OTP/session behavior.
- Converted root-level `verify_otp.php` into a compatibility redirect to `/auth/verify_otp.php`.
- Corrected shared-include paths in root-level `logout.php`, `gemini.php`, and legacy `index (2).php` / `index (4).php` files so they no longer resolve outside the project root.

## Logic risks identified for review/testing
- Authentication logic is duplicated between `auth/login.php` and `auth/verify_otp.php`; test both the login-page OTP form and the dedicated verification route to ensure only one active OTP workflow is linked from the UI.
- `dashboard.php` intentionally catches errors fetching upcoming compliance deadlines and displays an empty state; this can conceal schema/query problems in production logs unless database errors are logged.
- Several reporting queries assume particular table and column names. Validate against the production schema before enabling migrations or altering records.
- The database host and SMTP connectivity cannot be verified from a static ZIP. Incorrect hosting environment variables can still prevent login even when PHP syntax is valid.
- Apache `.htaccess` behavior depends on host `AllowOverride` settings; if directives are disallowed, configure `DirectoryIndex` and `Options` in the hosting vhost instead.

## Validation
- PHP syntax check run across the package: 77 PHP files passed; no syntax errors found.
- No live database, SMTP, session-cookie, or browser end-to-end tests were possible in this offline archive review.
- Existing UI assets and database SQL files were preserved; no database data was rewritten by this audit.
