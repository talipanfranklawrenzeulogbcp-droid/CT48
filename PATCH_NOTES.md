# CT47 patch notes

## Applied fixes
- Removed the `AST-0003` Network Printer (`SN-GSMS-0003`) seed row from both SQL schema copies. Seed upserts were also made non-destructive for existing user roles/credentials and asset status values, so re-importing a schema does not silently overwrite them.
- Added a one-time, migration-tracked cleanup for that exact asset on existing installations. It removes only that asset and linked issuance/maintenance rows; unrelated database content is preserved. The asset inventory queries also hide the target record if database permissions prevent the cleanup migration from running.
- Fixed asset availability arithmetic consistently in both service copies by casting quantities/counts to signed values before subtraction, preventing MySQL unsigned-overflow errors.
- Fixed issuance status transitions so returned items cannot be re-issued beyond registered quantity or when the linked asset is in Maintenance/Retired status. Asset status now preserves Maintenance/Retired during return processing.
- Validated issue dates and expected-return dates, including disallowing an expected-return date earlier than the issue date.
- Added CSRF validation to asset issue/status and asset registration POST routes, and hide issuance-status controls from roles that are not authorized to change them.
- Refined the Asset & Equipment UI using the existing GSMS purple theme, with responsive table overflow, more consistent spacing, and theme-based status/quantity styles. No other module theme assets were replaced.
- Preserved authentication/OTP implementation files and unrelated database schema/data.

## Validation performed
- PHP syntax validation across all PHP files.
- JavaScript syntax validation for `app.js`.
- Static checks for remaining references to the removed asset and the presence of required CSRF tokens.
- Live MySQL/MariaDB, SMTP, browser, and HostForge integration tests require the target environment and were not run in this archive-only environment.

## Deployment
- Rebuild/redeploy using the included Dockerfile (Apache port 80; health path `/health.php`).
- Back up the production database before rollout. The one-time application migration targets only the specified printer record and its dependent records.


## October 2026 targeted module repair

- Fixed Legal & Compliance HTTP 500 by loading the complete employee-document service methods from the canonical service implementation; made employee document schema preparation additive and portable across MySQL/MariaDB versions.
- Rebuilt System Administration & Security using the existing CT4 page header/footer, shared purple theme, responsive tables/cards, user-edit controls, and CSRF validation on write actions.
- Updated Gemini defaults and fallback model chain from shut-down Gemini 2.0 Flash to available stable Flash model IDs; prevented duplicate submission of the current chat message and improved error visibility in the assistant UI.
- No database dump was replaced and no existing application records are reset by these code changes.


## October 2026 global table and AI assistant repair

- System Administration & Security now loads up to 100 recent login records and 100 audit events; the two activity tables calculate a viewport showing five data rows at once inside a vertically scrollable region with sticky headers.
- Added shared, keyboard-accessible sortable headers to all HTML tables. Clicking a column cycles between ascending and descending order using date/time, numeric, or natural text comparison; sorting is client-side and does not modify database records.
- Added consistent scroll bounds, touch scrolling, and purple-theme scrollbar/header treatment across table wrappers, including on smaller screens.
- Hardened the live Gemini API endpoint: database context and audit logging failures no longer discard an otherwise successful AI answer; response text parts are combined; UTF-8-safe request encoding, cURL availability checks, and better model/configuration diagnostics are included.
- Kept the root `gemini.php` entry point as a compatibility shim to the same hardened API handler to avoid behavioral drift between duplicate endpoints.
- Both `database.sql` copies are byte-for-byte unchanged. No database content, schema, or existing records were edited by this patch.

## Validation for October 2026 global table and AI assistant repair

- PHP syntax validation passed for all 72 PHP files.
- JavaScript syntax validation passed for `app.js`.
- Confirmed all 12 rendered table tags are covered by the global sorter.
- Confirmed both SQL schema files are byte-for-byte unchanged from the input archive.
- Live browser, database, and Gemini API integration tests still require deployment configuration and were not run in this archive-only environment.

## Asset photos, notifications, and feedback actions

- Added optional asset photos to asset registration and the equipment issuance form (JPG, PNG, or WebP; up to 5 MB), including local preview, saved thumbnail display, and an authenticated image-serving endpoint. Photos are saved under `storage/asset_images` using a hashed asset tag, with no database schema changes.
- Updated the user-menu notifications endpoint to refresh the role-scoped notification list as well as the unread badge. View and Reply actions now use escaped data attributes and event handlers, so feedback containing quotes or line breaks does not break the buttons.
- Corrected notification read authorization for personal and broadcast notifications, and made repeated read actions safe. Feedback-management reply forms use deployment-aware action URLs and return to the Feedback Management page.
- Verified PHP and JavaScript syntax. The existing SQL schema and migration scripts are unchanged; live database and browser interaction testing still depends on the deployed environment.
