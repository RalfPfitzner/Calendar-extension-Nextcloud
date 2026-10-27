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
		<form v-else class="formular__inhalt" @submit.prevent="speichern">
			<h2>Planung: {{ termin.titel || '(ohne Titel)' }}</h2>
			<p class="formular__zeit">
				{{ zeitraumText(termin) }}
				<span v-if="termin.serie"> · Terminserie: die Planung gilt für alle Termine der Serie</span>
			</p>
			<p class="formular__zeit">
				Datum und Uhrzeit änderst du in der Kalender-App.
			</p>

			<NcNoteCard v-if="!termin.schreibbar" type="info">
				Diesen Kalender darfst du nur lesen.
			</NcNoteCard>
			<NcNoteCard v-if="meldung" type="success">
				{{ meldung }}
			</NcNoteCard>
			<NcNoteCard v-if="fehler" type="error">
				{{ fehler }}
			</NcNoteCard>

			<fieldset :disabled="!termin.schreibbar || speichert">
				<h3>1. Grunddaten</h3>
				<label class="formular__feld">
					<span>Titel der Veranstaltung</span>
					<input v-model="werte.titel" type="text" required maxlength="5000">
				</label>
				<label v-for="feld in ABSCHNITTE[0].felder.slice(0, 1)" :key="feld.name" class="formular__feld">
					<span>{{ feld.label }}</span>
					<input v-model="werte[feld.name]" type="text" maxlength="5000">
				</label>

				<template v-if="ortVorgabe">
					<div class="formular__feld" role="radiogroup" aria-label="Ort">
						<span>Ort</span>
						<label class="formular__wahl">
							<input v-model="werte.ort_extern" type="radio" :value="false">
							{{ ortVorgabe }}
						</label>
						<label class="formular__wahl">
							<input v-model="werte.ort_extern" type="radio" :value="true">
							Externer Ort
						</label>
					</div>
					<label v-if="werte.ort_extern" class="formular__feld">
						<span>Externer Ort – Adresse</span>
						<textarea v-model="werte.ort" rows="2" required maxlength="5000" />
					</label>
				</template>
				<label v-else class="formular__feld">
					<span>Ort</span>
					<textarea v-model="werte.ort" rows="2" maxlength="5000" />
				</label>

				<label v-for="feld in ABSCHNITTE[0].felder.slice(1)" :key="feld.name" class="formular__feld">
					<span>{{ feld.label }}</span>
					<textarea v-model="werte[feld.name]" rows="3" maxlength="5000" />
				</label>

				<template v-for="abschnitt in ABSCHNITTE.slice(1)" :key="abschnitt.titel">
					<h3>{{ abschnitt.titel }}</h3>
					<label v-for="feld in abschnitt.felder" :key="feld.name" class="formular__feld">
						<span>{{ feld.label }}</span>
						<textarea v-if="feld.mehrzeilig" v-model="werte[feld.name]" rows="3" maxlength="5000" />
						<input v-else v-model="werte[feld.name]" :type="feld.typ || 'text'" maxlength="5000">
					</label>
				</template>
			</fieldset>

			<div v-if="termin.schreibbar" class="formular__aktionen">
				<NcButton type="submit" variant="primary" :disabled="speichert || !geaendert">
					{{ speichert ? 'Speichert …' : 'Speichern' }}
				</NcButton>
				<NcButton :disabled="speichert || !geaendert" @click="zuruecksetzen">
					Änderungen verwerfen
				</NcButton>
			</div>
		</form>
	</section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ABSCHNITTE, FELDNAMEN } from '../felder.js'
import { fehlertext, terminLaden, terminSpeichern } from '../api.js'
import { zeitraumText } from '../datum.js'

const props = defineProps({
	kalender: { type: Number, required: true },
	termin: { type: String, required: true },
	/** Einstellungen der App (GET /api/einstellungen); ohne Standardort gibt es nur das freie Ortsfeld. */
	einstellungen: { type: Object, required: true },
})
const emit = defineEmits(['zurueck'])

const ortVorgabe = computed(() => props.einstellungen.ort_vorgabe ?? '')
const termin = ref(null)
const werte = reactive({})
const ladeFehler = ref('')
const fehler = ref('')
const meldung = ref('')
const speichert = ref(false)

function uebernehmen(daten) {
	termin.value = daten
	for (const name of FELDNAMEN) {
		werte[name] = daten[name]
	}
	// Beim Standardort bleibt das Adressfeld leer, damit der Umschalter auf extern sauber startet.
	if (ortVorgabe.value && !daten.ort_extern) {
		werte.ort = ''
	}
}

const geaendert = computed(() => termin.value !== null && FELDNAMEN.some((name) => {
	if (name === 'ort') {
		if (!ortVorgabe.value) {
			return werte.ort !== termin.value.ort
		}
		return werte.ort_extern && werte.ort !== (termin.value.ort_extern ? termin.value.ort : '')
	}
	return werte[name] !== termin.value[name]
}))

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
		ladeFehler.value = fehlertext(e)
	}
})
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnen))

function zurueck() {
	if (geaendert.value && !window.confirm('Die Änderungen sind noch nicht gespeichert. Trotzdem zur Liste?')) {
		return
	}
	emit('zurueck')
}

function zuruecksetzen() {
	uebernehmen(termin.value)
	fehler.value = ''
}

async function speichern() {
	speichert.value = true
	fehler.value = ''
	meldung.value = ''
	try {
		const planung = Object.fromEntries(FELDNAMEN.map((name) => [name, werte[name]]))
		if (!ortVorgabe.value) {
			// Ohne Standardort entscheidet allein das Ortsfeld; der Server prüft das ebenso.
			planung.ort_extern = planung.ort.trim() !== ''
		}
		const gespeichert = await terminSpeichern(props.kalender, props.termin, termin.value.etag, planung)
		uebernehmen(gespeichert)
		// Erst nach dem Übernehmen der neuen Daten melden, sonst überschreibt das Neuladen die Meldung.
		meldung.value = 'Gespeichert. Die Planung steht jetzt im Kalendertermin.'
	} catch (e) {
		fehler.value = fehlertext(e)
	} finally {
		speichert.value = false
	}
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

	fieldset {
		border: none;
		padding: 0;
		margin: 0;
	}

	&__feld {
		display: flex;
		flex-direction: column;
		margin-bottom: 12px;

		> span {
			font-weight: bold;
			margin-bottom: 4px;
		}

		input[type=text], input[type=tel], input[type=email], textarea {
			width: 100%;
			margin: 0;
		}

		textarea {
			resize: vertical;
		}
	}

	&__wahl {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 34px;

		input {
			margin: 0;
		}
	}

	&__aktionen {
		position: sticky;
		bottom: 0;
		display: flex;
		gap: 8px;
		padding: 12px 0;
		background-color: var(--color-main-background);
		border-top: 1px solid var(--color-border);
	}
}
</style>
