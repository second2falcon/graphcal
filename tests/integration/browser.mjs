// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Browser smoke test of the settings pages and the sign-in popup, against the same setup as run.php.
// Usage: NC_URL=http://127.0.0.1:8080 node tests/integration/browser.mjs
import { chromium } from 'playwright'

const nc = process.env.NC_URL ?? 'http://127.0.0.1:8080'
const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {})
let failures = 0
const check = (ok, what, detail = '') => {
	console.log(`${ok ? '  ok  ' : '  FAIL'} ${what}${ok || !detail ? '' : '\n       ' + detail}`)
	failures += ok ? 0 : 1
}

async function login(user, password) {
	const context = await browser.newContext()
	const page = await context.newPage()
	page.on('pageerror', (e) => check(false, `no JavaScript errors (${user})`, e.message))
	await page.goto(`${nc}/index.php/login`)
	await page.fill('input[name="user"]', user)
	await page.fill('input[name="password"]', password)
	await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login')), page.click('button[type="submit"]')])
	return page
}

console.log('Admin settings')
const admin = await login('admin', 'admin')
await admin.goto(`${nc}/index.php/settings/admin/groupware`)
await admin.locator('#graphcal-admin').waitFor()
check((await admin.inputValue('#graphcal-admin input[readonly]')).endsWith('/apps/graphcal/oauth/callback'), 'redirect URI is shown')
await admin.fill('#graphcal-admin-form input[name="tenant_id"]', 'tenant')
await admin.click('#graphcal-admin-form button[type="submit"]')
await admin.locator('#graphcal-admin-msg', { hasText: 'Saved' }).waitFor({ timeout: 10000 })
check(true, 'admin form saves')

console.log('Personal settings and sign-in popup')
const bob = await login('bob', 'Secret-Pass-123')
await bob.goto(`${nc}/index.php/settings/user/graphcal`)
const connect = bob.getByRole('button', { name: 'Connect Microsoft 365' })
await connect.waitFor()
check(true, 'connect button shown to a new user')
const [popup] = await Promise.all([bob.waitForEvent('popup'), connect.click()])
await popup.waitForEvent('close', { timeout: 20000 }).catch(() => check(false, 'popup closes itself after sign-in', popup.url()))
await bob.getByText('Connected as').waitFor({ timeout: 20000 })
check(true, 'settings page updates after the popup reports back')
const calendar = bob.getByLabel('Calendar', { exact: true })
check(await calendar.isChecked(), 'own Outlook calendar is synced by default')
await bob.screenshot({ path: process.env.SCREENSHOT ?? '/tmp/graphcal-settings.png', fullPage: true })
// Nextcloud's .checkbox style hides the native input; users click the label.
await Promise.all([
	bob.waitForResponse((r) => r.url().includes('/settings/personal/calendar/')),
	bob.locator('label', { hasText: /^Calendar$/ }).click(),
])
await bob.reload()
await bob.getByText('Connected as').waitFor()
check(!(await bob.getByLabel('Calendar', { exact: true }).isChecked()), 'unticking a calendar stops syncing it')

await browser.close()
console.log(failures === 0 ? '\nAll browser checks passed' : `\n${failures} browser check(s) failed`)
process.exit(failures === 0 ? 0 : 1)
