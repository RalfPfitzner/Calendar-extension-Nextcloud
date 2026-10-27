<template>
	<div class="editor">
		<section v-for="(abschnitt, a) in schema.abschnitte" :key="a" class="editor__abschnitt">
			<div class="editor__abschnittkopf">
				<label class="editor__titel">
					<span>Abschnitt {{ a + 1 }}</span>
					<input v-model="abschnitt.titel" type="text" maxlength="200" required placeholder="Überschrift">
				</label>
				<div class="editor__knoepfe">
					<NcButton variant="tertiary" :disabled="a === 0" title="Abschnitt nach oben" :aria-label="`Abschnitt ${a + 1} nach oben`" @click="verschieben(schema.abschnitte, a, -1)">
						↑
					</NcButton>
					<NcButton variant="tertiary" :disabled="a === schema.abschnitte.length - 1" title="Abschnitt nach unten" :aria-label="`Abschnitt ${a + 1} nach unten`" @click="verschieben(schema.abschnitte, a, 1)">
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
							<th>Bezeichnung</th>
							<th>Schlüssel</th>
							<th>Typ</th>
							<th>Pflicht</th>
							<th>Liste</th>
							<th>Max. Zeichen</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<template v-for="(feld, f) in abschnitt.felder" :key="f">
							<tr>
								<td>
									<input v-model="feld.label"
										type="text"
										maxlength="200"
										required
										:aria-label="`Bezeichnung Feld ${f + 1} in Abschnitt ${a + 1}`"
										@input="schluesselVorschlagen(feld)">
								</td>
								<td>
									<input v-model="feld.key"
										type="text"
										maxlength="31"
										required
										pattern="[a-z][a-z0-9_]{0,30}"
										class="editor__key"
										:aria-label="`Schlüssel Feld ${f + 1} in Abschnitt ${a + 1}`"
										@input="feld._manuell = true">
								</td>
								<td>
									<select v-model="feld.typ" :aria-label="`Typ Feld ${f + 1} in Abschnitt ${a + 1}`">
										<option v-for="typ in TYPEN" :key="typ.wert" :value="typ.wert">
											{{ typ.name }}
										</option>
									</select>
								</td>
								<td class="editor__mitte">
									<input v-model="feld.pflicht" type="checkbox" :aria-label="`Pflichtfeld ${feld.label || f + 1}`">
								</td>
								<td class="editor__mitte">
									<input v-model="feld.inListe" type="checkbox" :aria-label="`In der Liste: ${feld.label || f + 1}`">
								</td>
								<td>
									<input v-model.number="feld.maxlen" type="number" min="1" :max="MAX_LAENGE" class="editor__zahl" :aria-label="`Höchstlänge ${feld.label || f + 1}`">
								</td>
								<td class="editor__knoepfe">
									<NcButton variant="tertiary" :disabled="f === 0" title="Nach oben" :aria-label="`Feld ${feld.label || f + 1} nach oben`" @click="verschieben(abschnitt.felder, f, -1)">
										↑
									</NcButton>
									<NcButton variant="tertiary" :disabled="f === abschnitt.felder.length - 1" title="Nach unten" :aria-label="`Feld ${feld.label || f + 1} nach unten`" @click="verschieben(abschnitt.felder, f, 1)">
										↓
									</NcButton>
									<NcButton variant="tertiary" title="Feld entfernen" :aria-label="`Feld ${feld.label || f + 1} entfernen`" @click="abschnitt.felder.splice(f, 1)">
										✕
									</NcButton>
								</td>
							</tr>
							<tr v-if="feld.typ === 'select'">
								<td colspan="7" class="editor__optionen">
									<label>
										<span>Optionen von „{{ feld.label || feld.key }}“ (eine je Zeile)</span>
										<textarea :value="feld.optionen.join('\n')"
											rows="3"
											required
											@input="feld.optionen = $event.target.value.split('\n').map((o) => o.trim()).filter(Boolean)" />
									</label>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
			<NcButton @click="feldHinzufuegen(abschnitt)">
				+ Feld in Abschnitt {{ a + 1 }}
			</NcButton>
		</section>

		<NcButton @click="abschnittHinzufuegen">
			+ Abschnitt
		</NcButton>
	</div>
</template>

<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import { MAX_LAENGE, TYPEN, schluesselAus } from '../felder.js'

/** Das Schema wird direkt bearbeitet (v-model auf ein Objekt). */
const schema = defineModel({ type: Object, required: true })

function verschieben(liste, index, richtung) {
	const ziel = index + richtung
	if (ziel < 0 || ziel >= liste.length) {
		return
	}
	;[liste[index], liste[ziel]] = [liste[ziel], liste[index]]
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
}

function feldHinzufuegen(abschnitt) {
	abschnitt.felder.push({ key: '', label: '', typ: 'text', pflicht: false, maxlen: MAX_LAENGE, optionen: [], inListe: false, _manuell: false })
}

/** Neuer Schlüssel folgt der Bezeichnung, bis jemand ihn von Hand ändert. */
function schluesselVorschlagen(feld) {
	if (!feld._manuell) {
		feld.key = schluesselAus(feld.label)
	}
}
</script>

<style scoped lang="scss">
.editor {
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
		overflow-x: auto;
		margin-bottom: 8px;
	}

	table {
		width: 100%;
		border-collapse: collapse;
	}

	th {
		text-align: start;
		font-weight: bold;
		padding: 4px 6px;
	}

	td {
		padding: 2px 6px;
		vertical-align: middle;

		input, select {
			margin: 0;
			width: 100%;
			min-width: 90px;
		}
	}

	&__key {
		font-family: monospace;
	}

	&__zahl {
		max-width: 110px;
	}

	&__mitte {
		text-align: center;

		input {
			width: auto;
			min-width: 0;
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
}
</style>
