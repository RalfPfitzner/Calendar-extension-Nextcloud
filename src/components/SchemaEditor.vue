<template>
	<div ref="wurzel" class="editor">
		<section v-for="(abschnitt, a) in schema.abschnitte"
			:key="idVon(abschnitt)"
			:data-abschnitt="idVon(abschnitt)"
			class="editor__abschnitt">
			<div class="editor__abschnittkopf">
				<label class="editor__titel">
					<span>Abschnitt {{ a + 1 }}</span>
					<input v-model="abschnitt.titel" type="text" maxlength="200" required placeholder="Überschrift">
				</label>
				<div class="editor__knoepfe">
					<NcButton variant="tertiary"
						:disabled="a === 0"
						title="Abschnitt nach oben"
						data-ziel="hoch"
						:aria-label="`Abschnitt ${a + 1} nach oben`"
						@click="abschnittVerschieben(a, -1)">
						↑
					</NcButton>
					<NcButton variant="tertiary"
						:disabled="a === schema.abschnitte.length - 1"
						title="Abschnitt nach unten"
						data-ziel="runter"
						:aria-label="`Abschnitt ${a + 1} nach unten`"
						@click="abschnittVerschieben(a, 1)">
						↓
					</NcButton>
					<NcButton variant="tertiary" title="Abschnitt entfernen" :aria-label="`Abschnitt ${a + 1} entfernen`" @click="abschnittEntfernen(a)">
						✕
					</NcButton>
				</div>
			</div>

			<div class="editor__tabelle">
				<table>
					<thead>
						<tr>
							<th class="editor__spalte-bezeichnung">
								Bezeichnung
							</th>
							<th>Schlüssel</th>
							<th>Typ</th>
							<th>Pflicht</th>
							<th>Liste</th>
							<th>Max. Zeichen</th>
							<th><span class="editor__unsichtbar">Aktionen</span></th>
						</tr>
					</thead>
					<tbody>
						<template v-for="(feld, f) in abschnitt.felder" :key="idVon(feld)">
							<tr class="editor__zeile" :data-feld="idVon(feld)">
								<td class="editor__bezeichnung" data-titel="Bezeichnung">
									<input v-model="feld.label"
										type="text"
										maxlength="200"
										required
										data-ziel="bezeichnung"
										:aria-label="`Bezeichnung Feld ${f + 1} in Abschnitt ${a + 1}`"
										@input="schluesselVorschlagen(feld)">
								</td>
								<td data-titel="Schlüssel">
									<input v-model="feld.key"
										type="text"
										maxlength="31"
										required
										pattern="[a-z][a-z0-9_]{0,30}"
										class="editor__key"
										:readonly="!feld._neu"
										:title="feld._neu ? 'Wird aus der Bezeichnung vorgeschlagen; nach dem Speichern fest.' : FEST"
										:aria-label="`Schlüssel Feld ${f + 1} in Abschnitt ${a + 1}`"
										@input="feld._manuell = true">
								</td>
								<td data-titel="Typ">
									<select v-model="feld.typ" :aria-label="`Typ Feld ${f + 1} in Abschnitt ${a + 1}`">
										<option v-for="typ in TYPEN" :key="typ.wert" :value="typ.wert">
											{{ typ.name }}
										</option>
									</select>
								</td>
								<td class="editor__mitte" data-titel="Pflicht">
									<input v-model="feld.pflicht" type="checkbox" :aria-label="`Pflichtfeld ${feld.label || f + 1}`">
								</td>
								<td class="editor__mitte" data-titel="Liste">
									<input v-model="feld.inListe" type="checkbox" :aria-label="`In der Liste: ${feld.label || f + 1}`">
								</td>
								<td data-titel="Max. Zeichen">
									<input v-model.number="feld.maxlen" type="number" min="1" :max="MAX_LAENGE" class="editor__zahl" :aria-label="`Höchstlänge ${feld.label || f + 1}`">
								</td>
								<td class="editor__knoepfe editor__feldknoepfe">
									<NcButton variant="tertiary"
										:disabled="f === 0"
										title="Nach oben"
										data-ziel="hoch"
										:aria-label="`Feld ${feld.label || f + 1} nach oben`"
										@click="feldVerschieben(abschnitt, a, f, -1)">
										↑
									</NcButton>
									<NcButton variant="tertiary"
										:disabled="f === abschnitt.felder.length - 1"
										title="Nach unten"
										data-ziel="runter"
										:aria-label="`Feld ${feld.label || f + 1} nach unten`"
										@click="feldVerschieben(abschnitt, a, f, 1)">
										↓
									</NcButton>
									<NcButton variant="tertiary"
										title="Feld entfernen"
										data-ziel="entfernen"
										:aria-label="`Feld ${feld.label || f + 1} entfernen`"
										@click="feldEntfernen(abschnitt, a, f)">
										✕
									</NcButton>
								</td>
							</tr>
							<tr v-if="feld.typ === 'select'" class="editor__optionenzeile">
								<td colspan="7" class="editor__optionen">
									<label>
										<span>Optionen von „{{ feld.label || feld.key }}“ (eine je Zeile)</span>
										<textarea :value="optionenText(feld)"
											rows="3"
											required
											@input="optionenTippen(feld, $event.target.value)" />
									</label>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
			<p v-if="entfernt && entfernt.abschnitt === abschnitt" class="editor__rueckgaengig">
				<span>Feld „{{ entfernt.name }}“ entfernt.</span>
				<NcButton data-ziel="rueckgaengig" @click="rueckgaengig">
					Rückgängig
				</NcButton>
			</p>
			<NcButton data-ziel="neu" @click="feldHinzufuegen(abschnitt)">
				+ Feld in Abschnitt {{ a + 1 }}
			</NcButton>
		</section>

		<NcButton @click="abschnittHinzufuegen">
			+ Abschnitt
		</NcButton>
		<p class="editor__unsichtbar" role="status" aria-live="polite">
			{{ ansageText }}
		</p>
	</div>
</template>

<script setup>
import { nextTick, reactive, ref, toRaw, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { MAX_LAENGE, TYPEN, schluesselAus } from '../felder.js'

/** Das Schema wird direkt bearbeitet (v-model auf ein Objekt). */
const schema = defineModel({ type: Object, required: true })

const FEST = 'Der Schlüssel ist Teil der gespeicherten Daten und lässt sich nicht mehr ändern.'

const wurzel = ref(null)
const ansageText = ref('')
/** Zuletzt entferntes Feld zum Zurückholen: {abschnitt, index, feld, name} oder null. */
const entfernt = ref(null)

/**
 * Feste IDs für Abschnitte und Felder (nur in der Oberfläche): Mit dem Index als Schlüssel würde Vue beim
 * Verschieben die Inhalte zweier Zeilen tauschen statt der Zeilen, und der Fokus bliebe an der alten Stelle.
 */
const ids = new WeakMap()
let naechsteId = 1
function idVon(objekt) {
	const roh = toRaw(objekt)
	if (!ids.has(roh)) {
		ids.set(roh, naechsteId++)
	}
	return ids.get(roh)
}

/**
 * Rohtext der Optionen je Feld (Q-KAL-02): Die Textarea zeigt, was getippt wurde, auch eine leere letzte Zeile
 * oder ein Leerzeichen am Ende; `feld.optionen` bekommt die bereinigte Liste.
 */
const optionenRoh = reactive(new WeakMap())
function optionenText(feld) {
	return optionenRoh.has(feld) ? optionenRoh.get(feld) : feld.optionen.join('\n')
}
function optionenTippen(feld, text) {
	optionenRoh.set(feld, text)
	feld.optionen = text.split('\n').map((o) => o.trim()).filter(Boolean)
}

// Ein neues Schema (Vorlage geladen, gespeichert) hat andere Feldobjekte: das Zurückholen gilt nicht mehr.
watch(() => schema.value, () => {
	entfernt.value = null
})

async function ansagen(text) {
	ansageText.value = ''
	await nextTick()
	ansageText.value = text
}

function fokus(selektor) {
	const element = wurzel.value?.querySelector(selektor)
	if (element && !element.disabled) {
		element.focus()
		return true
	}
	return false
}

/** Fokus auf den Knopf in Bewegungsrichtung; ist der am Rand gesperrt, auf den Gegenknopf. */
function fokusNachVerschieben(behaelter, richtung) {
	const [vorne, hinten] = richtung < 0 ? ['hoch', 'runter'] : ['runter', 'hoch']
	fokus(`${behaelter} [data-ziel="${vorne}"]`) || fokus(`${behaelter} [data-ziel="${hinten}"]`)
}

function tauschen(liste, index, richtung) {
	const ziel = index + richtung
	if (ziel < 0 || ziel >= liste.length) {
		return -1
	}
	;[liste[index], liste[ziel]] = [liste[ziel], liste[index]]
	return ziel
}

async function feldVerschieben(abschnitt, a, f, richtung) {
	const feld = abschnitt.felder[f]
	const ziel = tauschen(abschnitt.felder, f, richtung)
	if (ziel < 0) {
		return
	}
	await nextTick()
	fokusNachVerschieben(`[data-feld="${idVon(feld)}"]`, richtung)
	ansagen(`Feld „${feld.label || feld.key || ziel + 1}“ ist jetzt Feld ${ziel + 1} von ${abschnitt.felder.length} in Abschnitt ${a + 1}.`)
}

async function abschnittVerschieben(a, richtung) {
	const abschnitt = schema.value.abschnitte[a]
	const ziel = tauschen(schema.value.abschnitte, a, richtung)
	if (ziel < 0) {
		return
	}
	await nextTick()
	fokusNachVerschieben(`[data-abschnitt="${idVon(abschnitt)}"] .editor__abschnittkopf`, richtung)
	ansagen(`Abschnitt „${abschnitt.titel}“ ist jetzt Abschnitt ${ziel + 1} von ${schema.value.abschnitte.length}.`)
}

function abschnittHinzufuegen() {
	schema.value.abschnitte.push({ titel: `${schema.value.abschnitte.length + 1}. Abschnitt`, felder: [] })
}

function abschnittEntfernen(index) {
	const abschnitt = schema.value.abschnitte[index]
	if (abschnitt.felder.length > 0 && !window.confirm(`Abschnitt „${abschnitt.titel}“ mit ${abschnitt.felder.length} Feldern entfernen?`)) {
		return
	}
	schema.value.abschnitte.splice(index, 1)
	if (entfernt.value?.abschnitt === abschnitt) {
		entfernt.value = null
	}
}

/**
 * Entfernen ohne Rückfrage, aber mit „Rückgängig“ (U-KAL-20). Der Fokus geht auf das Feld, das nachrückt,
 * sonst auf das davor, sonst auf „+ Feld“.
 */
async function feldEntfernen(abschnitt, a, f) {
	const [feld] = abschnitt.felder.splice(f, 1)
	const name = feld.label || feld.key || `Feld ${f + 1}`
	entfernt.value = { abschnitt, index: f, feld, name }
	await nextTick()
	const naechstes = abschnitt.felder[f] ?? abschnitt.felder[f - 1]
	if (!naechstes || !fokus(`[data-feld="${idVon(naechstes)}"] [data-ziel="entfernen"]`)) {
		fokus(`[data-abschnitt="${idVon(abschnitt)}"] [data-ziel="neu"]`)
	}
	ansagen(`Feld „${name}“ aus Abschnitt ${a + 1} entfernt. „Rückgängig“ holt es zurück.`)
}

async function rueckgaengig() {
	const { abschnitt, index, feld, name } = entfernt.value
	abschnitt.felder.splice(Math.min(index, abschnitt.felder.length), 0, feld)
	entfernt.value = null
	await nextTick()
	fokus(`[data-feld="${idVon(feld)}"] [data-ziel="bezeichnung"]`)
	ansagen(`Feld „${name}“ ist wieder da.`)
}

/** Neue Felder tragen `_neu`: Nur bei ihnen folgt der Schlüssel der Bezeichnung (Q-KAL-01). */
async function feldHinzufuegen(abschnitt) {
	const feld = { key: '', label: '', typ: 'text', pflicht: false, maxlen: MAX_LAENGE, optionen: [], inListe: false, _neu: true, _manuell: false }
	abschnitt.felder.push(feld)
	await nextTick()
	fokus(`[data-feld="${idVon(abschnitt.felder[abschnitt.felder.length - 1])}"] [data-ziel="bezeichnung"]`)
}

/**
 * Der Schlüssel eines neuen Feldes folgt der Bezeichnung, bis jemand ihn von Hand ändert. Gespeicherte Felder
 * und Felder aus einer Vorlage behalten ihren Schlüssel: Er verbindet das Feld mit den erfassten Werten.
 */
function schluesselVorschlagen(feld) {
	if (feld._neu && !feld._manuell) {
		feld.key = schluesselAus(feld.label)
	}
}
</script>

<style scoped lang="scss">
.editor {
	// Die Breite des Editors entscheidet über Tabelle oder Karten, nicht die des Fensters (die Navigation von
	// Nextcloud nimmt je nach Breite Platz weg). Kein seitliches Scrollen von 1280 bis 600 Pixel (U-KAL-18).
	container-type: inline-size;

	&__abschnitt {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: 12px;
		margin-bottom: 16px;
	}

	&__abschnittkopf {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		margin-bottom: 8px;
	}

	&__titel {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;

		span {
			font-weight: bold;
		}

		input {
			width: 100%;
			margin: 0;
		}
	}

	&__tabelle {
		margin-bottom: 8px;
	}

	table {
		width: 100%;
		border-collapse: collapse;
		table-layout: fixed;
	}

	th {
		text-align: start;
		font-weight: bold;
		padding: 4px 6px;
		overflow-wrap: anywhere;

		// Spaltenbreiten der Tabelle; die Bezeichnung bekommt den Rest.
		&:nth-child(2) { width: 18%; }
		&:nth-child(3) { width: 17%; }
		&:nth-child(4), &:nth-child(5) { width: 64px; }
		&:nth-child(6) { width: 96px; }
		&:nth-child(7) { width: 140px; }
	}

	td {
		padding: 2px 6px;
		vertical-align: middle;

		input, select {
			margin: 0;
			width: 100%;
			min-width: 0;
		}
	}

	&__key {
		font-family: monospace;

		&[readonly] {
			background-color: var(--color-background-dark);
			color: var(--color-text-maxcontrast);
			cursor: default;
		}
	}

	&__mitte {
		text-align: center;

		input {
			width: auto;
		}
	}

	&__knoepfe {
		display: flex;
		white-space: nowrap;
	}

	&__optionen {
		label {
			display: flex;
			flex-direction: column;
			padding: 4px 0 8px;
		}

		span {
			font-weight: bold;
		}

		textarea {
			width: 100%;
			max-width: 600px;
			margin: 0;
			resize: vertical;
		}
	}

	&__rueckgaengig {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin: 4px 0 8px;
	}

	&__unsichtbar {
		position: absolute;
		width: 1px;
		height: 1px;
		overflow: hidden;
		clip-path: inset(50%);
		white-space: nowrap;
	}
}

// Schmaler Editor (Tablet, Handy, Fenster mit Navigation): jedes Feld als Karte mit sichtbaren Beschriftungen.
@container (max-width: 860px) {
	.editor {
		table, tbody, td {
			display: block;
		}

		thead {
			display: none;
		}

		tr.editor__zeile {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 6px 12px;
			padding: 10px 0 6px;
			border-top: 1px solid var(--color-border);
		}

		tr.editor__optionenzeile {
			display: block;
		}

		td {
			padding: 0;
		}

		td[data-titel]::before {
			content: attr(data-titel);
			display: block;
			font-weight: bold;
			font-size: 0.9em;
		}

		.editor__bezeichnung {
			grid-column: 1 / -1;
		}

		.editor__mitte {
			display: flex;
			align-items: center;
			gap: 8px;
			text-align: start;

			&::before {
				display: inline;
			}
		}

		.editor__feldknoepfe {
			grid-column: 1 / -1;
			justify-content: flex-end;
		}
	}
}

@container (max-width: 420px) {
	.editor tr.editor__zeile {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
