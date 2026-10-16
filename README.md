# GraphCal

Two-way sync between Nextcloud Calendar and Microsoft 365 / Exchange Online calendars via Microsoft Graph, for Nextcloud 33 and newer.

- **All your Outlook calendars appear in Nextcloud Calendar.** You can also opt in to calendars shared with you.
- **Exchange sends the invitations, not Nextcloud.** For synced calendars, Nextcloud's own iMIP mails and internal delivery are switched off. Outlook users therefore get native meeting requests, and their Accept/Decline replies flow back as attendee status.
- **Changes propagate in seconds in both directions.** Graph change notifications bring Outlook changes in, and Nextcloud changes are pushed out right after they're saved. Everything runs inside Nextcloud: install from the App Store, no extra service or worker.

Design and research: [docs/DESIGN.md](docs/DESIGN.md), [docs/RESEARCH.md](docs/RESEARCH.md).

## Requirements

- Nextcloud 33–35, PHP 8.2–8.5, with the `intl` extension (used for time zone mapping).
- Exchange Online / Microsoft 365. On-premises Exchange is not supported, because it has no Graph API.
- Nextcloud reachable over **public HTTPS**. This is needed for change notifications; without it, sync falls back to polling every 5 minutes.
- Background jobs set to **Cron** (recommended for every Nextcloud). No separate worker or service is needed.
- Each user's Nextcloud email address should equal their Microsoft 365 address. The app uses it to decide who organizes a meeting.

## Setup

### 1. Register an app in Microsoft Entra ID (once, as tenant admin)

1. Go to Entra admin center → **App registrations → New registration**.
   - Supported account types: **Single tenant**.
   - Redirect URI: platform **Web**, and the URL shown in Nextcloud under *Administration settings → GraphCal*. It looks like `https://cloud.example.com/index.php/apps/graphcal/oauth/callback`.
2. **Certificates & secrets → New client secret.** Copy its value.
3. **API permissions → Add → Microsoft Graph → Delegated:** `User.Read`, `Calendars.ReadWrite`, `Calendars.ReadWrite.Shared` and `offline_access`.
4. Click **Grant admin consent for <tenant>**. The default user-consent policy of new tenants blocks calendar permissions, so without this step users can't connect.

You can also reuse the registration that Nextcloud Mail uses for Outlook: add the redirect URI and the permissions above to it, then click *Use Nextcloud Mail's Microsoft app* in the GraphCal admin settings.

### 2. Configure Nextcloud

Enter the tenant ID, client ID and client secret under *Administration settings → GraphCal*. The secret is stored encrypted.

### 3. Background jobs

Nothing extra to install. GraphCal does its work inside Nextcloud:

- **Nextcloud → Outlook:** a change is sent to Microsoft right after Nextcloud has answered the request that saved it. With PHP-FPM (the usual setup) the user doesn't wait for it; with Apache `mod_php`, saving takes about a second longer.
- **Outlook → Nextcloud:** Microsoft notifies Nextcloud and the change is fetched right after that notification is answered. This needs PHP-FPM, because Microsoft expects an answer within 3 seconds; without it the change waits for the next cron run.
- **Safety net:** every 5 minutes Nextcloud's own background jobs sync all connected users, retry anything that failed and renew the notification subscriptions. Set *Administration settings → Basic settings → Background jobs* to **Cron** so this runs reliably.

### 4. Users connect

Each user opens *Personal settings → GraphCal → Connect Microsoft 365*. A Microsoft sign-in popup opens, and after signing in their Outlook calendars appear in the Calendar app.

## Behaviour and limits

- **Exchange is the source of truth.** For meetings organized by someone else, only your own response (accept/decline/tentative) is sent back to Exchange.
- **Unsupported repeat patterns are rejected when you save**, rather than stored in a way Outlook can't show. Examples are several days of the month, "second to last weekday", or several months.
- **Meetings can't be moved between calendars**, because Graph has no move operation. Plain events can.
- **Graph keeps only one reminder per event.**
- **Shared calendars are polled every 5 minutes.** Microsoft does not send change notifications for them with delegated permissions.
- **Restoring a deleted meeting from the Nextcloud trash creates it again in Outlook**, so Exchange sends the invitations again.

## Data & privacy

Once a user connects, the events of their synced calendars are exchanged with Microsoft Graph: title, time, location, description and attendees.

- OAuth tokens are stored encrypted in Nextcloud.
- Disconnecting deletes the tokens, the synced Nextcloud calendars (the events stay in Outlook) and the change-notification subscription.
- Deleting a user removes their sync state.

## Development

```sh
composer install
composer run lint && composer run test:unit && composer run psalm
```

The end-to-end tests run against a real Nextcloud with a Graph mock (`tests/integration/`). GitHub Actions runs them on a native install of Nextcloud 33 (SQLite), 34 (SQLite) and 35 (PostgreSQL), on Nextcloud 35 behind nginx + PHP-FPM, and on the real **Nextcloud All-in-One** Docker containers (Apache + PHP-FPM, PostgreSQL, Redis). They cover the sign-in popup, mirroring in both directions, invitation suppression, replies, deletes, refusing repeat patterns Microsoft 365 can't store, the webhook and disconnecting, plus a headless-browser test of the settings pages and the Calendar app. Each run uploads screenshots of every step (admin Entra ID settings, personal settings, sign-in, Calendar views) as a build artifact.

## License

AGPL-3.0-or-later. Microsoft, Microsoft 365, Outlook and Exchange are trademarks of the Microsoft group of companies. This project is not affiliated with Microsoft.
