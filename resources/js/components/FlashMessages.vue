<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const success = computed(() => page.props.flash?.success);
const error = computed(() => page.props.flash?.error);
</script>

<template>
    <div v-if="success || error" class="flash" :class="error ? 'flash--error' : 'flash--success'">
        {{ error ?? success }}
    </div>
</template>

<style scoped>
.flash {
    margin: 1rem 1.5rem 0;
    padding: 0.7rem 1rem;
    border-radius: var(--radio);
    font-size: 0.9rem;
}

/* Los tintes semánticos no cambian con el tema: son claros siempre. Sin un color de texto propio heredaba el del tema,
   y en el oscuro quedaba texto claro sobre tinte claro. El texto es el tono oscuro de cada semántico (`-texto`): el
   verde o rojo puros sobre su tinte apenas dan 3:1. */
.flash--success {
    background: var(--color-exito-tenue);
    border: 1px solid color-mix(in srgb, var(--color-exito) 40%, transparent);
    color: var(--color-exito-texto);
}

.flash--error {
    background: var(--color-peligro-tenue);
    border: 1px solid color-mix(in srgb, var(--color-peligro) 40%, transparent);
    color: var(--color-peligro-texto);
}
</style>
