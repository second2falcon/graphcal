# GraphCal: design decisions

These decisions build on [RESEARCH.md](RESEARCH.md). They were made on 2026-10-16.

## Decisions

| # | Topic | Decision |
|---|---|---|
| 1 | Nextcloud versions | **NC 33 and newer.** `<nextcloud min-version="33" max-version="35"/>` and `<php min-version="8.2" max-version="8.5"/>`. Raise `max-version` to 36 after testing. |
| 2 | Name | **GraphCal**, with app id `graphcal` and namespace `GraphCal`. On 2026-10-16 no `graph*` id existed in `nextcloud/app-certificate-requests`. No trademark search has been done. "Graph" is a generic word, but the name does hint at "Microsoft Graph", so the description should say "works with Microsoft 365" and should not say "by Microsoft". |
| 3 | Scope | Sync **all of the user's own Outlook calendars**. The user can opt in to **calendars shared with them**. |
| 4 | Source of truth | **Exchange.** Ideally the user sees only their Outlook calendars in the Nextcloud Calendar app. Nextcloud never sends invitations for synced calendars. |
| 5 | Recurrence | The supported subset maps 1:1. Anything outside it is **rejected on write with a clear error**, not expanded into single events. See below. |
| 6 | Propagation | **Push in both directions, aiming for seconds.** Graph change notifications go to Nextcloud, and a dedicated background-job worker pushes changes to Graph. Polling remains as a safety net. |
| 7 | Login | A **popup** in the user's personal settings, using the same pattern as Nextcloud Mail. The admin grants tenant-wide consent once, so users only sign in (or pick their account). |

---

## 1. Sign-in (popup)

1. The admin enters the tenant ID, client ID and client secret. They can prefill these from Nextcloud Mail's Microsoft settings. The admin then clicks **Grant admin consent** in Entra.
2. In Personal settings → GraphCal, the user clicks **Connect Microsoft 365**. A popup opens:
   ```
   https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize
     ?client_id=…&response_type=code&response_mode=query
     &redirect_uri=https://<nc>/apps/graphcal/oauth/callback
     &scope=openid profile email offline_access User.Read Calendars.ReadWrite Calendars.ReadWrite.Shared
     &state=<HMAC(user, ts)>&code_challenge=<S256>&code_challenge_method=S256
     &login_hint=<nextcloud user email>
   ```
3. The callback checks `state`, redeems the code with the PKCE verifier and stores the refresh token in `IUserConfig` (sensitive). It then renders a small page that `postMessage`s the opener and closes itself. Nextcloud Mail does the same in `src/integration/oauth.js` and its `oauth_done` template.
4. Every refresh **stores the rotated refresh token**. Nextcloud Mail has a bug here (issue #13852).
5. When the refresh token dies (90 days idle, password reset, a Conditional Access policy, or the admin revoking it), the app sends an `INotifier` notification: "Reconnect Microsoft 365". Clicking it opens the same popup.

If the user is already signed in to Microsoft in the browser, or Nextcloud itself logs in through Entra ID, the popup usually closes after a single account click.

## 2. Which calendars

- On connect, `GET /me/calendars` lists the calendars.
  - Calendars the user owns are **on by default**.
  - Calendars with `isSharedWithMe=true` are listed **off**, and the user can switch them on in personal settings.
- An **"Add someone's calendar" (email address)** field opens `/users/{email}/calendar`. This needs `Calendars.ReadWrite.Shared` and works only if the owner has shared or delegated the calendar.
- Each enabled Outlook calendar becomes a Nextcloud calendar with the same name and colour. It is owned by the app's mapping, created through the internal `CalDavBackend`.
- The Outlook default calendar (`isDefaultCalendar`) becomes the Nextcloud **`schedule-default-calendar`**.
- **Existing local Nextcloud calendars are not touched in v1.** After connecting, the app tells the user they can delete or hide (untick) "Personal" in the Calendar app. A one-time "copy local events to Outlook" option is possible later. It would have to skip events with attendees, because creating those in Exchange would send invitations.

## 3. Invites: Exchange only, never Nextcloud

- **Suppressing Nextcloud's own invitations.** The `SuppressSchedulingPlugin`, registered via `SabrePluginAuthInitEvent` at priority 50, sets `Schedule-Reply: F` and `x-nc-scheduling: false` for every write or delete in a synced calendar (RESEARCH §4.3).
- **The user is the organizer:**
  - Graph `POST`, `PATCH` and `DELETE`/`cancel`.
  - Exchange sends the invitation, the update or the cancellation.
- **The user is an attendee:** a change to the user's own PARTSTAT calls `accept`, `decline` or `tentativelyAccept` with `sendResponse: true`. Exchange sends the reply. Any other change to an event the user doesn't organise is **rejected** (403), because Exchange owns it.
- **Never push an event that the user doesn't organise and that Graph doesn't know.** This can happen when Nextcloud delivers internally, from a user who isn't on GraphCal, into the default calendar. That event stays local and is marked as such. Pushing it would turn the user into the organizer of a new meeting.
- **Moving an event between two synced calendars.** Graph has **no move API** for events (`event-move` does not exist in v1.0 or beta):
  - an event **without attendees** is moved by creating it in the target calendar and deleting it from the source; nobody is notified;
  - an event **with attendees** gets a **403 on MOVE**, with the message "Meetings can't be moved between Outlook calendars". Allowing it would mean a cancellation plus a new invitation. Outlook doesn't allow it either.
- **Restore from trash** of a meeting whose cancellation has already gone out is **rejected**, because restoring it would send new invitations. A restore of a plain event re-creates it.
- When syncing attendees back, set `SCHEDULE-STATUS=1.1` so the Calendar app shows "Awaiting response" instead of "Invitation will be sent".

## 4. Recurrence (decision 5)

This is the goal: anything a user creates in either client appears in the other one **unchanged**, and no setting is ever silently lost.

| RRULE | Graph `patternedRecurrence` | Nextcloud Calendar UI can create it |
|---|---|---|
| `DAILY;INTERVAL=n` | `daily` | yes |
| `WEEKLY;BYDAY=…` | `weekly` + `daysOfWeek` (+ `firstDayOfWeek` ← `WKST`) | yes |
| `MONTHLY;BYMONTHDAY=d` (one day) | `absoluteMonthly` | yes |
| `MONTHLY;BYDAY=WE;BYSETPOS=1..4,-1` | `relativeMonthly` + `index` first…fourth/last | yes |
| `YEARLY;BYMONTH=m;BYMONTHDAY=d` | `absoluteYearly` | yes |
| `YEARLY;BYMONTH=m;BYDAY=…;BYSETPOS=…` | `relativeYearly` | yes |
| `COUNT` / `UNTIL` / neither | `numbered` / `endDate` / `noEnd` | yes |
| `EXDATE` | Delete or cancel that occurrence (via `/instances`) | yes |
| `RECURRENCE-ID` override | `PATCH` that occurrence | yes |
| **Not expressible in Graph:** `BYMONTHDAY` lists (1st and 15th), `BYSETPOS=-2` or `5`, several `BYMONTH`, `HOURLY`/`MINUTELY`, `RDATE`, `BYWEEKNO`/`BYYEARDAY` | — | Partly (the first three) |

**Advice: reject; don't expand.**

- **Expanding one series into many separate events breaks things.**
  - A meeting becomes dozens of meetings, each sending its own invitation.
  - The series can no longer be edited as a whole.
  - Outlook users see clutter.
  - Exchange → Nextcloud can never round-trip back to the original rule.
- **Rejecting is cheap and only affects a small edge.**
  - The Sabre plugin refuses the PUT with a `403` and a message like: "This repeat pattern isn't supported by Microsoft 365. Use a single day of the month, or first–fourth/last weekday."
  - The Calendar web app shows that error. DAV clients (Thunderbird, iOS) show a sync error.
  - Nothing goes out of sync.
  - The only rules this affects are the three Calendar-UI options in the last table row (and rules from other clients).
- **Direction Exchange → Nextcloud has no gaps.** Every Graph pattern can be expressed as an RRULE. The Calendar UI shows some of them (for example "first weekday of the month" = `BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1`) as "unsupported" with a warning, but it displays them correctly and other fields stay editable.
- **Other limits Graph puts on all events, recurring or not:**
  - **One reminder.** Graph keeps only the first VALARM, so the app writes back what Exchange stored and the client sees only one.
  - **`UNTIL` is a date only.**
  - **Time zones go through a Windows ⇄ IANA map.**

## 5. Push in both directions (decision 6)

### Nextcloud → Outlook (target: a few seconds)

```
CalDAV PUT ─► CalendarObject*Event (inside the DB transaction)
           └► IJobList::add(PushJob, {calendarId, uri, op})      // committed with the write
occ background-job:worker 'OCA\GraphCal\BackgroundJob\PushJob'   // polls every 1 s (default --interval=1)
           └► Graph POST/PATCH/DELETE/accept … (~0.5–1 s)
Exchange ─► Outlook Classic (cached mode gets Exchange push, usually seconds)
```

- Nextcloud has had `occ background-job:worker [job-classes…] [--interval=1] [--stop_after=…]` since 27. It was confirmed in `core/Command/Background/JobWorker.php` on stable33.
- **The admin runs one worker as a systemd service.** The admin docs will include a ready-made unit file. Without the worker, jobs run with normal cron, every 5 minutes.
- Pushes **for one user run one at a time** (one job per user at a time), because Graph allows at most 4 concurrent requests per mailbox.

### Outlook → Nextcloud (target: seconds to tens of seconds)

```
Exchange change ─► Graph change notification ─► POST https://<nc>/apps/graphcal/webhook  (public route)
                                                └► check clientState, reply 202 within 3 s, IJobList::add(DeltaJob, {user})
worker ─► calendarView/delta per calendar ─► CalDavBackend write (guard flag) ─► CalDAV clients pick it up
```

- **One subscription per user** on `/me/events`, which covers all calendars in the user's mailbox.
  - Requires `Calendars.Read` or higher.
  - Maximum lifetime 10,080 minutes. A daily `TimedJob` renews it.
  - Use a `lifecycleNotificationUrl`, and react to `missed` / `reauthorizationRequired` by running delta or renewing.
  - Limit: 1,000 subscriptions per mailbox, across all apps.
- **Shared calendars have one limitation.** Microsoft says the `.Shared` delegated permissions **do not support change notifications**: "To set up change notification subscriptions on events in a shared, delegated, or any other user or resource calendar … use the application permission, Calendars.Read." Source: `concepts/outlook-get-shared-events-calendars.md`.
  - **v1:** shared calendars are **polled** every 2 minutes, with delta, so this is cheap.
  - **Optional later:** if the admin also grants the *application* permission `Calendars.Read`, which is tenant-wide, these calendars get push too. This is an explicit admin choice.
- **Fallback:** a `TimedJob` runs delta for every connected user every 15 minutes, in case notifications are lost.
- **Delays outside our control:**
  - Graph doesn't promise a delivery time for notifications. It is usually seconds, but this is unverified and needs measuring in the test tenant.
  - The Nextcloud Calendar web UI does not refresh live, so a change appears on the next navigation or reload. DAV clients use their own sync interval.

### Requirements for the server

- **Public HTTPS** with a valid certificate. Graph checks the URL synchronously when the subscription is created.
- **`occ background-job:worker` running for GraphCal's job classes.**
- **Outbound HTTPS** to `login.microsoftonline.com` and `graph.microsoft.com`.

## 6. Mapping and echo protection

The app keeps one table, `graphcal_map`:

| Column | Purpose |
|---|---|
| `user_id` | Owner of the mapping |
| `nc_calendar_id`, `nc_uri`, `nc_uid` | The Nextcloud event |
| `graph_calendar_id`, `graph_event_id` (immutable ID) | The Exchange event |
| `change_key` | Exchange's version marker |
| `last_etag` | Nextcloud's version marker |
| `snapshot` | Attendees and PARTSTAT, for diffing |

- **Inbound items are skipped** when their `changeKey` is the one our own push received.
- **Outbound events are ignored** while the inbound guard flag is set.
- **The Nextcloud UID is also written** into a single-value extended property on the Graph event. The mapping can then be rebuilt after data loss.
- **The Exchange `iCalUId` is not used as the Nextcloud UID.** It differs per occurrence, and changing a UID breaks CalDAV clients.

## Still to verify in a test tenant

1. That the `Schedule-Reply`/`x-nc-scheduling` suppression stops all iMIP mail, on create, update and delete.
2. That attendee replies show up in the organizer's `calendarView/delta` and trigger a `/me/events` notification.
3. Whether changes in an `isSharedWithMe` calendar copy (in the user's own mailbox) trigger the `/me/events` subscription. If they do, shared calendars get push without the application permission.
4. Typical latency of Graph notifications in practice.
5. Creating a Nextcloud calendar per Outlook calendar through `CalDavBackend`, and marking it read-only in the Calendar UI where `canEdit=false`.
