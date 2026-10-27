// Hilfen für das Feldschema, das der Server unter GET /api/einstellungen liefert
// (Abschnitte mit Feldern {key, label, typ, pflicht, maxlen, optionen, inListe}; geprüft in lib/Service/Schema.php).

/** Feldtypen mit Anzeigename für den Schema-Editor; die Liste muss zu Schema::TYPEN passen. */
export const TYPEN = [
	{ wert: 'text', name: 'Text (eine Zeile)' },
	{ wert: 'textarea', name: 'Text (mehrzeilig)' },
	{ wert: 'email', name: 'E-Mail-Adresse' },
	{ wert: 'tel', name: 'Telefonnummer' },
	{ wert: 'number', name: 'Zahl' },
	{ wert: 'select', name: 'Auswahl' },
	{ wert: 'checkbox', name: 'Ja/Nein' },
	{ wert: 'date', name: 'Datum' },
]

export const MAX_LAENGE = 5000

/** Alle Felder des Schemas in Reihenfolge. */
export function alleFelder(schema) {
	return (schema?.abschnitte ?? []).flatMap((a) => a.felder)
}

/** Namen aller Formularwerte: feste Felder plus Schema-Felder. */
export function feldnamen(schema) {
	return ['titel', 'ort_extern', 'ort', ...alleFelder(schema).map((f) => f.key)]
}

/** Felder, die als Spalten in der Liste stehen (der Server begrenzt auf drei). */
export function listenFelder(schema) {
	return alleFelder(schema).filter((f) => f.inListe).slice(0, 3)
}

/** Wert eines Feldes für die Anzeige in der Liste. */
export function anzeigeWert(feld, wert) {
	if (feld.typ === 'checkbox') {
		return wert ? 'ja' : ''
	}
	return wert ?? ''
}

/** Leerer Wert eines Feldes im Formular. */
export function leererWert(feld) {
	return feld.typ === 'checkbox' ? false : ''
}

/** Schlüssel-Vorschlag aus einer Bezeichnung: a–z, 0–9 und _, beginnend mit einem Buchstaben, höchstens 31 Zeichen. */
export function schluesselAus(label) {
	const umlaute = { ä: 'ae', ö: 'oe', ü: 'ue', ß: 'ss' }
	let key = String(label).toLowerCase().replace(/[äöüß]/g, (z) => umlaute[z])
		.normalize('NFD').replace(/[̀-ͯ]/g, '')
		.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
	if (!/^[a-z]/.test(key)) {
		key = 'feld_' + key
	}
	return key.replace(/_+$/, '').slice(0, 31).replace(/_+$/, '')
}
