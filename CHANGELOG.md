# Changelog

## [Unreleased]

## 0.1.1

- Fix signing in to Microsoft 365, which failed with "Could not find a property named 'isSharedWithMe'". Graph v1.0 refuses that property, so calendars shared with you are now recognized by their owner.

## 0.1.0

First version, for Nextcloud 33–35.

- Microsoft 365 sign-in popup (OAuth 2.0 authorization code flow with PKCE) and admin settings, which can reuse Nextcloud Mail's Microsoft app registration.
- Mirrors all of the user's Outlook calendars into Nextcloud. Calendars shared with the user are opt-in.
- Pushes Nextcloud changes to Exchange, which then sends the invitations, updates, cancellations and replies. Nextcloud scheduling is switched off for synced calendars.
- Change notifications through a webhook, with polling as a fallback.
- No separate worker: changes are synced right after the request that caused them; Nextcloud's cron is the fallback.
