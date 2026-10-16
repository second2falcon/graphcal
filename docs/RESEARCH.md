# Research: Exchange Online calendar sync app for Nextcloud 32+

**Researched on 2026-10-16** by five parallel research agents. They covered:

1. Requirements for apps on Nextcloud 32 and newer
2. How the Microsoft OAuth integration in Nextcloud Mail ("Groupware") works
3. CalDAV scheduling, and how to make Exchange send invitations instead of Nextcloud
4. Two-way calendar sync through Microsoft Graph
5. Licensing and publishing on the App Store

**How sources were read.** The research environment's proxy blocked docs.nextcloud.com, learn.microsoft.com, nextcloud.com and apps.nextcloud.com. Claims were therefore checked against the source repos those sites are built from, cloned at HEAD on 2026-10-16:

- `nextcloud/server` at `stable32`, `stable33`, `stable34`, `stable35` and `master`
- `nextcloud/mail`, `nextcloud/calendar`, `nextcloud/documentation`, `nextcloud/appstore`, `nextcloud/app_template`, `nextcloud/.github`, `nextcloud/app-certificate-requests`
- `microsoftgraph/microsoft-graph-docs-contrib` and `MicrosoftDocs/entra-docs`

Unless noted, line numbers refer to `stable32`. Items marked **[UNVERIFIED]** need a manual check.

---

## 0. Summary of the findings

| Question | Answer |
|---|---|
| Can we reuse Nextcloud Mail's Microsoft tokens? | **No.** Mail's tokens only cover the Exchange Online resource (`outlook.office.com`, IMAP/SMTP scopes), and Mail has no public API. Use the **same mechanism** instead: the admin's own Entra app registration plus a per-user OAuth2 authorization-code flow. Our app runs its own consent for the Graph scopes and keeps its own tokens. The Entra app registration can be shared with Mail. |
| Which Microsoft API? | **Microsoft Graph**, v1.0 `calendarView/delta`. EWS in Exchange Online is being shut down: disablement began 2026-10-01 and it is turned off for good on 2027-04-01. Version 1 supports Exchange Online only. |
| How does Exchange end up sending the invites? | Push events that have attendees to Graph (`POST`/`PATCH`/`DELETE /me/events`), and Exchange sends the meeting requests. In Nextcloud, a small Sabre plugin turns scheduling off **for synced calendars only**. It sets the `Schedule-Reply: F` and `x-nc-scheduling: false` request headers. |
| Is Nextcloud 32 still supported? | **NC 32 reached end of life in 2026-09** (its last release was 32.0.15). Supporting 32+ is still possible. `min-version="32"` requires the code to stay compatible with PHP 8.1. |
| License | **AGPL-3.0-or-later.** It is required by the App Store rules, matches the server, and `@nextcloud/vue` is AGPL anyway. |
| App id | The repo name contains hyphens, so it **cannot be the app id** (the store requires `^[a-z][a-z0-9_]*[a-z0-9]$`, max. 32 characters). The name must also not contain "Nextcloud", and it should not contain Microsoft trademarks. |

---

## 1. Nextcloud 32+ app requirements

### 1.1 Release and PHP matrix

Sources: `lib/versioncheck.php` per branch, and the [server wiki](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule).

| NC | Name | Released | EOL | PHP |
|---|---|---|---|---|
| 32 | Hub 25 Autumn | 2025-09-27 | **2026-09 (EOL)** | 8.1 – 8.4 |
| 33 | Hub 26 Winter | 2026-02-17 | 2027-02-17 | 8.2 – 8.5 |
| 34 | Hub 26 Spring | 2026-06-08 | 2027-06-08 | 8.2 – 8.5 |
| 35 | Hub 26 Summer | 2026-09-15 | 2027-09-15 | 8.3 – 8.5 |
| 36 | Hub 27 Winter | planned 2027-02-17 | – | ≥ 8.3 |

`info.xml` dependencies:

```xml
<dependencies>
    <php min-version="8.1" max-version="8.5"/>
    <nextcloud min-version="32" max-version="35"/>
</dependencies>
```

- Both attributes on `<nextcloud>` are required by the XSD.
- The App Store accepts a `max-version` of at most "latest release + 1", which is currently 36. Raise it once the app has been tested on that version.
- PHP and library dependencies are only checked at install time. The code must therefore run on the whole PHP range: no PHP 8.2+ syntax while 32 is supported.
- **Decision to make:** if NC 32 support is dropped, set `min-version="33"` and PHP `8.2`.

### 1.2 Structure

Based on [nextcloud/app_template](https://github.com/nextcloud/app_template), HEAD 2026-08-24.

- **`appinfo/info.xml`.** The XSD is an `xs:sequence`, so **element order matters**. Required elements: `id`, `name`, `summary`, `description`, `version`, `licence`, `author`, `category`, `bugs`, `dependencies`. Optional elements in the order this app needs them: `namespace`, `website`, `repository`, `screenshot`, `background-jobs`, `repair-steps`, `settings`. Use categories `integration` and `organization`.
- **`lib/AppInfo/Application.php`.** `extends App implements IBootstrap`, with `register()` and `boot()`. `app.php` and `App::registerRoutes` were removed in NC 32.
- **Autoloading.** Composer PSR-4, `"OCA\\<Namespace>\\": "lib/"`. Set `<namespace>` explicitly when the app id contains `_`.
- **Migrations.** `lib/Migration/VersionXXXXDate….php` with `SimpleMigrationStep` and `ISchemaWrapper`.
- **Routes.** Use attribute routing (`#[FrontpageRoute]`, `#[ApiRoute]`, since 29); `routes.php` still works. The OAuth callback route needs `#[NoAdminRequired]`, `#[NoCSRFRequired]` and `#[UseSession]` (if it uses the session), and must validate `state`.
- **Settings.** `ISettings` and `IIconSection` registered in `info.xml` `<settings>`; declarative settings are also available (29+).
- **Frontend.** Vue 3, `@nextcloud/vue` 9.x (latest 9.13.1), vite with `@nextcloud/vite-config`. Apps bundle their own Vue, so this should also work on NC 32, whose core is still Vue 2 **[UNVERIFIED in practice]**.
- **Dev dependencies.** `nextcloud/ocp` `dev-stable32`; `vendor-bin/` with `nextcloud/coding-standard`, psalm, phpunit and rector.
- **CI.** Template workflows: `lint-php`, `lint-php-cs`, `psalm-matrix`, `lint-info-xml`, `phpunit-*`, `reuse`, `appstore-build-publish`, all from [nextcloud/.github](https://github.com/nextcloud/.github/tree/master/workflow-templates).

### 1.3 Server APIs needed (NC 32+)

| Need | API | Note |
|---|---|---|
| Per-user refresh tokens | `OCP\Config\IUserConfig` with `FLAG_SENSITIVE` (public since 32) | Encrypted automatically. `IConfig::setUserValue` is deprecated since 33. |
| Admin client secret | `OCP\IAppConfig::setValueString(..., sensitive: true)` | Encrypted automatically. Alternatively `ICrypto`. |
| Config keys | Config Lexicon (`registerConfigLexicon`, `ILexicon` since 32) | Optional. |
| HTTP to Graph and login.microsoftonline.com | `OCP\Http\Client\IClientService` | Do **not** bundle Guzzle. |
| Periodic sync | `TimedJob` / `QueuedJob` + `ITimeFactory` | `IJob::execute()` was removed in 33 (use `start()`). Job IDs are int in 32 and string in 33+, so don't type-hint them. |
| React to Nextcloud calendar changes | `OCP\Calendar\Events\CalendarObject{Created,Updated,Deleted,Moved,MovedToTrash,Restored}Event` (OCP since **32**) | Dispatched synchronously inside the database transaction. Only enqueue a job from the listener. `getOldObjectData()` only exists from 34/35. |
| Write Exchange changes into Nextcloud | Internal `OCA\DAV\CalDAV\CalDavBackend::create/update/deleteCalendarObject` | OCP has **no** update or delete for native calendars (`ICreateFromString` can only create). CalDavBackend never runs scheduling; `integration_google` uses the same approach. This is not public API, so it needs a test on every NC major. |
| Hook into Sabre | `OCA\DAV\Events\SabrePluginAuthInitEvent` (DAV app, internal) | The only event dispatched in all three Sabre servers: main, embedded `createFromString`, and invitation-response. |

---

## 2. How Nextcloud Mail's Microsoft OAuth integration works

**Repo:** [nextcloud/mail](https://github.com/nextcloud/mail).

**History:**
- Added in [PR #7722](https://github.com/nextcloud/mail/pull/7722) (2022-12) and first released in Mail 3.0.0.
- Every Mail version that supports NC 32+ (5.7 to 6.0-alpha) includes it.

### Files

| Part | File |
|---|---|
| Logic | `lib/Integration/MicrosoftIntegration.php` |
| Routes | `lib/Controller/MicrosoftIntegrationController.php` (route `GET /apps/mail/integration/microsoft-auth`) |
| Authorize URL | `lib/Controller/PageController.php` L283–298 |
| HMAC-signed `state` (5.11+) | `lib/Service/OauthStateService.php` |
| Token refresh before each IMAP connection | `lib/Listener/OauthTokenRefreshListener.php` |
| Admin UI | `src/components/settings/MicrosoftAdminOauthSettings.vue` (Groupware admin section) |

### Admin configuration (app `mail`, `IAppConfig`)

| Key | Content |
|---|---|
| `microsoft_oauth_tenant_id` | Default `common` |
| `microsoft_oauth_client_id` | Plain text |
| `microsoft_oauth_client_secret` | `ICrypto`-encrypted |

The admin docs (`admin_manual/groupware/mail.rst`, section "XOAUTH2 Authentication with Microsoft Azure AD") describe the setup:
- create the registration for "organizational and personal accounts";
- platform type Web;
- the redirect URI shown in Nextcloud;
- a client secret.

The docs list **no** API permissions; Mail relies on dynamic consent.

### The flow

1. **Authorize:**
   ```
   GET https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize
     ?client_id=…&redirect_uri=…&response_type=code&response_mode=query&state=…
     &scope=offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send
     &login_hint=<email>
   ```
   Mail does not use PKCE and does not request `openid`/`profile`. It opens the request in a popup, which posts `DONE` back to the opener.
2. **Code exchange:** `POST …/oauth2/v2.0/token` with `grant_type=authorization_code`, client id and secret, `redirect_uri` and `code`.
3. **Tokens:** stored in `mail_accounts.oauth_access_token`, `oauth_refresh_token` (both `ICrypto`-encrypted) and `oauth_token_ttl`. IMAP and SMTP log in with SASL XOAUTH2.
4. **Refresh:** runs when less than 60 s of validity is left. **Bug:** the rotated refresh token is not saved, so accounts break after 90 days ([issue #13852](https://github.com/nextcloud/mail/issues/13852), fix [PR #13853](https://github.com/nextcloud/mail/pull/13853) still open). Our app must always store the new refresh token.

### Why Mail's tokens can't be reused

- **One resource per access token.** Mail's tokens have audience `outlook.office.com` (Exchange Online). Graph Calendar needs audience `graph.microsoft.com`. The `/token` endpoint only accepts scopes from one resource per request.
- **Refresh tokens are not bound to a resource.** In theory, Mail's refresh token could be redeemed for a Graph token, but only if Graph `Calendars.ReadWrite` has already been consented for that client. That couples our app to Mail's database schema and races with Mail's own refresh. **Don't do this.**
- **No public API.** Mail exposes only `OCP\Mail\Provider` (sending mail), not tokens or credentials.
- **No calendar sync anywhere in Nextcloud.** Neither Mail, Calendar nor Server contains Graph or EWS code. `nextcloud/integration_onedrive` only does a one-off, read-only calendar import. The commercial "Nextcloud Exchange Connector" (Sendent, Enterprise Ultimate) is closed source.

### Recommendation

- **Same mechanism, own consent.** The admin enters the tenant id, client id and secret, or copies them from Mail's settings as an optional prefill:
  ```php
  $appConfig->getValueString('mail', 'microsoft_oauth_client_id');
  ```
  The secret is decrypted with `ICrypto`. Our app keeps its own copy, because Mail's "Unlink" deletes those keys.
- **Shared Entra registration.** Add our redirect URI to the same registration as Mail and add the Graph delegated permissions to it. A separate registration works just as well; support both.
- **Own flow.**
  - Authorization code flow **with PKCE** and an HMAC-signed `state`.
  - Scopes `openid profile email offline_access https://graph.microsoft.com/User.Read https://graph.microsoft.com/Calendars.ReadWrite`.
  - Our own encrypted refresh token via `IUserConfig`, sensitive.

---

## 3. Microsoft Graph sync

### 3.1 API choice

- **EWS (Exchange Online) is out.** Phased disablement started 2026-10-01: tenants need `EWSEnabled=True` plus the app in `EWSAllowedAppIDs`. It is permanently turned off on **2027-04-01** (MC1227454, MC1447678).
- **CalDAV and ActiveSync are not options.** Exchange Online has no CalDAV, and EAS requires a license and isn't suitable.
- **On-prem Exchange Server** has no Graph access, only EWS. **v1 supports Exchange Online only.**

### 3.2 Consent: admin consent is needed

`Calendars.*` officially says "admin consent: No". But the **Microsoft-managed default user-consent policy** for new tenants (as of 2026) blocks user consent for `Calendars.Read/ReadWrite(.Shared)`.

The setup docs must therefore tell the admin to click **"Grant admin consent for <tenant>"** on the app registration. Otherwise users get `AADSTS65001` / `AADSTS90094`. `Calendars.ReadWrite.Shared` is only needed for shared or delegated calendars, which v1 does not support.

### 3.3 Tenant model

- **Default:** single-tenant, bring-your-own app registration, with the tenant ID in the authority URL. With `common`, a single-tenant app fails with `AADSTS50194`.
- **Multi-tenant** (`organizations`) only for hosting providers that serve several M365 tenants. Admin consent per tenant avoids the publisher-verification requirement.
- **No shared client ID from the developer.** That would require publisher verification, and the developer would end up in the data path.

### 3.4 Sync

- **Primary mechanism:** `GET /me/calendars/{id}/calendarView/delta?startDateTime=…&endDateTime=…` (v1.0).
  - Rolling window, for example −1 year to +2 years.
  - Follow `@odata.nextLink`, store the `@odata.deltaLink`, and handle `@removed` entries (these can lie outside the window).
  - A new window means a fresh initial sync.
  - It returns expanded occurrences. Fetch the series master via `seriesMasterId` to rebuild the RRULE, EXDATE and RECURRENCE-ID values.
- **Avoid:** `/events/delta` without a date window is beta only, and only returns `id`, `type`, `start` and `end`.
- **Headers on every call:** `Prefer: IdType="ImmutableId"`, `Prefer: outlook.timezone="UTC"`, `Prefer: odata.maxpagesize=…`.
- **Polling is the baseline**, because many Nextcloud instances can't be reached from the internet. Webhooks are optional, as a "sync now" trigger:
  - public HTTPS endpoint;
  - echo the validation token;
  - reply within about 3 s;
  - maximum event subscription lifetime 10,080 min;
  - recover from `missed` notifications via delta.
- **Throttling** (per app and mailbox): 10,000 requests per 10 minutes and **4 concurrent requests**. Honor `Retry-After` on 429. Process each user serially.

### 3.5 Mapping Graph events to VEVENT

| Graph | VEVENT / use | Note |
|---|---|---|
| `id` (immutable) | Primary key in our mapping table | |
| `iCalUId` | Not the series UID | Differs per occurrence |
| `uid` | Series UID | Beta only |
| `transactionId` | — | Set a deterministic value on create (for idempotency) |
| `changeKey` | ETag / echo detection | |
| `isAllDay` | `DATE` values | |
| `reminderMinutesBeforeStart` | 1× VALARM | Graph allows only one reminder |
| `showAs` | `TRANSP` (+ X-property) | |
| `sensitivity` | `CLASS` | |
| `categories` | `CATEGORIES` | |
| `recurrence` | `RRULE` | `patternedRecurrence` can't express every RRULE: expand or make read-only |
| `dateTimeTimeZone` | `TZID` | Windows zone names, so a Windows⇄IANA table (CLDR `windowsZones.xml`) is needed. `tzone://Microsoft/Custom` falls back to UTC. |

- **Attachments** cost upload budget (150 MB per 5 min).
- **Teams:** `isOnlineMeeting` gives a read-only `joinUrl`.
- **No `.ics` export** exists for events. The authoritative mapping is the open spec **[MS-OXCICAL]**.

### 3.6 Invitations (Exchange sends them)

| Action in Nextcloud | Graph call | Exchange sends |
|---|---|---|
| New event with attendees (user is organizer) | `POST /me/calendars/{id}/events` | Invitations |
| Change | `PATCH /me/events/{id}` | Updates |
| Delete (organizer) | `DELETE` or `POST /events/{id}/cancel {comment}` | Cancellation |
| User is attendee: change PARTSTAT | `POST /events/{id}/accept`, `/decline` or `/tentativelyAccept` `{sendResponse:true}` | Reply to the organizer |
| Attendee reply arrives at Exchange | `attendees[].status.response` on the organizer's event | Map to `PARTSTAT` on the next delta **[test empirically]** |

### 3.7 SDK

**Don't bundle `microsoft/microsoft-graph`.**
- `src/` is 288 MB with 40k files, while the App Store archive limit is 20–30 MB.
- It brings its own Guzzle and League libraries, which risks conflicts.

Instead, use plain HTTP through `IClientService`, as Mail does. Three small helpers cover it: token refresh, delta paging, and 429/`Retry-After` handling. Don't bundle `sabre/vobject` either; the server ships 4.5.6.

---

## 4. Stopping Nextcloud invites: Exchange is the only sender

### 4.1 How Nextcloud schedules

1. A `PUT` emits Sabre `calendarObjectChange`.
2. `OCA\DAV\CalDAV\Schedule\Plugin::calendarObjectChange` (`apps/dav/lib/CalDAV/Schedule/Plugin.php` S32:158–210) runs the iTIP broker.
3. The broker emits `schedule`.
4. Two listeners handle `schedule`:
   - `scheduleLocalDelivery` (priority 100) for Nextcloud users;
   - `IMipPlugin::schedule` (priority 120) sends the mail.
5. A `DELETE` takes the same route via `beforeUnbind`, which produces CANCEL messages.
6. The organizer is recognised when `ORGANIZER` is in the user's `calendar-user-address-set` (system email plus the extra profile email addresses).

### 4.2 Options

| Option | Verdict |
|---|---|
| `dav sendInvitations=no` | Instance-wide, and local delivery keeps running. ✗ |
| `SCHEDULE-AGENT=CLIENT` on ATTENDEEs | Standard (RFC 6638), but the old and new versions are compared: events stored without it cause **CANCEL** messages on the next edit. Would have to be set again on every write. Only usable as a second layer. |
| `ExternalCalendar` / `ICalendarProvider` | Scheduling still runs. `isShared()` is missing, which probably causes a fatal error. Storage would have to be built ourselves. ✗ |
| Return `false` from the `schedule` listener | Calendar UI shows "Failed to deliver invitation". ✗ |
| **Own Sabre plugin sets `Schedule-Reply: F` + `x-nc-scheduling: false`** | ✓ **Recommended.** Honored by the NC Schedule plugin on `calendarObjectChange` **and** `beforeUnbind` (S32:167, 172, 218; also on master). Doesn't change the data and has no CANCEL pitfall. |

### 4.3 Sketch

```php
// Application::register()
$context->registerEventListener(SabrePluginAuthInitEvent::class, SabreRegistrar::class);

// SabreRegistrar::handle()
$event->getServer()->addPlugin(new SuppressSchedulingPlugin($this->syncedCalendars));

// SuppressSchedulingPlugin::initialize()
$server->on('calendarObjectChange', fn ($rq, $rs, $vCal, $calPath) => $this->maybeSuppress($calPath), 50);
$server->on('beforeUnbind', fn ($path) => $this->maybeSuppress(dirname($path)), 50);

private function maybeSuppress(string $calendarPath): void {
    if (!str_starts_with($calendarPath, 'calendars/')) return;
    try { $node = $this->server->tree->getNodeForPath($calendarPath); } catch (NotFound) { return; }
    if ($node instanceof \OCA\DAV\CalDAV\Calendar && $this->syncedCalendars->isSynced($node->getResourceId())) {
        $this->server->httpRequest->setHeader('Schedule-Reply', 'F');
        $this->server->httpRequest->setHeader('x-nc-scheduling', 'false');
    }
}
```

- **Priority 50 is required:** it runs before the Schedule plugin, which uses 100.
- **Register only through `SabrePluginAuthInitEvent`.** `SabrePluginAddEvent` and `info.xml` `<sabre>` plugins miss the `createFromString` path.

### 4.4 Calendar app: things to know

- The Calendar app always sets `ORGANIZER` to the user's email address, and it does not touch `SCHEDULE-AGENT`.
- **UI status:**
  - Without `SCHEDULE-STATUS` it shows "Invitation will be sent".
  - When syncing back from Exchange, write **`SCHEDULE-STATUS=1.1`** on the attendees so the UI shows "Awaiting response".
- **Free/busy** only works for Nextcloud users; Exchange colleagues show no availability. Optional later: intercept the outbox `POST` and answer it from Graph `getSchedule`.
- **Moving an event into or out of the synced calendar** is a DAV MOVE, which fires `CalendarObjectMovedEvent`. **Decision:**
  - moving out = a cancellation in Exchange;
  - moving in = a new meeting with new invitations.
- **The synced calendar must not be the user's `schedule-default-calendar`.**
- **If the user also uses Mail with the same mailbox,** Mail's iMIP job may process Exchange replies itself. This is harmless as long as UIDs differ, or as long as our outbound job ignores PARTSTAT-only diffs on events the user organizes.

### 4.5 Data flow

```
NC write ─► CalendarObject*Event ─► listener (guard: ignore own inbound writes)
                                       └─► IJobList::add(PushJob)  (atomic with the DB write)
PushJob ─► Graph POST/PATCH/DELETE/accept ─► Exchange sends invites/updates/cancellations/replies
TimedJob ─► Graph calendarView/delta ─► CalDavBackend create/update/delete (guard flag set)
                                        └─► attendees[].status → PARTSTAT, SCHEDULE-STATUS=1.1
Mapping table: nc_calendar_id, nc_uri, nc_uid ⇄ graph_id (immutable), iCalUId, changeKey, last_etag
```

**Echo protection:**
- Ignore inbound items whose `changeKey` equals the one our own push received.
- Ignore outbound events while the in-process guard flag is set.

**Two warnings about the listener:**
- Never make HTTP calls in it. It runs inside the DB transaction.
- An exception in it rolls back the user's save.

**Trash vs delete:** a normal delete fires `CalendarObjectMovedToTrashEvent`; `DeletedEvent` only fires on purge.

**Prior art:** [Outlook CalDav Synchronizer](https://github.com/aluxnimm/outlookcaldavsynchronizer) uses the same principle, client-side: `SCHEDULE-AGENT=CLIENT`, so that Outlook/Exchange sends the invitations.

---

## 5. License

- **Choice: AGPL-3.0-or-later.**
- The [App Store rules](https://github.com/nextcloud/documentation/blob/master/developer_manual/app_publishing_maintenance/publishing.rst) require "AGPL-3.0-or-later or any compatible license".
- The server is AGPL, and a bundled `@nextcloud/vue` (AGPL-3.0-or-later) makes the JS bundle AGPL anyway.

**Dependency licenses (all compatible):**

| Package | License |
|---|---|
| `vue` | MIT |
| `@nextcloud/axios`, `@nextcloud/l10n` and similar | GPL-3.0-or-later |
| `league/oauth2-client` (optional) | MIT |

**`info.xml`:** `<licence>AGPL-3.0-or-later</licence>`. SPDX identifiers are allowed for `min-version` ≥ 31; the old `agpl` value is deprecated.

**Files to add:**
- `LICENSES/AGPL-3.0-or-later.txt` (via `reuse download AGPL-3.0-or-later`)
- `COPYING` (for GitHub detection)
- an SPDX header in every file:
  ```
  SPDX-FileCopyrightText: 2026 <name> <email>
  SPDX-License-Identifier: AGPL-3.0-or-later
  ```
- `REUSE.toml` for files that can't carry a header (build output, lockfiles, l10n, images)
- `.github/workflows/reuse.yml`

REUSE is not required by the App Store, but it is Nextcloud convention and cheap to do.

---

## 6. Publishing on the App Store

### 6.1 Name and app id

- **App id:** `^[a-z][a-z0-9_]*[a-z0-9]$`, max. 32 characters. **No hyphens**, so the current repo name is not valid as an id. The release tarball folder must equal the id.
- **No "Nextcloud" in the name** (publishing.rst). A third-party app was renamed over this in Oct 2026.
- **Microsoft trademarks:** don't use "Exchange", "Outlook" or "Microsoft" in the product name; no Microsoft logos. A referential statement in the summary is fine ("works with Microsoft 365 / Exchange Online"), plus the footnote: "Microsoft, Microsoft 365, Outlook and Exchange are trademarks of the Microsoft group of companies."
- **Suggestion:** id `calbridge`, namespace `CalBridge`. Free in the cert repo as of 2026-10-16; no trademark search was done **[UNVERIFIED]**. Consider renaming the repo too.

### 6.2 Steps

1. **Account** at apps.nextcloud.com, plus an API token (`/account/token`).
2. **Key and CSR:**
   ```
   openssl req -nodes -newkey rsa:4096 -keyout APP_ID.key -out APP_ID.csr -subj "/CN=APP_ID"
   ```
3. **Certificate PR** to [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests) with `APP_ID/APP_ID.csr` and a link to the repo. CI checks the id pattern and that CN = id. Once approved, `APP_ID/APP_ID.crt` is committed there.
4. **Register the app** (web UI or `POST /api/v1/apps`) with the certificate and this signature:
   ```
   echo -n "APP_ID" | openssl dgst -sha512 -sign APP_ID.key | openssl base64
   ```
   Use `is_enterprise_only=false`. Re-registering with a new certificate **deletes all releases**.
5. **Build:**
   - `composer install --no-dev`, `npm ci && npm run build`, and strip dev files via `.nextcloudignore`/krankerl.
   - `occ integrity:sign-app` writes `appinfo/signature.json`. Treat signing as required: once a release is signed, every later release must be signed too.
   - Tarball: exactly one top-level folder `APP_ID/`, no `.git`, `info.xml` < 512 KB, archive ≤ 20 MB.
6. **Upload:**
   - The tarball needs a public HTTPS URL; a GitHub release asset works.
   - Signature: `openssl dgst -sha512 -sign APP_ID.key APP_ID.tar.gz | openssl base64`.
   - Submit with `POST /api/v1/apps/releases {download, signature, nightly:false}`.
   - Release notes come from `CHANGELOG.md` (Keep a Changelog format).
7. **Pre-releases:** use semver `1.0.0-beta.1` (beta channel). Do **not** publish them as nightly.
8. **Each new NC major:** publish a new release with a higher `max-version`, or the app is shown as incompatible.
9. **Automation:** [appstore-build-publish.yml](https://github.com/nextcloud/.github/blob/master/workflow-templates/appstore-build-publish.yml). Adjustments for this repo:
   - drop the `nextcloud-releases` owner check;
   - hardcode `APP_NAME` (the repo name has hyphens);
   - tags `v<version>`;
   - secrets `APP_PRIVATE_KEY` and `APPSTORE_TOKEN` in a protected environment.

### 6.3 Review rules (publishing.rst)

There is no manual review per release; validation is automated. The rules:
- Use only public OCP API **where possible**. CalDavBackend and SabrePluginAuthInitEvent are internal: document this and test every major.
- Don't slow Nextcloud down.
- Clean up on uninstall and on user deletion (`UserDeletedEvent`).
- **"If user data is sent anywhere, this must be clearly explained and kept to a minimum."** The description and README need a "Data & privacy" section covering what goes to Graph, the scopes, where tokens are stored, and how to revoke access.
- Ship a `SECURITY.md` and respond to security reports.
- Malice means a ban of at least 2 years.

### 6.4 Privacy / GDPR (brief, not legal advice)

- **Roles:** the Nextcloud operator is the controller, and Microsoft is a processor under the tenant agreement. The developer is not in the data path, provided the admin brings their own app registration.
- **Opt-in:** sync is opt-in per user and per calendar.
- **On disconnect or user deletion:** revoke and delete tokens and remove the mapping.

---

## 7. Open decisions

1. Do we support NC 32 even though it is already EOL? Or `min-version` 33 / PHP 8.2?
2. App id and display name (suggestion `calbridge` / "CalBridge"), and whether to rename the repo.
3. A new dedicated Nextcloud calendar per Exchange calendar ("Outlook"), or link an existing calendar? A new one is simpler and safer.
4. Behaviour on MOVE into or out of the synced calendar, and on restore from trash (each triggers new invitations or cancellations in Exchange).
5. Recurring events that Graph `patternedRecurrence` can't express: expand them or keep them read-only?
6. Webhooks (needs a public instance) or polling only for v1?

## 8. To verify on a test instance and test tenant

- `Schedule-Reply`/`x-nc-scheduling` set by the plugin actually stops all iMIP mail on create, update and delete (confirmed by reading code, not by running it).
- An attendee reply in Exchange shows up in the organizer's `calendarView/delta`.
- Whether Exchange keeps the iCal UID we supply on create (probably not, so rely on the mapping table).
- A refresh token redeemed for Graph scopes when the registration is shared with Mail.
- A Vue 3 bundle on NC 32 (core is Vue 2).
- The EWS dates and the consent-policy rollout (sources were search snippets only).
