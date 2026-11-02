<template>
	<NcContent app-name="calendar_planning">
		<!-- NcAppContent ist schon die main-Landmarke; innen deshalb nur ein div (U-KAL-13). -->
		<NcAppContent>
			<div ref="bereich" class="planung" tabindex="-1">
				<!-- Dauerhaft im DOM, damit Bildschirmleser den Wechsel der Ansicht ansagen (U-KAL-08). -->
				<p class="hidden-visually" role="status" aria-live="polite">
					{{ ansage }}
				</p>
				<NcNoteCard v-if="fehler" type="error">
					{{ fehler }}
				</NcNoteCard>
				<NcLoadingIcon v-else-if="kalender === null || einstellungen === null" :size="44" />
				<NcEmptyContent v-else-if="kalender.length === 0"
					name="Kein Kalender gefunden"
					description="Für dein Konto gibt es keinen Kalender. Lege einen in der Kalender-App an oder lass dir den Planungskalender freigeben." />
				<template v-else>
					<NcNoteCard v-if="hinweis" type="warning">
						{{ hinweis }}
					</NcNoteCard>
					<PlanungFormular v-if="zustand.termin"
						:key="zustand.kalender + '/' + zustand.termin"
						:kalender="Number(zustand.kalender)"
						:termin="zustand.termin"
						:einstellungen="einstellungen"
						@geaendert="ungespeichert = $event"
						@zurueck="zurListe" />
					<TerminListe v-else
						:kalender-liste="kalender"
						:zustand="zustand"
						:einstellungen="einstellungen"
						@aendern="aendern"
						@oeffnen="oeffnen" />
				</template>
			</div>
			<Rueckfrage v-if="rueckfrageBereit" ref="rueckfrage" />
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import TerminListe from './components/TerminListe.vue'
import { einstellungenLaden, fehlertext, kalenderLaden, kalenderWaehlen, terminVorladen } from './api.js'
import { adresseLesen, adresseSchreiben } from './adresse.js'

// Formular und Rückfrage (NcDialog) braucht die Liste nicht; sie kommen erst bei Bedarf (P-KAL-05).
const formularLaden = () => import('./components/PlanungFormular.vue')
const rueckfrageLaden = () => import('./components/Rueckfrage.vue')
const PlanungFormular = defineAsyncComponent(formularLaden)
const Rueckfrage = defineAsyncComponent(rueckfrageLaden)

const kalender = ref(null)
const einstellungen = ref(null)
const fehler = ref('')
const hinweis = ref('')
const ansage = ref('')
const zustand = reactive(adresseLesen())
/** Das Formular meldet ungespeicherte Eingaben mit `geaendert` (U-KAL-04). */
const ungespeichert = ref(false)
const rueckfrageBereit = ref(false)
const rueckfrage = ref(null)
const bereich = ref(null)

// Ein anderes Formular (oder die Liste) beginnt ohne ungespeicherte Eingaben.
watch(() => zustand.termin, () => {
	ungespeichert.value = false
})
// Die Rückfrage schon laden, sobald es etwas zu verlieren gibt, damit sie ohne Verzögerung erscheint.
watch(ungespeichert, (wert) => {
	if (wert) {
		rueckfrageBereit.value = true
	}
})

/**
 * Fragt, ob ungespeicherte Eingaben verworfen werden dürfen. Ohne Änderungen sofort true.
 *
 * @return {Promise<boolean>}
 */
async function verwerfenErlaubt() {
	if (!ungespeichert.value) {
		return true
	}
	rueckfrageBereit.value = true
	await rueckfrageLaden()
	for (let versuch = 0; versuch < 50 && !rueckfrage.value; versuch++) {
		await new Promise((weiter) => setTimeout(weiter, 20))
	}
	if (!rueckfrage.value) {
		// Notlösung, falls der Dialog nicht lädt: Eingaben nie still verwerfen.
		return window.confirm('Die Änderungen sind noch nicht gespeichert. Trotzdem verwerfen?')
	}
	return rueckfrage.value.fragen({
		titel: 'Änderungen verwerfen?',
		text: 'Die Änderungen sind noch nicht gespeichert.',
		ja: 'Änderungen verwerfen',
		nein: 'Weiter bearbeiten',
	})
}

/**
 * Nach einem Wechsel zwischen Liste und Formular: Fokus auf die Überschrift der neuen Ansicht und Ansage (U-KAL-08).
 * Das Formular zeigt seine Überschrift erst nach dem Laden; bis dahin steht der Fokus auf dem Bereich, nie auf BODY.
 */
let beobachter = null
async function ansichtGewechselt() {
	beobachter?.disconnect()
	await nextTick()
	const wurzel = bereich.value
	if (!wurzel) {
		return
	}
	wurzel.focus({ preventScroll: true })
	const fokussieren = () => {
		const ueberschrift = wurzel.querySelector('h2')
		if (!ueberschrift) {
			return false
		}
		// Hat jemand inzwischen selbst etwas angeklickt, den Fokus dort lassen.
		if (document.activeElement === wurzel || document.activeElement === document.body) {
			ueberschrift.setAttribute('tabindex', '-1')
			ueberschrift.focus()
		}
		ansage.value = ''
		nextTick(() => {
			ansage.value = 'Angezeigt: ' + ueberschrift.textContent.trim()
		})
		return true
	}
	if (fokussieren()) {
		return
	}
	beobachter = new MutationObserver(() => {
		if (fokussieren()) {
			beobachter.disconnect()
			beobachter = null
		}
	})
	beobachter.observe(wurzel, { childList: true, subtree: true })
	// Erscheint keine Überschrift (z. B. Ladefehler), nicht ewig beobachten.
	setTimeout(() => {
		beobachter?.disconnect()
		beobachter = null
	}, 10000)
}

async function zurueckTaste() {
	const neu = adresseLesen()
	const formularWeg = zustand.termin !== '' && (neu.termin !== zustand.termin || neu.kalender !== zustand.kalender)
	if (formularWeg && ungespeichert.value) {
		// Den Browser zurück auf das Formular stellen, bis entschieden ist (U-KAL-04).
		const ziel = window.location.pathname + window.location.search
		adresseSchreiben(zustand, true)
		if (!await verwerfenErlaubt()) {
			return
		}
		window.history.replaceState(null, '', ziel)
		ungespeichert.value = false
	}
	const wechsel = neu.termin !== zustand.termin
	Object.assign(zustand, neu)
	if (wechsel) {
		ansichtGewechselt()
	}
}

/** Warnung des Browsers beim Schließen oder Neuladen mit ungespeicherten Eingaben. */
function warnen(ereignis) {
	if (ungespeichert.value) {
		ereignis.preventDefault()
		ereignis.returnValue = ''
	}
}

onMounted(async () => {
	window.addEventListener('popstate', zurueckTaste)
	window.addEventListener('beforeunload', warnen)
	// Steht ein Termin in der Adresse, laden Formular-Code und Termin parallel zu Kalendern und Einstellungen.
	if (zustand.termin && zustand.kalender) {
		formularLaden().catch(() => {})
		terminVorladen(Number(zustand.kalender), zustand.termin)
	}
	try {
		[kalender.value, einstellungen.value] = await Promise.all([kalenderLaden(), einstellungenLaden()])
	} catch (e) {
		fehler.value = fehlertext(e)
		return
	}
	if (!zustand.kalender || !kalender.value.some((k) => String(k.id) === zustand.kalender)) {
		const gewaehlt = kalender.value.find((k) => k.gewaehlt)
		if (zustand.kalender && kalender.value.length > 0) {
			// Zwischenstand mit einem Kalender, den es nicht (mehr) gibt: sagen statt still tauschen (Q-KAL-12).
			hinweis.value = 'Der Kalender aus der Adresse ist nicht (mehr) verfügbar. '
				+ (gewaehlt ? `Gezeigt wird „${gewaehlt.name}“.` : 'Bitte einen Kalender wählen.')
			// Ein Termin aus dem unbekannten Kalender lässt sich nicht öffnen; Seite und Termin gehören zum alten Kalender.
			zustand.termin = ''
			zustand.seite = 1
		}
		zustand.kalender = gewaehlt ? String(gewaehlt.id) : ''
		adresseSchreiben(zustand, false)
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('popstate', zurueckTaste)
	window.removeEventListener('beforeunload', warnen)
	beobachter?.disconnect()
})

/** Änderung an Filter, Seite oder Kalender aus der Liste. */
function aendern(neu) {
	if (neu.kalender && neu.kalender !== zustand.kalender) {
		// Auswahl merken; schlägt das fehl, bleibt nur die Vorbelegung beim nächsten Mal falsch.
		kalenderWaehlen(Number(neu.kalender)).catch(() => {})
		hinweis.value = ''
	}
	Object.assign(zustand, neu)
	adresseSchreiben(zustand, true)
}

function oeffnen(termin) {
	hinweis.value = ''
	zustand.termin = termin
	adresseSchreiben(zustand, true)
	window.scrollTo(0, 0)
	ansichtGewechselt()
}

async function zurListe() {
	if (!await verwerfenErlaubt()) {
		return
	}
	ungespeichert.value = false
	zustand.termin = ''
	adresseSchreiben(zustand, true)
	ansichtGewechselt()
}
</script>

<style lang="scss">
.planung {
	padding: 8px 16px 24px 56px;
	max-width: 1200px;

	&:focus {
		outline: none;
	}
}

@media (max-width: 1024px) {
	.planung {
		padding-left: 16px;
		padding-top: 56px;
	}
}
</style>
