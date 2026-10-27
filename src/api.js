import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (pfad) => generateUrl('/apps/calendar_planning/api' + pfad)

/** Fehlertext aus der Antwort der App oder eine allgemeine Meldung. */
export function fehlertext(fehler) {
	return fehler?.response?.data?.fehler ?? 'Verbindung zur Nextcloud fehlgeschlagen'
}

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
