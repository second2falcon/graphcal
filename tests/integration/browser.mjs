// SPDX-FileCopyrightText: 2026 GraphCal contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Browser test of the settings pages, the sign-in popup and the Calendar app, against the same setup
// as run.php. Saves a screenshot of every step to SCREENSHOT_DIR (uploaded by CI as an artifact).
// Usage: NC_DIR=/path/to/server NC_URL=http://127.0.0.1:8080 MOCK_URL=http://127.0.0.1:8090 node browser.mjs
import { execFileSync, execSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import { chromium } from 'playwright'

const nc = process.env.NC_URL ?? 'http://127.0.0.1:8080'
const mock = process.env.MOCK_URL ?? 'http://127.0.0.1:8090'
const dir = process.env.SCREENSHOT_DIR ?? '/tmp/graphcal-screenshots'
mkdirSync(dir, { recursive: true })

const browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {})
let failures = 0
let shots = 0
const check = (ok, what, detail = '') => {
	console.log(`${ok ? '  ok  ' : '  FAIL'} ${what}${ok || !detail ? '' : '\n       ' + detail}`)
	failures += ok ? 0 : 1
}
/** Full-page screenshot, or just one element when a locator is given. */
async function shot(page, name, locator = null) {
	const path = `${dir}/${String(++shots).padStart(2, '0')}-${name}.png`
	await page.waitForLoadState('networkidle').catch(() => {})
	await (locator ?? page).screenshot(locator ? { path } : { path, fullPage: true })
	console.log(`       screenshot ${path}`)
}

async function login(user, password) {
	const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, timezoneId: 'Europe/Amsterdam', locale: 'en-GB' })
	const page = await context.newPage()
	page.on('pageerror', (e) => check(false, `no JavaScript errors (${user})`, e.message))
	await page.goto(`${nc}/index.php/login`)
	await page.fill('input[name="user"]', user)
	await page.fill('input[name="password"]', password)
	await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login')), page.click('button[type="submit"]')])
	return page
}

/** Create or change an event in the mocked Outlook mailbox. */
async function outlook(event) {
	await fetch(`${mock}/_event`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(event) })
}
const day = (offset, time) => `${new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10)}T${time}.0000000`

console.log('Admin settings (Entra ID app registration)')
const admin = await login('admin', process.env.ADMIN_PASS ?? 'admin')
await admin.goto(`${nc}/index.php/settings/admin/graphcal`)
const section = admin.locator('#graphcal-admin')
await section.waitFor()
check(await admin.getByRole('link', { name: 'GraphCal' }).first().isVisible(), 'own tab in Administration settings')
check((await admin.inputValue('#graphcal-admin input[readonly]')).endsWith('/apps/graphcal/oauth/callback'), 'redirect URI is shown')
await admin.fill('#graphcal-admin-form input[name="tenant_id"]', '72f988bf-86f1-41af-91ab-2d7cd011db47')
await admin.fill('#graphcal-admin-form input[name="client_id"]', '3fa85f64-5717-4562-b3fc-2c963f66afa6')
await admin.click('#graphcal-admin-form button[type="submit"]')
await admin.locator('#graphcal-admin-msg', { hasText: 'Saved' }).waitFor({ timeout: 10000 })
check(true, 'admin form saves')
await shot(admin, 'admin-entra-app-registration', section)
await shot(admin, 'admin-settings-tab')

console.log('Personal settings and sign-in popup')
await outlook({ id: 'demo-meeting', subject: 'Quarterly planning', isAllDay: false, isOrganizer: false,
	start: { dateTime: day(1, '09:00:00'), timeZone: 'UTC' }, end: { dateTime: day(1, '10:30:00'), timeZone: 'UTC' },
	originalStartTimeZone: 'W. Europe Standard Time', location: { displayName: 'Boardroom' },
	organizer: { emailAddress: { address: 'boss@contoso.test', name: 'Bea Boss' } },
	responseStatus: { response: 'notResponded' },
	attendees: [
		{ type: 'required', emailAddress: { address: 'alice@contoso.test', name: 'Alice' }, status: { response: 'none' } },
		{ type: 'required', emailAddress: { address: 'carol@contoso.test', name: 'Carol' }, status: { response: 'accepted' } },
	] })
await outlook({ id: 'demo-allday', subject: 'Team offsite', isAllDay: true, showAs: 'oof',
	start: { dateTime: day(2, '00:00:00'), timeZone: 'UTC' }, end: { dateTime: day(3, '00:00:00'), timeZone: 'UTC' } })
await outlook({ id: 'demo-series', type: 'seriesMaster', subject: 'Weekly stand-up', isAllDay: false,
	start: { dateTime: day(0, '07:30:00'), timeZone: 'UTC' }, end: { dateTime: day(0, '07:45:00'), timeZone: 'UTC' },
	originalStartTimeZone: 'W. Europe Standard Time',
	recurrence: { pattern: { type: 'daily', interval: 1 }, range: { type: 'numbered', numberOfOccurrences: 10, startDate: day(0, '00:00:00').slice(0, 10) } } })

const bob = await login('bob', 'Graphcal-Test-Pass-7731')
await bob.goto(`${nc}/index.php/settings/user/graphcal`)
const connect = bob.getByRole('button', { name: 'Connect Microsoft 365' })
await connect.waitFor()
check(true, 'connect button shown to a new user')
await shot(bob, 'personal-not-connected')
const [popup] = await Promise.all([bob.waitForEvent('popup'), connect.click()])
await popup.waitForEvent('close', { timeout: 20000 }).catch(() => check(false, 'popup closes itself after sign-in', popup.url()))
await bob.getByText('Connected as').waitFor({ timeout: 20000 })
check(true, 'settings page updates after the popup reports back')
const calendar = bob.getByLabel('Calendar', { exact: true })
check(await calendar.isChecked(), 'own Outlook calendar is synced by default')
await shot(bob, 'personal-connected')

const failed = await bob.context().newPage()
await failed.goto(`${nc}/index.php/apps/graphcal/oauth/callback?code=x&state=expired`)
await shot(failed, 'sign-in-error-page')
await failed.close()

console.log('Calendar app')
if (process.env.OCC) {
	execSync(`${process.env.OCC} background-job:worker --once 'OCA\\GraphCal\\BackgroundJob\\DeltaJob'`)
} else if (process.env.NC_DIR) {
	// What Nextcloud's cron does (php -S has no PHP-FPM to pull right after the sign-in response).
	execFileSync('php', [`${process.env.NC_DIR}/occ`, 'background-job:worker', '--once', 'OCA\\GraphCal\\BackgroundJob\\DeltaJob'])
}
const app = await bob.goto(`${nc}/index.php/apps/calendar/timeGridWeek/now`)
if (app?.ok()) {
	const event = bob.locator('.fc-event', { hasText: 'Quarterly planning' }).first()
	await event.waitFor({ timeout: 20000 }).catch(() => {})
	check(await event.isVisible(), 'Outlook meeting shows in the Calendar app')
	check(await bob.locator('.fc-event', { hasText: 'Weekly stand-up' }).first().isVisible(), 'Outlook series shows in the Calendar app')
	await shot(bob, 'calendar-week')
	if (await event.isVisible()) {
		await event.click()
		await bob.getByText('Bea Boss').first().waitFor({ timeout: 10000 }).catch(() => {})
		check(await bob.getByText('Carol').first().isVisible(), 'attendees from Outlook are listed in the event')
		await shot(bob, 'calendar-meeting-details')
		await bob.keyboard.press('Escape')
	}
	await bob.goto(`${nc}/index.php/apps/calendar/dayGridMonth/now`)
	await bob.locator('.fc-event').first().waitFor({ timeout: 20000 }).catch(() => {})
	await shot(bob, 'calendar-month')

	console.log('Calendar app: a repeat pattern Microsoft 365 cannot store is refused')
	await bob.goto(`${nc}/index.php/apps/calendar/timeGridWeek/now`)
	await bob.getByRole('button', { name: 'Event' }).first().click()
	await bob.getByPlaceholder('Title').waitFor()
	// Pick the synced Outlook calendar ("Calendar") instead of the default "Personal".
	await bob.getByText('Personal', { exact: true }).last().click()
	await bob.locator('.v-popper__popper:visible').getByText('Calendar', { exact: true }).click()
	await bob.getByPlaceholder('Title').fill('Payroll run')
	await bob.getByText('Does not repeat').locator('xpath=..').getByRole('button').first().click()
	const repeat = bob.getByRole('dialog', { name: 'Repeat event' })
	await repeat.getByRole('combobox').click()
	await bob.getByRole('option', { name: 'month', exact: true }).click()
	// The start day is preselected; adding the 1st makes "every month on two days" (BYMONTHDAY=1,<start>).
	await repeat.getByRole('button', { name: '1', exact: true }).click()
	await shot(bob, 'calendar-unsupported-repeat-chosen')
	await repeat.getByRole('button', { name: 'Set repetition' }).click()
	await bob.getByRole('button', { name: 'Save', exact: true }).click()
	const refused = bob.locator('.toastify', { hasText: 'Failed to save event' })
	await refused.waitFor({ timeout: 10000 }).catch(() => {})
	check(await refused.isVisible(), 'saving "monthly on two days" in the Outlook calendar is refused')
	await shot(bob, 'calendar-unsupported-repeat-refused')
	const sent = JSON.parse(await (await fetch(`${mock}/_state`)).text()).requests.filter((r) => r.body?.subject === 'Payroll run')
	check(sent.length === 0, 'the refused event was not sent to Exchange', JSON.stringify(sent))
} else {
	console.log('       Calendar app not installed, skipped')
}

console.log('Turning a calendar off')
await bob.goto(`${nc}/index.php/settings/user/graphcal`)
await bob.getByText('Connected as').waitFor()
// Nextcloud's .checkbox style hides the native input; users click the label.
await Promise.all([
	bob.waitForResponse((r) => r.url().includes('/settings/personal/calendar/')),
	bob.locator('label', { hasText: /^Calendar$/ }).click(),
])
await bob.reload()
await bob.getByText('Connected as').waitFor()
check(!(await bob.getByLabel('Calendar', { exact: true }).isChecked()), 'unticking a calendar stops syncing it')
await shot(bob, 'personal-calendar-unticked')

await browser.close()
console.log(failures === 0 ? `\nAll browser checks passed (${shots} screenshots in ${dir})` : `\n${failures} browser check(s) failed`)
process.exit(failures === 0 ? 0 : 1)
