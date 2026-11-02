<template>
	<NcDialog v-if="frage"
		:open="offen"
		:name="frage.titel"
		:message="frage.text"
		:buttons="knoepfe"
		size="normal"
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
function fragen(angaben) {
	aufloesen?.(false)
	ausloeser = document.activeElement
	ergebnis = false
	frage.value = angaben
	offen.value = true
	return new Promise((resolve) => {
		aufloesen = resolve
	})
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
	resolve?.(ergebnis)
}

defineExpose({ fragen })
</script>
