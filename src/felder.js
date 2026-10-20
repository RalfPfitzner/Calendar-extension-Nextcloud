// Aufbau des Formulars nach der Word-Vorlage „Vorlage blanko Planung alle Veranstaltungen“.
// Die Feldnamen müssen zu Planung::FELDER in lib/Service/Planung.php passen.

export const ORT_VORGABE = 'MakerSpace Bendorf'

/** Abschnitte mit ihren Feldern; Ort und Titel stehen gesondert im Formular. */
export const ABSCHNITTE = [
	{
		titel: '1. Grunddaten',
		felder: [
			{ name: 'zustaendig', label: 'Zuständig intern' },
			{ name: 'zufahrt', label: 'Parkmöglichkeiten / Zufahrt / Anlieferung', mehrzeilig: true },
			{ name: 'raum', label: 'Raum-Informationen', mehrzeilig: true },
		],
	},
	{
		titel: '2. Kontaktdaten',
		felder: [
			{ name: 'kontakt_organisation', label: 'Organisation / Firma' },
			{ name: 'kontakt_name', label: 'Name' },
			{ name: 'kontakt_telefon', label: 'Telefon', typ: 'tel' },
			{ name: 'kontakt_email', label: 'E-Mail', typ: 'email' },
			{ name: 'kontakt_rolle', label: 'Rolle / Funktion' },
		],
	},
	{
		titel: '3. Teilnehmende Gruppe',
		felder: [
			{ name: 'zielgruppe', label: 'Zielgruppe' },
			{ name: 'teilnehmende', label: 'Anzahl der Teilnehmenden' },
			{ name: 'anforderungen', label: 'Besondere Anforderungen (z. B. Barrierefreiheit, Sprache, Aufsichtspflicht)', mehrzeilig: true },
		],
	},
	{
		titel: '4. Inhalte & Werkzeuge',
		felder: [
			{ name: 'inhalte', label: 'Geplante Inhalte / Themenschwerpunkte', mehrzeilig: true },
			{ name: 'maschinen', label: 'Benötigte Maschinen / Tools', mehrzeilig: true },
			{ name: 'material', label: 'Benötigtes Material (geschätzt)', mehrzeilig: true },
			{ name: 'betreuung', label: 'Benötigte weitere Betreuung / Unterstützung', mehrzeilig: true },
		],
	},
	{
		titel: '5. Hinweise',
		felder: [
			{ name: 'hinweise', label: 'Besondere Wünsche / Hinweise / Anmerkungen', mehrzeilig: true },
		],
	},
]

export const FELDNAMEN = ['titel', 'ort_extern', 'ort', ...ABSCHNITTE.flatMap((a) => a.felder.map((f) => f.name))]
