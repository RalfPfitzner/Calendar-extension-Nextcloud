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

	// SICHTBAR=1 öffnet ein echtes Browserfenster und bremst die Schritte, um zuzuschauen.
	browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, headless: !process.env.SICHTBAR, slowMo: process.env.SICHTBAR ? 300 : 0 })
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

test('13 Einstellungen: lesbar für jedes Konto, nur Standardort und Feldschema werden verraten', async () => {
	const antwort = await eva.get(`${API}/einstellungen`)
	assert.equal(antwort.status(), 200)
	const daten = await json(antwort)
	assert.deepEqual(Object.keys(daten).sort(), ['ort_vorgabe', 'schema'])
	assert.equal(daten.ort_vorgabe, 'Vereinsheim')
	assert.equal(daten.schema.abschnitte.length, 5)
})

test('14 Schema-Routen: nur Admins; eva bekommt 403, Besucher 401', async () => {
	for (const [methode, pfad] of [['get', '/schema'], ['put', '/schema'], ['get', '/admin/einstellungen'], ['put', '/admin/einstellungen']]) {
		const antwort = await eva[methode](API + pfad, methode === 'put' ? { data: {} } : undefined)
		assert.equal(antwort.status(), 403, `eva ${methode.toUpperCase()} ${pfad}`)
	}
	const ohne = await request.newContext({ baseURL: BASIS, extraHTTPHeaders: { Accept: 'application/json', 'OCS-APIRequest': 'true' } })
	assert.equal((await ohne.get(`${API}/schema`)).status(), 401)
	await ohne.dispose()
	// Auch die Browser-Sitzung eines Admins braucht das CSRF-Token.
	assert.equal((await seite.request.put(BASIS + API + '/schema', { data: { schema: {} } })).status(), 412)
})

test('15 Schema-Härte: feindliche Schlüssel und zu viele Felder werden abgelehnt, das Schema bleibt', async () => {
	const vorher = (await json(await admin.get(`${API}/schema`))).schema
	const feld = (key, extra = {}) => ({ key, label: 'Feld ' + key, typ: 'text', ...extra })
	const faelle = [
		[{ abschnitte: [{ titel: 'T', felder: [feld('END:VEVENT')] }] }, /Feld 1 in Abschnitt 1 \(„Feld END:VEVENT“\): Der Schlüssel „END:VEVENT“ ist ungültig/],
		[{ abschnitte: [{ titel: 'T', felder: [feld('summary\r\nATTENDEE')] }] }, /Der Schlüssel „summary ATTENDEE“ ist ungültig/],
		[{ abschnitte: [{ titel: 'T', felder: [feld('titel')] }] }, /reserviert/],
		[{ abschnitte: [{ titel: 'T', felder: Array.from({ length: 51 }, (_, i) => feld(`f${i}`)) }] }, /Höchstens 50 Felder/],
		[{ abschnitte: [{ titel: 'T', felder: [feld('a', { maxlen: 999999 })] }] }, /„Max\. Zeichen“ muss eine ganze Zahl/],
		[{ abschnitte: [{ titel: 'T', felder: [feld('a', { typ: 'html' })] }] }, /Der Typ ist unbekannt/],
		[{ abschnitte: [{ titel: 'T', felder: [feld('a', { label: '<img src=x onerror=alert(1)>'.repeat(10) })] }] }, /zu lang/],
		[{ abschnitte: 'kaputt' }, /Liste/],
	]
	for (const [schema, muster] of faelle) {
		const antwort = await admin.put(`${API}/schema`, { data: { schema } })
		assert.equal(antwort.status(), 400, JSON.stringify(schema).slice(0, 80))
		assert.match((await json(antwort)).fehler, muster)
	}
	assert.deepEqual((await json(await admin.get(`${API}/schema`))).schema, vorher)
	// Bezeichnungen sind Text, kein HTML: der Server speichert sie unverändert, die Oberfläche escaped sie.
	const xss = await admin.put(`${API}/schema`, { data: { schema: { abschnitte: [{ titel: '<b>T</b>', felder: [feld('a', { label: '<script>alert(1)</script>' })] }] } } })
	assert.equal(xss.status(), 200)
	assert.equal((await json(xss)).schema.abschnitte[0].felder[0].label, '<script>alert(1)</script>')
	await seite.goto(BASIS + '/index.php/apps/calendar_planning/?kalender=' + planungskalender.id + '&termin=workshop-1.ics')
	await seite.getByText('<script>alert(1)</script>').waitFor()
	assert.equal(await seite.locator('form script').count(), 0)
	assert.equal((await admin.put(`${API}/schema`, { data: { schema: vorher } })).status(), 200)
})

test('16 Admin-Vorgaben: Steuerzeichen und Überlänge werden bereinigt, falscher Typ abgelehnt', async () => {
	const vorher = await json(await admin.get(`${API}/admin/einstellungen`))
	const antwort = await admin.put(`${API}/admin/einstellungen`, { data: { ort_vorgabe: "Halle\r\nSUMMARY:x\x00", kalender_muster: 'x'.repeat(300), link_text: '' } })
	assert.equal(antwort.status(), 200)
	const daten = await json(antwort)
	assert.equal(daten.ort_vorgabe, 'Halle SUMMARY:x')
	assert.equal(daten.kalender_muster.length, 100)
	assert.equal(daten.link_text, 'Planung öffnen:')
	assert.equal((await admin.put(`${API}/admin/einstellungen`, { data: { ort_vorgabe: ['kein', 'text'] } })).status(), 400)
	assert.equal((await admin.put(`${API}/admin/einstellungen`, { data: vorher })).status(), 200)
	assert.deepEqual(await json(await admin.get(`${API}/admin/einstellungen`)), vorher)
})

/** Container der Test-Nextcloud (tools/test-nextcloud.sh, NC_NAME); ohne Docker-Zugriff fallen die Prüfungen am Protokoll weg. */
const CONTAINER = process.env.NC_NAME ?? 'calplan-nc'
function imContainer(...argumente) {
	return execFileSync('docker', ['exec', '-u', 'www-data', '-w', '/var/www/html', CONTAINER, ...argumente], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
}
let mitDocker = true
try {
	imContainer('true')
} catch {
	mitDocker = false
}

/** Ersetzt Text in den gespeicherten Daten eines Kalenderobjekts direkt in der Datenbank, an CalDAV und seiner Prüfung vorbei. */
function kalenderdatenAendern(uri, alt, neu) {
	const php = `require 'lib/base.php';
$db = \\OCP\\Server::get(\\OCP\\IDBConnection::class);
$q = $db->getQueryBuilder();
$q->select('id', 'calendardata')->from('calendarobjects')->where($q->expr()->eq('uri', $q->createNamedParameter($argv[1])));
foreach ($q->executeQuery()->fetchAll() as $z) {
	$d = is_resource($z['calendardata']) ? stream_get_contents($z['calendardata']) : $z['calendardata'];
	$u = $db->getQueryBuilder();
	$u->update('calendarobjects')->set('calendardata', $u->createNamedParameter(str_replace($argv[2], $argv[3], $d), \\OCP\\DB\\QueryBuilder\\IQueryBuilder::PARAM_LOB))
		->where($u->expr()->eq('id', $u->createNamedParameter($z['id'])))->executeStatement();
}`
	imContainer('php', '-r', php, '--', uri, alt, neu)
}

async function davAnlegen(uri, ics) {
	const antwort = await fetch(`${DAV}/${uri}`, { method: 'PUT', headers: { Authorization: basic(NUTZER, PASSWORT), 'Content-Type': 'text/calendar' }, body: ics })
	assert.ok([201, 204].includes(antwort.status), `${uri} anlegen: ${antwort.status}`)
}

async function davLoeschen(uri) {
	await fetch(`${DAV}/${uri}`, { method: 'DELETE', headers: { Authorization: basic(NUTZER, PASSWORT), 'X-NC-CalDAV-No-Trashbin': '1' } })
}

const einTermin = (uid, zeilen) => 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\nBEGIN:VEVENT\r\n'
	+ `UID:${uid}\r\nDTSTAMP:20260101T000000Z\r\n${zeilen}END:VEVENT\r\nEND:VCALENDAR\r\n`

test('17 Gleichzeitig speichern mit demselben ETag: genau eine Speicherung gewinnt, die andere bekommt 409', async () => {
	for (const runde of [1, 2]) {
		const etag = (await termin(admin, planungskalender.id, 'workshop-1.ics')).daten.etag
		const antworten = await Promise.all(['Erste', 'Zweite'].map((wer) => speichern(admin, planungskalender.id, { zustaendig: `${wer} ${runde}` }, etag)))
		assert.deepEqual(antworten.map((a) => a.status).sort(), [200, 409], `Runde ${runde}`)
		const gewinner = antworten.find((a) => a.status === 200)
		const verlierer = antworten.find((a) => a.status === 409)
		assert.match(verlierer.daten.fehler, /geändert|gespeichert/)
		const danach = (await termin(admin, planungskalender.id, 'workshop-1.ics')).daten
		assert.equal(danach.zustaendig, gewinner.daten.zustaendig)
		assert.equal(danach.etag, gewinner.daten.etag)
	}
	await speichern(admin, planungskalender.id, {})
})

test('18 Interner Fehler: Termininhalt und Kontaktdaten erscheinen nicht im Nextcloud-Protokoll', { skip: !mitDocker && `kein Zugriff auf den Container ${CONTAINER}` }, async () => {
	const marke = `GEHEIM-${Date.now()}-kontakt@example.org`
	const uri = 'kaputt-1.ics'
	await davAnlegen(uri, einTermin('kaputt-1', `DTSTART;TZID=Europe/Berlin:20310105T090000\r\nDURATION:PT1H\r\nSUMMARY:Kaputter Termin ${marke}\r\nDESCRIPTION:Telefon 0123 ${marke}\r\n`))
	try {
		// Eine Dauer, an der der iCalendar-Parser scheitert; dessen Meldung zitiert den Wert, also die Marke.
		kalenderdatenAendern(uri, 'DURATION:PT1H', `DURATION:${marke}`)
		const antwort = await termin(admin, planungskalender.id, uri)
		assert.equal(antwort.status, 500)
		assert.doesNotMatch(JSON.stringify(antwort.daten), /GEHEIM|example\.org|Exception|\.php/)
		const protokoll = imContainer('cat', 'data/nextcloud.log')
		assert.ok(protokoll.includes('Kalender-Planung: interner Fehler'), 'der Fehler steht im Protokoll')
		assert.ok(!protokoll.includes('GEHEIM-'), 'die Marke aus dem Termin steht nicht im Protokoll')
		assert.ok(!protokoll.includes('0123'), 'die Telefonnummer steht nicht im Protokoll')
	} finally {
		kalenderdatenAendern(uri, `DURATION:${marke}`, 'DURATION:PT1H')
		await davLoeschen(uri)
	}
})

test('19 Zu großes Feldschema: 400 mit verständlicher Meldung, das gespeicherte Schema bleibt', async () => {
	const vorher = (await json(await admin.get(`${API}/schema`))).schema
	// Für sich gültig (50 Felder mit je 50 Optionen), als JSON aber weit über 64 KiB.
	const felder = Array.from({ length: 50 }, (_, i) => ({
		key: `f${i}`, label: `Feld ${i}`, typ: 'select', optionen: Array.from({ length: 50 }, (_, j) => `Option ${i}-${j} ` + 'x'.repeat(60)),
	}))
	const antwort = await admin.put(`${API}/schema`, { data: { schema: { abschnitte: [{ titel: 'Groß', felder }] } } })
	assert.equal(antwort.status(), 400)
	assert.match((await json(antwort)).fehler, /zu groß.*höchstens 64 KiB/)
	assert.deepEqual((await json(await admin.get(`${API}/schema`))).schema, vorher)
})

test('20 Unsichtbare und Richtungszeichen landen nicht im Termin', async () => {
	const antwort = await speichern(admin, planungskalender.id, { titel: 'Lötworkshop‮ Grund​schule⁦', zustaendig: '‏Eva﻿' })
	assert.equal(antwort.status, 200)
	assert.equal(antwort.daten.titel, 'Lötworkshop Grundschule')
	assert.equal(antwort.daten.zustaendig, 'Eva')
	assert.doesNotMatch(await rohesIcs(), /[​‎‏‪-‮⁦-⁩﻿]/)
	await speichern(admin, planungskalender.id, {})
})

test('21 Zeiten ohne Zeitzone gelten als Ortszeit, nicht als UTC', async () => {
	const uri = 'floating-1.ics'
	await davAnlegen(uri, einTermin('floating-1', 'DTSTART:20310105T140000\r\nDTEND:20310105T160000\r\nSUMMARY:Ohne Zeitzone\r\n'))
	try {
		const daten = (await termin(admin, planungskalender.id, uri)).daten
		assert.equal(daten.beginn, '2031-01-05T14:00:00+01:00')
		assert.equal(daten.ende, '2031-01-05T16:00:00+01:00')
		const liste = await json(await admin.get(`${API}/termine`, { params: { kalender: planungskalender.id, von: '2031-01-01', bis: '2031-01-31' } }))
		assert.equal(liste.termine.find((t) => t.titel === 'Ohne Zeitzone')?.beginn, '2031-01-05T14:00:00+01:00')
	} finally {
		await davLoeschen(uri)
	}
})

test('22 Suche nur in sichtbaren Feldern: Linktext, Adresse, UID und ISO-Datum finden nichts', async () => {
	// Q-KAL-06, U-KAL-11. Nach dem Speichern steht der Link „Planung öffnen: …“ in der Beschreibung.
	assert.equal((await speichern(admin, planungskalender.id, { zustaendig: 'Jörg' })).status, 200)
	assert.match(await rohesIcs(), /Planung öffnen: /)
	const { beginn } = (await termin(admin, planungskalender.id, 'workshop-1.ics')).daten
	const [jahr, monat, tag] = beginn.slice(0, 10).split('-')
	const treffer = async (suche) => {
		const liste = await json(await admin.get(`${API}/termine`, { params: { kalender: planungskalender.id, suche } }))
		return liste.termine.filter((t) => t.objekt === 'workshop-1.ics').length
	}
	for (const suche of ['LÖTWORKSHOP', 'loetworkshop', 'joerg', 'vereinsheim', `${tag}.${monat}.${jahr}`]) {
		assert.equal(await treffer(suche), 1, `„${suche}“ sollte den Termin finden`)
	}
	for (const suche of ['Planung öffnen', 'calendar_planning', 'localhost', 'workshop-1', `${jahr}-${monat}-${tag}`, 'Erste Zeile der Beschreibung']) {
		assert.equal(await treffer(suche), 0, `„${suche}“ darf den Termin nicht finden`)
	}
	await speichern(admin, planungskalender.id, {})
})

test('23 Sortierung nach Zeitpunkt, nicht nach dem Text mit verschiedenen UTC-Versätzen', async () => {
	// Q-KAL-04: 13:30 UTC liegt nach 14:00 Berliner Zeit (13:00 UTC), als Text „T13:30+00:00“ < „T14:00+01:00“.
	const termine = {
		'sort-utc.ics': einTermin('sort-utc', 'DTSTART:20310106T133000Z\r\nDTEND:20310106T140000Z\r\nSUMMARY:Sortierung UTC\r\n'),
		'sort-berlin.ics': einTermin('sort-berlin', 'DTSTART;TZID=Europe/Berlin:20310106T140000\r\nDTEND;TZID=Europe/Berlin:20310106T150000\r\nSUMMARY:Sortierung Berlin\r\n'),
		'sort-ny.ics': einTermin('sort-ny', 'DTSTART;TZID=America/New_York:20310106T075000\r\nDTEND;TZID=America/New_York:20310106T085000\r\nSUMMARY:Sortierung New York\r\n'),
		'sort-ganztags.ics': einTermin('sort-ganztags', 'DTSTART;VALUE=DATE:20310106\r\nDTEND;VALUE=DATE:20310107\r\nSUMMARY:Sortierung ganztags\r\n'),
	}
	for (const [uri, ics] of Object.entries(termine)) {
		await davAnlegen(uri, ics)
	}
	try {
		const liste = await json(await admin.get(`${API}/termine`, { params: { kalender: planungskalender.id, von: '2031-01-06', bis: '2031-01-06', suche: 'Sortierung' } }))
		assert.deepEqual(liste.termine.map((t) => t.objekt), ['sort-ganztags.ics', 'sort-ny.ics', 'sort-berlin.ics', 'sort-utc.ics'])
	} finally {
		for (const uri of Object.keys(termine)) {
			await davLoeschen(uri)
		}
	}
})

test('24 Systemkalender wie „Contact birthdays“ stehen nicht in der Auswahl und liefern keine Liste', async () => {
	// U-KAL-21. Den Geburtstagskalender legt Nextcloud selbst an; fehlt er noch, hier einen mit derselben URI anlegen.
	const geburtstage = `${BASIS}/remote.php/dav/calendars/${NUTZER}/contact_birthdays/`
	const da = await fetch(geburtstage, { method: 'PROPFIND', headers: { Authorization: basic(NUTZER, PASSWORT), Depth: '0' } })
	if (da.status === 404) {
		const neu = await fetch(geburtstage, { method: 'MKCALENDAR', headers: { Authorization: basic(NUTZER, PASSWORT) } })
		assert.equal(neu.status, 201)
	} else {
		assert.equal(da.status, 207)
	}
	// Die ID des Geburtstagskalenders steht nicht in CalDAV (oc:id gibt es nur für Dateien), also aus der Datenbank des
	// Containers. Ohne ID scheitert der Test, damit die 404-Prüfung unten nie stillschweigend entfällt.
	assert.ok(mitDocker, `Test 24 braucht Docker-Zugriff auf den Container ${CONTAINER} (NC_NAME)`)
	const php = `require 'lib/base.php';
$q = \\OCP\\Server::get(\\OCP\\IDBConnection::class)->getQueryBuilder();
$q->select('id')->from('calendars')->where($q->expr()->eq('principaluri', $q->createNamedParameter('principals/users/' . $argv[1])))
	->andWhere($q->expr()->eq('uri', $q->createNamedParameter('contact_birthdays')));
echo (string)$q->executeQuery()->fetchOne();`
	const id = imContainer('php', '-r', php, '--', NUTZER).trim()
	assert.match(id, /^\d+$/, `ID des Geburtstagskalenders nicht gefunden: „${id}“`)
	const kalender = (await json(await admin.get(`${API}/kalender`))).kalender
	assert.ok(kalender.length >= 2)
	assert.equal(kalender.some((k) => String(k.id) === id), false, `Geburtstagskalender ${id} in der Auswahl: ${JSON.stringify(kalender)}`)
	assert.equal(kalender.some((k) => /birthday|geburtstag/i.test(k.name)), false, JSON.stringify(kalender))
	// Die App kennt ihn nicht: Liste, Auswahl und ein Termin darin antworten mit 404.
	assert.equal((await admin.get(`${API}/termine`, { params: { kalender: id } })).status(), 404)
	assert.equal((await admin.get(`${API}/termin`, { params: { kalender: id, termin: 'x.ics' } })).status(), 404)
	assert.equal((await admin.put(`${API}/kalender/auswahl`, { data: { kalender: Number(id) } })).status(), 404)
})

test('25 Link in der Beschreibung führt auch die Freigabe (eva) zum richtigen Termin, nur zum Lesen', async () => {
	// U-KAL-21: Die Adresse enthält die Kalender-ID, die bei geteilten Kalendern für alle Konten gleich ist.
	assert.equal((await speichern(admin, planungskalender.id, {})).status, 200)
	const zeile = (await rohesIcs()).replace(/\r\n[ \t]/g, '').match(/Planung öffnen: (\S+)/)?.[1]
	assert.ok(zeile, 'Link fehlt in der Beschreibung')
	const link = new URL(zeile.replace(/\\[,;]/g, (z) => z[1]))
	const evasKalender = (await json(await eva.get(`${API}/kalender`))).kalender
	assert.ok(evasKalender.some((k) => String(k.id) === link.searchParams.get('kalender')), 'Kalender aus dem Link fehlt in Evas Auswahl')
	const { status, daten } = await termin(eva, link.searchParams.get('kalender'), link.searchParams.get('termin'))
	assert.equal(status, 200)
	assert.equal(daten.titel, 'Lötworkshop Grundschule')
	assert.equal(daten.schreibbar, false)
})
