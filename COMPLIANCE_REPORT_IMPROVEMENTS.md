# Compliance Report improvements

This build includes the compliance report attachment upload and the following fixes:

- Supports optional PDF, JPG, PNG, DOC and DOCX attachments up to 10 MB, validates MIME type, generates a random storage filename, and serves files through an authenticated PHP endpoint rather than a direct file URL.
- Adds document metadata columns using an additive migration; existing rows are not dropped.
- Shows an accurate report total independent of the five-row default view and date filtering.
- Excludes report rows from the compliance-obligation count and excludes compliant obligations from overdue totals.
- Enforces administrator-only deletion in the service layer and prevents using report deletion on an obligation (or obligation deletion on a report).
- Adds server-side field-length/contact validation and avoids showing unexpected exception details to end users.
- Adds an Apache rule to deny direct web access to uploaded compliance report documents.

## Important database compatibility note

For compatibility with existing installations, this patch continues to store legacy reports in `compliance_obligations`, identified by a non-null `report_name`, instead of automatically migrating live records to a new table. New uploads add columns only. A future dedicated `compliance_reports` table migration should first classify and verify existing report rows and then migrate them transactionally; do not drop or truncate the legacy table.

## Deployment and verification

1. Back up the database and current application files before deploying.
2. Deploy the updated files and ensure the PHP process can write to `storage/compliance_reports`.
3. Ensure PHP `fileinfo` is enabled and server `upload_max_filesize` and `post_max_size` are configured above the 10 MB file limit (for example 12M/13M).
4. Test as administrator and non-administrator: create a report with and without an attachment; view a PDF/image; confirm DOC/DOCX downloads; reject an unsupported file and oversized file; verify direct URL access to storage is denied; confirm non-admin deletion returns 403-equivalent application denial.
5. Verify dashboard obligation and overdue counts against known records. Existing database rows are preserved; this patch does not connect to or modify a live database during packaging.

## Remaining recommended next step

Implement a dedicated `compliance_reports` table and an explicit investigation workflow (`Submitted`, `Under Review`, `In Progress`, `Resolved`, `Closed`) after reviewing production data. This package intentionally avoids a blind data migration that could misclassify or lose legacy records.
