<template>
	<nav class="seiten" aria-label="Seiten">
		<div v-if="seiten > 1" class="seiten__knoepfe">
			<button :disabled="seite === 1" title="Erste Seite" @click="$emit('seite', 1)">
				«
			</button>
			<button :disabled="seite === 1" title="Vorherige Seite" @click="$emit('seite', seite - 1)">
				‹
			</button>
			<button v-for="nummer in nummern"
				:key="nummer"
				:class="{ aktiv: nummer === seite }"
				:aria-current="nummer === seite ? 'page' : undefined"
				@click="$emit('seite', nummer)">
				{{ nummer }}
			</button>
			<button :disabled="seite === seiten" title="Nächste Seite" @click="$emit('seite', seite + 1)">
				›
			</button>
			<button :disabled="seite === seiten" title="Letzte Seite" @click="$emit('seite', seiten)">
				»
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
