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
		show(text)
	}

	// Own notice instead of OCP.Toast: that API is deprecated and on Nextcloud 35 only renders the screen-reader text.
	function show(text) {
		document.querySelector('.graphcal-toast')?.remove()
		const box = document.createElement('div')
		box.className = 'graphcal-toast'
		box.setAttribute('role', 'alert')
		box.textContent = text
		Object.assign(box.style, {
			position: 'fixed', top: '120px', right: '12px', zIndex: '100000', maxWidth: '420px',
			padding: '12px 40px 12px 14px', lineHeight: '1.4',
			background: 'var(--color-main-background, #fff)', color: 'var(--color-main-text, #222)',
			borderLeft: '4px solid var(--color-error, #d91812)', borderRadius: 'var(--border-radius-large, 10px)',
			boxShadow: '0 0 6px 0 var(--color-box-shadow, rgba(0, 0, 0, .25))',
		})
		const close = document.createElement('button')
		close.type = 'button'
		close.textContent = '✕'
		close.setAttribute('aria-label', 'Close')
		Object.assign(close.style, { position: 'absolute', top: '6px', right: '6px', minHeight: 'auto', padding: '4px 8px', border: 'none', background: 'none', cursor: 'pointer' })
		close.addEventListener('click', () => box.remove())
		box.append(close)
		document.body.append(box)
		setTimeout(() => box.remove(), 20000)
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

	// Wrap fetch once the page has loaded: Nextcloud core installs its own fetch wrapper while loading,
	// and the Calendar app calls whatever window.fetch is at request time.
	function wrapFetch() {
		const fetch = window.fetch
		window.fetch = async (...args) => {
			const response = await fetch(...args)
			if (response.status === 403) {
				response.clone().text().then(explain, () => {})
			}
			return response
		}
	}
	if (document.readyState === 'complete') {
		wrapFetch()
	} else {
		window.addEventListener('load', wrapFetch, { once: true })
	}
})()
