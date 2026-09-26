<script setup>
import { computed } from 'vue';
import { ACTION_ICONS, ICON_PATHS } from '../icons';

/**
 * Icono de acción para un botón. `name` es una acción del vocabulario compartido (`ACTION_ICONS`), no un dibujo: se pide
 * `edit`, `trash`, `check`… y el trazo sale de una sola fuente para todo el panel.
 *
 * Si el nombre no es una acción, se busca entre los iconos de SECCIÓN (`ICON_PATHS`: `receipt`, `chart`, `users`…), que
 * el tablero de inicio usa en sus accesos. Antes sólo se miraba la primera tabla y esos accesos salían sin dibujo.
 *
 * Hereda el color por `currentColor`, así que sigue al texto del botón (tema, ámbar o rojo) sin configurarlo aquí.
 */
const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 14 },
});

const paths = computed(() => {
    if (ACTION_ICONS[props.name]) {
        return ACTION_ICONS[props.name];
    }

    if (ICON_PATHS[props.name]) {
        return [ICON_PATHS[props.name]];
    }

    if (import.meta.env.DEV) {
        console.warn(`Icon: «${props.name}» no existe en icons.js.`);
    }

    return [];
});
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
