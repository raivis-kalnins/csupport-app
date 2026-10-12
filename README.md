# ClientWatch 1.2.4 — dashboard-only update

This release updates the ClientWatch dashboard only. Existing WordPress Connector **1.2.2** installations and existing PHP/Node helpers remain compatible and do not need to be replaced.

## Upgrade

1. Back up the current `csupport.63.lv` dashboard folder and `clientwatch-private`.
2. Replace the dashboard application files with this package.
3. Keep the existing `config.php` unchanged.
4. Do **not** delete or replace `clientwatch-private`.
5. Do **not** reinstall the WordPress connector; keep 1.2.2 on client sites.
6. Reload ClientWatch. The database adds the new diagnostic columns automatically.

Your sites, login, statistics, errors, WordPress reports, hCaptcha settings, SEO history and cron settings are preserved.

## Statistics / tracker fixes

ClientWatch now accepts the safe `www` / non-`www` form of the same configured origin when the scheme and effective port are the same. The accepted origin is remembered automatically. This fixes a common case where WordPress backend reports work but frontend browser events are rejected.

The dashboard also stores the latest public collection failure for each website, such as an invalid public key or rejected origin. Open a website → **Overview** to see the result.

A new **Diagnose tracking** button performs a server-side homepage probe and reports whether ClientWatch can find:

- a ClientWatch tracker reference;
- the expected Site ID;
- the expected public tracking key;
- the dashboard endpoint;
- the reported/embedded consent mode.

If the tracker is found but visits remain at zero, the dashboard explains that consent, Do Not Track / Global Privacy Control, CSP, script-delay optimisation or a browser blocker are the likely remaining causes. It does not invent visits when no browser event was received.

Only the public tracking key is inspected in webpage HTML. The backend secret remains server-side.

## H1 / SEO fixes

The lightweight SEO checker now:

- collapses duplicate H1 text;
- evaluates multilingual H1 headings per language;
- no longer discards an H1 solely because its `lang` differs from the document language;
- ignores H1 content inside `template`; `noscript` fallback headings remain eligible so lightweight crawls do not falsely report a missing H1;
- reports both the effective H1 count and the raw number of H1 tags in source.

This reduces false “No H1” and false “multiple H1” warnings on multilingual/responsive sites.

## Existing client packages

The dashboard still contains links to:

- `downloads/clientwatch-connector-1.2.2.zip`
- `downloads/clientwatch-integrations-1.2.2.zip`

These are intentionally unchanged in this dashboard-only release.

## Requirements

PHP 8.1+, PDO SQLite and HTTPS. PHP cURL is required for uptime, SEO and tracker homepage probes. DOM improves HTML parsing where available.

### 1.2.4 dashboard-only diagnostics

- Records the last browser collection attempt/status so zero-traffic sites no longer fail silently.
- Adds CSP-aware tracker diagnostics and clearer Tracking active / ready / blocked badges.
- Detects strong public technology signals (WordPress, React/JavaScript and PHP when exposed by a response header).
- If a site was accidentally configured as WordPress but has never sent a WordPress backend report and strong non-WordPress signals are detected, WordPress-only UI is suppressed automatically.
- A genuine WordPress backend report remains authoritative, even when public WordPress signatures are hidden.
- No client/connector update is required for this dashboard release. Existing Connector 1.2.2 remains supported.
