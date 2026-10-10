# ClientWatch 1.0.0 — development verification

Build date: 9 October 2026.

## Completed checks

- PHP 8.4.24 syntax validation for all ten PHP source files.
- Node.js syntax validation for the dashboard JavaScript, both copies of the
  frontend tracker and the optional Node helper.
- **35 HTTP API assertions:** unauthenticated access, owner installation key,
  CSRF checks, safe website URLs, allowed origins, tracking keys, request size,
  pageview retry deduplication, session/visitor counts, period-based new/returning
  cohorts, multi-tab live presence, query stripping, error redaction and reopening,
  error retry deduplication, HMAC validation, timestamp expiry, nonce replay
  protection, update totals, fresh-check requests and private secret rotation.
- **18 browser/UI/tracker harness assertions:** HTML escaping, modal and WordPress
  panels, masked secrets, dark mode, mobile overflow, mobile settings access,
  no uncaught dashboard JS errors, no pre-consent collection/storage, consent
  activation, pageview delivery, simulated SPA navigation, browser-error delivery,
  consent withdrawal and Do Not Track handling.
- **7 WordPress API harness assertions:** signed report accepted by the HTTP
  receiver, queue acknowledgement, fresh-check metadata, local tracker enqueue,
  consent attributes, secret exclusion from frontend markup and admin capability.
- **21 uptime/network harness assertions:** private, loopback, multicast and
  reserved address rejection; public target allowance; DNS pinning settings;
  TLS verification and redirects disabled; HTTP status classification; two-failure
  confirmation; downtime/recovery notifications; bounded GET fallback.
- The standalone PHP helper sent a heartbeat through the real local HTTP endpoint.
  The Node helper sent a heartbeat and a signed error through that endpoint.

## Test environment limitations — important

The environment provided PHP but not its **PDO SQLite** or **cURL** extensions.
For API functional tests a TEST-ONLY PDO-compatible transport executed SQL against
Python's real SQLite engine. Database query results and HTTP application behaviour
were exercised, but the native PHP PDO SQLite extension itself was not exercised.
That adapter is NOT included or required in the released app: production uses
normal PHP PDO SQLite and requires the extension to be enabled.

Managed Chromium blocked URL navigation. UI/JavaScript tests rendered the actual
HTML/CSS/JS locally in Chromium with test bindings for HTTP transport, navigation
and visitor storage. The bindings called the actual local HTTP receiver where
relevant. Native cross-origin browser transport, real localStorage persistence,
CSP configurations and client cache/optimization plugins still require a normal
browser test on your deployed HTTPS site. No browser security policy was disabled.

A live WordPress installation was unavailable. The WordPress test supplied
WordPress API fixtures, not a genuine WordPress runtime. Real plugin activation,
cron scheduling, premium updaters, multisite and actual fatal handling need a
staging test before broad client rollout.

External HTTP probes and TLS sessions were not performed. The uptime checks used
transport fixtures to test classification and cURL options. PHP mail(), real email
delivery, production concurrency/load, host filesystem permissions and web-server
rules were not validated on your hosting account.

## Fixed during checks

The small-screen table's hidden accessibility label could extend page width.
The final CSS contains the label inside the scrollable table, and the mobile
width check passed after that correction. Reserved/multicast network targets
were also tightened, and backend error retry receipts were added.

## First deployment verification

1. Enable the correct PHP extensions and finish setup on HTTPS with private storage.
2. Connect one staging website and grant analytics consent in a test browser.
3. Verify a pageview, navigation, browser error and consent withdrawal.
4. Activate the WordPress plugin on staging and send a backend report.
5. Run dashboard cron manually, confirm a real HTTP check, then schedule it.
6. Check a test alert through your host's actual mail transport before relying on it.

The downloadable preview screenshots use **fictional sample websites and data**.
No client websites have been connected or modified. No sample database, test
credentials, test transport or environment configuration is shipped in the ZIPs.
