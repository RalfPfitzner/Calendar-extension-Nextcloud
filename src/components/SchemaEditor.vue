<template>
	<div ref="wurzel" class="editor">
		<section v-for="(abschnitt, a) in schema.abschnitte"
			:key="idVon(abschnitt)"
			:data-abschnitt="idVon(abschnitt)"
			class="editor__abschnitt">
			<div class="editor__abschnittkopf">
				<label class="editor__titel">
					<span>Abschnitt {{ a + 1 }}</span>
					<input v-model="abschnitt.titel"
						type="text"
						maxlength="200"
						required
						data-ziel="titel"
						placeholder="Überschrift">
				</label>
				<div class="editor__knoepfe">
					<NcButton variant="tertiary"
						:disabled="a === 0"
						title="Abschnitt nach oben"
						data-ziel="hoch"
						:aria-label="`${abschnittName(abschnitt, a)} nach oben`"
						@click="abschnittVerschieben(a, -1)">
						↑
					</NcButton>
					<NcButton variant="tertiary"
						:disabled="a === schema.abschnitte.length - 1"
						title="Abschnitt nach unten"
						data-ziel="runter"
						:aria-label="`${abschnittName(abschnitt, a)} nach unten`"
						@click="abschnittVerschieben(a, 1)">
						↓
					</NcButton>
					<NcButton variant="tertiary"
						title="Abschnitt entfernen"
						data-ziel="abschnitt-entfernen"
						:aria-label="`${abschnittName(abschnitt, a)} entfernen`"
						@click="abschnittEntfernen(a)">
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
							<!-- Jede Eingabe hat ein echtes <label>: in der Tabelle unsichtbar (der Spaltenkopf steht darüber),
								in der Karten-Ansicht sichtbar. Der Name beginnt mit dem sichtbaren Text (WCAG 2.5.3) und nennt
								danach Feld und Abschnitt, damit er auf der Seite eindeutig ist. -->
							<tr class="editor__zeile" :data-feld="idVon(feld)">
								<td class="editor__bezeichnung">
									<label class="editor__kartentitel" :for="eingabeId(feld, 'bezeichnung')">Bezeichnung<span class="editor__unsichtbar">{{ ' ' + feldStelle(a, f) }}</span></label>
									<input :id="eingabeId(feld, 'bezeichnung')"
										v-model="feld.label"
										type="text"
										maxlength="200"
										required
										data-ziel="bezeichnung"
										@input="schluesselVorschlagen(feld)">
								</td>
								<td>
									<label class="editor__kartentitel" :for="eingabeId(feld, 'schluessel')">Schlüssel<span class="editor__unsichtbar">{{ ' ' + feldName(feld, a, f) }}</span></label>
									<input :id="eingabeId(feld, 'schluessel')"
										v-model="feld.key"
										type="text"
										maxlength="31"
										required
										pattern="[a-z][a-z0-9_]{0,30}"
										class="editor__key"
										:readonly="!feld._neu"
										:title="feld._neu ? 'Wird aus der Bezeichnung vorgeschlagen; nach dem Speichern fest.' : FEST"
										@input="feld._manuell = true">
								</td>
								<td>
									<label class="editor__kartentitel" :for="eingabeId(feld, 'typ')">Typ<span class="editor__unsichtbar">{{ ' ' + feldName(feld, a, f) }}</span></label>
									<select :id="eingabeId(feld, 'typ')" v-model="feld.typ">
										<option v-for="typ in TYPEN" :key="typ.wert" :value="typ.wert">
											{{ typ.name }}
										</option>
									</select>
								</td>
								<td class="editor__mitte">
									<input :id="eingabeId(feld, 'pflicht')" v-model="feld.pflicht" type="checkbox">
									<label class="editor__kartentitel" :for="eingabeId(feld, 'pflicht')">Pflicht<span class="editor__unsichtbar">{{ ' ' + feldName(feld, a, f) }}</span></label>
								</td>
								<td class="editor__mitte">
									<input :id="eingabeId(feld, 'liste')" v-model="feld.inListe" type="checkbox">
									<label class="editor__kartentitel" :for="eingabeId(feld, 'liste')">Liste<span class="editor__unsichtbar">{{ ' ' + feldName(feld, a, f) }}</span></label>
								</td>
								<td>
									<label class="editor__kartentitel" :for="eingabeId(feld, 'maxlen')">Max. Zeichen<span class="editor__unsichtbar">{{ ' ' + feldName(feld, a, f) }}</span></label>
									<input :id="eingabeId(feld, 'maxlen')"
										v-model.number="feld.maxlen"
										type="number"
										min="1"
										:max="MAX_LAENGE"
										class="editor__zahl">
								</td>
								<td class="editor__knoepfe editor__feldknoepfe">
									<NcButton variant="tertiary"
										:disabled="f === 0"
										title="Nach oben"
										data-ziel="hoch"
										:aria-label="`${feldName(feld, a, f)} nach oben`"
										@click="feldVerschieben(abschnitt, a, f, -1)">
										↑
									</NcButton>
									<NcButton variant="tertiary"
										:disabled="f === abschnitt.felder.length - 1"
										title="Nach unten"
										data-ziel="runter"
										:aria-label="`${feldName(feld, a, f)} nach unten`"
										@click="feldVerschieben(abschnitt, a, f, 1)">
										↓
									</NcButton>
									<NcButton variant="tertiary"
										title="Feld entfernen"
										data-ziel="entfernen"
										:aria-label="`${feldName(feld, a, f)} entfernen`"
										@click="feldEntfernen(abschnitt, a, f)">
										✕
									</NcButton>
								</td>
							</tr>
							<tr v-if="feld.typ === 'select'" class="editor__optionenzeile">
								<td colspan="7" class="editor__optionen">
									<label>
										<span>Optionen für {{ feldName(feld, a, f) }}, eine je Zeile</span>
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
			<p v-if="oberstes && oberstes.abschnitt === abschnitt" class="editor__rueckgaengig">
				<span>Feld „{{ oberstes.name }}“ entfernt.<template v-if="stapel.length > 1">
					Mit „Rückgängig“ kommen nacheinander {{ stapel.length }} entfernte Felder zurück.
				</template></span>
				<NcButton data-ziel="rueckgaengig" @click="rueckgaengig">
					Rückgängig
				</NcButton>
			</p>
			<NcButton data-ziel="neu" @click="feldHinzufuegen(abschnitt)">
				+ Feld in Abschnitt {{ a + 1 }}
			</NcButton>
		</section>

		<NcButton data-ziel="abschnitt-neu" @click="abschnittHinzufuegen">
			+ Abschnitt
		</NcButton>
		<p class="editor__unsichtbar" role="status" aria-live="polite">
			{{ ansageText }}
		</p>
		<Rueckfrage ref="rueckfrage" />
	</div>
</template>

<script setup>
import { computed, nextTick, reactive, ref, toRaw, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import Rueckfrage from './Rueckfrage.vue'
import { MAX_LAENGE, TYPEN, schluesselAus } from '../felder.js'

/** Das Schema wird direkt bearbeitet (v-model auf ein Objekt). */
const schema = defineModel({ type: Object, required: true })

const FEST = 'Der Schlüssel ist Teil der gespeicherten Daten und lässt sich nicht mehr ändern.'

const wurzel = ref(null)
const rueckfrage = ref(null)
const ansageText = ref('')
/**
 * Entfernte Felder zum Zurückholen, zuletzt entferntes am Ende: {abschnitt, index, feld, name}. „Rückgängig“ holt
 * sie in umgekehrter Reihenfolge an ihre alte Stelle zurück; so stimmen auch die Indizes früherer Einträge wieder.
 */
const stapel = ref([])
const oberstes = computed(() => stapel.value.at(-1) ?? null)

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

/** ID einer Eingabe im Feld, für <label for>. */
const eingabeId = (feld, teil) => `calplan-feld-${idVon(feld)}-${teil}`

/** „Feld 2 in Abschnitt 1“ */
const feldStelle = (a, f) => `Feld ${f + 1} in Abschnitt ${a + 1}`

/** „Feld 2 in Abschnitt 1 („Name“)“, ohne leere Anführungszeichen; wie die Fehlermeldungen des Servers. */
function feldName(feld, a, f) {
	const label = (feld.label ?? '').trim()
	return feldStelle(a, f) + (label === '' ? '' : ` („${label}“)`)
}

/** „Abschnitt 2 („Kontakt“)“ */
function abschnittName(abschnitt, a) {
	const titel = (abschnitt.titel ?? '').trim()
	return `Abschnitt ${a + 1}` + (titel === '' ? '' : ` („${titel}“)`)
}

/** Kurzer Name eines Feldes für Hinweise und Ansagen: Bezeichnung, sonst Schlüssel, sonst die Stelle. */
const kurzname = (feld, a, f) => (feld.label ?? '').trim() || (feld.key ?? '').trim() || feldStelle(a, f)

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
	stapel.value = []
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
	const label = (feld.label ?? '').trim()
	ansagen(`${label === '' ? 'Das Feld' : `Feld „${label}“`} ist jetzt Feld ${ziel + 1} von ${abschnitt.felder.length} in Abschnitt ${a + 1}.`)
}

async function abschnittVerschieben(a, richtung) {
	const abschnitt = schema.value.abschnitte[a]
	const ziel = tauschen(schema.value.abschnitte, a, richtung)
	if (ziel < 0) {
		return
	}
	await nextTick()
	fokusNachVerschieben(`[data-abschnitt="${idVon(abschnitt)}"] .editor__abschnittkopf`, richtung)
	const titel = (abschnitt.titel ?? '').trim()
	ansagen(`${titel === '' ? 'Der Abschnitt' : `Abschnitt „${titel}“`} ist jetzt Abschnitt ${ziel + 1} von ${schema.value.abschnitte.length}.`)
}

/** Neuer Abschnitt am Ende; der Fokus steht danach in seiner Überschrift. */
async function abschnittHinzufuegen() {
	const abschnitt = { titel: `${schema.value.abschnitte.length + 1}. Abschnitt`, felder: [] }
	schema.value.abschnitte.push(abschnitt)
	await nextTick()
	fokus(`[data-abschnitt="${idVon(abschnitt)}"] [data-ziel="titel"]`)
	ansagen(`Abschnitt ${schema.value.abschnitte.length} angelegt.`)
}

/**
 * Abschnitt mit Feldern erst nach Rückfrage entfernen. Danach steht der Fokus in der Überschrift des Abschnitts,
 * der nachrückt, sonst auf „+ Abschnitt“.
 */
async function abschnittEntfernen(index) {
	const abschnitt = schema.value.abschnitte[index]
	const name = abschnittName(abschnitt, index)
	if (abschnitt.felder.length > 0 && !await rueckfrage.value.fragen({
		titel: `${name} entfernen?`,
		text: abschnitt.felder.length === 1
			? 'Der Abschnitt enthält ein Feld; es wird mit entfernt.'
			: `Der Abschnitt enthält ${abschnitt.felder.length} Felder; sie werden mit entfernt.`,
		ja: 'Abschnitt entfernen',
		nein: 'Behalten',
	})) {
		return
	}
	const jetzt = schema.value.abschnitte.indexOf(abschnitt)
	if (jetzt < 0) {
		return
	}
	schema.value.abschnitte.splice(jetzt, 1)
	stapel.value = stapel.value.filter((eintrag) => eintrag.abschnitt !== abschnitt)
	await nextTick()
	const naechster = schema.value.abschnitte[jetzt]
	if (!naechster || !fokus(`[data-abschnitt="${idVon(naechster)}"] [data-ziel="titel"]`)) {
		fokus('[data-ziel="abschnitt-neu"]')
	}
	ansagen(`${name} entfernt.`)
}

/**
 * Entfernen ohne Rückfrage, aber mit „Rückgängig“ (U-KAL-20). Der Fokus geht auf die Bezeichnung des Feldes,
 * das nachrückt, sonst auf „Rückgängig“.
 */
async function feldEntfernen(abschnitt, a, f) {
	const [feld] = abschnitt.felder.splice(f, 1)
	const name = kurzname(feld, a, f)
	stapel.value.push({ abschnitt, index: f, feld, name })
	await nextTick()
	const naechstes = abschnitt.felder[f]
	if (!naechstes || !fokus(`[data-feld="${idVon(naechstes)}"] [data-ziel="bezeichnung"]`)) {
		fokus(`[data-abschnitt="${idVon(abschnitt)}"] [data-ziel="rueckgaengig"]`)
	}
	ansagen(`Feld „${name}“ aus Abschnitt ${a + 1} entfernt. „Rückgängig“ holt es zurück.`)
}

async function rueckgaengig() {
	const eintrag = stapel.value.pop()
	if (!eintrag) {
		return
	}
	const { abschnitt, index, feld, name } = eintrag
	abschnitt.felder.splice(Math.min(index, abschnitt.felder.length), 0, feld)
	await nextTick()
	fokus(`[data-feld="${idVon(feld)}"] [data-ziel="bezeichnung"]`)
	ansagen(`Feld „${name}“ ist wieder da.` + (stapel.value.length > 0 ? ` Noch ${stapel.value.length} zurückholbar.` : ''))
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
		// Nextcloud setzt für Tabellen `white-space: nowrap`; lange Beschriftungen sollen umbrechen (320 Pixel).
		white-space: normal;
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
		position: relative;
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

	// Beschriftung je Eingabe: in der Tabelle nur für Screenreader (der Spaltenkopf steht darüber), als Karte sichtbar.
	&__unsichtbar,
	&__kartentitel {
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

		.editor__kartentitel {
			position: static;
			display: block;
			width: auto;
			height: auto;
			overflow: visible;
			clip-path: none;
			white-space: normal;
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

			.editor__kartentitel {
				display: inline;
				cursor: pointer;
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
