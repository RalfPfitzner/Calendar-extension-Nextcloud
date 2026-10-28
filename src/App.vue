<template>
	<NcContent app-name="calendar_planning">
		<NcAppContent>
			<main class="planung">
				<NcNoteCard v-if="fehler" type="error">
					{{ fehler }}
				</NcNoteCard>
				<NcLoadingIcon v-else-if="kalender === null || einstellungen === null" :size="44" />
				<NcEmptyContent v-else-if="kalender.length === 0"
					name="Kein Kalender gefunden"
					description="Für dein Konto gibt es keinen Kalender. Lege einen in der Kalender-App an oder lass dir den Planungskalender freigeben." />
				<PlanungFormular v-else-if="zustand.termin"
					:key="zustand.kalender + '/' + zustand.termin"
					:kalender="Number(zustand.kalender)"
					:termin="zustand.termin"
					:einstellungen="einstellungen"
					@zurueck="zurListe" />
				<TerminListe v-else
					:kalender-liste="kalender"
					:zustand="zustand"
					:einstellungen="einstellungen"
					@aendern="aendern"
					@oeffnen="oeffnen" />
			</main>
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PlanungFormular from './components/PlanungFormular.vue'
import TerminListe from './components/TerminListe.vue'
import { einstellungenLaden, fehlertext, kalenderLaden, kalenderWaehlen } from './api.js'
import { adresseLesen, adresseSchreiben } from './adresse.js'

const kalender = ref(null)
const einstellungen = ref(null)
const fehler = ref('')
const zustand = reactive(adresseLesen())

function zurueckTaste() {
	Object.assign(zustand, adresseLesen())
}

onMounted(async () => {
	window.addEventListener('popstate', zurueckTaste)
	try {
		[kalender.value, einstellungen.value] = await Promise.all([kalenderLaden(), einstellungenLaden()])
	} catch (e) {
		fehler.value = fehlertext(e)
		return
	}
	if (!zustand.kalender || !kalender.value.some((k) => String(k.id) === zustand.kalender)) {
		const gewaehlt = kalender.value.find((k) => k.gewaehlt)
		zustand.kalender = gewaehlt ? String(gewaehlt.id) : ''
		adresseSchreiben(zustand, false)
	}
})

onBeforeUnmount(() => window.removeEventListener('popstate', zurueckTaste))

/** Änderung an Filter, Seite oder Kalender aus der Liste. */
function aendern(neu) {
	if (neu.kalender && neu.kalender !== zustand.kalender) {
		// Auswahl merken; schlägt das fehl, bleibt nur die Vorbelegung beim nächsten Mal falsch.
		kalenderWaehlen(Number(neu.kalender)).catch(() => {})
	}
	Object.assign(zustand, neu)
	adresseSchreiben(zustand, true)
}

function oeffnen(termin) {
	zustand.termin = termin
	adresseSchreiben(zustand, true)
	window.scrollTo(0, 0)
}

function zurListe() {
	zustand.termin = ''
	adresseSchreiben(zustand, true)
}
</script>

<style lang="scss">
.planung {
	padding: 8px 16px 24px 56px;
	max-width: 1200px;
}

@media (max-width: 1024px) {
	.planung {
		padding-left: 16px;
		padding-top: 56px;
	}
}
</style>
