# Health & Safety Incident Report Update

- Added a dedicated incident-report form in `modules/health_safety/add_incident_report.php`.
- Extended `safety_incidents` additively; existing incident rows are retained. The service performs a runtime additive column check and both SQL schema copies include the new fields for fresh installs.
- Accepts up to five JPG, PNG, WEBP, or PDF attachments, up to 8 MB each. The server validates MIME types and stores generated filenames under `storage/health_safety/incidents/`.
- Uploaded evidence is blocked from direct web access and is served through the authenticated `incident_attachment.php` endpoint.
- The incident list retains the existing latest-five/all and date-filter behavior while showing expanded report details and evidence links.
- The Docker image prepares the incident upload directory and assigns it to the Apache runtime user.

## Deployment note
If the app runs in an ephemeral container, configure a persistent writable volume mounted at `/var/www/html/storage` (or at least `/var/www/html/storage/health_safety/incidents`). Without a persistent volume, uploaded files may be lost when the container is replaced. Database records remain in the configured database.
