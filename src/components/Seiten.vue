<template>
	<nav class="seiten" aria-label="Seiten">
		<!-- Knöpfe mit Namen statt nur Zeichen, aktuelle Seite mit aria-current (U-KAL-12). -->
		<div v-if="seiten > 1" class="seiten__knoepfe">
			<button type="button" :disabled="seite === 1" aria-label="Erste Seite" title="Erste Seite" @click="$emit('seite', 1)">
				<span aria-hidden="true">«</span>
			</button>
			<button type="button" :disabled="seite === 1" aria-label="Vorige Seite" title="Vorige Seite" @click="$emit('seite', seite - 1)">
				<span aria-hidden="true">‹</span>
			</button>
			<button v-for="nummer in nummern"
				:key="nummer"
				type="button"
				:class="{ aktiv: nummer === seite }"
				:aria-label="'Seite ' + nummer"
				:aria-current="nummer === seite ? 'page' : undefined"
				@click="$emit('seite', nummer)">
				{{ nummer }}
			</button>
			<button type="button" :disabled="seite === seiten" aria-label="Nächste Seite" title="Nächste Seite" @click="$emit('seite', seite + 1)">
				<span aria-hidden="true">›</span>
			</button>
			<button type="button" :disabled="seite === seiten" aria-label="Letzte Seite" title="Letzte Seite" @click="$emit('seite', seiten)">
				<span aria-hidden="true">»</span>
			</button>
		</div>
		<div class="seiten__info">
			Seite {{ seite }} von {{ seiten }} · {{ gesamt }} {{ gesamt === 1 ? 'Eintrag' : 'Einträge' }} · pro Seite
			<select :value="proSeite" aria-label="Einträge pro Seite" @change="$emit('pro-seite', Number($event.target.value))">
				<option v-for="anzahl in [25, 50, 100]" :key="anzahl" :value="anzahl">
					{{ anzahl }}
				</option>
			</select>
		</div>
	</nav>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
	seite: { type: Number, required: true },
	seiten: { type: Number, required: true },
	gesamt: { type: Number, required: true },
	proSeite: { type: Number, required: true },
})
defineEmits(['seite', 'pro-seite'])

/** Höchstens fünf Seitenzahlen rund um die aktuelle Seite. */
const nummern = computed(() => {
	const anfang = Math.max(1, Math.min(props.seite - 2, props.seiten - 4))
	const ende = Math.min(props.seiten, anfang + 4)
	return Array.from({ length: ende - anfang + 1 }, (_, i) => anfang + i)
})
</script>

<style scoped lang="scss">
.seiten {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 16px;
	padding-top: 8px;

	&__knoepfe {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;

		button {
			min-width: 34px;
			margin: 0;
		}

		.aktiv {
			background-color: var(--color-primary-element);
			color: var(--color-primary-element-text);
		}
	}

	&__info {
		color: var(--color-text-maxcontrast);

		select {
			margin: 0 0 0 4px;
			min-height: 34px;
		}
	}
}
</style>
