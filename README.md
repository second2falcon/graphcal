# GraphCal

> [!CAUTION]
> This code is entirely AI-generated. It is tested against a Microsoft 365 tenant in production, and by the automated tests in GitHub Actions.

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

## Installation

GraphCal is not on the Nextcloud App Store yet. Install it manually from its release archive, `graphcal-<version>.tar.gz`. It contains a single folder named `graphcal` with only the app; there is no build step and nothing else to install.

### Get the archive

- **A released version:** download `graphcal-<version>.tar.gz` from [Releases](https://github.com/second2falcon/graphcal/releases).
- **An unreleased build, for testing:** open a successful run of the [CI workflow](https://github.com/second2falcon/graphcal/actions/workflows/ci.yml) and download the `graphcal-app-archive` artifact. You need to be signed in to GitHub. It arrives as a `.zip` around the archive; unpack it with `unzip graphcal-app-archive.zip`, which gives you `graphcal-<version>.tar.gz`.

Copy the archive to your server, for example with `scp`. Don't use GitHub's *Code → Download ZIP* or a `git clone` instead: the folder would have the wrong name (it must be exactly `graphcal`), and it would include the developer test tools.

### Standard Nextcloud install

Set the two paths at the top, then run the commands on the server. They replace any older `graphcal` folder, hand the files to the web server user (`www-data` on Debian/Ubuntu; `apache` or `nginx` on some other systems) and enable the app.

```sh
ARCHIVE=~/graphcal-0.1.1.tar.gz   # the archive you downloaded
NC=/var/www/nextcloud             # your Nextcloud folder
sudo rm -rf "$NC/apps/graphcal"
sudo tar -xzf "$ARCHIVE" -C "$NC/apps"
sudo chown -R www-data:www-data "$NC/apps/graphcal"
sudo -u www-data php "$NC/occ" app:enable graphcal
```

If your `config.php` defines `apps_paths`, use the folder marked `'writable' => true` instead of `apps`, often `custom_apps`.

### Nextcloud All-in-One (Docker)

AIO keeps additional apps in `custom_apps` inside the `nextcloud-aio-nextcloud` container. Run on the Docker host:

```sh
ARCHIVE=~/graphcal-0.1.1.tar.gz   # the archive you downloaded
sudo docker cp "$ARCHIVE" nextcloud-aio-nextcloud:/tmp/graphcal.tar.gz
sudo docker exec nextcloud-aio-nextcloud sh -c 'mkdir -p /var/www/html/custom_apps && cd /var/www/html/custom_apps && rm -rf graphcal && tar -xzf /tmp/graphcal.tar.gz && chown -R www-data:www-data graphcal && rm /tmp/graphcal.tar.gz'
sudo docker exec --user www-data nextcloud-aio-nextcloud php occ app:enable graphcal
```

The AIO test in CI installs GraphCal with exactly these commands. AIO already runs Nextcloud's cron every 5 minutes, so there is nothing else to set up. Two things to know:

- **GraphCal survives AIO container updates:** `custom_apps` lives on AIO's persistent volume.
- **Major Nextcloud updates can switch it off.** During a major update, AIO disables every app in `custom_apps` and re-enables them afterwards. If the new Nextcloud version is newer than the highest one GraphCal supports (`max-version` in `appinfo/info.xml`, currently 35), GraphCal stays disabled and AIO sends you a notification. Install a GraphCal release that supports the new version; your settings and sync state are kept in the meantime.

### Updating

Run the same installation commands with the new archive. They replace the old `graphcal` folder, so no stale files remain. Then run `occ upgrade`: `sudo -u www-data php "$NC/occ" upgrade`, or on AIO `sudo docker exec --user www-data nextcloud-aio-nextcloud php occ upgrade`. Settings, tokens and sync state are kept, because they live in the database.

### Hosted Nextcloud without shell access

Without shell or file access, Nextcloud can only install apps from the App Store, through the Apps page; it has no way to upload an app archive. GraphCal has to be published on the App Store first. Check with your provider whether they allow App Store apps that aren't preinstalled, since some managed hosts offer only a curated selection.

The instance also needs:

- cron for background jobs, which most providers run for you;
- outbound HTTPS to `login.microsoftonline.com` and `graph.microsoft.com`;
- ideally PHP-FPM. With it, Outlook changes arrive within seconds; without it, after up to 5 minutes.

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
- **Unsupported repeat patterns are rejected when you save**, rather than stored in a way Outlook can't show. Examples are several days of the month, "second to last weekday", or several months. The Calendar app then shows a short explanation, e.g. "Microsoft 365 doesn't support this repeat pattern: several days, or the last day, of a month."
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
scripts/package.sh   # builds build/graphcal-<version>.tar.gz
```

To publish a release, set `<version>` in `appinfo/info.xml`, add a `## <version>` section to `CHANGELOG.md` and push a matching tag (`git tag v0.1.0 && git push origin v0.1.0`). The release workflow then attaches the archive to a GitHub release.

The end-to-end tests run against a real Nextcloud with a Graph mock (`tests/integration/`). GitHub Actions runs them on a native install of Nextcloud 33 (SQLite), 34 (SQLite) and 35 (PostgreSQL), on Nextcloud 35 behind nginx + PHP-FPM, and on the real **Nextcloud All-in-One** Docker containers (Apache + PHP-FPM, PostgreSQL, Redis). They cover the sign-in popup, mirroring in both directions, invitation suppression, replies, deletes, refusing repeat patterns Microsoft 365 can't store, the webhook and disconnecting, plus a headless-browser test of the settings pages and the Calendar app. Each run uploads screenshots of every step (admin Entra ID settings, personal settings, sign-in, Calendar views) as a build artifact.

## License

AGPL-3.0-or-later. Microsoft, Microsoft 365, Outlook and Exchange are trademarks of the Microsoft group of companies. This project is not affiliated with Microsoft.
