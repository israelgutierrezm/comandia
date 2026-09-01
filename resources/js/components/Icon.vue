<script setup>
import { computed } from 'vue';
import { ACTION_ICONS } from '../icons';

/**
 * Icono de acción para un botón. `name` es una acción del vocabulario compartido (`ACTION_ICONS`), no un dibujo: se pide
 * `edit`, `trash`, `check`… y el trazo sale de una sola fuente para todo el panel.
 *
 * Hereda el color por `currentColor`, así que sigue al texto del botón (tema, ámbar o rojo) sin configurarlo aquí.
 */
const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 14 },
});

const paths = computed(() => ACTION_ICONS[props.name] ?? ACTION_ICONS.dot ?? []);
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.9"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        class="icon"
    >
        <path v-for="(d, i) in paths" :key="i" :d="d" />
    </svg>
</template>

<style scoped>
.icon {
    flex: none;
}
</style>
