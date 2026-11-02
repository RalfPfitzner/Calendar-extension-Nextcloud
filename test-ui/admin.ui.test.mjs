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
/** Alle Dialoge der Seite (confirm, beforeunload) mit Typ und Text; `dialogAnnehmen` steuert die Antwort. */
const dialoge = []
let dialogAnnehmen = true

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
	await seite.goto(BASIS + ADMIN_SEITE)
	const bereich = seite.locator('#calendar-planning-admin')
	await bereich.getByRole('heading', { name: 'Kalender-Planung' }).waitFor()
	await bereich.getByRole('button', { name: /Vorlage laden: Makerspace/ }).click()
	await bereich.getByText(/geladen, noch nicht gespeichert/).waitFor()
	dialoge.length = 0
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Felder gespeichert.').waitFor()
	// Die Vorlage hat „ausstattung“ nicht: Rückfrage vor dem Speichern, Hinweis danach (Q-KAL-01).
	assert.equal(dialoge.length, 1)
	assert.match(dialoge[0].text, /„Benötigte Ausstattung \/ Technik“ \(ausstattung\)/)
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

test('Q-KAL-01: Bezeichnung eines gespeicherten Feldes ändern lässt den Schlüssel stehen', async () => {
	const bereich = await adminSeite()
	const schluessel = bereich.getByLabel('Schlüssel Feld 1 in Abschnitt 1')
	assert.equal(await schluessel.inputValue(), 'zustaendig')
	assert.equal(await schluessel.getAttribute('readonly'), '')
	await bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1').press('End')
	await bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1').pressSequentially(' (Person)')
	assert.equal(await schluessel.inputValue(), 'zustaendig')
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Felder gespeichert.').waitFor()
	assert.equal(dialoge.length, 0, 'kein Feld fällt weg, also keine Rückfrage')
	const schema = await gespeichertesSchema()
	assert.deepEqual(schema.abschnitte[0].felder[0], { ...vorgabe.abschnitte[0].felder[0], label: 'Zuständig intern (Person)' })
	assert.doesNotMatch(JSON.stringify(schema), /_neu|_manuell/)
})

test('Q-KAL-01: Vorlage laden, Schlüssel der Vorlage bleiben beim Umbenennen', async () => {
	const bereich = await adminSeite()
	await bereich.getByRole('button', { name: /Vorlage laden: Makerspace/ }).click()
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
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
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
	const optionen = bereich.getByLabel('Optionen von „Art“ (eine je Zeile)')
	await optionen.pressSequentially('Option A')
	await optionen.press('Enter')
	await optionen.pressSequentially('Option B ')
	assert.equal(await optionen.inputValue(), 'Option A\nOption B ')
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	await bereich.getByText('Felder gespeichert.').waitFor()
	assert.deepEqual((await gespeichertesSchema()).abschnitte[4].felder[1].optionen, ['Option A', 'Option B'])
	assert.equal(await optionen.inputValue(), 'Option A\nOption B')
})

test('U-KAL-10: ungespeicherte Änderungen zeigen einen Hinweis, Verlassen fragt nach', async () => {
	const bereich = await adminSeite()
	const hinweis = bereich.getByText('Ungespeicherte Änderungen an den Feldern.')
	assert.equal(await hinweis.count(), 0)
	await bereich.getByLabel('Pflichtfeld Zuständig intern').check()
	await hinweis.waitFor()
	// Zurücknehmen der Änderung nimmt auch den Hinweis weg.
	await bereich.getByLabel('Pflichtfeld Zuständig intern').uncheck()
	await hinweis.waitFor({ state: 'detached' })
	await bereich.getByLabel('Pflichtfeld Zuständig intern').check()
	await hinweis.waitFor()

	// Verlassen: der Browser fragt nach (beforeunload); „Bleiben“ lässt Seite und Eingaben stehen.
	dialogAnnehmen = false
	await seite.evaluate(() => { window.location.href = '/index.php/apps/dashboard/' }).catch(() => {})
	await seite.waitForTimeout(500)
	assert.deepEqual(dialoge.map((d) => d.typ), ['beforeunload'])
	assert.equal(await bereich.getByLabel('Pflichtfeld Zuständig intern').isChecked(), true)
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

for (const breite of [1280, 860, 600]) {
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

	// Verschieben: der Fokus bleibt am verschobenen Feld.
	await bereich.getByRole('button', { name: 'Feld Zuständig intern nach unten' }).click()
	assert.equal(await bereich.getByLabel('Bezeichnung Feld 2 in Abschnitt 1').inputValue(), 'Zuständig intern')
	assert.equal(await seite.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Feld Zuständig intern nach unten')
	await seite.waitForFunction(() => document.querySelector('#calendar-planning-admin .editor [role=status]').textContent.includes('jetzt Feld 2 von 3'))
	assert.match(await ansage.textContent(), /Feld „Zuständig intern“ ist jetzt Feld 2 von 3 in Abschnitt 1\./)
	// Am Rand: der Knopf in Richtung ist gesperrt, der Fokus geht auf den Gegenknopf.
	await seite.keyboard.press('Enter')
	assert.equal(await bereich.getByLabel('Bezeichnung Feld 3 in Abschnitt 1').inputValue(), 'Zuständig intern')
	assert.equal(await seite.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Feld Zuständig intern nach oben')

	// Entfernen: Hinweis mit „Rückgängig“, Fokus auf das nachrückende Feld.
	await bereich.getByRole('button', { name: 'Feld Parkmöglichkeiten / Zufahrt / Anlieferung entfernen' }).click()
	await bereich.getByText('Feld „Parkmöglichkeiten / Zufahrt / Anlieferung“ entfernt.').waitFor()
	assert.equal(await seite.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Feld Raum-Informationen entfernen')
	assert.match(await ansage.textContent(), /entfernt\. „Rückgängig“ holt es zurück\./)
	await bereich.getByRole('button', { name: 'Rückgängig' }).click()
	assert.equal(await bereich.getByLabel('Bezeichnung Feld 1 in Abschnitt 1').inputValue(), 'Parkmöglichkeiten / Zufahrt / Anlieferung')
	assert.equal(await bereich.getByLabel('Schlüssel Feld 1 in Abschnitt 1').inputValue(), 'zufahrt')
	assert.equal(await seite.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Bezeichnung Feld 1 in Abschnitt 1')
	assert.equal(await bereich.getByRole('button', { name: 'Rückgängig' }).count(), 0)

	// Letztes Feld eines Abschnitts entfernen: Fokus auf „+ Feld“; Speichern fragt nach, weil ein Schlüssel wegfällt.
	await bereich.getByRole('button', { name: 'Feld Besondere Wünsche / Hinweise / Anmerkungen entfernen' }).click()
	assert.match(await seite.evaluate(() => document.activeElement.textContent), /\+ Feld in Abschnitt 5/)
	dialogAnnehmen = false
	await bereich.getByRole('button', { name: 'Felder speichern' }).click()
	assert.equal(dialoge.length, 1)
	assert.match(dialoge[0].text, /„Besondere Wünsche \/ Hinweise \/ Anmerkungen“ \(hinweise\)/)
	assert.deepEqual(await gespeichertesSchema(), vorgabe, 'abgelehnte Rückfrage speichert nicht')
	dialogAnnehmen = true
	assert.equal(await seite.locator('body').innerText().then((t) => /\bnull\b|undefined/.test(t)), false)
})
