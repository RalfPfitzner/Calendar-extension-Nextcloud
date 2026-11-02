<template>
	<section class="liste">
		<h2>Veranstaltungen planen</h2>

		<div class="liste__filter">
			<label>
				Kalender
				<select :value="zustand.kalender" @change="aendern({ kalender: $event.target.value, seite: 1 })">
					<option v-for="eintrag in kalenderListe" :key="eintrag.id" :value="String(eintrag.id)">
						{{ eintrag.name }}{{ eintrag.schreibbar ? '' : ' (nur lesen)' }}
					</option>
				</select>
			</label>
			<label>
				Von
				<input type="date" :value="zustand.von" @change="aendern({ von: $event.target.value, seite: 1 })">
			</label>
			<label>
				Bis
				<input type="date" :value="zustand.bis" @change="aendern({ bis: $event.target.value, seite: 1 })">
			</label>
			<label class="liste__suche">
				Suche
				<input ref="suchfeld" v-model="suche" type="search" placeholder="Titel, Ort, Planungsfelder …">
			</label>
		</div>
		<p v-if="!zustand.von && !zustand.bis" class="liste__hinweis">
			Ohne Angabe: Termine ab 30 Tagen vor heute bis ein Jahr im Voraus.
		</p>

		<NcNoteCard v-if="fehler" type="error">
			{{ fehler }}
		</NcNoteCard>
		<NcNoteCard v-else-if="daten && !daten.schreibbar" type="info">
			Diesen Kalender darfst du nur lesen. Die Planung kannst du ansehen, aber nicht ändern.
		</NcNoteCard>

		<!-- Dauerhaft im DOM: sagt nach dem Laden die Zahl der Treffer an, nicht bei jeder Taste (U-KAL-15). -->
		<p class="hidden-visually" role="status" aria-live="polite">
			{{ ergebnisAnsage }}
		</p>

		<div class="liste__ergebnis" :aria-busy="laedt ? 'true' : 'false'">
			<NcLoadingIcon v-if="laedt && !daten" :size="44" name="Termine werden geladen" />
			<template v-else-if="daten">
				<NcEmptyContent v-if="daten.gesamt === 0"
					:name="gesucht ? 'Keine Treffer' : 'Keine Termine'"
					:description="leerText">
					<template v-if="gesucht" #action>
						<NcButton @click="sucheZuruecksetzen">
							Suche zurücksetzen
						</NcButton>
					</template>
				</NcEmptyContent>
				<template v-else>
					<!-- Kein seitliches Scrollen von 1280 bis 320 Pixel: Unter 861 Pixel stehen die Nebenspalten unter dem
						Titel, unter 601 Pixel auch Datum und Ort (U-KAL-02). Die Rollen halten die Tabelle für Bildschirmleser
						zusammen, auch wenn die Zeilen auf schmalen Breiten als Raster gesetzt sind; aria-colindex ordnet jede Zelle
						ihrem Spaltenkopf zu, auch wenn leere Zellen nur noch für Bildschirmleser da sind. -->
					<div class="liste__rahmen" :class="{ 'liste__rahmen--laedt': laedt }">
						<table role="table" :aria-colcount="spaltenZahl">
							<thead role="rowgroup">
								<tr role="row">
									<th role="columnheader" aria-colindex="1" class="liste__datum">
										Datum
									</th>
									<th role="columnheader" aria-colindex="2">
										Veranstaltung
									</th>
									<th role="columnheader" aria-colindex="3">
										Ort
									</th>
									<th v-for="(feld, nr) in spalten" :key="feld.key" role="columnheader" :aria-colindex="4 + nr">
										{{ feld.label }}
									</th>
									<th role="columnheader" :aria-colindex="spaltenZahl" class="liste__planung">
										Planung
									</th>
								</tr>
							</thead>
							<tbody role="rowgroup">
								<tr v-for="termin in daten.termine"
									:key="termin.objekt"
									role="row"
									@click="zeileKlick($event, termin.objekt)">
									<td role="cell" aria-colindex="1" class="liste__datum">
										{{ zeitraumText(termin) }}
										<span v-if="termin.serie" class="liste__marke" title="Terminserie: die Planung gilt für alle Termine der Serie">Serie</span>
									</td>
									<td role="cell" aria-colindex="2" class="liste__titel">
										<!-- Echter Link: Mittelklick und Strg-Klick öffnen einen neuen Tab (U-KAL-07). -->
										<a :href="terminAdresse(termin.objekt)" @click="linkKlick($event, termin.objekt)">
											{{ termin.titel || '(ohne Titel)' }}
										</a>
									</td>
									<td role="cell" aria-colindex="3" :class="{ 'liste__leer': !termin.ort }">
										<span v-if="termin.ort" class="liste__name" aria-hidden="true">Ort: </span>{{ termin.ort }}
									</td>
									<td v-for="(feld, nr) in spalten"
										:key="feld.key"
										role="cell"
										:aria-colindex="4 + nr"
										:class="{ 'liste__leer': anzeigeWert(feld, termin.felder[feld.key]) === '' }">
										<span v-if="anzeigeWert(feld, termin.felder[feld.key]) !== ''" class="liste__name" aria-hidden="true">{{ feld.label }}: </span>{{ anzeigeWert(feld, termin.felder[feld.key]) }}
									</td>
									<td role="cell" :aria-colindex="spaltenZahl" class="liste__planung">
										<span class="liste__name" aria-hidden="true">Planung: </span><span :class="termin.geplant ? 'liste__status--geplant' : 'liste__status--offen'">{{ termin.geplant ? 'erfasst' : 'offen' }}</span>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<Seiten :seite="daten.seite"
						:seiten="daten.seiten"
						:gesamt="daten.gesamt"
						:pro-seite="daten.pro_seite"
						@seite="aendern({ seite: $event })"
						@pro-seite="aendern({ pro_seite: $event, seite: 1 })" />
				</template>
			</template>
		</div>
	</section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Seiten from './Seiten.vue'
import { fehlertext, termineLaden } from '../api.js'
import { adresseFuer } from '../adresse.js'
import { anzeigeWert, listenFelder } from '../felder.js'
import { zeitraumText } from '../datum.js'

const props = defineProps({
	kalenderListe: { type: Array, required: true },
	zustand: { type: Object, required: true },
	/** Einstellungen der App (GET /api/einstellungen); die Spalten kommen aus dem Feldschema. */
	einstellungen: { type: Object, required: true },
})
const emit = defineEmits(['aendern', 'oeffnen'])

/** Pause beim Tippen, nach der die Suche losgeht (P-KAL-03). */
const ENTPRELLEN = 300

const spalten = computed(() => listenFelder(props.einstellungen.schema))
/** Datum, Veranstaltung, Ort, die Listenfelder und Planung. */
const spaltenZahl = computed(() => 4 + spalten.value.length)

const daten = ref(null)
const laedt = ref(false)
const fehler = ref('')
/** Inhalt des Suchfelds, so wie getippt; getrimmt wird nur für Adresse und Anfrage (Q-KAL-08). */
const suche = ref(props.zustand.suche)
const suchfeld = ref(null)
const ergebnisAnsage = ref('')
/** Suchbegriff der angezeigten Antwort (nicht der gerade getippte). */
const gesuchtNach = ref('')
let anfrage = 0
let steuerung = null
let suchTimer = null
/** Parameter der angezeigten Antwort, mit der Seite, die der Server geliefert hat. */
let geladenFuer = ''

const gesucht = computed(() => gesuchtNach.value !== '')
const leerText = computed(() => gesucht.value
	? `Keine Termine zu „${gesuchtNach.value}“ im gewählten Zeitraum.`
	: 'In diesem Zeitraum gibt es keine Termine.')

function aendern(neu) {
	emit('aendern', neu)
}

function parameterAus(zustand, seite = zustand.seite) {
	return {
		kalender: zustand.kalender,
		von: zustand.von || undefined,
		bis: zustand.bis || undefined,
		suche: zustand.suche.trim() || undefined,
		seite,
		pro_seite: zustand.pro_seite,
	}
}

/** „12 Termine gefunden“, bei mehreren Seiten mit der Seite; für die Live-Region (U-KAL-15). */
function ansageFuer(antwort, begriff) {
	if (antwort.gesamt === 0) {
		return begriff ? `Keine Termine zu „${begriff}“ gefunden.` : 'Keine Termine gefunden.'
	}
	const zahl = antwort.gesamt === 1 ? '1 Termin gefunden' : `${antwort.gesamt} Termine gefunden`
	return antwort.seiten > 1 ? `${zahl}, angezeigt wird Seite ${antwort.seite} von insgesamt ${antwort.seiten}.` : `${zahl}.`
}

async function ansagen(text) {
	// Erst leeren, damit auch derselbe Text wieder angesagt wird.
	ergebnisAnsage.value = ''
	await nextTick()
	ergebnisAnsage.value = text
}

async function laden() {
	if (!props.zustand.kalender) {
		return
	}
	const parameter = parameterAus(props.zustand)
	// Nach einer Korrektur der Seite durch den Server steht genau diese Antwort schon da (Q-KAL-07).
	if (daten.value && JSON.stringify(parameter) === geladenFuer) {
		// Eine noch laufende Anfrage für etwas anderes darf die Anzeige nicht mehr überschreiben, und der Fehler einer
		// gescheiterten Anfrage dazwischen gilt für die angezeigte Liste nicht.
		steuerung?.abort()
		steuerung = null
		anfrage++
		laedt.value = false
		fehler.value = ''
		return
	}
	// Eine ältere Anfrage wird nicht mehr gebraucht (P-KAL-03).
	steuerung?.abort()
	const eigene = new AbortController()
	steuerung = eigene
	const nummer = ++anfrage
	laedt.value = true
	try {
		const antwort = await termineLaden(parameter, { signal: eigene.signal })
		// Veraltete Antworten verwerfen, auch wenn der Abbruch zu spät kam.
		if (nummer !== anfrage) {
			return
		}
		daten.value = antwort
		fehler.value = ''
		gesuchtNach.value = parameter.suche ?? ''
		geladenFuer = JSON.stringify(parameterAus(props.zustand, antwort.seite))
		ansagen(ansageFuer(antwort, gesuchtNach.value))
		// Die Seite begrenzt der Server; die Adresse daran angleichen, ohne neuen Verlaufseintrag (Q-KAL-07).
		if (antwort.seite !== props.zustand.seite) {
			emit('aendern', { seite: antwort.seite }, { ersetzen: true })
		}
	} catch (e) {
		if (nummer === anfrage && !eigene.signal.aborted) {
			fehler.value = fehlertext(e)
		}
	} finally {
		if (nummer === anfrage) {
			laedt.value = false
			steuerung = null
		}
	}
}

watch(() => [props.zustand.kalender, props.zustand.von, props.zustand.bis, props.zustand.suche, props.zustand.seite, props.zustand.pro_seite],
	laden, { immediate: true })

// Von außen (Zurück-Taste, Zurücksetzen) geänderte Suche ins Feld übernehmen; die eigene, getrimmte Fassung nicht,
// sonst verschwände ein Leerzeichen am Ende beim Tippen (Q-KAL-08).
watch(() => props.zustand.suche, (wert) => {
	if (wert !== suche.value.trim()) {
		suche.value = wert
	}
})
watch(suche, (wert) => {
	clearTimeout(suchTimer)
	if (wert.trim() === props.zustand.suche) {
		return
	}
	suchTimer = setTimeout(() => emit('aendern', { suche: wert.trim(), seite: 1 }), ENTPRELLEN)
})

function sucheZuruecksetzen() {
	clearTimeout(suchTimer)
	suche.value = ''
	emit('aendern', { suche: '', seite: 1 })
	suchfeld.value?.focus()
}

/** Adresse des Formulars zu einem Termin; die Liste bleibt in der Adresse, damit „Zur Liste“ dorthin zurückführt. */
function terminAdresse(objekt) {
	return adresseFuer({ ...props.zustand, termin: objekt })
}

/** Einfacher Klick öffnet das Formular ohne Neuladen; Mittel-, Strg-, Umschalt- und Cmd-Klick bleiben beim Browser. */
function linkKlick(ereignis, objekt) {
	if (ereignis.defaultPrevented || ereignis.button !== 0
		|| ereignis.ctrlKey || ereignis.metaKey || ereignis.shiftKey || ereignis.altKey) {
		return
	}
	ereignis.preventDefault()
	emit('oeffnen', objekt)
}

/** Klick irgendwo in der Zeile wie auf den Titel (nicht beim Markieren von Text). */
function zeileKlick(ereignis, objekt) {
	if (ereignis.target.closest('a') || window.getSelection()?.toString()) {
		return
	}
	emit('oeffnen', objekt)
}

onBeforeUnmount(() => {
	clearTimeout(suchTimer)
	steuerung?.abort()
})
</script>

<style scoped lang="scss">
.liste {
	h2 {
		margin: 12px 0;
	}

	&__filter {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 16px;
		align-items: flex-end;

		label {
			display: flex;
			flex-direction: column;
			font-weight: bold;
		}

		select, input {
			font-weight: normal;
			margin: 4px 0 0;
		}
	}

	&__suche {
		flex: 1 1 200px;

		input {
			width: 100%;
		}
	}

	&__hinweis {
		color: var(--color-text-maxcontrast);
		margin: 4px 0 12px;
	}

	&__rahmen {
		max-height: calc(100vh - 340px);
		min-height: 200px;
		overflow: auto;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);

		&--laedt {
			opacity: .6;
		}
	}

	table {
		width: 100%;
		border-collapse: collapse;
	}

	th {
		position: sticky;
		top: 0;
		background-color: var(--color-main-background);
		box-shadow: 0 1px 0 var(--color-border);
		text-align: start;
		font-weight: bold;
		z-index: 1;
	}

	th, td {
		padding: 8px 10px;
		vertical-align: top;
		// Lange Wörter (Titel ohne Leerzeichen, Ortsangaben) brechen um, statt die Tabelle zu verbreitern (U-KAL-02);
		// Nextcloud setzt für Tabellenzellen sonst nowrap.
		white-space: normal;
		overflow-wrap: anywhere;
	}

	// Spaltenköpfe nur zwischen Wörtern umbrechen; die Spalte wird dann so breit wie ihr längstes Wort.
	th {
		overflow-wrap: break-word;
	}

	th.liste__planung, td.liste__planung {
		white-space: nowrap;
	}

	tbody tr {
		cursor: pointer;
		border-top: 1px solid var(--color-border);

		&:hover, &:focus-within {
			background-color: var(--color-background-hover);
		}
	}

	&__datum {
		min-width: 7em;
	}

	&__titel {
		font-weight: bold;

		a {
			color: inherit;

			&:hover, &:focus-visible {
				text-decoration: underline;
			}

			&:focus-visible {
				outline: 2px solid var(--color-main-text);
				outline-offset: 2px;
			}
		}
	}

	// Bezeichnung vor dem Wert; nur auf schmalen Breiten sichtbar, wo der Spaltenkopf fehlt.
	&__name {
		display: none;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}

	&__marke {
		display: inline-block;
		margin-inline-start: 6px;
		padding: 0 6px;
		border-radius: var(--border-radius-pill);
		background-color: var(--color-background-dark);
		font-size: .85em;
	}

	&__status--geplant {
		color: var(--color-success-text);
	}

	&__status--offen {
		color: var(--color-text-maxcontrast);
	}
}

// Tablet: Nebenspalten (Ort, Listenfelder, Planung) stehen unter dem Titel, das Datum bleibt links (U-KAL-02).
@media (max-width: 860px) {
	.liste {
		thead {
			// Spaltenköpfe nur für Bildschirmleser
			display: block;
			position: absolute;
			width: 1px;
			height: 1px;
			overflow: hidden;
			clip-path: inset(50%);
			white-space: nowrap;
		}

		table, tbody {
			display: block;
		}

		tbody tr {
			display: grid;
			grid-template-columns: minmax(0, 9em) minmax(0, 1fr);
			column-gap: 12px;
			padding: 8px 10px;
		}

		td {
			grid-column: 2;
			padding: 0 0 2px;
		}

		td.liste__datum {
			grid-column: 1;
			grid-row: 1 / span 8;
			min-width: 0;
		}

		td.liste__planung {
			white-space: normal;
		}

		&__name {
			display: inline;
		}

		// Leere Zellen nur optisch verstecken (wie .hidden-visually): Mit display:none fehlten sie Bildschirmlesern,
		// und die Zellen danach rutschten unter den falschen Spaltenkopf.
		td.liste__leer {
			position: absolute;
			width: 1px;
			height: 1px;
			padding: 0;
			overflow: hidden;
			clip-path: inset(50%);
			white-space: nowrap;
		}
	}
}

// Handy: eine Spalte, Datum über dem Titel.
@media (max-width: 600px) {
	.liste {
		tbody tr {
			grid-template-columns: minmax(0, 1fr);
		}

		td, td.liste__datum {
			grid-column: 1;
			grid-row: auto;
		}
	}
}
</style>
