import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (pfad) => generateUrl('/apps/calendar_planning/api' + pfad)

/** Fehlertext aus der Antwort der App oder eine allgemeine Meldung. */
export function fehlertext(fehler) {
	return fehler?.response?.data?.fehler ?? 'Verbindung zur Nextcloud fehlgeschlagen'
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

export async function terminLaden(kalender, termin) {
	return (await axios.get(url('/termin'), { params: { kalender, termin } })).data
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
