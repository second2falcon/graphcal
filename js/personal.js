// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const root = document.getElementById('graphcal-state')
const url = (path) => OC.generateUrl('/apps/graphcal/settings/personal' + path)

async function call(path, method = 'GET', body) {
	root.setAttribute('aria-busy', 'true')
	const response = await fetch(url(path), {
		method,
		headers: { 'Content-Type': 'application/json', requesttoken: document.head.dataset.requesttoken },
		body: body ? JSON.stringify(body) : undefined,
	})
	const data = await response.json()
	root.removeAttribute('aria-busy')
	if (!response.ok) {
		root.prepend(el('p', { className: 'warning', textContent: data.error || 'Request failed' }))
		return
	}
	render(data)
}

function el(tag, attrs = {}, ...children) {
	const node = Object.assign(document.createElement(tag), attrs)
	node.append(...children)
	return node
}

function render(state) {
	root.replaceChildren()
	if (!state.configured) {
		root.append(el('p', {}, 'Your administrator has not configured the Microsoft 365 connection yet.'))
		return
	}
	if (!state.connected) {
		const button = el('button', { className: 'primary', textContent: 'Connect Microsoft 365' })
		button.addEventListener('click', () => window.open(OC.generateUrl('/apps/graphcal/oauth/start'), 'graphcal', 'width=520,height=680'))
		root.append(el('p', {}, button))
		return
	}
	if (state.error) {
		const again = el('button', { textContent: 'Sign in again' })
		again.addEventListener('click', () => window.open(OC.generateUrl('/apps/graphcal/oauth/start'), 'graphcal', 'width=520,height=680'))
		root.append(el('p', { className: 'warning' }, 'Microsoft 365 needs you to sign in again: ' + state.error + ' ', again))
	}
	root.append(el('p', {}, 'Connected as ', el('strong', { textContent: state.account })))

	const own = state.calendars.filter((c) => !c.shared)
	const shared = state.calendars.filter((c) => c.shared)
	const list = (calendars) => el('ul', {}, ...calendars.map((c) => {
		const box = el('input', { type: 'checkbox', checked: c.enabled, id: 'graphcal-cal-' + c.id, className: 'checkbox' })
		box.addEventListener('change', () => call('/calendar/' + c.id, 'POST', { enabled: box.checked }))
		return el('li', {}, box, el('label', { htmlFor: box.id, textContent: c.name + (c.owner ? ' (' + c.owner + ')' : '') + (c.canEdit ? '' : ' – read only') }))
	}))
	root.append(el('h3', { textContent: 'My calendars' }), list(own))
	root.append(el('h3', { textContent: 'Calendars shared with me' }))
	if (shared.length) {
		root.append(list(shared), el('p', { className: 'settings-hint', textContent: 'Changes in shared calendars are picked up every few minutes.' }))
	}

	const email = el('input', { type: 'email', placeholder: 'colleague@example.com' })
	email.style.width = '280px'
	const add = el('button', { textContent: 'Add calendar' })
	add.addEventListener('click', () => email.value && call('/shared', 'POST', { email: email.value }))
	root.append(el('p', {}, email, ' ', add))

	const refresh = el('button', { textContent: 'Refresh calendar list' })
	refresh.addEventListener('click', () => call('/refresh', 'POST'))
	const disconnect = el('button', { textContent: 'Disconnect' })
	disconnect.addEventListener('click', () => {
		if (confirm('Disconnect Microsoft 365? The synced calendars are removed from Nextcloud (they stay in Outlook).')) {
			call('/disconnect', 'POST')
		}
	})
	root.append(el('p', {}, refresh, ' ', disconnect))
}

window.addEventListener('message', (e) => {
	if (e.origin === window.location.origin && e.data?.graphcal === 'done') {
		call('')
	}
})
call('')
