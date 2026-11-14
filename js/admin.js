// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const form = document.getElementById('graphcal-admin-form')
const msg = document.getElementById('graphcal-admin-msg')

async function post(path, body) {
	// Changing the app registration asks for the administrator's password again (Nextcloud's own dialog).
	if (OC.PasswordConfirmation.requiresPasswordConfirmation()) {
		await new Promise((resolve, reject) => OC.PasswordConfirmation.requirePasswordConfirmation(resolve, {}, reject))
	}
	const response = await fetch(OC.generateUrl('/apps/graphcal' + path), {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', requesttoken: document.head.dataset.requesttoken },
		body: JSON.stringify(body ?? {}),
	})
	const data = await response.json()
	msg.textContent = response.ok ? (data.configured ? 'Saved' : 'Saved – client ID and secret are still missing') : (data.error ?? data.message)
}

form?.addEventListener('submit', (e) => {
	e.preventDefault()
	post('/settings/admin', Object.fromEntries(new FormData(form)))
})
document.getElementById('graphcal-from-mail')?.addEventListener('click', async () => {
	await post('/settings/admin/from-mail')
	window.location.reload()
})
