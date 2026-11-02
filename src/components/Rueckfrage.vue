<template>
	<NcDialog v-if="frage"
		:key="frage.nummer"
		:open="offen"
		:name="frage.titel"
		:message="frage.text"
		:buttons="knoepfe"
		size="normal"
		@closing="zugehend"
		@update:open="offenGeaendert" />
</template>

<script setup>
import { computed, nextTick, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'

/**
 * Rückfrage als Dialog von Nextcloud statt `window.confirm()`: Die Knöpfe nennen die Aktion
 * („Trotzdem speichern“ / „Weiter bearbeiten“), der Dialog ist im Stil der Seite und per Tastatur bedienbar.
 * `fragen()` liefert true, wenn die Aktion bestätigt wurde. Danach steht der Fokus wieder auf dem Auslöser,
 * falls es ihn noch gibt; der Aufrufer darf ihn anschließend woanders hinsetzen.
 */
const frage = ref(null)
const offen = ref(false)
let ergebnis = false
let aufloesen = null
let ausloeser = null
let zaehler = 0
/**
 * NcDialog meldet `closing` sofort nach der Antwort, `update:open` erst rund 300 ms später, wenn er zu ist. Kommt in
 * dieser Zeit schon die nächste Frage (schnelles Klicken, Zurück-Taste), wartet sie auf `schliesst`; sonst schlösse das
 * späte `update:open` des alten Dialogs die neue Frage gleich wieder (rote Tests nur bei schnellem Ablauf).
 */
let schliesst = null
let geschlossen = null

const knoepfe = computed(() => frage.value === null
	? []
	: [
		{ label: frage.value.nein, callback: () => { ergebnis = false } },
		{ label: frage.value.ja, variant: 'primary', callback: () => { ergebnis = true } },
	])

/**
 * @param {{titel: string, text: string, ja: string, nein: string}} angaben
 * @return {Promise<boolean>}
 */
async function fragen(angaben) {
	const neuerAusloeser = document.activeElement
	while (schliesst) {
		await schliesst
	}
	if (aufloesen) {
		// Eine noch offene Frage endet als „nein“; ihr Dialog wird sofort ausgehängt, dann kann er nichts mehr melden.
		const alt = aufloesen
		aufloesen = null
		offen.value = false
		frage.value = null
		await nextTick()
		alt(false)
	}
	ausloeser = neuerAusloeser
	ergebnis = false
	frage.value = { ...angaben, nummer: ++zaehler }
	offen.value = true
	return new Promise((resolve) => {
		aufloesen = resolve
	})
}

/** Antwort gegeben (Knopf, Esc oder ✕); der Dialog geht jetzt zu. */
function zugehend() {
	if (!schliesst) {
		schliesst = new Promise((fertig) => {
			geschlossen = fertig
		})
	}
}

/** NcDialog meldet das Schließen erst, wenn der Dialog weg ist (auch bei Esc und ✕, dann gilt „nein“). */
async function offenGeaendert(wert) {
	if (wert) {
		return
	}
	offen.value = false
	frage.value = null
	await nextTick()
	if (ausloeser?.isConnected) {
		ausloeser.focus()
	}
	const resolve = aufloesen
	aufloesen = null
	const fertig = geschlossen
	schliesst = null
	geschlossen = null
	resolve?.(ergebnis)
	fertig?.()
}

defineExpose({ fragen })
</script>
