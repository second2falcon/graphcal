<!--
SPDX-FileCopyrightText: 2026 GraphCal contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# GraphCal security review, 2026-11-14

**Reviewer:** Claude Code (Claude Fable 5.2)
**Commit reviewed:** `c8f9a8c` (release 0.1.4)
**Scope:** everything tracked in the repository: `lib/`, `templates/`, `js/`, `appinfo/`, `scripts/`, the CI and release workflows, the test harness and the dependency metadata.

**Method:** manual read of every source file. Three framework behaviours the findings depend on were checked against the Nextcloud server source (`stable33`): `SecurityMiddleware`, `ExceptionSerializer` and `CalDavBackend::deleteCalendar`. `composer audit --locked` was run and reported no advisories.

**Not done:** the app was not run, and no dynamic testing or tenant testing took place. The deployed server, the Entra app registration and the GitHub repository settings are outside this review.

## Summary

No critical or high-severity issue was found. One medium issue should be fixed: OAuth credentials can end up in `nextcloud.log`. The rest are low-severity or hardening items. The fixes from the review of 2026-10-21 (`SECURITY_REVIEW.md`) are all still in place.

| # | Severity | Finding |
|---|---|---|
| 1 | Medium | Refresh tokens and authorization codes are written to the log in exception traces |
| 2 | Low | Another site can start the Microsoft sign-in in a user's browser |
| 3 | Low | People with write access to a shared mirrored calendar act as the owner in Exchange |
| 4 | Low | Disabled Nextcloud users keep syncing |
| 5 | Low | The OAuth callback shows text from the URL on a Nextcloud page |
| 6 | Low | Raw exception messages are returned to the browser |
| 7 | Hardening | Smaller items (admin settings, webhook, HTTP redirects, release workflow, CI inputs) |

## Findings

### 1. [Medium] Refresh tokens and authorization codes are written to the log in exception traces

`Microsoft::tokenRequest(array $params)` receives the refresh token, or the authorization code and PKCE verifier, as an argument. When it fails, the exception's stack trace holds that argument, and Nextcloud logs traces with their arguments.

The failure happens in two ways:

- The token endpoint answers with an error and `tokenRequest()` throws (`lib/Service/Microsoft.php:207-209`).
- The HTTP client throws because of a timeout or DNS failure.

Three places log the exception with its trace:

- Nextcloud's job runner, for `DeltaJob` and `PushJob`.
- `Deferred::run()` (`lib/Service/Deferred.php:44`).
- `OauthController::callback()` (`lib/Controller/OauthController.php:70`). Here the frame of `finishAuthorization($userId, $code, $verifier)` carries the code and verifier too.

Nextcloud's `ExceptionSerializer` only hides the arguments of a fixed list of methods. `OC\Http\Client\Client::post` is on that list; `tokenRequest`, `finishAuthorization` and `storeTokens` are not. I confirmed the trace shape with PHP 8.3: the first frame is `tokenRequest` with the full `$params` array.

**Likely trigger:** the Entra client secret expires. Every sync then fails at the token request, and each connected user's still-valid refresh token is logged every five minutes.

**Limits on the impact:**

- Redeeming a refresh token or code also needs the client secret. Guzzle's internal frames carry the request options, including `client_secret`, and I did not verify whether those are fully redacted.
- Servers with `zend.exception_ignore_args = On` (the `php.ini-production` default) log no arguments. PHP's built-in default is `Off`, which is what you get when no production `php.ini` is in use.
- The attacker needs read access to `nextcloud.log`, which often also reaches log collectors and support bundles.

**Fix:** mark the parameters with PHP 8.2's `#[\SensitiveParameter]`. PHP then replaces them in every trace, whatever the logger does.

- `tokenRequest(#[\SensitiveParameter] array $params)`
- `finishAuthorization(string $userId, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier)`
- `storeTokens(string $userId, #[\SensitiveParameter] array $tokens)`

Also wrap the HTTP call in `tokenRequest()` and rethrow a new exception without `previous`, so Guzzle's frames (with `client_secret`) never reach the log.

### 2. [Low] Another site can start the Microsoft sign-in in a user's browser

`OauthController::start()` is a `GET` route with `#[NoCSRFRequired]` (`lib/Controller/OauthController.php:39-50`). For such routes `SecurityMiddleware` skips both the CSRF token and the strict same-site cookie check. A top-level navigation from any site therefore starts the flow with the user's session.

If the user has a Microsoft session and the tenant admin has granted consent, Microsoft redirects straight back. The callback then stores tokens and mirrors the calendars, without the user having asked for it.

The attacker gets no data and cannot link their own Microsoft account: `state` and the PKCE verifier are bound to the victim's session. The harm is that the user's M365 calendar and a `Calendars.ReadWrite` refresh token land on the Nextcloud server without the user's intent.

**Fix:** add `#[StrictCookiesRequired]` to `start()`. The popup is opened from the settings page on the same site, so it still works. Optionally add `prompt=select_account` to the authorize request, so Microsoft always shows a screen.

### 3. [Low] People with write access to a shared mirrored calendar act as the owner in Exchange

`Listener::queue()` and `SyncService::push()` push every change in a mirrored calendar to Graph with the calendar owner's token (`lib/Listener/Listener.php:49-54`, `lib/Service/SyncService.php:356-426`). They do not look at who made the change.

If the owner shares the mirrored calendar with edit rights in Nextcloud, the sharee can create, change and delete events in the owner's Exchange mailbox. The sharee can also create meetings, and Exchange sends those invitations from the owner's address to any recipient.

This follows from the owner's own sharing decision, and it resembles how Nextcloud sends invitations for shared calendars. It is not documented, though, and it reaches further than a Nextcloud-only share.

**Fix:** at least document it in the README under "Behaviour and limits". A stricter option is to refuse writes by anyone but the owner in `SchedulingGuard`.

### 4. [Low] Disabled Nextcloud users keep syncing

`PollJob` queues a pull for every user that has a refresh token (`lib/BackgroundJob/PollJob.php:36-46`). `pull()` and the webhook do not check whether the Nextcloud account is enabled either.

After an admin disables an account, GraphCal keeps refreshing that person's Microsoft tokens and keeps copying their M365 calendar to the server. This lasts until the account is deleted or the refresh token dies.

**Fix:** skip users for whom `IUserManager::get($uid)?->isEnabled()` is not true, in `PollJob::run()` and at the top of `SyncService::pull()`.

### 5. [Low] The OAuth callback shows text from the URL on a Nextcloud page

`callback()` renders `error_description` from the query string before it checks whether a sign-in was pending (`lib/Controller/OauthController.php:59-61`). The value is HTML-escaped, so this is not XSS.

A crafted link to `/apps/graphcal/oauth/callback?error=x&error_description=…` shows a logged-in user any text the attacker chooses, on a genuine Nextcloud page under "Could not connect". That is useful for phishing ("your password has expired, call …").

**Fix:** only show Microsoft's error text when a pending `graphcal_oauth` session entry exists and `state` matches. Otherwise show the fixed "expired or did not match" message.

### 6. [Low] Raw exception messages are returned to the browser

Two places pass `$e->getMessage()` of any `Throwable` to the user:

- `SettingsController::run()` (`lib/Controller/SettingsController.php:110-117`)
- `OauthController::callback()` (`lib/Controller/OauthController.php:71`)

For Graph and token errors that is the intent. For anything else, such as a database or type error, internal details like table names, paths or SQL fragments reach an ordinary user.

**Fix:** pass through the messages of `GraphError`, `ReauthRequired` and `InvalidArgumentException`. Log everything else and return a generic message.

### 7. Hardening

- **Admin settings without password confirmation.** `saveAdmin()` and `importFromMail()` change the OAuth client and its secret (`lib/Controller/SettingsController.php:41-66`). They are admin-only and CSRF-protected. Adding `#[PasswordConfirmationRequired]` matches what Nextcloud does for comparable settings.
- **Webhook without rate limit.** `WebhookController::notify()` is public (`lib/Controller/WebhookController.php:34-59`). The 64-character `clientState` cannot be guessed, and a request costs one config read. An `#[AnonRateLimit]` would still cap abuse cheaply.
- **HTTP redirects are followed.** `Microsoft::graph()` and `tokenRequest()` use the client's default, which follows redirects. Guzzle drops the `Authorization` header on a cross-origin redirect, so the bearer token is safe today. Neither endpoint needs redirects, and `'allow_redirects' => false` removes the dependency on that behaviour.
- **Tokens are not revoked at Microsoft on disconnect.** `forget()` deletes the Graph subscription and the local tokens. The refresh token itself stays valid at Microsoft until it expires. It is unusable without the client secret, and the README's "deletes the tokens" is accurate for the local copy.
- **Signing key visible to the whole release job.** `APP_PRIVATE_KEY` is set as job-level `env` (`.github/workflows/release.yml:45-46`), so every step and action in the job can read it. Pass it only to the "Get the signing key" step, and use a separate boolean for the `if:` conditions.
- **Mutable inputs in CI.** None of these jobs holds secrets, and all run with `contents: read`, so the exposure is limited to CI results.
  - `info.xsd` is fetched from the App Store's `master` branch (`.github/workflows/ci.yml:58`).
  - Nextcloud server is checked out at `stable33`–`stable35` branch heads.
  - `nextcloud/all-in-one` is checked out at its default branch.
  - Playwright is installed without a lockfile and with install scripts enabled.

## Checked and found sound

- **OAuth flow.** Authorization code with PKCE S256. `state` is random, session-bound, single-use and compared with `hash_equals()`. The classic login-CSRF attack, linking the attacker's Microsoft account to a victim, is not possible.
- **Token storage.** Access token, refresh token, webhook secret and client secret are stored with Nextcloud's sensitive flag, which encrypts them.
- **Token destination.** `Microsoft::graph()` refuses any URL outside the configured Graph origin. Stored delta links are re-checked before use. Every path segment that comes from a user or from Graph goes through `rawurlencode()` or `http_build_query()`.
- **Endpoint configuration.** `graph_url` and `login_url` have no web endpoint; they can only be set with `occ` or by an admin.
- **Paging.** Bounded by `MAX_PAGES` and loop detection.
- **Authorization.** Personal routes act on the session user only. `setEnabled()` checks that the calendar row belongs to the caller. Admin routes are admin-only by framework default. "Add calendar" uses the caller's own Graph token, so Graph enforces access.
- **Webhook.** `clientState` is compared in constant time against a 64-character per-user secret. Unknown users and missing secrets get 403. The `validationToken` echo is `text/plain`.
- **Database.** All queries go through the query builder with bound parameters. The dynamic column names in `Store::updateCalendar()` come from internal callers only.
- **Output.** Templates use `p()`. The scripts use `textContent` and DOM nodes, never HTML strings. `postMessage` is sent to, and accepted from, the own origin only. `calendar-errors.js` parses server text in an inert document and displays it as text.
- **iCalendar.** Graph data is written through sabre/vobject, which escapes values. Object URIs are reduced to `[A-Za-z0-9_-]`. The calendar colour is validated with a regular expression.
- **Disconnect.** `CalDavBackend::deleteCalendar()` emits no per-object events, so removing a mirrored calendar cannot be pushed to Exchange as deletions.
- **Release.** Actions and the signing server checkout are pinned to commit SHAs. Tags are only released from `main` after the full CI run. The app certificate is checked against the private key. Workflow shell steps take ref names from environment variables, not from `${{ }}` interpolation. The archive contains only runtime folders.
- **Dependencies.** The app ships no Composer or npm packages. `composer audit --locked` on the dev dependencies found nothing.

## Not verified

- That the pinned action SHAs match the versions named in their comments.
- Whether Guzzle's internal stack frames expose `client_secret` in logged traces (see finding 1).
- Behaviour against a real tenant or a running server; the findings come from reading the code.

## Resolution

Findings 1 to 6 and four of the six hardening items were fixed on 2026-11-14; two hardening items were left unchanged on purpose (see below). Each fix has a test that fails without it. The end-to-end tests were run against Nextcloud `stable33`, and the password dialog was tried in a browser.

| Finding | Change |
|---|---|
| 1. Credentials in logged traces | The parameters that carry codes and tokens are marked `#[\SensitiveParameter]`. Failures of the HTTP client are rethrown without the original exception, so its stack frames (client secret, access token) are never logged. Unit test: `tests/unit/MicrosoftTest.php`. |
| 2. Sign-in started by another site | `OauthController::start()` requires Nextcloud's strict same-site cookie. |
| 3. Sharees act as the owner | `SchedulingGuard` refuses every create, change, delete and move in a synced calendar by anyone but its owner. This covers people the calendar is shared with and Nextcloud delegates. They can still read it. Documented in the README. |
| 4. Disabled users keep syncing | `PollJob` and `SyncService::pull()` skip accounts that are not enabled. |
| 5. Text from the URL on the callback page | The callback checks the pending sign-in and `state` before it shows anything from the URL. |
| 6. Raw exception messages | Users see Microsoft's answers and input errors only (`Microsoft::userMessage()`); everything else is logged and shown as a general message. |
| 7. Admin settings | `saveAdmin()` and `importFromMail()` require password confirmation; the settings page opens Nextcloud's dialog. |
| 7. HTTP redirects | Graph and token requests no longer follow redirects. |
| 7. Signing key | The release job only exposes whether a key exists; the key itself goes to the one step that writes it to a file. |
| 7. CI inputs | The Playwright install runs with `--ignore-scripts`. |

Left unchanged, on purpose:

- **Webhook rate limit.** Nextcloud limits per IP address, and Microsoft delivers every user's notifications from a few addresses. A limit would throttle genuine notifications, and Graph drops subscriptions whose endpoint answers slowly. The 64-character secret already makes guessing infeasible.
- **Revoking tokens at Microsoft.** Entra ID offers no call that revokes only this app's refresh token; the available one signs the user out of every app. The README now says the tokens are deleted from Nextcloud.
- **`info.xsd` and the Nextcloud branch heads in CI.** These jobs exist to test against the current upstream state, and they hold no secrets.
