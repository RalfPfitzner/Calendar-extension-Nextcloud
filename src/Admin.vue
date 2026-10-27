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
					Schlüssel: a–z, 0–9 und _, beginnend mit einem Buchstaben.
				</p>
				<SchemaEditor v-model="schema" />
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
import { onMounted, ref } from 'vue'
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

const kopie = (wert) => JSON.parse(JSON.stringify(wert))

onMounted(async () => {
	try {
		const [e, s] = await Promise.all([adminEinstellungenLaden(), schemaLaden()])
		einstellungen.value = e
		schema.value = s.schema
		vorgabe.value = s.vorgabe
		presets.value = s.presets
	} catch (fehler) {
		ladeFehler.value = fehlertext(fehler)
	}
})

async function einstellungenSichern() {
	speichert.value = true
	einstellungenMeldung.value = null
	try {
		einstellungen.value = await adminEinstellungenSpeichern(einstellungen.value)
		einstellungenMeldung.value = { typ: 'success', text: 'Vorgaben gespeichert.' }
	} catch (fehler) {
		einstellungenMeldung.value = { typ: 'error', text: fehlertext(fehler) }
	} finally {
		speichert.value = false
	}
}

async function schemaSichern() {
	speichert.value = true
	schemaMeldung.value = null
	try {
		schema.value = (await schemaSpeichern(schema.value)).schema
		schemaMeldung.value = { typ: 'success', text: 'Felder gespeichert. Das Formular zeigt sie beim nächsten Laden.' }
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
