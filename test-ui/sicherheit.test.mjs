// Sicherheits-Regression gegen eine laufende Test-Nextcloud (tools/test-nextcloud.sh): die Live-Prüfungen des
// Audits vom 27.10.2026, als Tests. Jede Prüfung beschreibt, was ein Konto NICHT können darf.
// Läuft nach planung.ui.test.mjs; legt die Testdaten vorher neu an.
import { execFileSync } from 'node:child_process'
import http from 'node:http'
import { after, before, test } from 'node:test'
import assert from 'node:assert/strict'
import { chromium, request } from 'playwright-core'

const BASIS = process.env.NC_URL ?? 'http://localhost:8080'
const NUTZER = process.env.NC_USER ?? 'admin'
const PASSWORT = process.env.NC_PASS ?? 'Admin-Test-123'
const EVA_PASSWORT = process.env.NC_EVA_PASS ?? 'Eva-Test-12345'
const API = '/index.php/apps/calendar_planning/api'
const KALENDER_NAME = 'Planungskalender Verein'
const DAV = `${BASIS}/remote.php/dav/calendars/${NUTZER}/planung-test`

let admin, eva, browser, seite
let planungskalender, privaterKalender

const basic = (nutzer, passwort) => 'Basic ' + Buffer.from(`${nutzer}:${passwort}`).toString('base64')

/** Legt die Testdaten über tools/testdaten.sh an; unter Windows läuft das Skript über die Git Bash. */
function testdatenAnlegen() {
	const [befehl, argumente] = process.platform === 'win32' ? ['bash', ['tools/testdaten.sh']] : ['tools/testdaten.sh', []]
	execFileSync(befehl, argumente, { env: { ...process.env, NC_URL: BASIS, NC_USER: NUTZER, NC_PASS: PASSWORT, NC_EVA_PASS: EVA_PASSWORT }, stdio: 'inherit' })
}

/** API-Kontext mit Basic-Auth (wie ein Programm mit App-Passwort), nicht als Browser-Sitzung. */
async function kontext(nutzer, passwort, extra = {}) {
	return request.newContext({
		baseURL: BASIS,
		extraHTTPHeaders: { Authorization: basic(nutzer, passwort), 'OCS-APIRequest': 'true', Accept: 'application/json', ...extra },
	})
}

async function json(antwort) {
	try {
		return await antwort.json()
	} catch {
		return null
	}
}

async function termin(ctx, kalender, uri) {
	const antwort = await ctx.get(`${API}/termin`, { params: { kalender, termin: uri } })
	return { status: antwort.status(), daten: await json(antwort) }
}

/** Speichert workshop-1 mit den gegebenen Feldern über die API des gegebenen Kontos. */
async function speichern(ctx, kalender, felder, etag) {
	const vorher = etag ?? (await termin(ctx, kalender, 'workshop-1.ics')).daten.etag
	const planung = { titel: 'Lötworkshop Grundschule', ort_extern: false, ort: '', ...felder }
	const antwort = await ctx.put(`${API}/termin`, { data: { kalender, termin: 'workshop-1.ics', etag: vorher, planung } })
	return { status: antwort.status(), daten: await json(antwort) }
}

async function rohesIcs(uri = 'workshop-1.ics') {
	const antwort = await fetch(`${DAV}/${uri}`, { headers: { Authorization: basic(NUTZER, PASSWORT) } })
	return antwort.text()
}

before(async () => {
	testdatenAnlegen()
	admin = await kontext(NUTZER, PASSWORT)
	eva = await kontext('eva', EVA_PASSWORT)
	const kalender = (await json(await admin.get(`${API}/kalender`))).kalender
	planungskalender = kalender.find((k) => k.name === KALENDER_NAME)
	privaterKalender = kalender.find((k) => k.name !== KALENDER_NAME)
	assert.ok(planungskalender && privaterKalender, 'Test braucht den Planungskalender und einen zweiten, nicht geteilten Kalender')

	browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined })
	seite = await browser.newPage()
	await seite.goto(BASIS + '/login')
	await seite.fill('#user', NUTZER)
	await seite.fill('#password', PASSWORT)
	await seite.click('button[type=submit]')
	await seite.waitForURL(/apps/)
})

after(async () => {
	await admin?.dispose()
	await eva?.dispose()
	await browser?.close()
})

test('1 Ohne Anmeldung: alle Routen antworten 401', async () => {
	const ohne = await request.newContext({ baseURL: BASIS, extraHTTPHeaders: { Accept: 'application/json', 'OCS-APIRequest': 'true' } })
	for (const [methode, pfad] of [['get', '/einstellungen'], ['get', '/kalender'], ['put', '/kalender/auswahl'], ['get', '/termine?kalender=1'], ['get', '/termin?kalender=1&termin=x.ics'], ['put', '/termin']]) {
		const antwort = await ohne[methode](API + pfad, methode === 'put' ? { data: {} } : undefined)
		assert.equal(antwort.status(), 401, `${methode.toUpperCase()} ${pfad}`)
	}
	await ohne.dispose()
})

test('2 Browser-Sitzung ohne requesttoken: CSRF-Prüfung schlägt an (412)', async () => {
	const antwort = await seite.request.put(BASIS + API + '/kalender/auswahl', { data: { kalender: planungskalender.id } })
	assert.equal(antwort.status(), 412)
})

test('3 Fremder Kalender: eva bekommt für den nicht geteilten Kalender 404', async () => {
	const liste = (await json(await eva.get(`${API}/kalender`))).kalender
	assert.ok(!liste.some((k) => k.id === privaterKalender.id), 'der private Kalender steht nicht in evas Liste')
	assert.equal((await eva.get(`${API}/termine`, { params: { kalender: privaterKalender.id } })).status(), 404)
	assert.equal((await termin(eva, privaterKalender.id, 'workshop-1.ics')).status, 404)
	assert.equal((await eva.put(`${API}/kalender/auswahl`, { data: { kalender: privaterKalender.id } })).status(), 404)
})

test('4 Private und vertrauliche Termine sind für die Freigabe unsichtbar, auch bei direktem Zugriff', async () => {
	const vertraulich = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\nBEGIN:VEVENT\r\nUID:vertraulich-1\r\nDTSTAMP:20260101T000000Z\r\n'
		+ 'DTSTART;TZID=Europe/Berlin:20300105T090000\r\nDTEND;TZID=Europe/Berlin:20300105T100000\r\nSUMMARY:Vertraulicher Termin\r\nCLASS:CONFIDENTIAL\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n'
	const anlegen = await fetch(`${DAV}/vertraulich-1.ics`, { method: 'PUT', headers: { Authorization: basic(NUTZER, PASSWORT), 'Content-Type': 'text/calendar' }, body: vertraulich })
	assert.ok([201, 204].includes(anlegen.status))
	try {
		const parameter = { kalender: planungskalender.id, von: '2029-12-01', bis: '2030-02-01', pro_seite: 100 }
		const adminListe = await json(await admin.get(`${API}/termine`, { params: parameter }))
		assert.ok(adminListe.termine.some((t) => t.titel === 'Vertraulicher Termin'), 'der Eigentümer sieht den Termin')
		const evaListe = await json(await eva.get(`${API}/termine`, { params: parameter }))
		assert.ok(!evaListe.termine.some((t) => t.titel === 'Vertraulicher Termin'))
		assert.equal((await termin(eva, planungskalender.id, 'vertraulich-1.ics')).status, 404)
		assert.equal((await termin(eva, planungskalender.id, 'privat-1.ics')).status, 404)
		assert.equal((await termin(admin, planungskalender.id, 'privat-1.ics')).status, 200)
	} finally {
		await fetch(`${DAV}/vertraulich-1.ics`, { method: 'DELETE', headers: { Authorization: basic(NUTZER, PASSWORT) } })
	}
})

test('5 Nur-Lese-Freigabe: Speichern antwortet 403, der Termin bleibt unverändert', async () => {
	const vorher = await termin(eva, planungskalender.id, 'workshop-1.ics')
	assert.equal(vorher.status, 200)
	assert.equal(vorher.daten.schreibbar, false)
	const antwort = await speichern(eva, planungskalender.id, { titel: 'von eva geändert' }, vorher.daten.etag)
	assert.equal(antwort.status, 403)
	assert.equal((await termin(admin, planungskalender.id, 'workshop-1.ics')).daten.titel, 'Lötworkshop Grundschule')
})

test('6 iCalendar-Injection: Zeilenumbrüche und Schlüsselwörter landen als Text, nicht als Eigenschaften', async () => {
	const gift = '\r\nSUMMARY:injiziert\r\nATTENDEE:mailto:boese@example.org\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:neu\r\n'
	const antwort = await speichern(admin, planungskalender.id, {
		titel: 'Lötworkshop Grundschule' + gift,
		ort_extern: true,
		ort: 'Halle' + gift,
		hinweise: 'Hinweis' + gift,
	})
	assert.equal(antwort.status, 200)
	const ics = await rohesIcs()
	const zeilen = ics.split(/\r?\n/)
	assert.equal(zeilen.filter((z) => z === 'BEGIN:VEVENT').length, 1)
	assert.equal(zeilen.filter((z) => z.startsWith('ATTENDEE')).length, 0)
	assert.equal(zeilen.filter((z) => z.startsWith('SUMMARY:')).length, 1)
	assert.equal(zeilen.filter((z) => z.startsWith('UID:')).length, 1)
	// Der Termin bleibt für die App und die Kalender-App lesbar.
	const danach = await termin(admin, planungskalender.id, 'workshop-1.ics')
	assert.equal(danach.status, 200)
	assert.match(danach.daten.hinweise, /^Hinweis\nSUMMARY:injiziert/)
	assert.doesNotMatch(danach.daten.titel, /[\r\n]/)
	await speichern(admin, planungskalender.id, {})
})

test('7 Eingabegrenzen: zu lang, falscher Typ, ungültige Mail antworten 400 ohne Stacktrace', async () => {
	const faelle = [
		[{ hinweise: 'x'.repeat(5001) }, /zu lang/],
		[{ titel: { a: 1 } }, /kein Text/],
		[{ kontakt_email: 'keine-mail' }, /E-Mail/],
		[{ ort_extern: true, ort: '' }, /Adresse/],
	]
	for (const [felder, muster] of faelle) {
		const antwort = await speichern(admin, planungskalender.id, felder)
		assert.equal(antwort.status, 400, JSON.stringify(felder).slice(0, 60))
		assert.match(antwort.daten.fehler, muster)
		assert.doesNotMatch(JSON.stringify(antwort.daten), /Exception|\.php|Stack/)
	}
	// planung als Zeichenkette statt Objekt: Nextcloud lehnt den Parameter ab, kein 500.
	const etag = (await termin(admin, planungskalender.id, 'workshop-1.ics')).daten.etag
	const kaputt = await admin.put(`${API}/termin`, { data: { kalender: planungskalender.id, termin: 'workshop-1.ics', etag, planung: 'text' } })
	assert.ok(kaputt.status() >= 400 && kaputt.status() < 500, `Status ${kaputt.status()}`)
})

test('8 Veralteter ETag: 409, nichts gespeichert', async () => {
	const antwort = await speichern(admin, planungskalender.id, { zustaendig: 'Konflikt' }, '"veraltet"')
	assert.equal(antwort.status, 409)
	assert.notEqual((await termin(admin, planungskalender.id, 'workshop-1.ics')).daten.zustaendig, 'Konflikt')
})

test('9 Termin-URI mit Pfad oder SQL: 404', async () => {
	for (const uri of ['../../../etc/passwd', "' OR 1=1--", '..%2F..%2Fconfig%2Fconfig.php']) {
		assert.equal((await termin(admin, planungskalender.id, uri)).status, 404, uri)
	}
})

test('10 Unsinnige Parameter werden abgefangen', async () => {
	assert.equal((await admin.get(`${API}/termine`, { params: { kalender: 'abc' } })).status(), 404)
	const seiten = await json(await admin.get(`${API}/termine`, { params: { kalender: planungskalender.id, pro_seite: 7, seite: -5 } }))
	assert.equal(seiten.pro_seite, 25)
	assert.equal(seiten.seite, 1)
	const zehnJahre = await admin.get(`${API}/termine`, { params: { kalender: planungskalender.id, von: '2020-01-01', bis: '2030-01-01' } })
	assert.equal(zehnJahre.status(), 400)
	assert.match((await json(zehnJahre)).fehler, /drei Jahre/)
	const verdreht = await admin.get(`${API}/termine`, { params: { kalender: planungskalender.id, von: '2026-05-01', bis: '2026-04-01' } })
	assert.equal(verdreht.status(), 400)
	assert.equal((await admin.get(`${API}/termine`, { params: { kalender: planungskalender.id, von: '2026-13-45' } })).status(), 400)
})

test('11 Fremder Host im Link: Host-Header wird abgelehnt, X-Forwarded-Host ignoriert', async () => {
	const { hostname, port } = new URL(BASIS)
	const hostStatus = await new Promise((fertig, fehler) => {
		const anfrage = http.request({ hostname, port, path: `${API}/kalender`, method: 'GET', headers: { Host: 'evil.example.org', Authorization: basic(NUTZER, PASSWORT), 'OCS-APIRequest': 'true' } }, (antwort) => {
			antwort.resume()
			antwort.on('end', () => fertig(antwort.statusCode))
		})
		anfrage.on('error', fehler)
		anfrage.end()
	})
	assert.equal(hostStatus, 400)

	const weitergeleitet = await kontext(NUTZER, PASSWORT, { 'X-Forwarded-Host': 'evil.example.org', 'X-Forwarded-Proto': 'https' })
	const antwort = await speichern(weitergeleitet, planungskalender.id, { zustaendig: 'Link-Test' })
	await weitergeleitet.dispose()
	assert.equal(antwort.status, 200)
	assert.doesNotMatch(antwort.daten.beschreibung, /evil\.example\.org/)
	assert.match(antwort.daten.beschreibung, new RegExp(`Planung öffnen: ${BASIS.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`))
})

test('12 Antwort-Header der App-Seite: CSP mit Nonce, nosniff, no-referrer, kein Framing von außen', async () => {
	const antwort = await seite.goto(BASIS + '/index.php/apps/calendar_planning/')
	const header = antwort.headers()
	assert.match(header['content-security-policy'] ?? '', /default-src 'none'/)
	assert.match(header['content-security-policy'] ?? '', /nonce-/)
	assert.equal(header['x-content-type-options'], 'nosniff')
	assert.equal(header['referrer-policy'], 'no-referrer')
	assert.equal(header['x-frame-options'], 'SAMEORIGIN')
})

test('13 Einstellungen: lesbar für jedes Konto, nur der Standardort wird verraten', async () => {
	const antwort = await eva.get(`${API}/einstellungen`)
	assert.equal(antwort.status(), 200)
	assert.deepEqual(await json(antwort), { ort_vorgabe: 'Vereinsheim' })
})
