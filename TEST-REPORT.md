# ClientWatch 1.1.0 — verification report

Build date: 11 October 2026.

## Checks completed in the build environment

- PHP 8.4.24 syntax validation passed for the dashboard, WordPress connector and
  PHP integration files.
- Node.js syntax validation passed for dashboard JavaScript, both tracker copies
  and the Node integration helper.
- Static package checks confirmed that the dashboard references the 1.1.0
  connector/integration download names and that no `config.php` or SQLite
  database is included in the release archives.
- Upgrade review confirmed no destructive database schema change is required;
  existing site rows, credentials, traffic records and settings remain in the
  private SQLite database.
- hCaptcha remains disabled by default and requires both a site key and saved
  secret before it can be enabled.
- Frontend analytics remains consent-gated by default. The new Immediate mode
  is explicit and still honours Do Not Track / Global Privacy Control.

## Environment limitations

The build container has PHP PDO but does not have PDO SQLite or PHP cURL
extensions enabled. Native SQLite database execution, live hCaptcha verification,
external uptime probes and production email delivery therefore could not be run
inside this environment. These need a staging check on the target hosting.

A live WordPress runtime was not available in the build container. Plugin PHP
syntax was validated, but activation, WP-Cron scheduling, premium update sources,
object-cache detection and the first 1.1.0 report should be verified on one
staging WordPress site before rolling the connector to every client.

## Recommended first deployment checks

1. Back up the current dashboard folder and private `clientwatch-private` folder.
2. Replace dashboard files while keeping the existing `config.php` unchanged.
3. Confirm the dashboard still shows all existing websites.
4. Update one WordPress site to ClientWatch Connector 1.1.0 and send a report.
5. Confirm plugin totals, environment information and update status appear.
6. Choose the correct frontend tracking mode and test a real consenting visit.
7. If enabling hCaptcha, test sign-in in another browser before logging out of
   the current administrator session.
