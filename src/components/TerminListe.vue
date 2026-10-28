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
				<input v-model="suche" type="search" placeholder="Titel, Ort, Planungsfelder …">
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

		<NcLoadingIcon v-if="laedt && !daten" :size="44" />
		<template v-else-if="daten">
			<NcEmptyContent v-if="daten.gesamt === 0"
				name="Keine Termine"
				description="In diesem Zeitraum gibt es keine passenden Termine." />
			<template v-else>
				<div class="liste__rahmen" :class="{ 'liste__rahmen--laedt': laedt }">
					<table>
						<thead>
							<tr>
								<th>Datum</th>
								<th>Veranstaltung</th>
								<th>Ort</th>
								<th v-for="feld in spalten" :key="feld.key">
									{{ feld.label }}
								</th>
								<th>Planung</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="termin in daten.termine"
								:key="termin.objekt"
								tabindex="0"
								@click="$emit('oeffnen', termin.objekt)"
								@keydown.enter="$emit('oeffnen', termin.objekt)">
								<td class="liste__datum">
									{{ zeitraumText(termin) }}
									<span v-if="termin.serie" class="liste__marke" title="Terminserie: die Planung gilt für alle Termine der Serie">Serie</span>
								</td>
								<td class="liste__titel">
									{{ termin.titel || '(ohne Titel)' }}
								</td>
								<td>{{ termin.ort }}</td>
								<td v-for="feld in spalten" :key="feld.key">
									{{ anzeigeWert(feld, termin.felder[feld.key]) }}
								</td>
								<td>
									<span :class="termin.geplant ? 'liste__status--geplant' : 'liste__status--offen'">
										{{ termin.geplant ? 'erfasst' : 'offen' }}
									</span>
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
	</section>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Seiten from './Seiten.vue'
import { fehlertext, termineLaden } from '../api.js'
import { anzeigeWert, listenFelder } from '../felder.js'
import { zeitraumText } from '../datum.js'

const props = defineProps({
	kalenderListe: { type: Array, required: true },
	zustand: { type: Object, required: true },
	/** Einstellungen der App (GET /api/einstellungen); die Spalten kommen aus dem Feldschema. */
	einstellungen: { type: Object, required: true },
})
const emit = defineEmits(['aendern', 'oeffnen'])

const spalten = computed(() => listenFelder(props.einstellungen.schema))

const daten = ref(null)
const laedt = ref(false)
const fehler = ref('')
const suche = ref(props.zustand.suche)
let anfrage = 0
let suchTimer = null

function aendern(neu) {
	emit('aendern', neu)
}

async function laden() {
	if (!props.zustand.kalender) {
		return
	}
	const nummer = ++anfrage
	laedt.value = true
	try {
		const antwort = await termineLaden({
			kalender: props.zustand.kalender,
			von: props.zustand.von || undefined,
			bis: props.zustand.bis || undefined,
			suche: props.zustand.suche || undefined,
			seite: props.zustand.seite,
			pro_seite: props.zustand.pro_seite,
		})
		if (nummer !== anfrage) {
			return
		}
		daten.value = antwort
		fehler.value = ''
		// Die Seite begrenzt der Server; die Adresse daran angleichen.
		if (antwort.seite !== props.zustand.seite) {
			emit('aendern', { seite: antwort.seite })
		}
	} catch (e) {
		if (nummer === anfrage) {
			fehler.value = fehlertext(e)
		}
	} finally {
		if (nummer === anfrage) {
			laedt.value = false
		}
	}
}

watch(() => [props.zustand.kalender, props.zustand.von, props.zustand.bis, props.zustand.suche, props.zustand.seite, props.zustand.pro_seite],
	laden, { immediate: true })

watch(() => props.zustand.suche, (wert) => { suche.value = wert })
watch(suche, (wert) => {
	clearTimeout(suchTimer)
	if (wert === props.zustand.suche) {
		return
	}
	suchTimer = setTimeout(() => emit('aendern', { suche: wert.trim(), seite: 1 }), 300)
})
onBeforeUnmount(() => clearTimeout(suchTimer))
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
	}

	tbody tr {
		cursor: pointer;
		border-top: 1px solid var(--color-border);

		&:hover, &:focus-visible {
			background-color: var(--color-background-hover);
		}
	}

	&__datum {
		white-space: nowrap;
	}

	&__titel {
		font-weight: bold;
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
</style>
