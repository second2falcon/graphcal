<?php
// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
?>
<div class="guest-box" id="graphcal-oauth-done" data-error="<?php p($_['error']); ?>">
	<?php if ($_['error'] === ''): ?>
		<h2>Connected to Microsoft 365</h2>
		<p>You can close this window.</p>
	<?php else: ?>
		<h2>Could not connect</h2>
		<p><?php p($_['error']); ?></p>
	<?php endif; ?>
</div>
