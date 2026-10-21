# GraphCal security review

**Review date:** 2026-10-21  
**Scope:** Repository source, app configuration, CI/release workflows, and checked-in dependency metadata.  
**Method:** Manual static review plus live Packagist and npm advisory database queries. Tests and dynamic scanners were not run.

## Findings

### [P2] Graph pagination can send a user's bearer token to an arbitrary host

`Microsoft::graph()` accepts any absolute `http://` or `https://` path and attaches the account's Graph access token to the request. The absolute URL can come from Graph's `@odata.nextLink` / `@odata.deltaLink`; delta links are also persisted and reused. If an upstream response is compromised or otherwise manipulated to supply an attacker-controlled URL, the next request sends the user's bearer token to that host. The `http://` branch also permits cleartext if such a link is supplied.

**Locations:** `lib/Service/Microsoft.php:127-143`, `lib/Service/Microsoft.php:161-166`, `lib/Service/SyncService.php:243-248`, `lib/Service/SyncService.php:271`.

**Recommendation:** Before attaching authorization, parse and validate absolute pagination URLs against the configured Microsoft Graph origin (including explicitly supported sovereign cloud origins) and require HTTPS. Reject URLs with unexpected userinfo, ports, or hostnames. Keep the Graph base URL itself restricted to administrator-controlled HTTPS origins.

### [P2] Pagination has no cycle, page-count, or total-size bounds

`graphAll()` keeps following `@odata.nextLink` until the response omits it and accumulates every item in memory. Calendar delta does the same in a `while (true)` loop and builds an unbounded master-event set. A looping or extremely large upstream response can keep a request/background job busy indefinitely, make repeated outbound requests, and exhaust worker memory. This is reachable through the sync path whenever the upstream endpoint behaves unexpectedly or is compromised.

**Locations:** `lib/Service/Microsoft.php:160-168`, `lib/Service/SyncService.php:224-249`.

**Recommendation:** Track visited links, impose per-sync page/item/time limits, and fail safely while retaining the previous delta cursor when a limit is reached.

### [P2] Removed or revoked source calendars can leave event copies behind

`refreshCalendars()` only upserts calendars returned by Graph; it does not reconcile stored calendars that disappeared from the response. Pull failures other than a small set of delta-reset conditions are rethrown without disabling the calendar or deleting its Nextcloud copy. If a shared calendar is unshared, or a source calendar is removed, the mirrored calendar and its event data can therefore remain available in Nextcloud after access at the source has been revoked.

**Locations:** `lib/Service/SyncService.php:63-75`, `lib/Service/SyncService.php:195-207`.

**Recommendation:** Reconcile the locally stored calendar list after a successful refresh, and define an explicit cleanup/disable policy for source deletion and permission-revocation responses. Avoid deleting data on transient Graph failures.

## Security hardening observations

### [P3] Release workflows depend on mutable GitHub Action tags

CI and release workflows refer to actions by major-version tags (for example, `actions/checkout@v5`). A compromised action publisher or moved tag could change code executed in CI. The impact is greatest for jobs that have release or signing credentials in their environment. This is a supply-chain hardening risk rather than an identified exploit in the app code.

**Locations:** `.github/workflows/ci.yml`, `.github/workflows/release.yml`.

**Recommendation:** Pin actions to reviewed full commit SHAs and automate reviewed SHA updates. Protect release tags and require approval for the secret-bearing release environment.

## Reviewed controls

- OAuth authorization uses session-bound state, `hash_equals()`, PKCE S256, and a one-time pending session value (`lib/Controller/OauthController.php`, `lib/Service/Microsoft.php`).
- Access and refresh tokens and the webhook client state are marked sensitive in Nextcloud configuration (`lib/Service/Microsoft.php`, `lib/Service/SyncService.php`).
- Personal settings routes are authenticated by default, and calendar IDs are checked against the current user's ownership before toggling (`lib/Controller/SettingsController.php`, `lib/Service/SyncService.php:86-92`).
- The webhook validates a 64-character random client state before queueing a sync; invalid notifications are forbidden (`lib/Controller/WebhookController.php`, `lib/Service/SyncService.php:462-480`).
- HTML output uses Nextcloud's escaping helper, and client-side dynamic values are assigned with `textContent` rather than HTML injection (`templates/*.php`, `js/*.js`).
- Database values are passed through Nextcloud's query builder parameters (`lib/Db/Store.php`).

## Dependency advisory check

Queried Packagist's security-advisories API for all package names in `composer.lock`, and npm's bulk advisory API for `playwright@1.56.1` from `tests/integration/package.json`.

- **No locked Composer package matched an affected version.** Packagist returned PHPUnit advisories, but the locked `phpunit/phpunit` version is `11.5.57`; the relevant 11.x affected range ends before `11.5.50`. Other queried packages had no advisories returned.
- **No advisory was returned for `playwright@1.56.1`.** The npm project has no lockfile, so its transitive dependency versions are not fixed by this repository and were not included in the query. CI installs with `npm install --no-package-lock`, so dependency resolution can change between runs.

These are point-in-time database results; they do not establish that a package is free of undisclosed or newly published vulnerabilities.

## Review limits

This review covers the checked-in repository, not the deployed Nextcloud server, reverse proxy, Microsoft tenant, app-store account, or live GitHub repository settings. No tests, integration runs, or dynamic security scans were performed.

## Resolution

All findings were addressed on 2026-10-21. The integration tests cover each P2 fix, and fail without it.

| Finding | Change |
|---|---|
| [P2] Bearer token to an arbitrary host | `Microsoft::graph()` refuses any absolute URL that doesn't start with the configured Graph origin plus `/` (same scheme, host and port, no user info), so stored or returned next/delta links can't take the token elsewhere. A stored delta link on another origin is discarded and the sync starts fresh. The Graph base URL stays an `occ`-only app setting (`graph_url`, for tests and sovereign clouds). |
| [P2] Unbounded pagination | `Microsoft::follow()` stops at a repeated link or after `MAX_PAGES` (2000) pages, in both `graphAll()` and the calendar delta loop. The sync fails without saving, so the previous delta cursor is kept. |
| [P2] Removed or revoked calendars | `refreshCalendars()` removes the copy of any listed calendar missing from a complete `/me/calendars` response, and it now also runs from the pull at most hourly. A 403/404 on a calendar's delta is confirmed with a `GET` on the calendar itself before its copy is removed; other errors change nothing. |
| [P3] Mutable action tags | All actions are pinned to full commit SHAs (version in a comment), and `.github/dependabot.yml` proposes updates as pull requests. The release job's Nextcloud server checkout (used to sign the app) is pinned to the v35.0.2 commit. Protecting `v*` tags and requiring reviewers on the `appstore` environment are repository settings for the owner. |

On the npm note: `tests/integration/package.json` pins `playwright` exactly, and it pins its only dependency (`playwright-core`) exactly, so the test tree doesn't drift without a lockfile.

