// Browser-Test gegen eine laufende Test-Nextcloud mit aktivierter App und Kalender-App (tools/test-nextcloud.sh).
// Legt vorher mit tools/testdaten.sh den Kalender „Planungskalender Verein“ mit Testterminen neu an.
import { execFileSync } from 'node:child_process'
import { after, before, test } from 'node:test'
import assert from 'node:assert/strict'
import { chromium } from 'playwright-core'

const BASIS = process.env.NC_URL ?? 'http://localhost:8080'
const NUTZER = process.env.NC_USER ?? 'admin'
const PASSWORT = process.env.NC_PASS ?? 'Admin-Test-123'
const EVA_PASSWORT = process.env.NC_EVA_PASS ?? 'Eva-Test-12345'

let browser
let seite
const fehler = []

/** Legt die Testdaten über tools/testdaten.sh an; unter Windows läuft das Skript über die Git Bash. */
function testdatenAnlegen() {
	const [befehl, argumente] = process.platform === 'win32' ? ['bash', ['tools/testdaten.sh']] : ['tools/testdaten.sh', []]
	execFileSync(befehl, argumente, { env: { ...process.env, NC_URL: BASIS, NC_USER: NUTZER, NC_PASS: PASSWORT, NC_EVA_PASS: EVA_PASSWORT }, stdio: 'inherit' })
}

before(async () => {
	testdatenAnlegen()
	browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined })
	seite = await browser.newPage({ viewport: { width: 1280, height: 900 } })
	seite.on('pageerror', (e) => fehler.push('JS: ' + e.message))
	seite.on('console', (m) => {
		if (m.type() === 'error' && /Content Security Policy|calendar_planning/i.test(m.text())) {
			fehler.push('Konsole: ' + m.text())
		}
	})
	await anmelden(seite, NUTZER, PASSWORT)
})

async function anmelden(tab, nutzer, passwort) {
	await tab.goto(BASIS + '/login')
	await tab.fill('#user', nutzer)
	await tab.fill('#password', passwort)
	await tab.click('button[type=submit]')
	await tab.waitForURL(/apps/)
}

after(async () => {
	await browser?.close()
})

test('Liste lädt ohne Fehler und ohne „null“', async () => {
	await seite.goto(BASIS + '/index.php/apps/calendar_planning/')
	await seite.getByText('Lötworkshop Grundschule').waitFor()
	const text = await seite.locator('.planung').innerText()
	assert.doesNotMatch(text, /\bnull\b|\bundefined\b/)
	assert.match(text, /Seite 1 von 2 · 33 Einträge/)
	assert.match(seite.url(), /kalender=\d+/)
	assert.deepEqual(fehler, [])
})

test('Planung erfassen, speichern und nach Neuladen wiederfinden', async () => {
	await seite.getByText('Lötworkshop Grundschule').click()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	assert.match(seite.url(), /termin=workshop-1\.ics/)

	await seite.getByRole('radio', { name: 'Externer Ort' }).check()
	await seite.getByLabel('Externer Ort – Adresse').fill('Grundschule Musterstadt\nSchulstraße 1')
	await seite.getByLabel('Zuständig intern').fill('Eva')
	await seite.getByLabel('E-Mail').fill('kontakt@example.org')
	await seite.getByLabel('Benötigte Ausstattung / Technik').fill('Lötstationen, 3D-Drucker')
	await seite.getByRole('button', { name: 'Speichern' }).click()
	await seite.getByText('Gespeichert.').waitFor()

	await seite.reload()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), 'Eva')
	assert.equal(await seite.getByLabel('Externer Ort – Adresse').inputValue(), 'Grundschule Musterstadt\nSchulstraße 1')
	assert.equal(await seite.getByLabel('Benötigte Ausstattung / Technik').inputValue(), 'Lötstationen, 3D-Drucker')
	assert.deepEqual(fehler, [])
})

test('Ungültige E-Mail zeigt die Meldung des Servers', async () => {
	await seite.getByLabel('E-Mail').fill('kein-mail')
	// Der Browser prüft type=email selbst; die Prüfung des Servers über das Formular umgehen.
	await seite.locator('form').evaluate((f) => { f.noValidate = true })
	await seite.getByRole('button', { name: 'Speichern' }).click()
	await seite.getByText('Die E-Mail-Adresse des Kontakts ist ungültig').waitFor()
	await seite.getByRole('button', { name: 'Änderungen verwerfen' }).click()
})

test('Zurück zur Liste zeigt den Termin als erfasst', async () => {
	await seite.getByRole('button', { name: /Zur Liste/ }).click()
	const zeile = seite.locator('tr', { hasText: 'Lötworkshop Grundschule' })
	await zeile.getByText('erfasst').waitFor()
	assert.match(await zeile.innerText(), /Eva/)
	assert.deepEqual(fehler, [])
})

test('Suche filtert und steht in der Adresse', async () => {
	await seite.getByPlaceholder(/Titel, Kontakt/).fill('Werkstatt')
	await seite.waitForURL(/suche=Werkstatt/)
	await seite.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1)
	assert.match(await seite.locator('tbody').innerText(), /Offene Werkstatt/)
	await seite.goBack()
	await seite.getByText('Lötworkshop Grundschule').waitFor()
})

test('Handy-Breite: kein waagerechtes Scrollen der Seite', async () => {
	await seite.setViewportSize({ width: 390, height: 800 })
	await seite.reload()
	await seite.getByText('Lötworkshop Grundschule').waitFor()
	const breit = await seite.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)
	assert.equal(breit, false)
	if (process.env.SCREENSHOT_DIR) {
		await seite.screenshot({ path: process.env.SCREENSHOT_DIR + '/liste-handy.png' })
	}
	await seite.setViewportSize({ width: 1280, height: 900 })
})

test('Blättern: Seite steht in der Adresse und übersteht Neuladen', async () => {
	await seite.goto(BASIS + '/index.php/apps/calendar_planning/')
	await seite.getByText('Lötworkshop Grundschule').waitFor()
	await seite.getByRole('button', { name: '2', exact: true }).click()
	await seite.waitForURL(/seite=2/)
	await seite.getByText(/Seite 2 von 2/).waitFor()
	assert.equal(await seite.locator('tbody tr').count(), 8)
	await seite.reload()
	await seite.getByText(/Seite 2 von 2/).waitFor()
	await seite.locator('.seiten select').selectOption('50')
	await seite.waitForURL(/pro_seite=50/)
	await seite.getByText(/Seite 1 von 1 · 33 Einträge/).waitFor()
	assert.deepEqual(fehler, [])
})

test('Lesefreigabe: private Termine fehlen, Formular nur zum Lesen', async () => {
	const kontext = await browser.newContext()
	const tab = await kontext.newPage()
	const evaFehler = []
	tab.on('pageerror', (e) => evaFehler.push(e.message))
	await anmelden(tab, 'eva', EVA_PASSWORT)
	await tab.goto(BASIS + '/index.php/apps/calendar_planning/?pro_seite=50')
	await tab.getByText('Lötworkshop Grundschule').waitFor()
	const text = await tab.locator('.planung').innerText()
	assert.doesNotMatch(text, /Privater Termin/)
	assert.match(text, /32 Einträge/)
	assert.match(text, /nur lesen/)
	await tab.getByText('Lötworkshop Grundschule').click()
	await tab.getByText('Diesen Kalender darfst du nur lesen.').waitFor()
	assert.equal(await tab.getByRole('button', { name: 'Speichern' }).count(), 0)
	assert.equal(await tab.getByLabel('Zuständig intern').isDisabled(), true)
	assert.deepEqual(evaFehler, [])
	await kontext.close()
})

test('Kalenderauswahl wird je Konto gespeichert', async () => {
	const api = async (methode, pfad, daten) => {
		const antwort = await fetch(`${BASIS}/index.php/apps/calendar_planning/api${pfad}`, {
			method: methode,
			headers: {
				Authorization: 'Basic ' + Buffer.from(`${NUTZER}:${PASSWORT}`).toString('base64'),
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			body: daten === undefined ? undefined : JSON.stringify(daten),
		})
		return { status: antwort.status, daten: await antwort.json() }
	}
	const gewaehlt = async () => (await api('GET', '/kalender')).daten.kalender.find((k) => k.gewaehlt)?.name

	const { daten } = await api('GET', '/kalender')
	const planungskalender = daten.kalender.find((k) => k.name === 'Planungskalender Verein')
	const anderer = daten.kalender.find((k) => k.id !== planungskalender.id)
	assert.ok(anderer, 'Test braucht einen zweiten Kalender')
	assert.equal(await gewaehlt(), planungskalender.name)

	assert.equal((await api('PUT', '/kalender/auswahl', { kalender: anderer.id })).status, 200)
	assert.equal(await gewaehlt(), anderer.name)
	assert.equal((await api('PUT', '/kalender/auswahl', { kalender: 999999 })).status, 404)
	assert.equal(await gewaehlt(), anderer.name)

	assert.equal((await api('PUT', '/kalender/auswahl', { kalender: planungskalender.id })).status, 200)
	assert.equal(await gewaehlt(), planungskalender.name)
})

test('Kalender-App: Bearbeiten des Termins lässt die Planung stehen', async () => {
	const ics = async () => {
		const antwort = await fetch(`${BASIS}/remote.php/dav/calendars/${NUTZER}/planung-test/workshop-1.ics`, {
			headers: { Authorization: 'Basic ' + Buffer.from(`${NUTZER}:${PASSWORT}`).toString('base64') },
		})
		return antwort.text()
	}
	const vorher = await ics()
	assert.match(vorher, /X-CALPLAN-ZUSTAENDIG:Eva/)
	const [, jahr, monat, tag] = vorher.match(/DTSTART;TZID=Europe\/Berlin:(\d{4})(\d{2})(\d{2})/)

	// Englische Oberfläche, damit die Bezeichnungen der Kalender-App fest sind.
	const kontext = await browser.newContext({ locale: 'en-US', timezoneId: 'Europe/Berlin', viewport: { width: 1400, height: 1000 } })
	const tab = await kontext.newPage()
	const kalenderFehler = []
	tab.on('pageerror', (e) => kalenderFehler.push(e.message))
	await anmelden(tab, NUTZER, PASSWORT)
	await tab.goto(`${BASIS}/index.php/apps/calendar/timeGridWeek/${jahr}-${monat}-${tag}`)
	await tab.getByText('Lötworkshop Grundschule').first().click()
	// Der Link aus der Beschreibung ist im Termin anklickbar.
	await tab.locator('a[href*="/apps/calendar_planning/"]').first().waitFor()
	await tab.getByRole('button', { name: 'Edit', exact: true }).click()
	await tab.getByPlaceholder('Title').fill('Lötworkshop Grundschule (geändert in der Kalender-App)')
	await tab.getByRole('button', { name: 'Update', exact: true }).click()
	await tab.getByPlaceholder('Title').waitFor({ state: 'detached' })
	await kontext.close()

	let nachher = ''
	for (let versuch = 0; versuch < 20 && !nachher.includes('geändert in der Kalender-App'); versuch++) {
		await new Promise((fertig) => setTimeout(fertig, 250))
		nachher = await ics()
	}
	assert.match(nachher, /SUMMARY:Lötworkshop Grundschule \(geändert in der Kalender-App\)/)
	for (const zeile of vorher.split(/\r?\n/).filter((z) => z.startsWith('X-CALPLAN-'))) {
		assert.ok(nachher.includes(zeile), `fehlt nach dem Bearbeiten: ${zeile}`)
	}
	assert.match(nachher.replace(/\r?\n /g, ''), /Planung öffnen: http/)
	assert.deepEqual(kalenderFehler, [])
})
