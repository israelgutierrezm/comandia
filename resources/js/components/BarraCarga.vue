<script setup>
import { computed } from 'vue';
import { peticionesEnVuelo } from '../api/progress';

/**
 * Barra de carga delgada, fija en el borde superior, mientras haya peticiones a la API en vuelo. Sustituye al texto
 * «Cargando…» de las pantallas: es el mismo lenguaje que la barra de navegación de Inertia, pero para los datos.
 */
const activa = computed(() => peticionesEnVuelo.value > 0);
</script>

<template>
    <div class="barra-carga" :class="{ 'barra-carga--activa': activa }" role="progressbar" aria-hidden="true">
        <span class="barra-carga__pulso" />
    </div>
</template>

<style scoped>
.barra-carga {
    position: fixed;
    inset: 0 0 auto 0;
    height: 3px;
    z-index: 200;
    overflow: hidden;
    opacity: 0;
    transition: opacity 0.25s ease;
    pointer-events: none;
}

.barra-carga--activa {
    opacity: 1;
}

.barra-carga__pulso {
    position: absolute;
    top: 0;
    bottom: 0;
    left: -40%;
    width: 40%;
    background: var(--color-acento);
    border-radius: 0 3px 3px 0;
    animation: barra-carga-desliza 1.1s ease-in-out infinite;
}

@keyframes barra-carga-desliza {
    0% { left: -40%; }
    100% { left: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .barra-carga__pulso { animation-duration: 2.2s; }
}
</style>
