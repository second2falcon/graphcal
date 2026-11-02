<?php
// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
?>
<div id="graphcal-admin" class="section">
	<h2>GraphCal – Microsoft 365 calendar sync</h2>
	<p class="settings-hint">
		Register an app in Microsoft Entra ID (single tenant, platform "Web") with the redirect URI below,
		add the delegated Microsoft Graph permissions <code>User.Read</code>, <code>Calendars.ReadWrite</code>,
		<code>Calendars.ReadWrite.Shared</code> and <code>offline_access</code>, then click
		<strong>Grant admin consent</strong>. You can reuse the registration of Nextcloud Mail by adding this redirect URI to it.
	</p>
	<p><label>Redirect URI<br><input type="text" readonly value="<?php p($_['redirect_uri']); ?>" style="width: 100%; max-width: 600px"></label></p>
	<form id="graphcal-admin-form">
		<p><label>Tenant ID<br><input type="text" name="tenant_id" value="<?php p($_['tenant_id']); ?>" placeholder="00000000-0000-0000-0000-000000000000" style="width: 360px"></label></p>
		<p><label>Client ID<br><input type="text" name="client_id" value="<?php p($_['client_id']); ?>" style="width: 360px"></label></p>
		<p><label>Client secret<br><input type="password" name="client_secret" autocomplete="new-password" placeholder="<?php p($_['has_secret'] ? '(unchanged)' : ''); ?>" style="width: 360px"></label></p>
		<p>
			<button type="submit" class="primary">Save</button>
			<?php if ($_['mail_configured']): ?>
				<button type="button" id="graphcal-from-mail">Use Nextcloud Mail's Microsoft app</button>
			<?php endif; ?>
			<span id="graphcal-admin-msg" class="msg"></span>
		</p>
	</form>
	<p class="settings-hint">
		Changes are synced right after they happen; no extra service is needed. Set background jobs to
		<strong>Cron</strong> (Basic settings) so the 5-minute safety-net sync and retries run reliably.
		Nextcloud must be reachable over HTTPS from the internet for Outlook changes to arrive within seconds.
	</p>
</div>
