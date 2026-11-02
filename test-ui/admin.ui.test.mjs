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
// Adresse der Admin-Einstellungsseite, nur hier (ändert sich, wenn die App einen eigenen Bereich bekommt).
const ADMIN_SEITE = '/index.php/settings/admin/calendar_planning'
const basic = (nutzer, passwort) => 'Basic ' + Buffer.from(`${nutzer}:${passwort}`).toString('base64')

/** Legt die Testdaten über tools/testdaten.sh an; unter Windows läuft das Skript über die Git Bash. */
function testdatenAnlegen() {
	const [befehl, argumente] = process.platform === 'win32' ? ['bash', ['tools/testdaten.sh']] : ['tools/testdaten.sh', []]
	execFileSync(befehl, argumente, { env: { ...process.env, NC_URL: BASIS, NC_USER: NUTZER, NC_PASS: PASSWORT }, stdio: 'inherit' })
}

let browser, seite, admin
let vorgabe
/**
 * Dialoge des Browsers mit Typ und Text; `dialogAnnehmen` steuert die Antwort. Erwartet ist nur noch `beforeunload`:
 * Rückfragen der Seite sind Dialoge von Nextcloud (NcDialog) und werden über ihre Rolle angesprochen.
 */
const dialoge = []
let dialogAnnehmen = true

/** Rückfrage der Seite (NcDialog, außerhalb des Admin-Bereichs eingehängt). */
const rueckfrage = (name) => seite.getByRole('dialog', { name })

/** Knopf in der Rückfrage drücken und warten, bis sie weg ist. */
async function antworten(dialog, knopf) {
	await dialog.getByRole('button', { name: knopf, exact: true }).click()
	await dialog.waitFor({ state: 'detached' })
}

/**
 * Ob der Locator das fokussierte Element ist. Wartet bis zu 3 Sekunden: Nach einer Rückfrage setzt die Seite den
 * Fokus erst, wenn der Dialog geschlossen ist.
 */
const hatFokus = (locator) => locator.evaluate((e) => new Promise((ja) => {
	const start = Date.now()
	const pruefen = () => {
		if (e === document.activeElement) {
			ja(true)
		} else if (Date.now() - start > 3000) {
			ja(false)
		} else {
			requestAnimationFrame(pruefen)
		}
	}
	pruefen()
}))

/** Wartet bis zu 3 Sekunden, bis die Funktion den erwarteten Wert liefert (die Oberfläche baut nach Rückfragen verzögert um). */
async function wirdZu(lesen, erwartet) {
	const start = Date.now()
	let wert = await lesen()
	while (wert !== erwartet && Date.now() - start < 3000) {
		await new Promise((r) => setTimeout(r, 50))
		wert = await lesen()
	}
	return wert
}

/** Wartet auf das PUT /schema der Oberfläche und liefert dessen Schema. */
async function speichernMitAnfrage(bereich) {
	const anfrage = seite.waitForRequest((r) => r.method() === 'PUT' && r.url().endsWith('/api/schema'))
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	return JSON.parse((await anfrage).postData()).schema
}

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
	seite.on('dialog', async (dialog) => {
		dialoge.push({ typ: dialog.type(), text: dialog.message() })
		await (dialogAnnehmen ? dialog.accept() : dialog.dismiss())
	})
	await anmelden(seite, NUTZER, PASSWORT)
})

after(async () => {
	await schemaSetzen(vorgabe)
	await admin?.dispose()
	await browser?.close()
})

test('Eigener Bereich in der Verwaltung, auch neben Impersonate mit kaputtem Template', async () => {
	// Die Test-Nextcloud hat Impersonate 5.0.0/6.0.0 aktiv (tools/test-nextcloud.sh); unter „Zusätzliche Einstellungen“
	// verschluckte dessen offenes <div> früher das Formular dieser App.
	const apps = await (await admin.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')).json()
	assert.ok(apps.ocs.data.apps.includes('impersonate'), 'Impersonate muss für diesen Test aktiv sein')
	await seite.goto(BASIS + '/index.php/settings/admin/overview')
	await seite.locator('a[href$="/settings/admin/calendar_planning"]').first().click()
	await seite.waitForURL(/settings\/admin\/calendar_planning/)
	await seite.locator('#calendar-planning-admin').getByRole('heading', { name: 'Kalender-Planung' }).waitFor()
})

test('Admin-Seite zeigt Vorgaben und Felder, ein neues Auswahlfeld lässt sich speichern', async () => {
	await seite.goto(BASIS + ADMIN_SEITE)
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
	await bereich.getByLabel('Liste Feld 2 in Abschnitt 5 („Art der Veranstaltung“)').check()
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Höchstens 3 Felder dürfen in der Liste stehen').waitFor()
	const unveraendert = (await (await admin.get(`${API}/schema`)).json()).schema
	assert.deepEqual(unveraendert, vorgabe)

	await bereich.getByLabel('Liste Feld 1 in Abschnitt 1 („Zuständig intern“)').uncheck()
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
	// Auswahl als NcSelect (U-KAL-17): öffnen und Option wählen.
	await seite.getByRole('combobox', { name: 'Art der Veranstaltung' }).click()
	await seite.getByRole('option', { name: 'Kurs', exact: true }).click()
	await seite.getByLabel('Anzahl der Teilnehmenden').fill('12')
	await seite.getByRole('button', { name: 'Speichern' }).click()
	await seite.getByText('Gespeichert.').waitFor()
	await seite.reload()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	assert.equal((await seite.locator('.formular__feld', { has: seite.getByText('Art der Veranstaltung', { exact: true }) }).locator('.vs__selected').innerText()).trim(), 'Kurs')

	await seite.getByRole('button', { name: /Zur Liste/ }).click()
	const zeile = seite.locator('tr', { hasText: 'Lötworkshop Grundschule' })
	await zeile.getByText('Kurs').waitFor()
	assert.match(await zeile.innerText(), /12/)

	// Der Wert steht als eigene Eigenschaft im Termin.
	const ics = await (await fetch(`${BASIS}/remote.php/dav/calendars/${NUTZER}/planung-test/workshop-1.ics`, { headers: { Authorization: basic(NUTZER, PASSWORT) } })).text()
	assert.match(ics, /X-CALPLAN-ART-DER-VERANSTALTUNG:Kurs/)
})

test('Vorlage „Makerspace“ laden: das Formular zeigt die alten Bezeichnungen', async () => {
	await seite.goto(BASIS + ADMIN_SEITE)
	const bereich = seite.locator('#calendar-planning-admin')
	await bereich.getByRole('heading', { name: 'Kalender-Planung' }).waitFor()
	const ausloeser = bereich.getByRole('button', { name: /Vorlage laden: Makerspace/ })
	await ausloeser.click()
	// Rückfrage als Dialog von Nextcloud mit Knöpfen, die die Aktion nennen; danach Fokus zurück zum Auslöser.
	await antworten(rueckfrage(/^Makerspace.* laden\?$/), 'Vorlage laden')
	await bereich.getByText(/geladen, noch nicht gespeichert/).waitFor()
	assert.equal(await hatFokus(ausloeser), true)
	// Die Meldung steht in einem dauerhaften Live-Bereich.
	assert.equal(await bereich.getByText(/geladen, noch nicht gespeichert/).locator('xpath=ancestor::*[@aria-live="polite"]').count(), 1)
	dialoge.length = 0
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	// Die Vorlage hat „ausstattung“ nicht: Rückfrage vor dem Speichern, Hinweis danach (Q-KAL-01).
	const frage = rueckfrage(/(Feld fällt|Felder fallen) weg/)
	await frage.getByText(/„Benötigte Ausstattung \/ Technik“ \(ausstattung\)/).waitFor()
	await antworten(frage, 'Trotzdem speichern')
	await bereich.getByText('Felder gespeichert.').waitFor()
	assert.deepEqual(dialoge, [], 'keine Dialoge des Browsers')
	await bereich.getByText(/Nicht mehr im Formular: .*„Benötigte Ausstattung \/ Technik“ \(ausstattung\).*; erfasste Werte bleiben im Termin/).waitFor()

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

/** Admin-Seite mit frisch gesetztem Vorgabe-Schema öffnen. */
async function adminSeite() {
	// Nur bei Bedarf zurücksetzen: PUT /schema ist auf 30 je Minute begrenzt.
	if (JSON.stringify(await gespeichertesSchema()) !== JSON.stringify(vorgabe)) {
		await schemaSetzen(vorgabe)
	}
	dialogAnnehmen = true
	await seite.setViewportSize({ width: 1400, height: 1000 })
	await seite.goto(BASIS + ADMIN_SEITE)
	const bereich = seite.locator('#calendar-planning-admin')
	await bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1').waitFor()
	dialoge.length = 0
	return bereich
}

const gespeichertesSchema = async () => (await (await admin.get(`${API}/schema`)).json()).schema

test('Q-KAL-01: Bezeichnung eines gespeicherten Feldes ändern lässt den Schlüssel stehen, auch nach Entfernen und Rückgängig', async () => {
	const bereich = await adminSeite()
	// Gespeichertes Feld entfernen und zurückholen: Es bleibt ein gespeichertes Feld mit festem Schlüssel.
	await bereich.getByRole('button', { name: 'Feld 1 in Abschnitt 1 („Zuständig intern“) entfernen' }).click()
	await bereich.getByRole('button', { name: 'Rückgängig' }).click()
	const schluessel = bereich.getByLabel('Schlüssel Feld 1 in Abschnitt 1')
	assert.equal(await schluessel.inputValue(), 'zustaendig')
	assert.equal(await schluessel.getAttribute('readonly'), '')
	await bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1').press('End')
	await bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1').pressSequentially(' (Person)')
	assert.equal(await schluessel.inputValue(), 'zustaendig')
	// Abschnitt verschieben ändert keine Schlüssel.
	await bereich.getByRole('button', { name: 'Abschnitt 2 („2. Kontaktdaten“) nach unten' }).click()
	const gesendet = await speichernMitAnfrage(bereich)
	await bereich.getByText('Felder gespeichert.').waitFor()
	assert.equal(dialoge.length, 0, 'kein Feld fällt weg, also keine Rückfrage')
	assert.equal(await seite.getByRole('dialog').count(), 0)
	assert.doesNotMatch(JSON.stringify(gesendet), /_neu|_manuell/)
	const schema = await gespeichertesSchema()
	assert.deepEqual(schema.abschnitte[0].felder[0], { ...vorgabe.abschnitte[0].felder[0], label: 'Zuständig intern (Person)' })
	assert.deepEqual(schema.abschnitte.map((a) => a.titel), ['1. Grunddaten', '3. Teilnehmende Gruppe', '2. Kontaktdaten', '4. Inhalte & Ausstattung', '5. Hinweise'])
	assert.deepEqual(schema.abschnitte[2].felder, vorgabe.abschnitte[1].felder)
	const schluesselVon = (s) => s.abschnitte.flatMap((a) => a.felder.map((f) => f.key)).sort()
	assert.deepEqual(schluesselVon(schema), schluesselVon(vorgabe))
})

test('Q-KAL-01: Vorlage laden, Schlüssel der Vorlage bleiben beim Umbenennen', async () => {
	const bereich = await adminSeite()
	await bereich.getByRole('button', { name: /Vorlage laden: Makerspace/ }).click()
	await antworten(rueckfrage(/^Makerspace.* laden\?$/), 'Vorlage laden')
	await bereich.getByText(/geladen, noch nicht gespeichert/).waitFor()
	// „Benötigte Maschinen / Tools“ ist Feld 2 in Abschnitt 4 der Vorlage.
	const bezeichnung = bereich.getByLabel('Bezeichnung Feld 2 in Abschnitt 4')
	assert.equal(await bezeichnung.inputValue(), 'Benötigte Maschinen / Tools')
	await bezeichnung.press('End')
	await bezeichnung.pressSequentially(' im Werkraum')
	const schluessel = bereich.getByLabel('Schlüssel Feld 2 in Abschnitt 4')
	assert.equal(await schluessel.inputValue(), 'maschinen')
	assert.equal(await schluessel.getAttribute('readonly'), '')
})

test('Q-KAL-01: neues Feld, Schlüssel folgt der Bezeichnung und ist bearbeitbar', async () => {
	const bereich = await adminSeite()
	await bereich.getByRole('button', { name: '+ Feld in Abschnitt 5' }).click()
	const bezeichnung = bereich.getByLabel('Bezeichnung Feld 2 in Abschnitt 5')
	// Der Fokus steht schon in der Bezeichnung des neuen Feldes.
	assert.equal(await bezeichnung.evaluate((e) => e === document.activeElement), true)
	await bezeichnung.pressSequentially('Raum Nummer')
	const schluessel = bereich.getByLabel('Schlüssel Feld 2 in Abschnitt 5')
	assert.equal(await schluessel.inputValue(), 'raum_nummer')
	assert.equal(await schluessel.getAttribute('readonly'), null)
	// Von Hand geändert (_manuell): folgt der Bezeichnung nicht mehr.
	await schluessel.fill('raum_nr')
	await bezeichnung.press('End')
	await bezeichnung.pressSequentially('n')
	assert.equal(await schluessel.inputValue(), 'raum_nr')
	await schluessel.fill('raum_nummer')
	// Die Hilfsangaben des Editors gehen nicht an den Server.
	const gesendet = await speichernMitAnfrage(bereich)
	assert.deepEqual(gesendet.abschnitte[4].felder[1], { key: 'raum_nummer', label: 'Raum Nummern', typ: 'text', pflicht: false, maxlen: 5000, optionen: [], inListe: false })
	assert.doesNotMatch(JSON.stringify(gesendet), /_neu|_manuell/)
	await bereich.getByText('Felder gespeichert.').waitFor()
	assert.equal((await gespeichertesSchema()).abschnitte[4].felder[1].key, 'raum_nummer')
	// Nach dem Speichern ist auch dieses Feld fest.
	assert.equal(await schluessel.getAttribute('readonly'), '')
	await bezeichnung.pressSequentially(' alt')
	assert.equal(await schluessel.inputValue(), 'raum_nummer')
})

test('Q-KAL-02: Optionen lassen sich mit Enter und Leerzeichen tippen', async () => {
	const bereich = await adminSeite()
	await bereich.getByRole('button', { name: '+ Feld in Abschnitt 5' }).click()
	await bereich.getByLabel('Bezeichnung Feld 2 in Abschnitt 5').pressSequentially('Art')
	await bereich.getByLabel('Typ Feld 2 in Abschnitt 5').selectOption('select')
	const optionen = bereich.getByLabel('Optionen für Feld 2 in Abschnitt 5 („Art“), eine je Zeile')
	await optionen.pressSequentially('Option A')
	await optionen.press('Enter')
	// Leerzeile in der Mitte: bleibt beim Tippen stehen, gespeichert wird sie nicht.
	await optionen.press('Enter')
	await optionen.pressSequentially('Option B ')
	assert.equal(await optionen.inputValue(), 'Option A\n\nOption B ')
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Felder gespeichert.').waitFor()
	assert.deepEqual((await gespeichertesSchema()).abschnitte[4].felder[1].optionen, ['Option A', 'Option B'])
	assert.equal(await optionen.inputValue(), 'Option A\nOption B')
})

test('U-KAL-10: ungespeicherte Änderungen zeigen einen Hinweis, Verlassen fragt nach', async () => {
	const bereich = await adminSeite()
	const hinweis = bereich.getByText('Ungespeicherte Änderungen an den Feldern.')
	assert.equal(await hinweis.count(), 0)
	await bereich.getByLabel('Pflicht Feld 1 in Abschnitt 1 („Zuständig intern“)').check()
	await hinweis.waitFor()
	// Zurücknehmen der Änderung nimmt auch den Hinweis weg.
	await bereich.getByLabel('Pflicht Feld 1 in Abschnitt 1 („Zuständig intern“)').uncheck()
	await hinweis.waitFor({ state: 'detached' })
	await bereich.getByLabel('Pflicht Feld 1 in Abschnitt 1 („Zuständig intern“)').check()
	await hinweis.waitFor()

	// Verlassen: der Browser fragt nach (beforeunload); „Bleiben“ lässt Seite und Eingaben stehen.
	dialogAnnehmen = false
	const verlassen = seite.waitForEvent('dialog')
	await seite.evaluate(() => { window.location.href = '/index.php/apps/dashboard/' }).catch(() => {})
	await verlassen
	assert.deepEqual(dialoge.map((d) => d.typ), ['beforeunload'])
	assert.equal(await bereich.getByLabel('Pflicht Feld 1 in Abschnitt 1 („Zuständig intern“)').isChecked(), true)
	dialogAnnehmen = true

	// Vorgaben haben ihren eigenen Hinweis.
	await bereich.getByLabel('Standardort').fill('Vereinsheim Nord')
	await bereich.getByText('Ungespeicherte Änderungen an den Vorgaben.').waitFor()
	await bereich.getByLabel('Standardort').fill('Vereinsheim')
	await bereich.getByText('Ungespeicherte Änderungen an den Vorgaben.').waitFor({ state: 'detached' })

	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Felder gespeichert.').waitFor()
	assert.equal(await hinweis.count(), 0)
	// Ohne Änderungen fragt das Verlassen nicht.
	dialoge.length = 0
	await seite.goto(BASIS + '/index.php/apps/dashboard/')
	assert.deepEqual(dialoge, [])
})

for (const breite of [1280, 860, 600, 320]) {
	test(`U-KAL-18: Feld-Editor bei ${breite} Pixel ohne seitliches Scrollen`, async () => {
		const bereich = await adminSeite()
		await seite.setViewportSize({ width: breite, height: 900 })
		await bereich.getByRole('button', { name: '+ Feld in Abschnitt 5' }).click()
		await bereich.getByLabel('Typ Feld 2 in Abschnitt 5').selectOption('select')
		const masse = await seite.evaluate(() => {
			const editor = document.querySelector('#calendar-planning-admin .editor')
			const ueber = [...editor.querySelectorAll('*')]
				.filter((e) => e.getBoundingClientRect().right > editor.getBoundingClientRect().right + 1 && e.getClientRects().length > 0)
				.map((e) => e.tagName + '.' + e.className)
			const typ = editor.querySelector('select')
			return {
				seite: document.documentElement.scrollWidth - document.documentElement.clientWidth,
				editor: editor.scrollWidth - editor.clientWidth,
				ueber,
				// Die Typ-Auswahl zeigt ihren Text ganz („Text (eine Zeile)“).
				typ: typ.scrollWidth <= typ.clientWidth,
			}
		})
		assert.equal(masse.seite, 0, 'Seite scrollt seitlich')
		assert.equal(masse.editor, 0, 'Editor scrollt seitlich')
		assert.deepEqual(masse.ueber, [], 'Elemente ragen über den Editor hinaus')
		if (breite <= 860) {
			// Schmal: Felder als Karten mit sichtbaren Beschriftungen, Tabellenkopf ausgeblendet.
			assert.equal(await bereich.locator('thead').first().isVisible(), false)
			const schluessel = bereich.getByLabel('Schlüssel Feld 1 in Abschnitt 1')
			const bezeichnung = bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1')
			assert.ok((await bezeichnung.boundingBox()).width >= (await schluessel.boundingBox()).width, 'Bezeichnung über die ganze Breite')
			// Echte, sichtbare Beschriftungen; ein Klick auf „Pflicht“ schaltet das Kästchen.
			const zeile = bereich.locator('tr.editor__zeile').first()
			assert.equal(await zeile.locator('label', { hasText: /^Max\. Zeichen/ }).isVisible(), true)
			const pflicht = bereich.getByLabel('Pflicht Feld 1 in Abschnitt 1')
			assert.equal(await pflicht.isChecked(), false)
			await zeile.locator('label', { hasText: /^Pflicht/ }).click()
			assert.equal(await pflicht.isChecked(), true)
		} else {
			// Breit: die Beschriftung steht im Spaltenkopf, die je Feld nur für Screenreader.
			const box = await bereich.locator('tr.editor__zeile').first().locator('label', { hasText: /^Pflicht/ }).boundingBox()
			assert.ok(box.width <= 1 && box.height <= 1, `Beschriftung sichtbar: ${JSON.stringify(box)}`)
		}
	})
}

test('U-KAL-19: Fehlermeldungen nennen Feld und Abschnitt ab 1 mit Bezeichnung', async () => {
	const schema = structuredClone(vorgabe)
	schema.abschnitte[1].felder[2].maxlen = 0
	const antwort = await admin.put(`${API}/schema`, { data: { schema } })
	assert.equal(antwort.status(), 400)
	assert.equal((await antwort.json()).fehler, 'Feld 3 in Abschnitt 2 („Telefon“): „Max. Zeichen“ muss eine ganze Zahl von 1 bis 5000 sein.')
	// In der Oberfläche: doppelter Schlüssel eines neuen Feldes.
	const bereich = await adminSeite()
	await bereich.getByRole('button', { name: '+ Feld in Abschnitt 1' }).click()
	await bereich.getByLabel('Bezeichnung Feld 4 in Abschnitt 1').pressSequentially('Raum')
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Feld 4 in Abschnitt 1 („Raum“): Der Schlüssel „raum“ ist schon bei Feld 3 in Abschnitt 1 („Raum-Informationen“) vergeben.').waitFor()
	assert.deepEqual(await gespeichertesSchema(), vorgabe)
})

test('U-KAL-20: Feld entfernen mit Rückgängig, Fokus und Ansage beim Verschieben und Entfernen', async () => {
	const bereich = await adminSeite()
	const ansage = bereich.locator('[role=status]').last()
	const fokusLabel = () => seite.evaluate(() => document.activeElement.getAttribute('aria-label'))

	// Verschieben: der Fokus bleibt am verschobenen Feld; die Namen nennen Feld, Abschnitt und Bezeichnung.
	await bereich.getByRole('button', { name: 'Feld 1 in Abschnitt 1 („Zuständig intern“) nach unten' }).click()
	assert.equal(await bereich.getByLabel('Bezeichnung Feld 2 in Abschnitt 1').inputValue(), 'Zuständig intern')
	assert.equal(await fokusLabel(), 'Feld 2 in Abschnitt 1 („Zuständig intern“) nach unten')
	await seite.waitForFunction(() => document.querySelector('#calendar-planning-admin .editor [role=status]').textContent.includes('jetzt Feld 2 von 3'))
	assert.match(await ansage.textContent(), /Feld „Zuständig intern“ ist jetzt Feld 2 von 3 in Abschnitt 1\./)
	// Am Rand: der Knopf in Richtung ist gesperrt, der Fokus geht auf den Gegenknopf.
	await seite.keyboard.press('Enter')
	assert.equal(await bereich.getByLabel('Bezeichnung Feld 3 in Abschnitt 1').inputValue(), 'Zuständig intern')
	assert.equal(await fokusLabel(), 'Feld 3 in Abschnitt 1 („Zuständig intern“) nach oben')

	// Entfernen: Hinweis mit „Rückgängig“, Fokus auf die Bezeichnung des nachrückenden Feldes.
	await bereich.getByRole('button', { name: 'Feld 1 in Abschnitt 1 („Parkmöglichkeiten / Zufahrt / Anlieferung“) entfernen' }).click()
	await bereich.getByText('Feld „Parkmöglichkeiten / Zufahrt / Anlieferung“ entfernt.').waitFor()
	assert.equal(await bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1').inputValue(), 'Raum-Informationen')
	assert.equal(await hatFokus(bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1')), true)
	assert.match(await ansage.textContent(), /entfernt\. „Rückgängig“ holt es zurück\./)
	await bereich.getByRole('button', { name: 'Rückgängig' }).click()
	assert.equal(await bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1').inputValue(), 'Parkmöglichkeiten / Zufahrt / Anlieferung')
	assert.equal(await bereich.getByLabel('Schlüssel Feld 1 in Abschnitt 1').inputValue(), 'zufahrt')
	assert.equal(await hatFokus(bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1')), true)
	assert.equal(await bereich.getByRole('button', { name: 'Rückgängig' }).count(), 0)

	// Letztes Feld eines Abschnitts entfernen: Fokus auf „Rückgängig“; Speichern fragt nach, weil ein Schlüssel wegfällt.
	await bereich.getByRole('button', { name: 'Feld 1 in Abschnitt 5 („Besondere Wünsche / Hinweise / Anmerkungen“) entfernen' }).click()
	assert.equal(await hatFokus(bereich.getByRole('button', { name: 'Rückgängig' })), true)
	const speichern = bereich.getByRole('button', { name: 'Felder speichern' })
	await speichern.click()
	const frage = rueckfrage('Ein Feld fällt weg')
	await frage.getByText(/„Besondere Wünsche \/ Hinweise \/ Anmerkungen“ \(hinweise\)/).waitFor()
	await antworten(frage, 'Weiter bearbeiten')
	assert.equal(await hatFokus(speichern), true, 'Fokus zurück auf „Felder speichern“')
	assert.deepEqual(await gespeichertesSchema(), vorgabe, 'abgelehnte Rückfrage speichert nicht')
	assert.deepEqual(dialoge, [])
	assert.equal(await seite.locator('body').innerText().then((t) => /\bnull\b|undefined/.test(t)), false)
})

test('U-KAL-20: mehrfach Rückgängig in umgekehrter Reihenfolge, danach nichts mehr ungespeichert', async () => {
	const bereich = await adminSeite()
	const bezeichnung = (n) => bereich.getByLabel(`Bezeichnung Feld ${n} in Abschnitt 2`)
	await bereich.getByRole('button', { name: 'Feld 2 in Abschnitt 2 („Name“) entfernen' }).click()
	assert.equal(await hatFokus(bezeichnung(2)), true)
	assert.equal(await bezeichnung(2).inputValue(), 'Telefon')
	await bereich.getByRole('button', { name: 'Feld 2 in Abschnitt 2 („Telefon“) entfernen' }).click()
	assert.equal(await bezeichnung(2).inputValue(), 'E-Mail')
	await bereich.getByText('Feld „Telefon“ entfernt.').waitFor()
	await bereich.getByText(/nacheinander 2 entfernte Felder zurück/).waitFor()
	await bereich.getByText('Ungespeicherte Änderungen an den Feldern.').waitFor()

	await bereich.getByRole('button', { name: 'Rückgängig' }).click()
	assert.equal(await bezeichnung(2).inputValue(), 'Telefon')
	assert.equal(await hatFokus(bezeichnung(2)), true)
	await bereich.getByText('Feld „Name“ entfernt.').waitFor()
	await bereich.getByRole('button', { name: 'Rückgängig' }).click()
	const reihe = []
	for (let n = 1; n <= 5; n++) {
		reihe.push(await bezeichnung(n).inputValue())
	}
	assert.deepEqual(reihe, vorgabe.abschnitte[1].felder.map((f) => f.label))
	assert.equal(await bereich.getByRole('button', { name: 'Rückgängig' }).count(), 0)
	// Alles zurück: kein Hinweis, keine Rückfrage beim Verlassen.
	await bereich.getByText('Ungespeicherte Änderungen an den Feldern.').waitFor({ state: 'detached' })
	dialoge.length = 0
	await seite.goto(BASIS + '/index.php/apps/dashboard/')
	assert.deepEqual(dialoge, [])
})

test('Abschnitt entfernen fragt nach, Fokus danach auf den nächsten Abschnitt oder „+ Abschnitt“', async () => {
	const bereich = await adminSeite()
	const titel = (n) => bereich.getByLabel(`Abschnitt ${n}`, { exact: true })
	const entfernen = (n, name) => bereich.getByRole('button', { name: `Abschnitt ${n} („${name}“) entfernen` })

	// „Behalten“: nichts passiert, Fokus zurück auf den Knopf.
	await entfernen(2, '2. Kontaktdaten').click()
	await antworten(rueckfrage('Abschnitt 2 („2. Kontaktdaten“) entfernen?'), 'Behalten')
	assert.equal(await titel(2).inputValue(), '2. Kontaktdaten')
	assert.equal(await hatFokus(entfernen(2, '2. Kontaktdaten')), true)

	// Entfernen: der nächste Abschnitt rückt nach, der Fokus steht in seiner Überschrift.
	await entfernen(2, '2. Kontaktdaten').click()
	const frage = rueckfrage('Abschnitt 2 („2. Kontaktdaten“) entfernen?')
	await frage.getByText('Der Abschnitt enthält 5 Felder; sie werden mit entfernt.').waitFor()
	await antworten(frage, 'Abschnitt entfernen')
	assert.equal(await wirdZu(() => titel(2).inputValue(), '3. Teilnehmende Gruppe'), '3. Teilnehmende Gruppe')
	assert.equal(await hatFokus(titel(2)), true)
	await seite.waitForFunction(() => document.querySelector('#calendar-planning-admin .editor [role=status]').textContent.includes('entfernt'))

	// Letzter Abschnitt: Fokus auf „+ Abschnitt“.
	await entfernen(4, '5. Hinweise').click()
	await antworten(rueckfrage('Abschnitt 4 („5. Hinweise“) entfernen?'), 'Abschnitt entfernen')
	const neu = bereich.getByRole('button', { name: '+ Abschnitt' })
	assert.equal(await hatFokus(neu), true)

	// Neuer Abschnitt: Fokus in seiner Überschrift; leer entfernen geht ohne Rückfrage.
	await neu.click()
	assert.equal(await wirdZu(() => titel(4).inputValue(), '4. Abschnitt'), '4. Abschnitt')
	assert.equal(await hatFokus(titel(4)), true)
	await entfernen(4, '4. Abschnitt').click()
	assert.equal(await seite.getByRole('dialog').count(), 0)
	assert.equal(await wirdZu(() => titel(4).count(), 0), 0)
	assert.equal(await hatFokus(neu), true)
	assert.deepEqual(dialoge, [])
})
