// Browser-Tests für die Terminliste (src/components/TerminListe.vue, Seiten.vue): Breiten ohne seitliches Scrollen
// (U-KAL-02), Suche entprellt mit Abbruch alter Anfragen (P-KAL-03, Q-KAL-08), Korrektur der Seite ohne neuen
// Verlaufseintrag (Q-KAL-07), Terminzeilen als Links (U-KAL-07), benannte Blätter-Knöpfe (U-KAL-12), leere Suche mit
// Ausweg (U-KAL-14) und Ansage der Ergebnisse (U-KAL-15).
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
const DAV = `${BASIS}/remote.php/dav/calendars/${NUTZER}/planung-test`
const BASIC = 'Basic ' + Buffer.from(`${NUTZER}:${PASSWORT}`).toString('base64')
const TERMINE = '**/apps/calendar_planning/api/termine?**'

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

async function listeOeffnen(parameter = '') {
	await seite.goto(APP + parameter)
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
}

const suchfeld = () => seite.getByPlaceholder(/Titel, Ort/)
const ansage = () => seite.locator('.liste > [role=status]')
const suchParameter = () => new URL(seite.url()).searchParams.get('suche')
const ohneNull = async () => assert.doesNotMatch(await seite.locator('.planung').innerText(), /\bnull\b|\bundefined\b/)

/** Legt einen Termin per CalDAV an (Präfix „UI-“ im Namen der Datei); `tage` ab heute. */
async function terminAnlegen(uid, tage, titel, ort) {
	const tag = new Date(Date.now() + tage * 86400000).toISOString().slice(0, 10).replaceAll('-', '')
	const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Kalender-Planung Test//DE', 'BEGIN:VEVENT', `UID:${uid}`,
		`DTSTAMP:${tag}T000000Z`, `DTSTART;TZID=Europe/Berlin:${tag}T100000`, `DTEND;TZID=Europe/Berlin:${tag}T120000`,
		`SUMMARY:${titel}`, `LOCATION:${ort}`, 'END:VEVENT', 'END:VCALENDAR', ''].join('\r\n')
	const antwort = await fetch(`${DAV}/${uid}.ics`, { method: 'PUT', headers: { Authorization: BASIC, 'Content-Type': 'text/calendar' }, body: ics })
	assert.ok([201, 204].includes(antwort.status), `Termin anlegen: ${antwort.status}`)
}

async function terminLoeschen(uid) {
	await fetch(`${DAV}/${uid}.ics`, { method: 'DELETE', headers: { Authorization: BASIC } })
}

for (const breite of [1280, 1024, 860, 600, 320]) {
	test(`U-KAL-02: Liste bei ${breite} Pixel ohne seitliches Scrollen, Planungsstatus jeder Zeile sichtbar, auch mit langem Titel`, async () => {
		const uid = `UI-lang-${breite}`
		await terminAnlegen(uid, 2, 'UI-' + 'Ueberlangertitelohneleerzeichen'.repeat(3).slice(0, 77), 'UI-' + 'Ortsangabeohneleerzeichen'.repeat(3))
		try {
			await seite.setViewportSize({ width: breite, height: 900 })
			await listeOeffnen()
			await seite.locator('tbody').getByText(/^UI-Ueberlang/).waitFor()
			const masse = await seite.evaluate(() => {
				const rahmen = document.querySelector('.liste__rahmen')
				const r = rahmen.getBoundingClientRect()
				const marken = [...document.querySelectorAll('tbody .liste__planung')].map((zelle) => {
					const m = zelle.querySelector('[class*="liste__status"]').getBoundingClientRect()
					return { text: zelle.textContent, sichtbar: m.width > 0 && m.left >= r.left - 1 && m.right <= r.right + 1 }
				})
				const kopf = [...document.querySelectorAll('thead th')].find((th) => th.textContent.trim() === 'Planung').getBoundingClientRect()
				// Spaltenzuordnung für Bildschirmleser: jede Zelle mit dem aria-colindex ihres Spaltenkopfs, keine Zelle
				// mit display:none (sonst rutschen die folgenden Zellen unter den falschen Kopf).
				const koepfe = [...document.querySelectorAll('thead th')].map((th) => th.getAttribute('aria-colindex'))
				const zeilen = [...document.querySelectorAll('tbody tr')].map((tr) => [...tr.querySelectorAll('td')].map((td) => td.getAttribute('aria-colindex')))
				const versteckt = [...document.querySelectorAll('tbody td')].filter((td) => getComputedStyle(td).display === 'none').length
				const leer = [...document.querySelectorAll('tbody td.liste__leer')].map((td) => td.getBoundingClientRect())
				return {
					spaltenZahl: document.querySelector('table').getAttribute('aria-colcount'),
					koepfe,
					zeilen,
					versteckt,
					leerSichtbar: leer.filter((m) => m.width > 1 || m.height > 1).length,
					leerZahl: leer.length,
					seite: document.documentElement.scrollWidth - document.documentElement.clientWidth,
					rahmen: rahmen.scrollWidth - rahmen.clientWidth,
					marken,
					kopfSichtbar: kopf.width > 1 && kopf.left >= r.left - 1 && kopf.right <= r.right + 1,
				}
			})
			assert.equal(masse.seite, 0, 'Seite scrollt seitlich')
			assert.ok(masse.rahmen <= 0, `Liste scrollt seitlich um ${masse.rahmen} Pixel`)
			assert.ok(masse.marken.length > 20, 'Zeilen fehlen')
			assert.deepEqual(masse.marken.filter((m) => !m.sichtbar), [], 'Planungsstatus außerhalb des sichtbaren Bereichs')
			if (breite >= 1024) {
				assert.equal(masse.kopfSichtbar, true, 'Spalte „Planung“ nicht sichtbar')
			}
			const spalten = Number(masse.spaltenZahl)
			assert.ok(spalten >= 4, `aria-colcount ${masse.spaltenZahl}`)
			assert.deepEqual(masse.koepfe, Array.from({ length: spalten }, (_, i) => String(i + 1)))
			for (const zeile of masse.zeilen) {
				assert.deepEqual(zeile, masse.koepfe, 'aria-colindex der Zellen passt nicht zu den Spaltenköpfen')
			}
			assert.equal(masse.versteckt, 0, 'Zellen mit display:none')
			assert.ok(masse.leerZahl > 0, 'keine leeren Zellen in den Testdaten')
			if (breite <= 860) {
				assert.equal(masse.leerSichtbar, 0, 'leere Zellen nehmen Platz ein')
			}
			await ohneNull()
			if (process.env.SCREENSHOT_DIR) {
				await seite.screenshot({ path: `${process.env.SCREENSHOT_DIR}/liste-${breite}.png` })
			}
		} finally {
			await terminLoeschen(uid)
			await seite.setViewportSize({ width: 1280, height: 900 })
		}
		assert.deepEqual(fehler, [])
	})
}

test('P-KAL-03, Q-KAL-08: schnelles Tippen schickt nur eine Anfrage, das Leerzeichen am Ende bleibt im Feld', async () => {
	await listeOeffnen()
	const anfragen = []
	const merken = (r) => {
		if (r.url().includes('/api/termine?')) {
			anfragen.push(new URL(r.url()).searchParams.get('suche'))
		}
	}
	seite.on('request', merken)
	await suchfeld().pressSequentially('Werkstatt ', { delay: 30 })
	await seite.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1)
	seite.off('request', merken)
	assert.deepEqual(anfragen, ['Werkstatt'])
	assert.equal(suchParameter(), 'Werkstatt')
	assert.equal(await suchfeld().inputValue(), 'Werkstatt ')
	// Weitertippen nach dem Leerzeichen
	await suchfeld().pressSequentially('X')
	await seite.waitForURL(/suche=Werkstatt(\+|%20)X/)
	assert.equal(await suchfeld().inputValue(), 'Werkstatt X')
	assert.deepEqual(fehler, [])
})

test('P-KAL-03: eine überholte Anfrage wird abgebrochen, ihre späte Antwort ändert die Liste nicht', async () => {
	await listeOeffnen()
	let freigeben
	const gehalten = new Promise((weiter) => { freigeben = weiter })
	let erledigt
	const fertig = new Promise((weiter) => { erledigt = weiter })
	await seite.route(TERMINE, async (route) => {
		if (new URL(route.request().url()).searchParams.get('suche') !== 'Füller') {
			return route.continue()
		}
		// Antwort holen, aber erst nach der neueren Anfrage ausliefern.
		const antwort = await route.fetch()
		await gehalten
		await route.fulfill({ response: antwort }).catch(() => {})
		erledigt()
	})
	const abgebrochen = seite.waitForEvent('requestfailed', (r) => r.url().includes('suche=F'))
	await suchfeld().fill('Füller')
	await seite.waitForURL(/suche=F/)
	await suchfeld().fill('Werkstatt')
	await seite.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1)
	await abgebrochen
	freigeben()
	await fertig
	await seite.evaluate(() => new Promise((weiter) => requestAnimationFrame(() => requestAnimationFrame(weiter))))
	assert.match(await seite.locator('tbody').innerText(), /Offene Werkstatt/)
	assert.equal(await seite.locator('tbody tr').count(), 1)
	assert.equal(suchParameter(), 'Werkstatt')
	await seite.unroute(TERMINE)
	assert.deepEqual(fehler, [])
})

test('Q-KAL-07: Seite über der letzten wird ohne neuen Verlaufseintrag korrigiert, die Zurück-Taste funktioniert', async () => {
	await listeOeffnen()
	const vorher = seite.url()
	const laenge = await seite.evaluate(() => history.length)
	const kalender = new URL(vorher).searchParams.get('kalender')
	await seite.goto(`${APP}?kalender=${kalender}&seite=99`)
	await seite.waitForURL(/seite=2(&|$)/)
	await seite.getByText(/Seite 2 von 2/).waitFor()
	assert.equal(await seite.evaluate(() => history.length), laenge + 1, 'Korrektur hat einen Verlaufseintrag angelegt')
	assert.equal(await seite.evaluate(() => history.state?.calplanIndex), 0, 'Position im Verlauf fehlt')
	await seite.goBack()
	await seite.waitForURL(vorher)
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	await seite.getByText(/Seite 1 von 2/).waitFor()
	assert.equal(seite.url(), vorher)
	await seite.goForward()
	await seite.getByText(/Seite 2 von 2/).waitFor()
	assert.match(seite.url(), /seite=2(&|$)/)
	assert.deepEqual(fehler, [])
})

test('U-KAL-07: Titel ist ein Link; Klick öffnet das Formular ohne Neuladen, Strg-Klick einen neuen Tab', async () => {
	await listeOeffnen()
	const link = seite.locator('tbody').getByRole('link', { name: 'Lötworkshop Grundschule' })
	const href = await link.getAttribute('href')
	assert.match(href, /\/apps\/calendar_planning\/\?kalender=\d+&termin=workshop-1\.ics/)
	// Die Zeile selbst ist kein zusätzlicher Tab-Halt.
	assert.equal(await seite.locator('tbody tr[tabindex]').count(), 0)

	const [neuerTab] = await Promise.all([kontext.waitForEvent('page'), link.click({ modifiers: ['Control'] })])
	await neuerTab.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	assert.match(neuerTab.url(), /termin=workshop-1\.ics/)
	await neuerTab.close()
	assert.doesNotMatch(seite.url(), /termin=/, 'Strg-Klick hat die Liste verlassen')

	await seite.evaluate(() => { window.ohneNeuladen = true })
	await link.click()
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	assert.match(seite.url(), /termin=workshop-1\.ics/)
	assert.equal(await seite.evaluate(() => window.ohneNeuladen), true, 'Seite wurde neu geladen')

	// Per Tastatur: Tab auf den Link, Enter
	await seite.goBack()
	await link.waitFor()
	await link.focus()
	await seite.keyboard.press('Enter')
	await seite.getByRole('heading', { name: /Planung: Lötworkshop/ }).waitFor()
	assert.equal(await seite.evaluate(() => window.ohneNeuladen), true)
	assert.deepEqual(fehler, [])
})

test('U-KAL-12: Blätter-Knöpfe tragen Namen, die aktuelle Seite ist markiert', async () => {
	await listeOeffnen()
	const knopf = (name) => seite.getByRole('navigation', { name: 'Seiten' }).getByRole('button', { name, exact: true })
	for (const name of ['Erste Seite', 'Vorige Seite', 'Seite 1', 'Seite 2', 'Nächste Seite', 'Letzte Seite']) {
		assert.equal(await knopf(name).count(), 1, `Knopf „${name}“ fehlt`)
	}
	assert.equal(await knopf('Seite 1').getAttribute('aria-current'), 'page')
	assert.equal(await knopf('Seite 2').getAttribute('aria-current'), null)
	assert.equal(await knopf('Vorige Seite').isDisabled(), true)
	await knopf('Nächste Seite').click()
	await seite.waitForURL(/seite=2/)
	await seite.getByText(/Seite 2 von 2/).waitFor()
	assert.equal(await knopf('Seite 2').getAttribute('aria-current'), 'page')
	assert.equal(await knopf('Letzte Seite').isDisabled(), true)
	assert.deepEqual(fehler, [])
})

test('U-KAL-14: leere Suche nennt den Suchbegriff und bietet „Suche zurücksetzen“', async () => {
	await listeOeffnen()
	await suchfeld().fill('Gibtesnicht')
	await seite.getByText('Keine Termine zu „Gibtesnicht“ im gewählten Zeitraum.').waitFor()
	await ohneNull()
	await seite.getByRole('button', { name: 'Suche zurücksetzen' }).click()
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	assert.equal(await suchfeld().inputValue(), '')
	assert.equal(suchParameter(), null)
	assert.equal(await suchfeld().evaluate((e) => e === document.activeElement), true, 'Fokus im Suchfeld')
	assert.deepEqual(fehler, [])
})

test('Q-KAL-07: Fehler einer Anfrage verschwindet, wenn die angezeigte Liste wieder gilt', async () => {
	await listeOeffnen()
	await seite.route(TERMINE, async (route) => {
		if (new URL(route.request().url()).searchParams.get('suche') !== 'Kaputt') {
			return route.continue()
		}
		await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ fehler: 'Testfehler aus dem Browser-Test' }) })
	})
	await suchfeld().fill('Kaputt')
	await seite.waitForURL(/suche=Kaputt/)
	await seite.waitForFunction(() => document.querySelector('.liste__ergebnis')?.getAttribute('aria-busy') === 'false'
		&& document.querySelector('.liste .notecard--error') !== null)
	// Die Liste der vorigen Antwort steht noch da; zurück zu genau diesen Parametern: kein neues Laden, keine Fehlerkarte.
	const anfragen = []
	const merken = (r) => {
		if (r.url().includes('/api/termine?')) {
			anfragen.push(r.url())
		}
	}
	seite.on('request', merken)
	await suchfeld().fill('')
	await seite.waitForFunction(() => !new URL(location.href).searchParams.has('suche'))
	await seite.waitForFunction(() => document.querySelector('.liste .notecard--error') === null)
	seite.off('request', merken)
	assert.deepEqual(anfragen, [], 'Liste wurde neu geladen statt die angezeigte zu behalten')
	await seite.locator('tbody').getByText('Lötworkshop Grundschule').waitFor()
	await seite.unroute(TERMINE)
	assert.deepEqual(fehler, [])
})

test('U-KAL-15: Laden ist als aria-busy markiert, die Zahl der Treffer wird einmal nach dem Tippen angesagt', async () => {
	await listeOeffnen()
	await seite.waitForFunction(() => /33 Termine gefunden/.test(document.querySelector('.liste > [role=status]')?.textContent ?? ''))
	assert.equal(await ansage().getAttribute('aria-live'), 'polite')
	// Alle Texte mitschreiben, die die Live-Region während des Tippens bekommt.
	await seite.evaluate(() => {
		window.ansagen = []
		new MutationObserver(() => {
			const text = document.querySelector('.liste > [role=status]').textContent.trim()
			if (text) {
				window.ansagen.push(text)
			}
		}).observe(document.querySelector('.liste > [role=status]'), { childList: true, subtree: true, characterData: true })
	})

	let freigeben
	const gehalten = new Promise((weiter) => { freigeben = weiter })
	await seite.route(TERMINE, async (route) => {
		await gehalten
		await route.continue()
	})
	await suchfeld().pressSequentially('Werkstatt', { delay: 30 })
	await seite.waitForURL(/suche=Werkstatt/)
	await seite.waitForFunction(() => document.querySelector('.liste__ergebnis')?.getAttribute('aria-busy') === 'true')
	freigeben()
	await seite.waitForFunction(() => document.querySelector('.liste__ergebnis')?.getAttribute('aria-busy') === 'false')
	await seite.waitForFunction(() => window.ansagen.length > 0)
	assert.deepEqual(await seite.evaluate(() => window.ansagen), ['1 Termin gefunden.'])
	await seite.unroute(TERMINE)
	assert.deepEqual(fehler, [])
})
