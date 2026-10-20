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
		window.history.pushState(null, '', ziel)
	} else {
		window.history.replaceState(null, '', ziel)
	}
}
