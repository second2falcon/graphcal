// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const box = document.getElementById('graphcal-oauth-done')
if (window.opener && box) {
	window.opener.postMessage({ graphcal: 'done', error: box.dataset.error }, window.location.origin)
	if (!box.dataset.error) {
		window.close()
	}
}
