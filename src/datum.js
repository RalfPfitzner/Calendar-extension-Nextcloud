const ZONE = 'Europe/Berlin'

const tag = new Intl.DateTimeFormat('de-DE', { timeZone: ZONE, weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
const zeit = new Intl.DateTimeFormat('de-DE', { timeZone: ZONE, hour: '2-digit', minute: '2-digit' })

/** „Do., 05.11.2026, 14:00–17:00“ bzw. bei ganztägigen Terminen nur das Datum. */
export function zeitraumText(termin) {
	if (!termin.beginn) {
		return ''
	}
	const beginn = new Date(termin.beginn)
	if (termin.ganztags) {
		// Ganztägige Termine haben kein Zeitzonen-Ende; das Datum steht als UTC-Mitternacht.
		return new Intl.DateTimeFormat('de-DE', { timeZone: 'UTC', weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' }).format(beginn)
	}
	const ende = termin.ende ? new Date(termin.ende) : null
	let text = `${tag.format(beginn)}, ${zeit.format(beginn)}`
	if (ende) {
		text += tag.format(ende) === tag.format(beginn) ? `–${zeit.format(ende)}` : ` bis ${tag.format(ende)}, ${zeit.format(ende)}`
	}
	return text
}

/** Datum als JJJJ-MM-TT in deutscher Zeit (für Datumsfelder). */
export function isoTag(datum) {
	return new Intl.DateTimeFormat('sv-SE', { timeZone: ZONE }).format(datum)
}
