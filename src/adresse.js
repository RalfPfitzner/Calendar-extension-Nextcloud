// Zustand der Oberfläche steht in der Adresse, damit Neuladen, Lesezeichen und Zurück-Taste funktionieren.

const SCHLUESSEL = ['kalender', 'termin', 'von', 'bis', 'suche', 'seite', 'pro_seite']

export function adresseLesen() {
	const parameter = new URLSearchParams(window.location.search)
	const zustand = {}
	for (const schluessel of SCHLUESSEL) {
		zustand[schluessel] = parameter.get(schluessel) ?? ''
	}
	zustand.seite = Number.parseInt(zustand.seite, 10) || 1
	zustand.pro_seite = [25, 50, 100].includes(Number(zustand.pro_seite)) ? Number(zustand.pro_seite) : 25
	return zustand
}

export function adresseSchreiben(zustand, neuerEintrag) {
	const parameter = new URLSearchParams()
	for (const schluessel of SCHLUESSEL) {
		const wert = zustand[schluessel]
		// Vorgaben weglassen, damit die Adresse kurz bleibt.
		if (wert === '' || wert === null || wert === undefined
			|| (schluessel === 'seite' && wert === 1) || (schluessel === 'pro_seite' && wert === 25)) {
			continue
		}
		parameter.set(schluessel, String(wert))
	}
	const text = parameter.toString()
	const ziel = window.location.pathname + (text ? '?' + text : '')
	if (neuerEintrag) {
		window.history.pushState({ [INDEX]: eintragIndex() + 1 }, '', ziel)
	} else {
		window.history.replaceState({ [INDEX]: eintragIndex() }, '', ziel)
	}
}

/**
 * Jeder Eintrag der App im Verlauf trägt seine Position (`calplanIndex` in `history.state`), damit die App bei
 * popstate weiß, wie weit und in welche Richtung (Zurück oder Vor) der Browser gesprungen ist (U-KAL-04).
 */
const INDEX = 'calplanIndex'

/** Position des Eintrags, auf dem der Browser gerade steht; 0, wenn er keine trägt (erster Aufruf). */
export function eintragIndex(zustand = window.history.state) {
	const index = zustand?.[INDEX]
	return Number.isInteger(index) ? index : 0
}

/** Ob ein Verlaufseintrag (`history.state`) von der App stammt. */
export function hatIndex(zustand) {
	return Number.isInteger(zustand?.[INDEX])
}
