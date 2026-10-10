# Compliance Report Improvements

## Included
- Accurate report total independent of the latest-five display limit and date filter.
- Separate report queries from ordinary compliance-obligation queries using the existing report marker, avoiding destructive changes to existing rows.
- Compliance obligation and overdue KPIs exclude report rows; upcoming compliance dashboard items exclude report rows.
- Administrator-only deletion enforced in the service layer; report deletion cannot delete a normal obligation and obligation deletion cannot delete a report.
- Add Compliance Report supports an optional supporting document upload (PDF, JPG, PNG, DOC, DOCX; 10 MB maximum), validates MIME type and size, stores random server-side filenames, and offers a protected View Document endpoint.
- Server-side length and contact validation, CSRF validation, safe error handling, and audit records retained.
- Existing theme/classes and existing database rows are preserved. Schema changes for attachment columns are additive and performed only when missing.
- Upload storage includes a `.htaccess` deny rule; verify equivalent deny rules on the production web server if it is not Apache.

## Deployment notes
- Back up the database and files before deployment.
- Ensure PHP `file_uploads=On`, `upload_max_filesize` is at least `10M`, and `post_max_size` is greater than `10M` (recommend `12M` or more).
- Ensure the application can create/write `storage/compliance_reports`; the directory should not be publicly browseable.
- Run on the same PHP version as the existing application (the codebase uses PHP 8 syntax).
- Existing compliance report records remain in `compliance_obligations` and are identified by non-NULL `report_name`. This patch intentionally does not relocate existing rows, to avoid risking data loss. A future dedicated-table migration should be done as a separately backed-up, verified migration.

## Validation
- All PHP files passed `php -l` syntax checks in the build environment.
- Live database, authenticated role tests, file upload runtime, and hosting size limits were not testable in this offline static patch.
