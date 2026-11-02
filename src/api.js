import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (pfad) => generateUrl('/apps/calendar_planning/api' + pfad)

export const MELDUNG_NETZ = 'Verbindung zur Nextcloud fehlgeschlagen. Bitte die Netzwerkverbindung prüfen und es erneut versuchen.'
export const MELDUNG_ZU_VIELE = 'Zu viele Anfragen in kurzer Zeit. Bitte kurz warten und es dann erneut versuchen.'
export const MELDUNG_PROGRAMM = 'Unerwarteter Fehler in der App. Bitte die Seite neu laden; bleibt es dabei, wende dich an die Administration.'
export const MELDUNG_SITZUNG = 'Deine Anmeldung bei der Nextcloud ist abgelaufen. Melde dich in einem neuen Tab neu an und versuche es '
	+ 'dann hier erneut – deine Eingaben bleiben bis dahin hier stehen.'

/**
 * Fehler einer Anfrage an die App: `status` (HTTP-Status, 0 ohne Antwort) und `message` (Text für Nutzer).
 * `daten` ist der Inhalt der Antwort, falls es JSON war (z. B. für den Konflikt 409).
 */
export class ApiFehler extends Error {
	constructor(status, message, daten = null) {
		super(message)
		this.name = 'ApiFehler'
		this.status = status
		this.daten = daten
	}
}

/** Verständliche Meldung je Fall, wenn die App selbst keinen Text (`fehler`) geliefert hat (U-KAL-06). */
function meldungFuer(status, daten) {
	// 401: nicht mehr angemeldet; 412: CSRF-Token passt nicht mehr (Nextcloud versucht es vorher einmal neu).
	if (status === 401 || status === 412) {
		return MELDUNG_SITZUNG
	}
	if (typeof daten?.fehler === 'string' && daten.fehler !== '') {
		return daten.fehler
	}
	if (status === 0) {
		return MELDUNG_NETZ
	}
	if (status === 429) {
		return MELDUNG_ZU_VIELE
	}
	if (status === 403) {
		return 'Dafür fehlt deinem Konto die Berechtigung. Lade die Seite neu; bleibt es dabei, wende dich an die Administration.'
	}
	if (status === 503) {
		return 'Die Nextcloud ist gerade nicht erreichbar (Wartung). Bitte später erneut versuchen.'
	}
	if (status >= 500) {
		return `Die Nextcloud hat einen Fehler gemeldet (Status ${status}). Bitte später erneut versuchen; bleibt es dabei, wende dich an die Administration.`
	}
	return `Unerwartete Antwort der Nextcloud (Status ${status}). Bitte die Seite neu laden.`
}

// Alle Anfragen der App erwarten JSON. Nach den Interceptors von @nextcloud/axios (CSRF-Token neu holen, Wartung)
// wird jeder Fehler zu einem ApiFehler; Abbrüche (AbortController) bleiben, wie sie sind.
axios.interceptors.response.use((antwort) => {
	// Abgelaufene Sitzung: Nextcloud leitet auf die Anmeldeseite um, die Antwort ist dann HTML statt JSON.
	const ziel = antwort.request?.responseURL ?? ''
	if (typeof antwort.data !== 'object' || antwort.data === null || /\/login(\?|$)/.test(ziel)) {
		throw new ApiFehler(401, MELDUNG_SITZUNG)
	}
	return antwort
}, (fehler) => {
	if (axios.isCancel(fehler) || fehler instanceof ApiFehler) {
		throw fehler
	}
	const status = fehler?.response?.status ?? 0
	const daten = typeof fehler?.response?.data === 'object' ? fehler.response.data : null
	throw new ApiFehler(status, meldungFuer(status, daten), daten)
})

/**
 * Text für Nutzer zu einem Fehler; die eine Stelle für Meldungen in der App. Fehler aus den Anfragen sind ApiFehler;
 * alles andere ohne Antwort ist ein Programmfehler (z. B. TypeError) und keine Netzstörung.
 */
export function fehlertext(fehler) {
	if (fehler instanceof ApiFehler) {
		return fehler.message
	}
	if (fehler?.response) {
		return meldungFuer(fehler.response.status, fehler.response.data)
	}
	if (fehler?.isAxiosError || axios.isCancel(fehler)) {
		return MELDUNG_NETZ
	}
	return MELDUNG_PROGRAMM
}

/** Einstellungen für die Oberfläche: Standardort und Feldschema. */
export async function einstellungenLaden() {
	return (await axios.get(url('/einstellungen'))).data
}

export async function kalenderLaden() {
	return (await axios.get(url('/kalender'))).data.kalender
}

export async function kalenderWaehlen(kalender) {
	await axios.put(url('/kalender/auswahl'), { kalender })
}

export async function termineLaden(parameter) {
	return (await axios.get(url('/termine'), { params: parameter })).data
}

/** Vorgeladener Termin; gilt nur für das erste `terminLaden` und höchstens VORLADEN_GUELTIG Millisekunden. */
let vorgeladen = null
const VORLADEN_GUELTIG = 10000

export async function terminLaden(kalender, termin) {
	const vorrat = vorgeladen
	// Beim ersten Laden verbraucht oder verworfen: Ein anderer Termin oder ein später geöffneter bekommt frische Daten.
	vorgeladen = null
	if (vorrat && vorrat.kalender === kalender && vorrat.termin === termin && Date.now() - vorrat.zeit < VORLADEN_GUELTIG) {
		return vorrat.antwort
	}
	return (await axios.get(url('/termin'), { params: { kalender, termin } })).data
}

/**
 * Startet das Laden eines Termins schon beim Start der App, parallel zu Kalendern und Einstellungen (P-KAL-05).
 * Das Formular bekommt beim ersten `terminLaden` mit denselben Werten diese Anfrage statt einer neuen.
 */
export function terminVorladen(kalender, termin) {
	const antwort = axios.get(url('/termin'), { params: { kalender, termin } }).then((a) => a.data)
	// Wird der Termin nie abgeholt (z. B. unbekannter Kalender), soll kein unbehandelter Fehler entstehen.
	antwort.catch(() => {})
	vorgeladen = { kalender, termin, antwort, zeit: Date.now() }
}

export async function terminSpeichern(kalender, termin, etag, planung) {
	return (await axios.put(url('/termin'), { kalender, termin, etag, planung })).data
}

// Nur für Admins (Einstellungsseite).

/** {schema, vorgabe, presets: [{id, name, schema}]} */
export async function schemaLaden() {
	return (await axios.get(url('/schema'))).data
}

export async function schemaSpeichern(schema) {
	return (await axios.put(url('/schema'), { schema })).data
}

/** {ort_vorgabe, kalender_muster, link_text} */
export async function adminEinstellungenLaden() {
	return (await axios.get(url('/admin/einstellungen'))).data
}

export async function adminEinstellungenSpeichern(einstellungen) {
	return (await axios.put(url('/admin/einstellungen'), einstellungen)).data
}
