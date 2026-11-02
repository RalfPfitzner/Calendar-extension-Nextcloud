<template>
	<div class="admin">
		<h2>Kalender-Planung</h2>
		<p class="admin__hinweis">
			Vorgaben und Felder des Planungsformulars. Die Werte stehen in den Kalenderterminen als Eigenschaften
			<code>X-CALPLAN-&lt;SCHLÜSSEL&gt;</code>; ein geänderter Schlüssel trennt das Feld von bereits erfassten Werten.
		</p>

		<NcNoteCard v-if="ladeFehler" type="error">
			{{ ladeFehler }}
		</NcNoteCard>
		<NcLoadingIcon v-else-if="!einstellungen || !schema" :size="44" />
		<template v-else>
			<form class="admin__block" @submit.prevent="einstellungenSichern">
				<h3>Vorgaben</h3>
				<label class="admin__feld">
					<span>Standardort</span>
					<input v-model="einstellungen.ort_vorgabe" type="text" maxlength="500" placeholder="leer: freies Ortsfeld">
					<small>Mit Standardort wählt das Formular „Standardort“ oder „Externer Ort“ mit Adresse.</small>
				</label>
				<label class="admin__feld">
					<span>Kalender-Vorbelegung (Namensfragment)</span>
					<input v-model="einstellungen.kalender_muster" type="text" maxlength="100" placeholder="leer: erster Kalender">
					<small>Der erste Kalender, dessen Name dieses Fragment enthält, ist für neue Konten vorgewählt.</small>
				</label>
				<label class="admin__feld">
					<span>Text vor dem Link in der Terminbeschreibung</span>
					<input v-model="einstellungen.link_text" type="text" maxlength="100" placeholder="Planung öffnen:">
				</label>
				<div aria-live="polite">
					<NcNoteCard v-if="einstellungenGeaendert" type="warning">
						Ungespeicherte Änderungen an den Vorgaben.
					</NcNoteCard>
				</div>
				<NcNoteCard v-if="einstellungenMeldung" :type="einstellungenMeldung.typ">
					{{ einstellungenMeldung.text }}
				</NcNoteCard>
				<div class="admin__aktionen">
					<NcButton type="submit" variant="primary" :disabled="speichert">
						Vorgaben speichern
					</NcButton>
				</div>
			</form>

			<form class="admin__block" @submit.prevent="schemaSichern">
				<h3>Felder des Planungsformulars</h3>
				<p class="admin__hinweis">
					Titel und Ort stehen immer im Formular. Höchstens 50 Felder, höchstens 3 davon als Spalte in der Liste.
					Schlüssel: a–z, 0–9 und _, beginnend mit einem Buchstaben. Der Schlüssel eines neuen Feldes folgt der
					Bezeichnung; nach dem Speichern bleibt er fest, damit erfasste Werte am Feld bleiben.
				</p>
				<SchemaEditor v-model="schema" />
				<div aria-live="polite">
					<NcNoteCard v-if="schemaGeaendert" type="warning">
						Ungespeicherte Änderungen an den Feldern. Erst „Felder speichern“ übernimmt sie.
					</NcNoteCard>
				</div>
				<NcNoteCard v-if="schemaMeldung" :type="schemaMeldung.typ">
					{{ schemaMeldung.text }}
				</NcNoteCard>
				<div class="admin__aktionen">
					<NcButton type="submit" variant="primary" :disabled="speichert">
						Felder speichern
					</NcButton>
					<NcButton :disabled="speichert" @click="laden(vorgabe, 'Vorgabe')">
						Vorgabe laden
					</NcButton>
					<NcButton v-for="preset in presets" :key="preset.id" :disabled="speichert" @click="laden(preset.schema, preset.name)">
						Vorlage laden: {{ preset.name }}
					</NcButton>
				</div>
			</form>
		</template>
	</div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import SchemaEditor from './components/SchemaEditor.vue'
import { adminEinstellungenLaden, adminEinstellungenSpeichern, fehlertext, schemaLaden, schemaSpeichern } from './api.js'

const einstellungen = ref(null)
const schema = ref(null)
const vorgabe = ref(null)
const presets = ref([])
const ladeFehler = ref('')
const einstellungenMeldung = ref(null)
const schemaMeldung = ref(null)
const speichert = ref(false)

/** Zuletzt geladener oder gespeicherter Stand, zum Erkennen ungespeicherter Änderungen (U-KAL-10). */
const schemaGespeichert = ref(null)
const einstellungenGespeichert = ref('')

const kopie = (wert) => JSON.parse(JSON.stringify(wert))

/** Schema ohne die Hilfsangaben des Editors (`_neu`, `_manuell`), so wie der Server es speichert. */
function ohneHilfsfelder(wert) {
	return {
		...wert,
		abschnitte: wert.abschnitte.map((abschnitt) => ({
			...abschnitt,
			felder: abschnitt.felder.map(({ _neu, _manuell, ...feld }) => feld),
		})),
	}
}

const schemaGeaendert = computed(() => schema.value !== null && schemaGespeichert.value !== null
	&& JSON.stringify(ohneHilfsfelder(schema.value)) !== JSON.stringify(schemaGespeichert.value))
const einstellungenGeaendert = computed(() => einstellungen.value !== null
	&& JSON.stringify(einstellungen.value) !== einstellungenGespeichert.value)

/** Rückfrage des Browsers beim Verlassen oder Neuladen der Seite mit ungespeicherten Änderungen. */
function vorDemVerlassen(ereignis) {
	if (schemaGeaendert.value || einstellungenGeaendert.value) {
		ereignis.preventDefault()
		ereignis.returnValue = ''
	}
}

onMounted(async () => {
	window.addEventListener('beforeunload', vorDemVerlassen)
	try {
		const [e, s] = await Promise.all([adminEinstellungenLaden(), schemaLaden()])
		einstellungen.value = e
		einstellungenGespeichert.value = JSON.stringify(e)
		schema.value = s.schema
		schemaGespeichert.value = kopie(s.schema)
		vorgabe.value = s.vorgabe
		presets.value = s.presets
	} catch (fehler) {
		ladeFehler.value = fehlertext(fehler)
	}
})

onBeforeUnmount(() => window.removeEventListener('beforeunload', vorDemVerlassen))

async function einstellungenSichern() {
	speichert.value = true
	einstellungenMeldung.value = null
	try {
		einstellungen.value = await adminEinstellungenSpeichern(einstellungen.value)
		einstellungenGespeichert.value = JSON.stringify(einstellungen.value)
		einstellungenMeldung.value = { typ: 'success', text: 'Vorgaben gespeichert.' }
	} catch (fehler) {
		einstellungenMeldung.value = { typ: 'error', text: fehlertext(fehler) }
	} finally {
		speichert.value = false
	}
}

const felderVon = (wert) => wert.abschnitte.flatMap((abschnitt) => abschnitt.felder)
const feldname = (feld) => `„${feld.label}“ (${feld.key})`

async function schemaSichern() {
	// Fallen gespeicherte Schlüssel weg, hängen erfasste Werte an keinem Feld mehr (Q-KAL-01): vorher nachfragen.
	const bleibt = new Set(felderVon(schema.value).map((feld) => feld.key))
	const weg = felderVon(schemaGespeichert.value).filter((feld) => !bleibt.has(feld.key))
	if (weg.length > 0 && !window.confirm(`Diese Felder sind nach dem Speichern nicht mehr im Formular: ${weg.map(feldname).join(', ')}. `
		+ 'Bereits in Terminen erfasste Werte bleiben dort gespeichert, erscheinen aber erst wieder, wenn ein Feld mit demselben Schlüssel angelegt wird. Trotzdem speichern?')) {
		return
	}
	speichert.value = true
	schemaMeldung.value = null
	try {
		const antwort = await schemaSpeichern(ohneHilfsfelder(schema.value))
		schema.value = antwort.schema
		schemaGespeichert.value = kopie(antwort.schema)
		const entfernt = antwort.entfernt ?? []
		schemaMeldung.value = {
			typ: 'success',
			text: 'Felder gespeichert. Das Formular zeigt sie beim nächsten Laden.'
				+ (entfernt.length > 0 ? ` Nicht mehr im Formular: ${entfernt.map(feldname).join(', ')}; erfasste Werte bleiben im Termin.` : ''),
		}
	} catch (fehler) {
		schemaMeldung.value = { typ: 'error', text: fehlertext(fehler) }
	} finally {
		speichert.value = false
	}
}

function laden(quelle, name) {
	if (!window.confirm(`„${name}“ in den Editor laden? Die aktuellen Felder im Editor werden ersetzt; gespeichert wird erst mit „Felder speichern“.`)) {
		return
	}
	schema.value = kopie(quelle)
	schemaMeldung.value = { typ: 'info', text: `„${name}“ geladen, noch nicht gespeichert.` }
}
</script>

<style scoped lang="scss">
.admin {
	max-width: 1000px;

	h3 {
		margin: 16px 0 8px;
		font-weight: bold;
	}

	&__hinweis {
		color: var(--color-text-maxcontrast);
		margin: 0 0 12px;
	}

	&__block {
		margin-bottom: 32px;
	}

	&__feld {
		display: flex;
		flex-direction: column;
		margin-bottom: 12px;
		max-width: 600px;

		> span {
			font-weight: bold;
			margin-bottom: 4px;
		}

		input {
			width: 100%;
			margin: 0;
		}

		small {
			color: var(--color-text-maxcontrast);
		}
	}

	&__aktionen {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 12px;
	}
}
</style>
