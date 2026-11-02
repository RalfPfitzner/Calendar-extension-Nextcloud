<template>
	<section class="formular">
		<div class="formular__kopf">
			<NcButton variant="tertiary" @click="zurueck">
				‹ Zur Liste
			</NcButton>
		</div>

		<NcNoteCard v-if="ladeFehler" type="error">
			{{ ladeFehler }}
		</NcNoteCard>
		<NcLoadingIcon v-else-if="!termin" :size="44" />
		<template v-else>
			<h2>Planung: {{ termin.titel || '(ohne Titel)' }}</h2>
			<p class="formular__zeit">
				{{ zeitraumText(termin) }}
				<span v-if="termin.serie"> · Terminserie: die Planung gilt für alle Termine der Serie</span>
			</p>
			<p class="formular__zeit">
				Datum und Uhrzeit änderst du in der Kalender-App.
			</p>

			<!-- Nur lesen (U-KAL-09): Werte als Beschreibungsliste in voller Schriftfarbe statt gesperrter Felder. -->
			<div v-if="!termin.schreibbar" class="formular__inhalt">
				<NcNoteCard type="info">
					Diesen Kalender darfst du nur lesen.
				</NcNoteCard>
				<template v-for="gruppe in leseGruppen" :key="gruppe.titel">
					<h3 :id="'lesen-' + gruppe.nummer">
						{{ gruppe.titel }}
					</h3>
					<dl class="formular__lesen" tabindex="0" :aria-labelledby="'lesen-' + gruppe.nummer">
						<div v-for="eintrag in gruppe.eintraege" :key="eintrag.label" class="formular__eintrag">
							<dt>{{ eintrag.label }}</dt>
							<dd :class="{ 'formular__leer': eintrag.leer }">
								{{ eintrag.wert }}
							</dd>
						</div>
					</dl>
				</template>
			</div>

			<form v-else
				class="formular__inhalt"
				novalidate
				@submit.prevent="speichern">
				<h3>Termin</h3>
				<div class="formular__feld">
					<label for="feld-titel">Titel der Veranstaltung *</label>
					<NcTextField id="feld-titel"
						v-model="werte.titel"
						label-outside
						required
						:maxlength="MAX_LAENGE"
						v-bind="fehlerAttribute('titel')" />
				</div>

				<!-- Ort (U-KAL-01): fieldset mit legend, Knöpfe direkt bei ihrer Beschriftung, Pfeiltasten wechseln. -->
				<template v-if="ortVorgabe">
					<NcRadioGroup v-model="ortWahl" class="formular__feld formular__gruppe" label="Ort">
						<NcCheckboxRadioSwitch value="vorgabe" name="ort-wahl">
							{{ ortVorgabe }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch value="extern" name="ort-wahl">
							Externer Ort
						</NcCheckboxRadioSwitch>
					</NcRadioGroup>
					<div v-if="werte.ort_extern" class="formular__feld">
						<label for="feld-ort">Externer Ort – Adresse *</label>
						<NcTextArea id="feld-ort"
							v-model="werte.ort"
							label-outside
							rows="2"
							resize="vertical"
							required
							:maxlength="MAX_LAENGE"
							v-bind="fehlerAttribute('ort')" />
					</div>
				</template>
				<div v-else class="formular__feld">
					<label for="feld-ort">Ort</label>
					<NcTextArea id="feld-ort"
						v-model="werte.ort"
						label-outside
						rows="2"
						resize="vertical"
						:maxlength="MAX_LAENGE"
						v-bind="fehlerAttribute('ort')" />
				</div>

				<template v-for="abschnitt in schema.abschnitte" :key="abschnitt.titel">
					<h3>{{ abschnitt.titel }}</h3>
					<template v-for="feld in abschnitt.felder" :key="feld.key">
						<div v-if="feld.typ === 'checkbox'" class="formular__feld">
							<NcCheckboxRadioSwitch :id="'feld-' + feld.key"
								v-model="werte[feld.key]"
								:required="feld.pflicht"
								v-bind="fehlerAttribute(feld.key)">
								{{ feld.label }}{{ feld.pflicht ? ' *' : '' }}
							</NcCheckboxRadioSwitch>
						</div>
						<div v-else class="formular__feld">
							<label :for="'feld-' + feld.key">{{ feld.label }}{{ feld.pflicht ? ' *' : '' }}</label>
							<NcTextArea v-if="feld.typ === 'textarea'"
								:id="'feld-' + feld.key"
								v-model="werte[feld.key]"
								label-outside
								rows="3"
								resize="vertical"
								:required="feld.pflicht"
								:maxlength="feld.maxlen"
								v-bind="fehlerAttribute(feld.key)" />
							<NcSelect v-else-if="feld.typ === 'select'"
								:input-id="'feld-' + feld.key"
								label-outside
								:model-value="auswahlWert(feld)"
								:options="auswahlOptionen(feld)"
								:required="feld.pflicht"
								:clearable="!feld.pflicht"
								:aria-label-clear-selected="'Auswahl leeren: ' + feld.label"
								:error="fehlerFeld === feld.key"
								placeholder="nicht gewählt"
								@update:model-value="(option) => { werte[feld.key] = option?.id ?? '' }" />
							<NcTextField v-else
								:id="'feld-' + feld.key"
								v-model="werte[feld.key]"
								label-outside
								:type="feld.typ === 'number' ? 'text' : feld.typ"
								:inputmode="feld.typ === 'number' ? 'decimal' : undefined"
								:required="feld.pflicht"
								:maxlength="feld.maxlen"
								v-bind="fehlerAttribute(feld.key)" />
						</div>
					</template>
				</template>

				<div class="formular__aktionen">
					<!-- Dauerhafte Live-Regionen (U-KAL-03): leer im DOM, damit Ansagen sicher ankommen. -->
					<p id="formular-erfolg"
						ref="erfolgElement"
						class="formular__meldung formular__meldung--erfolg"
						role="status"
						aria-live="polite"
						tabindex="-1">
						{{ meldung }}
					</p>
					<p id="formular-fehler"
						ref="fehlerElement"
						class="formular__meldung formular__meldung--fehler"
						role="alert"
						tabindex="-1">
						{{ fehler }}
					</p>
					<!-- Konflikt (U-KAL-05): Eingaben bleiben, zwei Wege mit Rückfrage. -->
					<div v-if="konflikt" class="formular__konflikt">
						<template v-if="konflikt.geladen">
							<p v-if="konflikt.unterschiede.length">
								Im neuen Stand anders:
							</p>
							<p v-else>
								Die Planungsfelder sind im neuen Stand unverändert.
							</p>
							<ul v-if="konflikt.unterschiede.length">
								<li v-for="unterschied in konflikt.unterschiede" :key="unterschied.label">
									{{ unterschied.label }}: {{ unterschied.wert }}
								</li>
							</ul>
						</template>
						<div class="formular__knoepfe">
							<NcButton variant="primary" :disabled="speichert" @click="eingabenBehalten">
								Meine Eingaben behalten und erneut speichern
							</NcButton>
							<NcButton :disabled="speichert" @click="neuenStandLaden">
								Neuen Stand laden
							</NcButton>
						</div>
					</div>
					<div class="formular__knoepfe">
						<NcButton type="submit"
							variant="primary"
							:disabled="!geaendert && !speichert"
							:aria-busy="speichert ? 'true' : 'false'">
							{{ speichert ? 'Speichert …' : 'Speichern' }}
						</NcButton>
						<NcButton :disabled="speichert || !geaendert" @click="zuruecksetzen">
							Änderungen verwerfen
						</NcButton>
					</div>
				</div>
			</form>
		</template>
		<Rueckfrage ref="rueckfrage" />
	</section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Rueckfrage from './Rueckfrage.vue'
import { MAX_LAENGE, alleFelder, feldnamen, leererWert } from '../felder.js'
import { terminLaden, terminSpeichern } from '../api.js'
import { zeitraumText } from '../datum.js'

const props = defineProps({
	kalender: { type: Number, required: true },
	termin: { type: String, required: true },
	/** Einstellungen der App (GET /api/einstellungen): Standardort und Feldschema. */
	einstellungen: { type: Object, required: true },
})
/** `geaendert` (true/false): ob ungespeicherte Eingaben vorliegen, damit App.vue bei der Zurück-Taste fragen kann. */
const emit = defineEmits(['zurueck', 'geaendert'])

const ALT = ' (nicht mehr in der Liste)'

const ortVorgabe = computed(() => props.einstellungen.ort_vorgabe ?? '')
const schema = computed(() => props.einstellungen.schema ?? { abschnitte: [] })
const namen = computed(() => feldnamen(schema.value))
/** Zuletzt geladener oder gespeicherter Stand des Termins; Grundlage für „geändert“ und den ETag. */
const termin = ref(null)
const werte = reactive({})
const ladeFehler = ref('')
const fehler = ref('')
/** Schlüssel des Feldes, auf das sich der letzte Fehler bezieht (aria-invalid, Fokus). */
const fehlerFeld = ref('')
const meldung = ref('')
const speichert = ref(false)
/** Bei 409: {geladen, unterschiede: [{label, wert}]} */
const konflikt = ref(null)
const erfolgElement = ref(null)
const fehlerElement = ref(null)
const rueckfrage = ref(null)

/** Formularwerte zu einem Stand des Termins; beim Standardort bleibt das Adressfeld leer. */
function formularWerte(daten) {
	const ergebnis = {}
	for (const feld of alleFelder(schema.value)) {
		ergebnis[feld.key] = daten[feld.key] ?? leererWert(feld)
	}
	ergebnis.titel = daten.titel
	ergebnis.ort_extern = daten.ort_extern
	ergebnis.ort = ortVorgabe.value && !daten.ort_extern ? '' : daten.ort
	return ergebnis
}

function uebernehmen(daten) {
	termin.value = daten
	Object.assign(werte, formularWerte(daten))
}

/** Ob zwei Formularstände in einem Wert übereinstimmen. Beim Standardort zählt die Adresse nur bei externem Ort. */
function gleich(a, b, name) {
	if (name === 'ort' && ortVorgabe.value && !a.ort_extern && !b.ort_extern) {
		return true
	}
	return a[name] === b[name]
}

const basis = computed(() => termin.value === null ? null : formularWerte(termin.value))
const geaendert = computed(() => basis.value !== null && namen.value.some((name) => !gleich(werte, basis.value, name)))

watch(geaendert, (ja) => emit('geaendert', ja), { immediate: true })
// Die Erfolgsmeldung gilt nur für den gespeicherten Stand; die nächste Änderung räumt sie weg (Q-KAL-11).
watch(werte, () => {
	if (geaendert.value) {
		meldung.value = ''
	}
}, { deep: true })

const ortWahl = computed({
	get: () => (werte.ort_extern ? 'extern' : 'vorgabe'),
	set: (wahl) => { werte.ort_extern = wahl === 'extern' },
})

/** Bezeichnung zu einem Formularwert, für Fehler und Konflikte. */
function bezeichnung(name) {
	if (name === 'titel') {
		return 'Titel der Veranstaltung'
	}
	if (name === 'ort' || name === 'ort_extern') {
		return 'Ort'
	}
	return alleFelder(schema.value).find((f) => f.key === name)?.label ?? name
}

/**
 * Optionen eines Auswahlfeldes. Ein gespeicherter Wert, den der Admin aus der Liste genommen hat, bleibt als eigene
 * Option erhalten und lässt sich unverändert speichern (Q-KAL-09).
 */
function auswahlOptionen(feld) {
	const optionen = feld.optionen.map((option) => ({ id: option, label: option }))
	const alt = termin.value?.[feld.key]
	if (typeof alt === 'string' && alt !== '' && !feld.optionen.includes(alt)) {
		optionen.unshift({ id: alt, label: alt + ALT })
	}
	return optionen
}

function auswahlWert(feld) {
	return auswahlOptionen(feld).find((option) => option.id === werte[feld.key]) ?? null
}

/** Anzeige eines Wertes in der Nur-Lesen-Ansicht. */
function anzeige(feld, wert) {
	if (feld.typ === 'checkbox') {
		return wert ? 'ja' : 'nein'
	}
	if (feld.typ === 'select' && wert && !feld.optionen.includes(wert)) {
		return wert + ALT
	}
	return wert ?? ''
}

const leseGruppen = computed(() => {
	if (termin.value === null) {
		return []
	}
	const eintrag = (label, wert) => ({ label, wert: wert === '' ? 'keine Angabe' : wert, leer: wert === '' })
	return [
		{ nummer: 0, titel: 'Termin', eintraege: [eintrag('Titel der Veranstaltung', termin.value.titel ?? ''), eintrag('Ort', termin.value.ort ?? '')] },
		...schema.value.abschnitte.map((abschnitt, i) => ({
			nummer: i + 1,
			titel: abschnitt.titel,
			eintraege: abschnitt.felder.map((feld) => eintrag(feld.label, anzeige(feld, termin.value[feld.key]))),
		})),
	]
})

/** aria-invalid und Verweis auf die Fehlermeldung für das Feld, auf das sich der Fehler bezieht. */
function fehlerAttribute(name) {
	return fehlerFeld.value === name
		? { error: true, 'aria-invalid': 'true', 'aria-describedby': 'formular-fehler' }
		: {}
}

/**
 * Meldung zu einem Fehler aus api.js: `ApiFehler` (status, message, daten) bringt eine verständliche `message` mit;
 * ältere Axios-Fehler den Text des Servers in `response.data.fehler`; sonst eine allgemeine Meldung.
 */
function fehlerMeldung(e) {
	if (typeof e?.status === 'number' && !e?.isAxiosError && e?.message) {
		return e.message
	}
	const vomServer = e?.response?.data?.fehler
	if (typeof vomServer === 'string' && vomServer !== '') {
		return vomServer
	}
	return 'Verbindung zur Nextcloud fehlgeschlagen. Bitte später erneut versuchen.'
}

function fehlerStatus(e) {
	return e?.status ?? e?.response?.status
}

/** Feld, das die Meldung des Servers nennt („Bezeichnung“ …), sonst ''. */
function feldAusMeldung(text) {
	if (text.includes('Titel der Veranstaltung')) {
		return 'titel'
	}
	if (text.includes('externen Ort') || text.includes('„Ort“')) {
		return 'ort'
	}
	return alleFelder(schema.value).find((f) => text.includes('„' + f.label + '“'))?.key ?? ''
}

/** Fokus auf ein Element, sobald es gezeichnet ist. */
async function fokus(element) {
	await nextTick()
	element?.focus?.()
}

// App.vue warnt ebenfalls beim Verlassen; doppelt schadet nicht, und das Formular bleibt für sich sicher.
function warnen(ereignis) {
	if (geaendert.value) {
		ereignis.preventDefault()
	}
}

onMounted(async () => {
	window.addEventListener('beforeunload', warnen)
	try {
		uebernehmen(await terminLaden(props.kalender, props.termin))
	} catch (e) {
		ladeFehler.value = fehlerMeldung(e)
	}
})
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnen))

/** Nachfragen bei ungespeicherten Eingaben übernimmt App.vue (Ereignis `geaendert`), damit es nur eine Rückfrage gibt. */
function zurueck() {
	emit('zurueck')
}

function zuruecksetzen() {
	uebernehmen(termin.value)
	fehler.value = ''
	fehlerFeld.value = ''
}

function eingaben() {
	const planung = Object.fromEntries(namen.value.map((name) => [name, werte[name]]))
	if (!ortVorgabe.value) {
		// Ohne Standardort entscheidet allein das Ortsfeld; der Server prüft das ebenso.
		planung.ort_extern = planung.ort.trim() !== ''
	}
	return planung
}

async function speichern() {
	if (speichert.value) {
		return
	}
	speichert.value = true
	fehler.value = ''
	fehlerFeld.value = ''
	meldung.value = ''
	const planung = eingaben()
	const gesendet = JSON.stringify(planung)
	try {
		const gespeichert = await terminSpeichern(props.kalender, props.termin, termin.value.etag, planung)
		konflikt.value = null
		if (JSON.stringify(eingaben()) === gesendet) {
			uebernehmen(gespeichert)
		} else {
			// Während des Speicherns weitergetippt: die neuen Eingaben bleiben, sie gelten als ungespeichert.
			termin.value = gespeichert
		}
		// Erst nach dem Übernehmen der neuen Daten melden, sonst räumt die Änderung der Werte die Meldung weg.
		meldung.value = 'Gespeichert. Die Planung steht jetzt im Kalendertermin.'
		speichert.value = false
		// Der Knopf ist jetzt gesperrt (nichts mehr zu speichern); der Fokus geht auf die Meldung statt auf BODY.
		await fokus(erfolgElement.value)
	} catch (e) {
		speichert.value = false
		if (fehlerStatus(e) === 409) {
			await konfliktZeigen()
			await fokus(fehlerElement.value)
			return
		}
		fehler.value = fehlerMeldung(e)
		fehlerFeld.value = fehlerStatus(e) === 400 ? feldAusMeldung(fehler.value) : ''
		await fokus(fehlerFeld.value ? document.getElementById('feld-' + fehlerFeld.value) : fehlerElement.value)
	}
}

/** Felder, in denen sich zwei Stände unterscheiden, mit dem Wert aus `neu` für die Anzeige. */
function unterschiede(alt, neu) {
	const ergebnis = new Map()
	for (const name of namen.value) {
		if (!gleich(alt, neu, name)) {
			const label = bezeichnung(name)
			let wert = neu[name]
			if (name === 'ort' || name === 'ort_extern') {
				wert = neu.ort_extern ? neu.ort : ortVorgabe.value
			}
			const feld = alleFelder(schema.value).find((f) => f.key === name)
			wert = feld ? anzeige(feld, wert) : String(wert ?? '')
			wert = wert === '' ? 'leer' : '„' + (wert.length > 80 ? wert.slice(0, 80) + ' …' : wert) + '“'
			ergebnis.set(label, { label, wert })
		}
	}
	return [...ergebnis.values()]
}

/** 409: Eingaben bleiben stehen; neuen Stand laden und zeigen, was sich dort geändert hat. */
async function konfliktZeigen() {
	fehler.value = 'Der Termin wurde inzwischen geändert oder gerade von jemand anderem gespeichert. '
		+ 'Deine Eingaben sind noch da. Du kannst sie über den neuen Stand speichern oder den neuen Stand laden.'
	konflikt.value = { geladen: false, unterschiede: [] }
	try {
		const neu = await terminLaden(props.kalender, props.termin)
		konflikt.value = { geladen: true, unterschiede: unterschiede(basis.value, formularWerte(neu)) }
	} catch {
		// Ohne Vergleich bleiben beide Wege; sie laden den Stand selbst noch einmal.
	}
}

async function neuenStandLaden() {
	const ja = await rueckfrage.value.fragen({
		titel: 'Neuen Stand laden?',
		text: 'Deine Eingaben in diesem Formular gehen dabei verloren.',
		ja: 'Neuen Stand laden',
		nein: 'Abbrechen',
	})
	if (!ja) {
		return
	}
	try {
		uebernehmen(await terminLaden(props.kalender, props.termin))
		konflikt.value = null
		fehler.value = ''
		fehlerFeld.value = ''
		meldung.value = 'Der neue Stand ist geladen.'
		await fokus(erfolgElement.value)
	} catch (e) {
		fehler.value = fehlerMeldung(e)
		await fokus(fehlerElement.value)
	}
}

/**
 * Eigene Eingaben über den neuen Stand speichern: Felder, die du nicht geändert hast, bekommen den neuen Wert; in
 * Feldern, die du geändert hast, gilt deine Eingabe.
 */
async function eingabenBehalten() {
	let neu
	try {
		neu = await terminLaden(props.kalender, props.termin)
	} catch (e) {
		fehler.value = fehlerMeldung(e)
		await fokus(fehlerElement.value)
		return
	}
	const neueWerte = formularWerte(neu)
	const alt = basis.value
	const eigene = namen.value.filter((name) => !gleich(werte, alt, name))
	const ueberschrieben = unterschiede(alt, neueWerte)
		.filter((u) => eigene.some((name) => bezeichnung(name) === u.label && !gleich(werte, neueWerte, name)))
		.map((u) => '„' + u.label + '“')
	const ja = await rueckfrage.value.fragen({
		titel: 'Eigene Eingaben speichern?',
		text: (ueberschrieben.length
			? 'Deine Eingaben ersetzen die neuen Werte in ' + ueberschrieben.join(', ') + '. '
			: '')
			+ 'Änderungen der anderen Person in Feldern, die du nicht bearbeitet hast, bleiben erhalten.',
		ja: 'Meine Eingaben speichern',
		nein: 'Abbrechen',
	})
	if (!ja) {
		return
	}
	// Erst bestimmen, was unverändert ist, dann übernehmen; Ort und „extern“ gehören zusammen.
	const unveraendert = namen.value.filter((name) => {
		const zusammen = name === 'ort' || name === 'ort_extern' ? ['ort', 'ort_extern'] : [name]
		return zusammen.every((n) => gleich(werte, alt, n))
	})
	for (const name of unveraendert) {
		werte[name] = neueWerte[name]
	}
	termin.value = neu
	await speichern()
}
</script>

<style scoped lang="scss">
.formular {
	max-width: 800px;

	&__kopf {
		margin: 8px 0;
	}

	h2 {
		margin: 8px 0 4px;
	}

	h3 {
		margin: 24px 0 8px;
		padding-bottom: 4px;
		border-bottom: 1px solid var(--color-border);
		font-weight: bold;
	}

	&__zeit {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}

	&__feld {
		display: flex;
		flex-direction: column;
		margin-bottom: 12px;

		> label {
			font-weight: bold;
			margin-bottom: 4px;
		}
	}

	&__gruppe {
		margin-inline: 0;
		padding-inline: 0;

		:deep(legend) {
			padding-inline: 0;
			font-weight: bold;
		}
	}

	&__lesen {
		margin: 0;
		border-radius: var(--border-radius-element, 8px);

		&:focus-visible {
			outline: 2px solid var(--color-main-text);
			outline-offset: 2px;
		}
	}

	&__eintrag {
		display: grid;
		grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
		gap: 4px 16px;
		padding: 6px 0;
		border-bottom: 1px solid var(--color-border);

		// Nextcloud setzt dt/dd global auf feste Breite, nowrap und rechtsbündig; hier zurücksetzen.
		dt, dd {
			display: block;
			width: auto;
			margin: 0;
			padding: 0;
			white-space: normal;
			text-align: start;
		}

		dt {
			font-weight: bold;
		}

		dd {
			white-space: pre-wrap;
			overflow-wrap: anywhere;
			color: var(--color-main-text);
		}

		@media (max-width: 600px) {
			grid-template-columns: minmax(0, 1fr);
		}
	}

	dd.formular__leer {
		color: var(--color-text-maxcontrast);
		font-style: italic;
	}

	&__aktionen {
		position: sticky;
		bottom: 0;
		padding: 8px 0 12px;
		background-color: var(--color-main-background);
		border-top: 1px solid var(--color-border);
	}

	&__knoepfe {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 4px;
	}

	&__meldung {
		margin: 0;
		padding: 6px 12px;
		border-radius: var(--border-radius-element, 8px);

		&:empty {
			padding: 0;
		}

		&--erfolg {
			color: var(--color-main-text);
			background-color: var(--color-success-light, var(--color-background-dark));
			border-inline-start: 4px solid var(--color-success);
		}

		&--fehler {
			color: var(--color-main-text);
			background-color: var(--color-error-light, var(--color-background-dark));
			border-inline-start: 4px solid var(--color-error);
		}

		&:empty {
			border: none;
		}
	}

	&__konflikt {
		margin-top: 8px;

		p, ul {
			margin: 4px 0;
		}

		ul {
			padding-inline-start: 20px;
			list-style: disc;
		}

		li {
			overflow-wrap: anywhere;
		}
	}
}
</style>
