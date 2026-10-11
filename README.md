# ClientWatch 1.1.0

A basic, self-hosted client website maintenance dashboard. Its entry point is
`index.php`; the `assets/` and `lib/` folders are required too. The WordPress
connector and PHP/Node integrations are separate, optional site-side components.

## What is included

- Responsive website table, search, filters, sorting, light/dark modes and modal details.
- Visits, pageviews, unique browser estimates, new/returning cohorts and live presence.
- Browser JavaScript/resource errors, deduplication, resolution and reopening.
- Signed WordPress backend reports: core, plugin/theme inventory, available updates,
  PHP version, WP_DEBUG/cron flags, update check timestamps and PHP fatal queue.
- A request-fresh-checks button. **No remote update installation or automatic repair.**
- Optional public HTTP uptime checks, two-failure downtime confirmation, history,
  recovery alerts, email batching and retention cleanup through a hosting cron.
- Copyable per-site tracking snippet, private credential rotation and PHP/Node helpers.
- One administrator account. No client logins, billing, backups or vulnerability scanner.

## Requirements

Use PHP 8.1 or newer with `PDO` and `pdo_sqlite` (SQLite 3.24+). PHP 8.4 is suitable.
Enable PHP `curl` for HTTP uptime checks. `openssl` and a valid CA certificate store
are needed for secure connections. No MySQL account, Composer, Node server or build
step is required to run the dashboard. The Node helper is optional for existing
Node backends; it is not needed for this app.

Use HTTPS, a private directory outside ALL public document roots, and permission
for PHP to write that directory. Host the dashboard on its own subdomain when
practical. It must be reachable by the client browsers and backend connectors.

## Install on cPanel / ordinary PHP hosting

1. Upload **all contents of the `clientwatch/` folder** to the dashboard's document
   root, or to a subdirectory such as `public_html/maintenance/`. Do not upload
   just index.php. Do not upload the WordPress plugin into this app folder manually;
   the supplied app ZIP already includes a downloadable copy under `downloads/`.
2. Rename `config.example.php` to `config.php` and edit its two values:

   ```php
   <?php
   return [
       'data_dir' => '/home/YOUR_ACTUAL_CPANEL_USER/clientwatch-private',
       'setup_key' => 'YOUR_OWN_RANDOM_PRIVATE_INSTALLATION_KEY_AT_LEAST_24_CHARS',
       'allow_http' => false,
   ];
   ```

   Use your real hosting account path. `clientwatch-private` must be OUTSIDE
   public_html, the dashboard's document root, and any other public subdomain root.
   Create it in File Manager if PHP cannot create it. Restrict directory permissions
   to your hosting account; do not use world-writable 0777. Store no private database
   inside the public app folder. The app refuses storage inside its detected root.
3. Visit `https://YOUR-DASHBOARD/index.php` (or the subdirectory equivalent). Enter
   your installation key, a username, a password of at least 14 characters and the
   exact public dashboard URL ending in `/index.php`.
4. Add a website. Its **Connect website** tab supplies the public tracking snippet
   and the separate private connector credentials.

The setup key is used only before the administrator is created. Keep config.php
private and remove/replace its installation key after setup if desired. There is
no default administrator password and no preloaded client traffic.

A reverse proxy must correctly expose HTTPS to PHP. The application deliberately
does not trust arbitrary client-supplied X-Forwarded-Proto headers. Configure this
at your trusted proxy/web server; do not disable TLS checks in production.

## Add a WordPress website

1. In ClientWatch add the exact homepage URL and choose **WordPress**.
2. Install **clientwatch-connector-1.1.0.zip** using WordPress Plugins > Add New >
   Upload Plugin. Activate it. Do not install the main dashboard ZIP as a plugin.
3. Open WordPress **Settings > ClientWatch**. Copy the dashboard URL, Site ID,
   public tracking key and private backend secret from the Connect website tab.
4. Save settings and click **Send report & refresh checks**. A cached report is
   sent immediately; fresh checks are queued for WP-Cron. Ensure WP-Cron actually runs.
5. Leave frontend tracking enabled in the connector and connect your analytics
   consent manager. Do not add a duplicate manual snippet.

Reports are scheduled every five minutes, but WordPress cron is normally triggered
by visits. Quiet sites need a real hosting scheduler to run WordPress cron reliably.
A typical command, adjusted to your host's executable and filesystem path, is:

```cron
*/5 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/wp-cron.php >/dev/null 2>&1
```

Bedrock sites typically have a different WordPress path, such as
`/home/ACCOUNT/project/web/wp/wp-cron.php`. Use the correct path for that site and
ensure its environment configuration is available in CLI. Do not set
DISABLE_WP_CRON until the replacement cron is confirmed to work.

WordPress installation or updates are never performed by ClientWatch. Open the
provided WP Updates link and use your normal staging/backup/update process.
Premium updates are visible only when the premium updater/licence supplies them.
“Unknown” means a report or cache is missing; “Clear” means no update was reported
in the latest available cache. Neither is a security certification.

Multisite does not auto-provision all subsites. Configure each intended site
explicitly; test network administration behaviour before rollout.

## Add another PHP, HTML or JavaScript website

Choose its platform, copy the generated script and add it ONCE to the common HTML
template. It can run in a page generated by PHP, WordPress, a JavaScript framework
or a static host. It observes history push/replace/popstate navigation and excludes
query strings and URL fragments by default. Hash-only routes are intentionally not
separate pages; use a custom pathname mapping and explicit pageview calls where needed.

The script cannot read server errors or CMS updates. For server reporting, use
`clientwatch-integrations-1.1.0.zip` and its README. Never put a backend secret into
browser JavaScript, public environment variables or query strings.

## Consent and visitor privacy

The default snippet and WordPress plugin do not send analytics or create visitor
storage until analytics consent is granted. From your consent manager's grant
callback, including when previously saved consent is restored, call:

```js
window.ClientWatchConsent = true;
window.ClientWatch?.consent(true);
```

On withdrawal:

```js
window.ClientWatchConsent = false;
window.ClientWatch?.consent(false);
```

The script sends a final presence-removal message on withdrawal, then clears its
visitor/session storage and stops collecting. The consent manager is responsible
for saving consent and notifying other open tabs. The tracker honours Do Not Track
and Global Privacy Control regardless of consent callbacks.

Only use `data-consent="granted"` on a manual snippet where you have already
established an appropriate basis and consent behaviour for that deployment. It is
not a substitute for consent management or legal/privacy review.

Random browser IDs use first-party localStorage after consent. IDs are scoped per
site; the dashboard hashes them with a server-side HMAC secret. The app does not
store raw client IP addresses (short-lived keyed hashes are used for rate limiting).
Your hosting access logs may independently record IP addresses.

Paths, filenames and error messages can still contain sensitive information even
after pattern-based redaction. Query strings, common secrets, email addresses and
some long identifiers are removed, but redaction is not perfect. Do not send form
contents, payment details, passwords or customer account data. Review paths/error
messages, access, retention and your privacy notice before enabling collection.

For custom path redaction define this before the tracker loads:

```js
window.ClientWatchPath = pathname =>
  pathname.startsWith('/account/') ? '/account/[private]' : pathname;
```

CSP sites must allow the dashboard origin in **script-src** (manual remote snippet)
and **connect-src** (all collection). The WordPress plugin serves its JS locally,
so it normally only needs the dashboard origin added to connect-src. Do not weaken
other directives or add unsafe-inline merely for ClientWatch.

WP Rocket/other performance plugins may delay tracker.js until interaction;
exclude `clientwatch-tracker` / `tracker.js` from delayed execution when appropriate.
Ad blockers, consent refusal, offline devices and disabled JavaScript reduce coverage.

## What the statistics mean

- **Pageviews:** accepted initial loads or tracked navigation events. Event IDs
  prevent duplicate ingestion. Query-only and hash-only changes do not add pages.
- **Visits:** distinct session IDs with a pageview during the selected rolling
  period. A new browser session ID is created after 30 minutes without tracked
  activity; visible-tab heartbeats count as activity.
- **Users:** distinct site-specific browser IDs with a pageview in the period,
  not logged-in accounts or verified people. Portfolio totals are sums across
  sites, not cross-site deduplicated people.
- **New:** those browser IDs first observed inside the selected period.
- **Returning (old):** those browser IDs first observed before the selected period.
  New and returning are disjoint for that period; a newly seen visitor remains in
  its new cohort even if it makes several visits during that same period.
- **Live:** distinct browser IDs with a visible-tab heartbeat in the previous
  90 seconds. Heartbeats are attempted every 30 seconds. A hidden tab does not
  remove another visible tab belonging to the same visitor.

Metrics begin after installation and consent. No previous GA4/WooCommerce figures
are imported. Browser storage clearing, multiple devices, privacy settings and
blocking change the estimates. Visitors expire after 180 days of inactivity;
returning identification is not permanent. Charts group the chosen rolling period
by UTC calendar day; timestamps in the UI use the viewing browser's locale.

## Uptime checks, cleanup and email alerts

In hosting Cron Jobs add this command with a five-minute schedule:

```cron
*/5 * * * * /usr/local/bin/php /FULL/PATH/TO/clientwatch/cron.php
```

In cPanel's five schedule fields enter `*/5`, `*`, `*`, `*`, `*`; in its separate
Command field put only `/usr/local/bin/php /FULL/PATH/TO/clientwatch/cron.php`.
The Settings screen shows your actual app path. Use the same PHP version and
extensions as the web app. The cron file is CLI-only, not a public web endpoint.

The job checks up to 30 due websites per run (oldest first), removes expired data
and sends pending emails. This basic checker is sequential. With more than 30
monitored sites or many slow endpoints, each site's interval can exceed five
minutes; schedule more frequently or use a dedicated uptime service for scale.
It does not claim a monitoring SLA or comprehensive availability percentage.

HTTP checks: verify TLS; use public DNS only; pin the resolved address; block
private/reserved targets; allow only ports 80/443; do not follow redirects; try HEAD
first and a bounded GET when HEAD is unsupported. A 2xx/3xx response means the
configured URL responded, NOT that checkout/login/all pages work. 401/403/429 are
shown as protected/rate-limited rather than definitely down. Two consecutive
failed checks mark a site down. No checks for 15 minutes is shown as stale.

A backend heartbeat is separate from HTTP uptime. WordPress reports older than
20 minutes are flagged. A frontend tracker with no recent visitor does NOT prove
that a website is down.

Email is optional. In Settings configure a recipient and a legitimate sending
address on your own domain. New server errors, confirmed downtime and recovery
are batched at most once per 30 minutes. Browser-error emails are opt-in. The
hosting PHP mail() transport must work; SMTP settings are not built into this
first version. “Accepted by transport” does not prove inbox delivery. Monitor the
mail status and use your hosting mail configuration/SPF/DKIM settings.

## Limits and maintenance

This is a small single-admin, SQLite-based monitor, not a hosted analytics platform.
It allows up to 200 registered sites, but the sequential HTTP checker and shared
hosting capacity determine practical throughput. The app queries statistics for
the selected window and retains event-level pageviews. Start with a few sites and
watch disk use, request latency and cron runtime. There is no high-volume/SLA test.

The public tracking key is public by design. Origin checks and rate limits help
reduce accidental or abusive ingestion but cannot prove that a visitor is a human
or prevent all forged analytics. Backend reports require HMAC, a fresh timestamp
and a unique nonce. Keep clocks synchronised. No WordPress admin password is needed.

Cron enforces the selected 30/60/90/180-day event/error retention. It retains
HTTP checks for 30 days, event-retry receipts for seven days and visitor IDs for
180 days of inactivity. Without cron, cleanup and email do not run. No files are
silently removed from client websites. Deleting a dashboard site removes its
stored dashboard history and credentials only; remove its remote snippet/plugin
separately. Plugin deactivation stops future WordPress reports.

Protect the dashboard with HTTPS and hosting access controls where appropriate.
If adding Basic Auth/IP restrictions, exempt only the public tracker asset and
collection/signed-report routes as required; otherwise client sites cannot report.
Never expose the private data directory. Back it up using your hosting tools. For
a filesystem backup stop writers or use SQLite's online backup facility; blindly
copying a live .sqlite file alone can miss data in its WAL file.

To update this app, replace its code/assets, keeping config.php and the private
storage directory. There are no changes to existing client themes or databases.

### Administrator password recovery

From a trusted hosting terminal run:

```sh
php /FULL/PATH/TO/clientwatch/reset-password.php
```

Enter a new password when prompted. Terminal input may be visible. Do not pass
passwords as command-line arguments or put them in shared shell history. This
CLI-only tool changes the password and invalidates existing dashboard sessions.

### Web server notes

Apache .htaccess files disable directory listings and block direct configuration,
cron and private-library access. On Nginx configure equivalent restrictions for
`config.php`, `config.example.php`, `cron.php`, `reset-password.php`, `README.md` and
`/lib/`, while still serving index.php, assets and downloadable ZIPs. Storage is
required outside the public document root regardless of web server.

### Testing scope

See TEST-REPORT.md in the complete kit. Source checks and local functional/browser
tests are included in the development verification; real client hosting, live
WordPress installation, real email delivery and production load need deployment
validation. This package is not installed on any of your client websites yet.

## 1.1.0 upgrade notes

Version 1.1.0 keeps the existing SQLite database and website IDs. Replace the
ClientWatch application files but keep your existing `config.php` and private
`clientwatch-private` directory. No database migration is required.

New in 1.1.0:

- Optional hCaptcha protection for the administrator sign-in form.
- WordPress connector tracking mode: **Consent required** (default) or
  **Immediate tracking** for sites where your privacy configuration permits it.
- Clearer frontend-tracker status so zero traffic can be distinguished from a
  backend-only WordPress connection.
- WordPress plugin totals, active/inactive counts, update counts, active theme,
  database version, memory limits, object-cache status and selected PHP
  extension/runtime information.
- PHP and Node backend helpers now send a small technology/runtime health report.

After replacing the WordPress connector, open **Settings > ClientWatch**, save
its settings, choose the appropriate tracking mode, and click **Send report &
refresh checks**. Existing connection values are retained by WordPress.
