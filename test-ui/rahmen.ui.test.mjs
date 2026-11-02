// Browser-Tests für den App-Rahmen (src/App.vue, src/api.js): Zurück-Taste mit ungespeicherten Eingaben (U-KAL-04),
// Meldungen bei 429/abgelaufener Sitzung (U-KAL-06), Fokus und Ansage beim Wechsel (U-KAL-08), eine main-Landmarke
// (U-KAL-13), unbekannter Kalender in der Adresse (Q-KAL-12) und Vorladen des Termins (P-KAL-05).
// Läuft gegen die Test-Nextcloud aus tools/test-nextcloud.sh und legt die Testdaten neu an.
import { execFileSync } from 'node:child_process'
import { after, before, test } from 'node:test'
import assert from 'node:assert/strict'
import { chromium } from 'playwright-core'

const BASIS = process.env.NC_URL ?? 'http://localhost:8080'
const NUTZER = process.env.NC_USER ?? 'admin'
const PASSWORT = process.env.NC_PASS ?? 'Admin-Test-123'
const EVA_PASSWORT = process.env.NC_EVA_PASS ?? 'Eva-Test-12345'
const APP = BASIS + '/index.php/apps/calendar_planning/'

let browser
let kontext
let seite
const fehler = []

before(async () => {
	const [befehl, argumente] = process.platform === 'win32' ? ['bash', ['tools/testdaten.sh']] : ['tools/testdaten.sh', []]
	execFileSync(befehl, argumente, { env: { ...process.env, NC_URL: BASIS, NC_USER: NUTZER, NC_PASS: PASSWORT, NC_EVA_PASS: EVA_PASSWORT }, stdio: 'inherit' })
	browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, headless: !process.env.SICHTBAR, slowMo: process.env.SICHTBAR ? 300 : 0 })
	kontext = await browser.newContext({ viewport: { width: 1280, height: 900 } })
	seite = await kontext.newPage()
	seite.on('pageerror', (e) => fehler.push('JS: ' + e.message))
	seite.on('console', (m) => {
		if (m.type() === 'error' && /Content Security Policy|calendar_planning/i.test(m.text())) {
			fehler.push('Konsole: ' + m.text())
		}
	})
	await seite.goto(BASIS + '/login')
	await seite.fill('#user', NUTZER)
	await seite.fill('#password', PASSWORT)
	await seite.click('button[type=submit]')
	await seite.waitForURL(/apps/)
})

after(async () => {
	await browser?.close()
})

const ansage = () => seite.locator('.planung > [role=status]').innerText()
const fokus = () => seite.evaluate(() => ({ tag: document.activeElement?.tagName, text: document.activeElement?.textContent?.trim() }))

async function listeOeffnen() {
	await seite.goto(APP)
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
}

async function formularOeffnen() {
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').click()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
}

test('Nur eine main-Landmarke (U-KAL-13)', async () => {
	await listeOeffnen()
	assert.equal(await seite.locator('main, [role=main]').count(), 1)
	await formularOeffnen()
	assert.equal(await seite.locator('main, [role=main]').count(), 1)
	assert.deepEqual(fehler, [])
})

test('Wechsel Liste ↔ Formular: Fokus auf der Überschrift, keine doppelte Ansage (U-KAL-08)', async () => {
	await listeOeffnen()
	await formularOeffnen()
	await seite.waitForFunction(() => document.activeElement?.tagName === 'H2')
	const imFormular = await fokus()
	assert.match(imFormular.text, /^Planung: Lötworkshop/)
	// Die fokussierte Überschrift wird vorgelesen; die Live-Region bleibt dann leer.
	assert.equal((await ansage()).trim(), '')

	await seite.getByRole('button', { name: /Zur Liste/ }).click()
	await seite.waitForFunction(() => document.activeElement?.tagName === 'H2')
	assert.equal((await fokus()).text, 'Veranstaltungen planen')
	assert.equal((await ansage()).trim(), '')

	// Auch die Zurück-Taste lässt den Fokus nicht auf BODY fallen.
	await formularOeffnen()
	await seite.goBack()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	await seite.waitForFunction(() => document.activeElement?.tagName === 'H2')
	assert.notEqual((await fokus()).tag, 'BODY')
	assert.deepEqual(fehler, [])
})

test('Wechsel, während der Fokus woanders steht: Fokus bleibt, die Live-Region sagt die Ansicht an (U-KAL-08)', async () => {
	await listeOeffnen()
	// Den Termin zurückhalten, bis der Fokus auf „Zur Liste“ steht (wie ein Klick während des Ladens).
	let freigeben
	const gehalten = new Promise((weiter) => { freigeben = weiter })
	const muster = '**/apps/calendar_planning/api/termin?**'
	await seite.route(muster, async (route) => {
		await gehalten
		await route.continue()
	})
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').click()
	const knopf = seite.getByRole('button', { name: /Zur Liste/ })
	await knopf.waitFor()
	await knopf.focus()
	freigeben()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	await seite.waitForFunction(() => /Angezeigt: Planung: Lötworkshop/.test(document.querySelector('.planung > [role=status]')?.textContent ?? ''))
	assert.match((await fokus()).text, /Zur Liste/)
	await seite.unroute(muster)
	assert.deepEqual(fehler, [])
})

test('Zurück-Taste ohne Änderungen fragt nicht (U-KAL-04)', async () => {
	await listeOeffnen()
	await formularOeffnen()
	await seite.goBack()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	assert.equal(await seite.getByRole('dialog').count(), 0)
	assert.doesNotMatch(seite.url(), /termin=/)
	assert.deepEqual(fehler, [])
})

// Die folgenden drei Tests brauchen das Ereignis `geaendert` aus PlanungFormular.vue (Paket C1).
test('Zurück-Taste mit ungespeicherter Eingabe fragt nach, „Weiter bearbeiten“ behält Eingabe und Adresse (U-KAL-04)', async () => {
	await listeOeffnen()
	await formularOeffnen()
	const adresse = seite.url()
	await seite.getByLabel('Zuständig intern').fill('Noch nicht gespeichert')
	await seite.goBack()
	const dialog = seite.getByRole('dialog')
	await dialog.getByText('Die Änderungen sind noch nicht gespeichert.').waitFor()
	await dialog.getByRole('button', { name: 'Weiter bearbeiten' }).click()
	await dialog.waitFor({ state: 'detached' })
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), 'Noch nicht gespeichert')
	assert.equal(seite.url(), adresse)

	// Zweiter Versuch, diesmal verwerfen: zur Liste, ohne Termin in der Adresse.
	await seite.goBack()
	await dialog.getByText('Die Änderungen sind noch nicht gespeichert.').waitFor()
	await dialog.getByRole('button', { name: 'Änderungen verwerfen' }).click()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	assert.doesNotMatch(seite.url(), /termin=/)
	assert.deepEqual(fehler, [])
})

const dialogText = 'Die Änderungen sind noch nicht gespeichert.'
/** Wartet auf die Rückfrage und klickt einen ihrer Knöpfe; danach ist sie weg. */
async function antworten(knopf) {
	const dialog = seite.getByRole('dialog')
	await dialog.getByText(dialogText).waitFor()
	await dialog.getByRole('button', { name: knopf }).click()
	await dialog.waitFor({ state: 'detached' })
}
const terminInAdresse = () => new URL(seite.url()).searchParams.get('termin')

test('Nach „Änderungen verwerfen“ über die Zurück-Taste steht die Liste nur einmal im Verlauf (U-KAL-04)', async () => {
	await seite.goto('about:blank')
	await listeOeffnen()
	await formularOeffnen()
	await seite.getByLabel('Zuständig intern').fill('Noch nicht gespeichert')
	await seite.goBack()
	await antworten('Änderungen verwerfen')
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	await seite.waitForFunction(() => !new URLSearchParams(location.search).has('termin'))
	// Noch einmal zurück: die Seite vor der App, nicht ein zweites Mal die Liste.
	await seite.goBack()
	await seite.waitForURL('about:blank')
	assert.deepEqual(fehler, [])
})

test('„Weiter bearbeiten“ lässt Vorwärts-Einträge stehen; Vor-Taste mit Eingaben fragt ebenso (U-KAL-04)', async () => {
	// Verlauf: Liste, Formular Lötworkshop, Liste, Formular Offene Werkstatt.
	await listeOeffnen()
	await formularOeffnen()
	await seite.getByRole('button', { name: /Zur Liste/ }).click()
	await seite.locator('tbody').getByText('Offene Werkstatt').first().click()
	await seite.getByRole('heading', { name: /Planung: Offene Werkstatt/ }).waitFor()
	await seite.goBack()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	await seite.goBack()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	const adresse = seite.url()
	const gespeichert = await seite.getByLabel('Zuständig intern').inputValue()
	await seite.getByLabel('Zuständig intern').fill('Noch nicht gespeichert')

	// Zurück und „Weiter bearbeiten“: Formular und Adresse bleiben.
	await seite.goBack()
	await antworten('Weiter bearbeiten')
	await seite.waitForURL(adresse)
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), 'Noch nicht gespeichert')
	// Vor und „Weiter bearbeiten“: ebenso.
	await seite.goForward()
	await antworten('Weiter bearbeiten')
	await seite.waitForURL(adresse)
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), 'Noch nicht gespeichert')
	// Vor und verwerfen: die Liste danach, und die Vor-Taste führt weiter zum zweiten Formular.
	await seite.goForward()
	await antworten('Änderungen verwerfen')
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	await seite.waitForFunction(() => !new URLSearchParams(location.search).has('termin'))
	await seite.goForward()
	await seite.getByRole('heading', { name: /Planung: Offene Werkstatt/ }).waitFor()
	assert.equal(terminInAdresse(), 'serie-1.ics')
	// Und zurück über alle Einträge: Liste, Formular Lötworkshop (ohne Rückfrage, Eingaben sind verworfen), Liste.
	await seite.goBack()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	await seite.goBack()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), gespeichert)
	await seite.goBack()
	await seite.waitForFunction(() => !new URLSearchParams(location.search).has('termin'))
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	assert.equal(await seite.getByRole('dialog').count(), 0)
	assert.deepEqual(fehler, [])
})

test('Vor-Taste ins Formular und wieder zurück, ohne Änderungen ohne Rückfrage (U-KAL-04, U-KAL-08)', async () => {
	await listeOeffnen()
	await formularOeffnen()
	await seite.goBack()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	await seite.goForward()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	await seite.waitForFunction(() => document.activeElement?.tagName === 'H2')
	assert.equal(terminInAdresse(), 'workshop-1.ics')
	await seite.goBack()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	assert.equal(terminInAdresse(), null)
	assert.equal(await seite.getByRole('dialog').count(), 0)
	assert.deepEqual(fehler, [])
})

test('„Zur Liste“ mit ungespeicherter Eingabe fragt mit demselben Dialog (U-KAL-04)', async () => {
	await listeOeffnen()
	await formularOeffnen()
	await seite.getByLabel('Zuständig intern').fill('Noch nicht gespeichert')
	await seite.getByRole('button', { name: /Zur Liste/ }).click()
	const dialog = seite.getByRole('dialog')
	await dialog.getByRole('button', { name: 'Weiter bearbeiten' }).click()
	await dialog.waitFor({ state: 'detached' })
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), 'Noch nicht gespeichert')
	assert.match(seite.url(), /termin=workshop-1\.ics/)

	await seite.getByRole('button', { name: /Zur Liste/ }).click()
	await dialog.getByRole('button', { name: 'Änderungen verwerfen' }).click()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	assert.doesNotMatch(seite.url(), /termin=/)
	assert.deepEqual(fehler, [])
})

test('Schließen mit ungespeicherter Eingabe warnt (beforeunload), ohne Eingabe nicht (U-KAL-04)', async () => {
	const tab = await kontext.newPage()
	const dialoge = []
	tab.on('dialog', (d) => {
		dialoge.push(d.type())
		d.accept()
	})
	await tab.goto(APP + '?termin=workshop-1.ics')
	await tab.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	await tab.getByLabel('Zuständig intern').fill('Noch nicht gespeichert')
	await tab.close({ runBeforeUnload: true })
	await new Promise((fertig) => setTimeout(fertig, 500))
	assert.deepEqual(dialoge, ['beforeunload'])

	const ohne = await kontext.newPage()
	const keine = []
	ohne.on('dialog', (d) => {
		keine.push(d.type())
		d.accept()
	})
	await ohne.goto(APP + '?termin=workshop-1.ics')
	await ohne.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	await ohne.getByLabel('Zuständig intern').click()
	await ohne.close({ runBeforeUnload: true })
	await new Promise((fertig) => setTimeout(fertig, 500))
	assert.deepEqual(keine, [])
})

test('Fehlermeldungen je Fall: 429, abgelaufene Sitzung, Netzfehler (U-KAL-06)', async () => {
	const muster = '**/apps/calendar_planning/api/termine?**'
	const faelle = [
		[(route) => route.fulfill({ status: 429, contentType: 'application/json', body: '{"message":"Reached maximum delay"}' }), /Zu viele Anfragen in kurzer Zeit/],
		[(route) => route.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Current user is not logged in"}' }), /Anmeldung bei der Nextcloud ist abgelaufen/],
		// Nextcloud leitet manchmal auf die Anmeldeseite um: HTML statt JSON.
		[(route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!DOCTYPE html><html><body>Login</body></html>' }), /Anmeldung bei der Nextcloud ist abgelaufen/],
		[(route) => route.fulfill({ status: 412, contentType: 'application/json', body: '{"message":"CSRF check failed"}' }), /Anmeldung bei der Nextcloud ist abgelaufen/],
		[(route) => route.fulfill({ status: 500, contentType: 'text/html', body: 'Fehler' }), /Fehler gemeldet \(Status 500\)/],
		[(route) => route.abort('connectionrefused'), /Verbindung zur Nextcloud fehlgeschlagen/],
	]
	for (const [antwort, erwartet] of faelle) {
		await seite.route(muster, antwort)
		await seite.goto(APP)
		await seite.getByText(erwartet).waitFor()
		assert.doesNotMatch(await seite.locator('.planung').innerText(), /\bnull\b|\bundefined\b/)
		await seite.unroute(muster)
	}
	// Meldungen der App selbst (`fehler`) bleiben, wie sie sind.
	await seite.route(muster, (route) => route.fulfill({ status: 400, contentType: 'application/json', body: '{"fehler":"Zeitraum ungültig (Testmeldung)"}' }))
	await seite.goto(APP)
	await seite.getByText('Zeitraum ungültig (Testmeldung)').waitFor()
	await seite.unroute(muster)
})

test('Unbekannter Kalender in der Adresse: Hinweis und korrigierte Adresse (Q-KAL-12)', async () => {
	await seite.goto(APP + '?kalender=999999&termin=workshop-1.ics&seite=2')
	await seite.getByText(/Der Kalender aus der Adresse ist nicht \(mehr\) verfügbar\. Gezeigt wird „.+“\./).waitFor()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	const adresse = new URL(seite.url())
	assert.notEqual(adresse.searchParams.get('kalender'), '999999')
	assert.match(adresse.searchParams.get('kalender') ?? '', /^\d+$/)
	assert.equal(adresse.searchParams.get('termin'), null)
	assert.equal(adresse.searchParams.get('seite'), null)
	// Gültiger Kalender in der Adresse: kein Hinweis.
	await seite.goto(APP + '?kalender=' + adresse.searchParams.get('kalender'))
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	assert.equal(await seite.getByText(/nicht \(mehr\) verfügbar/).count(), 0)
	assert.deepEqual(fehler, [])
})

test('Start mit Termin in der Adresse lädt den Termin nur einmal, parallel zum Rest (P-KAL-05)', async () => {
	await listeOeffnen()
	const kalender = new URL(seite.url()).searchParams.get('kalender')
	const anfragen = []
	const zaehlen = (anfrage) => {
		const pfad = new URL(anfrage.url()).pathname
		if (pfad.includes('/apps/calendar_planning/api/')) {
			anfragen.push(pfad.replace(/.*\/api/, ''))
		}
	}
	seite.on('request', zaehlen)
	await seite.goto(APP + `?kalender=${kalender}&termin=workshop-1.ics`)
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	seite.off('request', zaehlen)
	assert.equal(anfragen.filter((a) => a === '/termin').length, 1)
	// Die Terminanfrage startet vor dem Ende der Kalenderliste, nicht erst danach.
	assert.ok(anfragen.indexOf('/termin') < 3, anfragen.join(', '))
	assert.deepEqual(fehler, [])
})
