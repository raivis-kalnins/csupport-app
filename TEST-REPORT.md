# ClientWatch 1.2.4 verification

Dashboard-only release. WordPress Connector 1.2.2 and the existing integrations remain compatible.

## Checks completed

- PHP syntax validation passed for `index.php`, `cron.php`, `reset-password.php`, `lib/core.php`, `lib/monitor.php`, and `lib/views.php`.
- JavaScript syntax validation passed for `assets/app.js` and the unchanged 1.2.2 public tracker.
- Version/cache-busting references updated to 1.2.4.
- Collector origin matching was reviewed to allow only the same scheme, effective port, and hostname with an optional `www.` difference.
- Collector diagnostics never expose or require the backend secret in browser JavaScript.
- H1 logic now collapses duplicate text and evaluates multilingual headings per language instead of rejecting a heading solely because its `lang` differs from the document language.
- Tracker probing is server-side and only looks for the public Site ID/public tracking key and public endpoint markers in the homepage HTML.

## Hosting validation still required

The build environment does not provide PDO SQLite, cURL, or DOM PHP extensions, so the live database migration, real remote homepage probe, and production browser event delivery must be verified on the deployed hosting account. Existing data is preserved by the migration code.

- 1.2.4 adds dashboard-only collector diagnostics and conservative technology detection.
