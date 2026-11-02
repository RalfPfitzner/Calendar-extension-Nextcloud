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
import { adresseLesen, adresseSchreiben, eintragIndex } from './adresse.js'

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
		// Lädt der Dialog nicht, bleiben die Eingaben: nie still verwerfen.
		return false
	}
	return rueckfrage.value.fragen({
		titel: 'Änderungen verwerfen?',
		text: 'Die Änderungen sind noch nicht gespeichert.',
		ja: 'Änderungen verwerfen',
		nein: 'Weiter bearbeiten',
	})
}

/**
 * Nach einem Wechsel zwischen Liste und Formular: Fokus auf die Überschrift der neuen Ansicht (U-KAL-08). Bildschirmleser
 * lesen die fokussierte Überschrift vor; nur wenn der Fokus dort nicht hin darf (jemand hat inzwischen selbst etwas
 * angeklickt), sagt die Live-Region „Angezeigt: …“ an, sonst käme alles doppelt.
 * Das Formular zeigt seine Überschrift erst nach dem Laden; bis dahin steht der Fokus auf dem Bereich, nie auf BODY.
 */
let beobachter = null
async function ansichtGewechselt() {
	beobachter?.disconnect()
	beobachter = null
	ansage.value = ''
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
		if (document.activeElement === wurzel || document.activeElement === document.body) {
			ueberschrift.setAttribute('tabindex', '-1')
			ueberschrift.focus()
		}
		if (document.activeElement !== ueberschrift) {
			nextTick(() => {
				ansage.value = 'Angezeigt: ' + ueberschrift.textContent.trim()
			})
		}
		return true
	}
	if (fokussieren()) {
		return
	}
	// Jeder Aufruf hat seinen eigenen Beobachter; Zeitgrenze und Abschluss trennen nur diesen.
	const eigener = new MutationObserver(() => {
		if (fokussieren()) {
			eigener.disconnect()
			if (beobachter === eigener) {
				beobachter = null
			}
		}
	})
	beobachter = eigener
	eigener.observe(wurzel, { childList: true, subtree: true })
	// Erscheint keine Überschrift (z. B. Ladefehler), nicht ewig beobachten.
	setTimeout(() => {
		eigener.disconnect()
		if (beobachter === eigener) {
			beobachter = null
		}
	}, 10000)
}

/** Position des Verlaufseintrags, den die App gerade zeigt (siehe adresse.js). */
let index = eintragIndex()
/**
 * Läuft gerade ein Rücksprung auf den Formular-Eintrag (`history.go`), den die App selbst ausgelöst hat: dessen popstate
 * übergehen. `fertig` meldet, dass der Browser wieder auf dem Formular steht.
 */
let ruecksprung = null

/**
 * Zurück- und Vor-Taste. Mit ungespeicherten Eingaben springt der Browser erst auf den Formular-Eintrag zurück (in
 * der Gegenrichtung, so weit er gesprungen ist), dann fragt die App (U-KAL-04). „Weiter bearbeiten“ lässt den Verlauf
 * so, wie er war; „Änderungen verwerfen“ springt zum Ziel. Es entstehen keine neuen Einträge, Vorwärts-Einträge bleiben.
 */
async function verlaufGewechselt(ereignis) {
	const ziel = eintragIndex(ereignis.state)
	if (ruecksprung !== null) {
		if (ziel === ruecksprung.index) {
			const fertig = ruecksprung.fertig
			ruecksprung = null
			fertig()
			return
		}
		// Unerwarteter Sprung während des Rücksprungs (schnelles Mehrfachklicken): normal behandeln.
		ruecksprung = null
	}
	const neu = adresseLesen()
	const formularWeg = zustand.termin !== '' && (neu.termin !== zustand.termin || neu.kalender !== zustand.kalender)
	if (formularWeg && ungespeichert.value) {
		const schritte = ziel - index
		if (schritte === 0) {
			// Eintrag ohne Position (nicht von der App): Adresse ersetzen statt springen.
			const zielAdresse = window.location.pathname + window.location.search
			adresseNeu(false)
			if (await verwerfenErlaubt()) {
				ungespeichert.value = false
				window.history.replaceState(window.history.state, '', zielAdresse)
				Object.assign(zustand, neu)
				ansichtGewechselt()
			}
			return
		}
		const formular = index
		await new Promise((fertig) => {
			ruecksprung = { index: formular, fertig }
			window.history.go(-schritte)
			// Kommt das popstate nicht (sollte nicht passieren), nicht ewig warten.
			setTimeout(fertig, 2000)
		})
		if (!await verwerfenErlaubt()) {
			return
		}
		ungespeichert.value = false
		// Zum Ziel springen; das popstate dort läuft ohne ungespeicherte Eingaben durch diese Funktion.
		window.history.go(schritte)
		return
	}
	index = ziel
	const wechsel = neu.termin !== zustand.termin
	Object.assign(zustand, neu)
	if (wechsel) {
		ansichtGewechselt()
	}
}

/** Schreibt den Zustand in die Adresse (neuer Eintrag oder ersetzen) und merkt sich dessen Position. */
function adresseNeu(neuerEintrag) {
	adresseSchreiben(zustand, neuerEintrag)
	index = eintragIndex()
}

/** Warnung des Browsers beim Schließen oder Neuladen mit ungespeicherten Eingaben. */
function warnen(ereignis) {
	if (ungespeichert.value) {
		ereignis.preventDefault()
		ereignis.returnValue = ''
	}
}

onMounted(async () => {
	window.addEventListener('popstate', verlaufGewechselt)
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
		adresseNeu(false)
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('popstate', verlaufGewechselt)
	window.removeEventListener('beforeunload', warnen)
	beobachter?.disconnect()
})

/**
 * Änderung an Filter, Seite oder Kalender aus der Liste. Schritte des Nutzers bekommen einen neuen Verlaufseintrag;
 * `ersetzen` (die Liste gleicht die vom Server korrigierte Seite an) ersetzt den Eintrag, sonst hängt die Zurück-Taste
 * fest (Q-KAL-07).
 */
function aendern(neu, { ersetzen = false } = {}) {
	if (neu.kalender && neu.kalender !== zustand.kalender) {
		// Auswahl merken; schlägt das fehl, bleibt nur die Vorbelegung beim nächsten Mal falsch.
		kalenderWaehlen(Number(neu.kalender)).catch(() => {})
		hinweis.value = ''
	}
	Object.assign(zustand, neu)
	adresseNeu(!ersetzen)
}

function oeffnen(termin) {
	hinweis.value = ''
	zustand.termin = termin
	adresseNeu(true)
	window.scrollTo(0, 0)
	ansichtGewechselt()
}

async function zurListe() {
	if (!await verwerfenErlaubt()) {
		return
	}
	ungespeichert.value = false
	zustand.termin = ''
	adresseNeu(true)
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
