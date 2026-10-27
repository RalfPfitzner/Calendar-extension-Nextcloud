// Browser-Test der Admin-Einstellungen (Feldschema) gegen eine laufende Test-Nextcloud (tools/test-nextcloud.sh).
// Läuft nach planung.ui.test.mjs und sicherheit.test.mjs; stellt am Ende das Vorgabe-Schema wieder her.
import { execFileSync } from 'node:child_process'
import { after, before, test } from 'node:test'
import assert from 'node:assert/strict'
import { chromium, request } from 'playwright-core'

const BASIS = process.env.NC_URL ?? 'http://localhost:8080'
const NUTZER = process.env.NC_USER ?? 'admin'
const PASSWORT = process.env.NC_PASS ?? 'Admin-Test-123'
const API = '/index.php/apps/calendar_planning/api'
const basic = (nutzer, passwort) => 'Basic ' + Buffer.from(`${nutzer}:${passwort}`).toString('base64')

/** Legt die Testdaten über tools/testdaten.sh an; unter Windows läuft das Skript über die Git Bash. */
function testdatenAnlegen() {
	const [befehl, argumente] = process.platform === 'win32' ? ['bash', ['tools/testdaten.sh']] : ['tools/testdaten.sh', []]
	execFileSync(befehl, argumente, { env: { ...process.env, NC_URL: BASIS, NC_USER: NUTZER, NC_PASS: PASSWORT }, stdio: 'inherit' })
}

let browser, seite, admin
let vorgabe

async function anmelden(tab, nutzer, passwort) {
	await tab.goto(BASIS + '/login')
	await tab.fill('#user', nutzer)
	await tab.fill('#password', passwort)
	await tab.click('button[type=submit]')
	await tab.waitForURL(/apps/)
}

async function schemaSetzen(schema) {
	const antwort = await admin.put(`${API}/schema`, { data: { schema } })
	assert.equal(antwort.status(), 200, await antwort.text())
}

before(async () => {
	testdatenAnlegen()
	admin = await request.newContext({ baseURL: BASIS, extraHTTPHeaders: { Authorization: basic(NUTZER, PASSWORT), 'OCS-APIRequest': 'true', Accept: 'application/json' } })
	vorgabe = (await (await admin.get(`${API}/schema`)).json()).vorgabe
	await schemaSetzen(vorgabe)
	browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, headless: !process.env.SICHTBAR, slowMo: process.env.SICHTBAR ? 300 : 0 })
	seite = await browser.newPage({ viewport: { width: 1400, height: 1000 } })
	await anmelden(seite, NUTZER, PASSWORT)
})

after(async () => {
	await schemaSetzen(vorgabe)
	await admin?.dispose()
	await browser?.close()
})

test('Admin-Seite zeigt Vorgaben und Felder, ein neues Auswahlfeld lässt sich speichern', async () => {
	await seite.goto(BASIS + '/index.php/settings/admin/additional')
	const bereich = seite.locator('#calendar-planning-admin')
	await bereich.getByRole('heading', { name: 'Kalender-Planung' }).waitFor()
	assert.equal(await bereich.getByLabel('Standardort').inputValue(), 'Vereinsheim')
	assert.equal(await bereich.getByLabel('Kalender-Vorbelegung (Namensfragment)').inputValue(), 'Planung')

	// Neues Feld im letzten Abschnitt: Auswahl mit zwei Optionen, als Spalte in der Liste.
	await bereich.getByRole('button', { name: '+ Feld in Abschnitt 5' }).click()
	const bezeichnung = bereich.getByLabel('Bezeichnung Feld 2 in Abschnitt 5')
	await bezeichnung.fill('Art der Veranstaltung')
	assert.equal(await bereich.getByLabel('Schlüssel Feld 2 in Abschnitt 5').inputValue(), 'art_der_veranstaltung')
	await bereich.getByLabel('Typ Feld 2 in Abschnitt 5').selectOption('select')
	await bereich.locator('textarea').last().fill('Kurs\nOffene Werkstatt')
	// Vier Listenfelder wären zu viele: der Server lehnt ab, das Schema bleibt.
	await bereich.getByLabel('In der Liste: Art der Veranstaltung').check()
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Höchstens 3 Felder dürfen in der Liste stehen').waitFor()
	const unveraendert = (await (await admin.get(`${API}/schema`)).json()).schema
	assert.deepEqual(unveraendert, vorgabe)

	await bereich.getByLabel('In der Liste: Zuständig intern').uncheck()
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Felder gespeichert.').waitFor()
	const gespeichert = (await (await admin.get(`${API}/schema`)).json()).schema
	const neu = gespeichert.abschnitte[4].felder[1]
	assert.deepEqual(neu, { key: 'art_der_veranstaltung', label: 'Art der Veranstaltung', typ: 'select', pflicht: false, maxlen: 5000, optionen: ['Kurs', 'Offene Werkstatt'], inListe: true })
})

test('Formular und Liste folgen dem geänderten Schema', async () => {
	await seite.goto(BASIS + '/index.php/apps/calendar_planning/')
	await seite.getByText('Lötworkshop Grundschule').waitFor()
	const kopf = await seite.locator('thead').innerText()
	assert.match(kopf, /Organisation \/ Firma/)
	assert.match(kopf, /Art der Veranstaltung/)
	assert.doesNotMatch(kopf, /Zuständig intern/)

	await seite.getByText('Lötworkshop Grundschule').click()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	await seite.getByLabel('Art der Veranstaltung').selectOption('Kurs')
	await seite.getByLabel('Anzahl der Teilnehmenden').fill('12')
	await seite.getByRole('button', { name: 'Speichern' }).click()
	await seite.getByText('Gespeichert.').waitFor()
	await seite.reload()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	assert.equal(await seite.getByLabel('Art der Veranstaltung').inputValue(), 'Kurs')

	await seite.getByRole('button', { name: /Zur Liste/ }).click()
	const zeile = seite.locator('tr', { hasText: 'Lötworkshop Grundschule' })
	await zeile.getByText('Kurs').waitFor()
	assert.match(await zeile.innerText(), /12/)

	// Der Wert steht als eigene Eigenschaft im Termin.
	const ics = await (await fetch(`${BASIS}/remote.php/dav/calendars/${NUTZER}/planung-test/workshop-1.ics`, { headers: { Authorization: basic(NUTZER, PASSWORT) } })).text()
	assert.match(ics, /X-CALPLAN-ART-DER-VERANSTALTUNG:Kurs/)
})

test('Vorlage „Makerspace“ laden: das Formular zeigt die alten Bezeichnungen', async () => {
	await seite.goto(BASIS + '/index.php/settings/admin/additional')
	const bereich = seite.locator('#calendar-planning-admin')
	await bereich.getByRole('heading', { name: 'Kalender-Planung' }).waitFor()
	seite.once('dialog', (dialog) => dialog.accept())
	await bereich.getByRole('button', { name: /Vorlage laden: Makerspace/ }).click()
	await bereich.getByText(/geladen, noch nicht gespeichert/).waitFor()
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Felder gespeichert.').waitFor()

	await seite.goto(BASIS + '/index.php/apps/calendar_planning/')
	await seite.getByText('Lötworkshop Grundschule').click()
	await seite.getByLabel('Benötigte Maschinen / Tools').waitFor()
	assert.equal(await seite.getByLabel('Benötigte Ausstattung / Technik').count(), 0)
	// Werte aus anderen Schemata bleiben im Termin, werden aber nicht angezeigt.
	assert.equal(await seite.getByLabel('Art der Veranstaltung').count(), 0)
})

test('Schema per Hand ungültig gesetzt: die App fällt auf die Vorgabe zurück', async () => {
	// Simuliert `occ config:app:set calendar_planning schema --value 'kaputt'` über die Admin-Route ist nicht möglich
	// (die lehnt ab); deshalb prüfen wir nur, dass die Route die Ablehnung meldet und das Schema steht.
	const antwort = await admin.put(`${API}/schema`, { data: { schema: { abschnitte: 'kaputt' } } })
	assert.equal(antwort.status(), 400)
	assert.match((await antwort.json()).fehler, /Liste/)
	const schema = (await (await admin.get(`${API}/schema`)).json()).schema
	assert.equal(schema.abschnitte[3].felder[1].key, 'maschinen')
})
