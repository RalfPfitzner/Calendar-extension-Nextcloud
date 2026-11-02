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
	// SICHTBAR=1 öffnet ein echtes Browserfenster und bremst die Schritte, um zuzuschauen.
	browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, headless: !process.env.SICHTBAR, slowMo: process.env.SICHTBAR ? 300 : 0 })
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

/** Aufruf der App-API als admin (Basic-Auth), für Änderungen „von jemand anderem“ und für Schema-Tests. */
async function api(methode, pfad, daten) {
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

async function planungskalenderId() {
	return (await api('GET', '/kalender')).daten.kalender.find((k) => k.name === 'Planungskalender Verein').id
}

/** Speichert Planungswerte über die API, wie es eine zweite Person im selben Augenblick täte. */
async function vonAnderenSpeichern(kalender, termin, werte) {
	const stand = (await api('GET', `/termin?kalender=${kalender}&termin=${termin}`)).daten
	const antwort = await api('PUT', '/termin', { kalender, termin, etag: stand.etag, planung: { titel: stand.titel, ort_extern: false, ort: '', ...werte } })
	assert.equal(antwort.status, 200, JSON.stringify(antwort.daten))
	return antwort.daten
}

async function formularOeffnen(tab, kalender, termin) {
	await tab.goto(`${BASIS}/index.php/apps/calendar_planning/?kalender=${kalender}&termin=${termin}`)
	await tab.getByRole('heading', { name: /^Planung:/ }).waitFor()
}

/** Ob das Element ganz im sichtbaren Bereich liegt. */
const imViewport = (locator) => locator.evaluate((e) => {
	const r = e.getBoundingClientRect()
	return r.height > 0 && r.top >= 0 && r.bottom <= window.innerHeight
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

	await seite.getByText('Externer Ort', { exact: true }).click()
	assert.equal(await seite.getByRole('radio', { name: 'Externer Ort' }).isChecked(), true)
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
	await seite.getByText('„E-Mail“: die E-Mail-Adresse ist ungültig').waitFor()
	// U-KAL-03: Fehler sichtbar in einer Meldung mit role="alert", Fokus auf dem betroffenen Feld.
	const meldung = seite.getByRole('alert').filter({ hasText: 'E-Mail-Adresse ist ungültig' })
	assert.equal(await imViewport(meldung), true)
	assert.equal(await seite.getByLabel('E-Mail').evaluate((e) => e === document.activeElement), true)
	assert.equal(await seite.getByLabel('E-Mail').getAttribute('aria-invalid'), 'true')
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
	await seite.getByPlaceholder(/Titel, Ort/).fill('Werkstatt')
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
	await seite.getByRole('button', { name: 'Seite 2', exact: true }).click()
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
	// U-KAL-09: Werte als Beschreibungsliste in normaler Schriftfarbe, kein gesperrtes Feld; gegliedert durch h3.
	assert.equal(await tab.locator('.formular').locator('input, textarea, select').count(), 0)
	const eintrag = tab.locator('.formular__eintrag', { has: tab.getByRole('term').filter({ hasText: /^Zuständig intern$/ }) })
	assert.equal((await eintrag.getByRole('definition').innerText()).trim(), 'Eva')
	const farben = await eintrag.getByRole('definition').evaluate((e) => [getComputedStyle(e).color, getComputedStyle(e.previousElementSibling).color])
	assert.equal(farben[0], farben[1], 'Wert in der Schriftfarbe der Bezeichnung, nicht blass')
	// Die Liste selbst ist kein Tab-Halt (Lesen mit den Lesetasten); jede Gruppe steht unter einer h3.
	const liste = eintrag.locator('xpath=ancestor::dl')
	assert.equal(await liste.getAttribute('tabindex'), null)
	assert.equal(await liste.getAttribute('aria-labelledby'), null)
	assert.equal(await liste.evaluate((e) => e.previousElementSibling?.tagName), 'H3')
	assert.equal(await tab.locator('.formular dl').count(), await tab.locator('.formular h3').count())
	assert.deepEqual(evaFehler, [])
	await kontext.close()
})

test('Kalenderauswahl wird je Konto gespeichert', async () => {
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

test('U-KAL-01: Ort-Auswahl als Gruppe, Knopf direkt bei der Beschriftung, per Tastatur bedienbar', async () => {
	const kalender = await planungskalenderId()
	await formularOeffnen(seite, kalender, 'fuellung-3.ics')
	const gruppe = seite.getByRole('group', { name: 'Ort' })
	assert.equal(await gruppe.evaluate((e) => e.tagName), 'FIELDSET')
	for (const name of ['Vereinsheim', 'Externer Ort']) {
		const wahl = gruppe.locator('.checkbox-radio-switch', { hasText: name })
		const knopf = await wahl.locator('.checkbox-radio-switch__icon').boundingBox()
		const text = await wahl.locator('.checkbox-radio-switch__text').boundingBox()
		assert.ok(knopf.width < 30, `Knopf „${name}“ ist ${knopf.width} Pixel breit`)
		const abstand = text.x - (knopf.x + knopf.width)
		assert.ok(abstand >= 0 && abstand < 16, `Abstand Knopf–Beschriftung „${name}“: ${abstand} Pixel`)
		assert.ok(Math.abs((knopf.y + knopf.height / 2) - (text.y + text.height / 2)) < 12, `„${name}“ steht in einer Zeile`)
	}
	// Beschriftung klickbar, Pfeiltasten wechseln.
	await gruppe.getByText('Externer Ort', { exact: true }).click()
	assert.equal(await seite.getByRole('radio', { name: 'Externer Ort' }).isChecked(), true)
	await seite.getByLabel('Externer Ort – Adresse').waitFor()
	await seite.getByRole('radio', { name: 'Externer Ort' }).focus()
	await seite.keyboard.press('ArrowUp')
	assert.equal(await seite.getByRole('radio', { name: 'Vereinsheim' }).isChecked(), true)
	assert.equal(await seite.getByLabel('Externer Ort – Adresse').count(), 0)
	assert.deepEqual(fehler, [])
})

test('U-KAL-03, Q-KAL-11: Erfolgsmeldung sichtbar und angesagt, Fokus nicht auf BODY, weg nach der nächsten Änderung', async () => {
	const kalender = await planungskalenderId()
	await formularOeffnen(seite, kalender, 'fuellung-4.ics')
	await seite.getByLabel('Besondere Wünsche / Hinweise / Anmerkungen').fill('Unten im Formular geändert')
	await seite.mouse.wheel(0, 5000)
	await seite.getByRole('button', { name: 'Speichern' }).click()
	const status = seite.getByRole('status').filter({ hasText: 'Gespeichert' })
	await status.waitFor()
	assert.equal(await status.getAttribute('aria-live'), 'polite')
	assert.equal(await imViewport(status), true)
	// Ansage nur über die Live-Region; der Fokus bleibt auf dem (jetzt als gesperrt gekennzeichneten) Knopf.
	await seite.waitForFunction(() => document.activeElement?.textContent?.trim() === 'Speichern')
	const knopf = seite.getByRole('button', { name: 'Speichern' })
	assert.equal(await knopf.getAttribute('aria-disabled'), 'true')
	// Nicht nativ gesperrt (Playwrights isDisabled() zählt aria-disabled mit, deshalb die Eigenschaft selbst).
	assert.equal(await knopf.evaluate((e) => e.disabled), false)
	assert.equal(await status.getAttribute('tabindex'), null)
	// Enter auf dem gekennzeichneten Knopf schickt nichts ab.
	let gesendet = 0
	const zaehlen = (anfrage) => { if (anfrage.method() === 'PUT') gesendet++ }
	seite.on('request', zaehlen)
	await seite.keyboard.press('Enter')
	await seite.evaluate(() => new Promise((weiter) => requestAnimationFrame(() => requestAnimationFrame(weiter))))
	seite.off('request', zaehlen)
	assert.equal(gesendet, 0)
	// Die Live-Region bleibt im DOM und wird nur geleert.
	await seite.getByLabel('Zuständig intern').fill('Noch eine Änderung')
	await seite.waitForFunction(() => document.querySelector('[role=status][aria-live=polite]')?.textContent.trim() === '')
	assert.equal(await seite.getByText('Gespeichert.').count(), 0)
	await seite.getByRole('button', { name: 'Änderungen verwerfen' }).click()
	assert.deepEqual(fehler, [])
})

test('U-KAL-05: Konflikt 409 – Eingaben bleiben, „behalten und erneut speichern“ übernimmt fremde Änderungen in anderen Feldern', async () => {
	const kalender = await planungskalenderId()
	await formularOeffnen(seite, kalender, 'fuellung-1.ics')
	await seite.getByLabel('Zuständig intern').fill('Mein Wert')
	await seite.getByLabel('Telefon').fill('0123 456')
	await vonAnderenSpeichern(kalender, 'fuellung-1.ics', { zustaendig: 'Fremder Wert', hinweise: 'Von der anderen Person' })

	await seite.getByRole('button', { name: 'Speichern' }).click()
	const meldung = seite.getByRole('alert').filter({ hasText: 'inzwischen geändert' })
	await meldung.waitFor()
	assert.equal(await imViewport(meldung), true)
	// Die Meldung sagt role=alert an; der Fokus bleibt auf „Speichern“ statt auf der Meldung (sonst doppelt).
	await seite.waitForFunction(() => document.activeElement?.textContent?.trim() === 'Speichern')
	assert.equal(await meldung.getAttribute('tabindex'), null)
	await seite.getByText('Im neuen Stand anders:').waitFor()
	const liste = await seite.locator('.formular__konflikt ul').innerText()
	assert.match(liste, /Zuständig intern: „Fremder Wert“/)
	assert.match(liste, /Besondere Wünsche \/ Hinweise \/ Anmerkungen: „Von der anderen Person“/)
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), 'Mein Wert')
	assert.equal(await seite.getByLabel('Telefon').inputValue(), '0123 456')

	// Abbrechen in der Rückfrage ändert nichts.
	await seite.getByRole('button', { name: 'Meine Eingaben behalten und erneut speichern' }).click()
	let dialog = seite.getByRole('dialog', { name: 'Eigene Eingaben speichern?' })
	await dialog.getByText(/ersetzen die neuen Werte in „Zuständig intern“/).waitFor()
	await dialog.getByRole('button', { name: 'Abbrechen' }).click()
	await dialog.waitFor({ state: 'detached' })
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), 'Mein Wert')

	await seite.getByRole('button', { name: 'Meine Eingaben behalten und erneut speichern' }).click()
	dialog = seite.getByRole('dialog', { name: 'Eigene Eingaben speichern?' })
	await dialog.getByRole('button', { name: 'Meine Eingaben speichern' }).click()
	await seite.getByRole('status').filter({ hasText: 'Gespeichert' }).waitFor()
	assert.equal(await seite.locator('.formular__konflikt').count(), 0)
	const stand = (await api('GET', `/termin?kalender=${kalender}&termin=fuellung-1.ics`)).daten
	assert.equal(stand.zustaendig, 'Mein Wert')
	assert.equal(stand.kontakt_telefon, '0123 456')
	assert.equal(stand.hinweise, 'Von der anderen Person', 'fremde Änderung in einem anderen Feld bleibt')
	assert.equal(await seite.getByLabel('Besondere Wünsche / Hinweise / Anmerkungen').inputValue(), 'Von der anderen Person')
	assert.deepEqual(fehler, [])
})

test('U-KAL-05: Konflikt 409 – „Neuen Stand laden“ fragt nach und verwirft erst dann die Eingaben', async () => {
	const kalender = await planungskalenderId()
	await formularOeffnen(seite, kalender, 'fuellung-2.ics')
	await seite.getByLabel('Telefon').fill('999')
	await vonAnderenSpeichern(kalender, 'fuellung-2.ics', { kontakt_telefon: '0777' })
	await seite.getByRole('button', { name: 'Speichern' }).click()
	await seite.getByRole('alert').filter({ hasText: 'inzwischen geändert' }).waitFor()
	await seite.getByText('Telefon: „0777“').waitFor()

	await seite.getByRole('button', { name: 'Neuen Stand laden' }).click()
	const dialog = seite.getByRole('dialog', { name: 'Neuen Stand laden?' })
	await dialog.getByText('Deine Eingaben in diesem Formular gehen dabei verloren.').waitFor()
	await dialog.getByRole('button', { name: 'Neuen Stand laden' }).click()
	await seite.getByRole('status').filter({ hasText: 'neue Stand ist geladen' }).waitFor()
	assert.equal(await seite.getByLabel('Telefon').inputValue(), '0777')
	assert.equal(await seite.getByRole('alert').filter({ hasText: /\S/ }).count(), 0)
	await seite.waitForFunction(() => document.activeElement?.textContent?.trim() === 'Speichern')
	assert.deepEqual(fehler, [])
})

test('Q-KAL-09: gespeicherter Wert, der keine Option mehr ist, bleibt und lässt sich speichern', async () => {
	const { daten } = await api('GET', '/schema')
	const vorher = daten.schema
	const mitAuswahl = (optionen) => {
		const schema = structuredClone(vorher)
		schema.abschnitte.at(-1).felder.push({ key: 'art_test', label: 'Art (Test)', typ: 'select', optionen })
		return schema
	}
	const kalender = await planungskalenderId()
	try {
		assert.equal((await api('PUT', '/schema', { schema: mitAuswahl(['Kurs', 'Offen']) })).status, 200)
		await vonAnderenSpeichern(kalender, 'fuellung-5.ics', { art_test: 'Kurs' })
		assert.equal((await api('PUT', '/schema', { schema: mitAuswahl(['Offen', 'Vortrag']) })).status, 200)

		await formularOeffnen(seite, kalender, 'fuellung-5.ics')
		const auswahl = seite.getByLabel('Art (Test)')
		assert.equal(await auswahl.evaluate((e) => e.tagName), 'SELECT')
		assert.equal(await auswahl.inputValue(), 'Kurs')
		const optionen = () => auswahl.locator('option').evaluateAll((liste) => liste.map((o) => o.textContent.trim()))
		assert.deepEqual(await optionen(), ['– bitte wählen –', 'Kurs (nicht mehr in der Liste)', 'Offen', 'Vortrag'])
		await seite.getByLabel('Zuständig intern').fill('Mit Altwert')
		await seite.getByRole('button', { name: 'Speichern' }).click()
		await seite.getByRole('status').filter({ hasText: 'Gespeichert' }).waitFor()
		const stand = (await api('GET', `/termin?kalender=${kalender}&termin=fuellung-5.ics`)).daten
		assert.equal(stand.art_test, 'Kurs')
		assert.equal(stand.zustaendig, 'Mit Altwert')

		// Ein anderer Wert außerhalb der Optionen wird weiter abgelehnt.
		const abgelehnt = await api('PUT', '/termin', { kalender, termin: 'fuellung-5.ics', etag: stand.etag, planung: { titel: stand.titel, ort_extern: false, ort: '', art_test: 'Workshop' } })
		assert.equal(abgelehnt.status, 400)
		assert.match(abgelehnt.daten.fehler, /keine der Optionen/)

		// Über die Auswahl auf eine gültige Option wechseln.
		await auswahl.selectOption('Vortrag')
		await seite.getByRole('button', { name: 'Speichern' }).click()
		await seite.getByRole('status').filter({ hasText: 'Gespeichert' }).waitFor()
		assert.equal((await api('GET', `/termin?kalender=${kalender}&termin=fuellung-5.ics`)).daten.art_test, 'Vortrag')
		assert.deepEqual(await optionen(), ['– bitte wählen –', 'Offen', 'Vortrag'])

		// 400 mit Bezug auf das Auswahlfeld: aria-invalid und Fokus direkt am select.
		const muster = '**/apps/calendar_planning/api/termin'
		await seite.route(muster, (route) => route.request().method() === 'PUT'
			? route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ fehler: '„Art (Test)“: „Offen“ ist keine der Optionen' }) })
			: route.continue())
		await auswahl.selectOption('Offen')
		await seite.getByRole('button', { name: 'Speichern' }).click()
		await seite.getByRole('alert').filter({ hasText: 'ist keine der Optionen' }).waitFor()
		await seite.unroute(muster)
		assert.equal(await auswahl.getAttribute('aria-invalid'), 'true')
		assert.equal(await auswahl.getAttribute('aria-describedby'), 'formular-fehler')
		assert.equal(await auswahl.evaluate((e) => e === document.activeElement), true)
		// Leeren über „– bitte wählen –“ (optionales Feld) und verwerfen.
		await auswahl.selectOption('')
		assert.equal(await auswahl.inputValue(), '')
		await seite.getByRole('button', { name: 'Änderungen verwerfen' }).click()
		assert.equal(await auswahl.inputValue(), 'Vortrag')
		assert.equal(await auswahl.getAttribute('aria-invalid'), null)
	} finally {
		assert.equal((await api('PUT', '/schema', { schema: vorher })).status, 200)
	}
	assert.deepEqual(fehler, [])
})

/** Speichern, auf den Konflikt warten; der Fokus bleibt auf „Speichern“. */
async function konfliktAbwarten() {
	await seite.getByRole('button', { name: 'Speichern' }).click()
	await seite.getByRole('alert').filter({ hasText: 'inzwischen geändert' }).waitFor()
	await seite.getByText('Im neuen Stand anders:').waitFor()
}

test('U-KAL-05: „Änderungen verwerfen“ räumt auch den Konflikt weg', async () => {
	const kalender = await planungskalenderId()
	await formularOeffnen(seite, kalender, 'fuellung-6.ics')
	await seite.getByLabel('Telefon').fill('0611')
	await vonAnderenSpeichern(kalender, 'fuellung-6.ics', { zustaendig: 'Andere Person' })
	await konfliktAbwarten()
	await seite.getByRole('button', { name: 'Änderungen verwerfen' }).click()
	await seite.locator('.formular__konflikt').waitFor({ state: 'detached' })
	assert.equal(await seite.getByRole('alert').filter({ hasText: /\S/ }).count(), 0)
	assert.equal(await seite.getByLabel('Telefon').inputValue(), '')
	// Der Knopf „Änderungen verwerfen“ ist jetzt gesperrt; der Fokus geht auf „Speichern“, nicht auf BODY.
	await seite.waitForFunction(() => document.activeElement?.textContent?.trim() === 'Speichern')
	assert.deepEqual(fehler, [])
})

test('U-KAL-05: 400 nach „Meine Eingaben behalten“ zeigt die Meldung ohne den alten Vergleich', async () => {
	const kalender = await planungskalenderId()
	await formularOeffnen(seite, kalender, 'fuellung-7.ics')
	await seite.getByLabel('Telefon').fill('0711')
	await vonAnderenSpeichern(kalender, 'fuellung-7.ics', { zustaendig: 'Andere Person' })
	await konfliktAbwarten()
	const muster = '**/apps/calendar_planning/api/termin'
	await seite.route(muster, (route) => route.request().method() === 'PUT'
		? route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ fehler: '„Telefon“ ist zu lang (Testmeldung)' }) })
		: route.continue())
	await seite.getByRole('button', { name: 'Meine Eingaben behalten und erneut speichern' }).click()
	await seite.getByRole('dialog', { name: 'Eigene Eingaben speichern?' }).getByRole('button', { name: 'Meine Eingaben speichern' }).click()
	await seite.getByRole('alert').filter({ hasText: 'Testmeldung' }).waitFor()
	await seite.unroute(muster)
	assert.equal(await seite.locator('.formular__konflikt').count(), 0)
	assert.equal(await seite.getByText('Im neuen Stand anders:').count(), 0)
	assert.equal(await seite.getByLabel('Telefon').getAttribute('aria-invalid'), 'true')
	assert.equal(await seite.getByLabel('Telefon').evaluate((e) => e === document.activeElement), true)
	// Die Werte der anderen Person sind übernommen, die eigene Eingabe steht noch.
	assert.equal(await seite.getByLabel('Zuständig intern').inputValue(), 'Andere Person')
	assert.equal(await seite.getByLabel('Telefon').inputValue(), '0711')
	await seite.getByRole('button', { name: 'Änderungen verwerfen' }).click()
	assert.deepEqual(fehler, [])
})

test('U-KAL-05: Konflikt bei Titel und Ort – Vergleich nennt beide, eigene Eingabe im Titel gilt, fremder Ort bleibt', async () => {
	const kalender = await planungskalenderId()
	await formularOeffnen(seite, kalender, 'fuellung-8.ics')
	await seite.getByLabel('Titel der Veranstaltung').fill('Mein Titel')
	await vonAnderenSpeichern(kalender, 'fuellung-8.ics', { titel: 'Fremder Titel', ort_extern: true, ort: 'Andere Halle 5' })
	await konfliktAbwarten()
	const liste = await seite.locator('.formular__konflikt ul').innerText()
	assert.match(liste, /Titel der Veranstaltung: „Fremder Titel“/)
	assert.match(liste, /Ort: „Andere Halle 5“/)
	assert.equal(await seite.getByLabel('Titel der Veranstaltung').inputValue(), 'Mein Titel')

	await seite.getByRole('button', { name: 'Meine Eingaben behalten und erneut speichern' }).click()
	const dialog = seite.getByRole('dialog', { name: 'Eigene Eingaben speichern?' })
	await dialog.getByText(/ersetzen die neuen Werte in „Titel der Veranstaltung“\./).waitFor()
	await dialog.getByRole('button', { name: 'Meine Eingaben speichern' }).click()
	await seite.getByRole('status').filter({ hasText: 'Gespeichert' }).waitFor()
	const stand = (await api('GET', `/termin?kalender=${kalender}&termin=fuellung-8.ics`)).daten
	assert.equal(stand.titel, 'Mein Titel')
	assert.equal(stand.ort_extern, true)
	assert.equal(stand.ort, 'Andere Halle 5')
	assert.equal(await seite.getByRole('radio', { name: 'Externer Ort' }).isChecked(), true)
	assert.equal(await seite.getByLabel('Externer Ort – Adresse').inputValue(), 'Andere Halle 5')
	assert.deepEqual(fehler, [])
})

test('U-KAL-05: zweiter Konflikt nach „Meine Eingaben behalten“ zeigt den neuen Vergleich, dann klappt es', async () => {
	const kalender = await planungskalenderId()
	await formularOeffnen(seite, kalender, 'fuellung-9.ics')
	await seite.getByLabel('Telefon').fill('0911')
	await vonAnderenSpeichern(kalender, 'fuellung-9.ics', { zustaendig: 'Erste Änderung' })
	await konfliktAbwarten()

	await seite.getByRole('button', { name: 'Meine Eingaben behalten und erneut speichern' }).click()
	const dialog = seite.getByRole('dialog', { name: 'Eigene Eingaben speichern?' })
	await dialog.getByRole('button', { name: 'Meine Eingaben speichern' }).waitFor()
	// Während die Rückfrage offen ist, speichert die andere Person noch einmal.
	await vonAnderenSpeichern(kalender, 'fuellung-9.ics', { zustaendig: 'Erste Änderung', hinweise: 'Zweite Änderung' })
	await dialog.getByRole('button', { name: 'Meine Eingaben speichern' }).click()
	await seite.locator('.formular__konflikt li', { hasText: 'Besondere Wünsche / Hinweise / Anmerkungen: „Zweite Änderung“' }).waitFor()
	await seite.getByRole('alert').filter({ hasText: 'inzwischen geändert' }).waitFor()
	assert.equal(await seite.locator('.formular__konflikt li', { hasText: 'Erste Änderung' }).count(), 0, 'der alte Vergleich ist ersetzt')
	await seite.waitForFunction(() => document.activeElement?.textContent?.trim() === 'Speichern')
	assert.equal(await seite.getByLabel('Telefon').inputValue(), '0911')

	await seite.getByRole('button', { name: 'Meine Eingaben behalten und erneut speichern' }).click()
	await dialog.getByRole('button', { name: 'Meine Eingaben speichern' }).click()
	await seite.getByRole('status').filter({ hasText: 'Gespeichert' }).waitFor()
	const stand = (await api('GET', `/termin?kalender=${kalender}&termin=fuellung-9.ics`)).daten
	assert.equal(stand.kontakt_telefon, '0911')
	assert.equal(stand.zustaendig, 'Erste Änderung')
	assert.equal(stand.hinweise, 'Zweite Änderung')
	assert.deepEqual(fehler, [])
})
