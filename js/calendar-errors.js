// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Loaded on Calendar app pages. When GraphCal refuses a change (e.g. a repeat pattern Microsoft 365 can't
// store), the Calendar app only says "Failed to save event"; show GraphCal's explanation next to it.
(() => {
	const MARKER = 'Microsoft 365'
	let lastShown = ''

	function explain(body) {
		const match = typeof body === 'string' && body.match(/<s:message>([\s\S]*?)<\/s:message>/)
		if (!match) {
			return
		}
		const text = new DOMParser().parseFromString(match[1], 'text/html').documentElement.textContent.trim()
		if (!text.includes(MARKER) || text === lastShown) {
			return
		}
		lastShown = text
		setTimeout(() => { lastShown = '' }, 3000)
		if (window.OCP?.Toast?.error) {
			window.OCP.Toast.error(text, { timeout: 20000 })
		} else {
			window.alert(text)
		}
	}

	const send = XMLHttpRequest.prototype.send
	XMLHttpRequest.prototype.send = function(...args) {
		this.addEventListener('load', () => {
			if (this.status === 403 && (this.responseType === '' || this.responseType === 'text')) {
				explain(this.responseText)
			}
		})
		return send.apply(this, args)
	}

	const fetch = window.fetch
	window.fetch = async (...args) => {
		const response = await fetch(...args)
		if (response.status === 403) {
			response.clone().text().then(explain, () => {})
		}
		return response
	}
})()
