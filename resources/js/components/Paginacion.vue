<script setup>
import { computed } from 'vue';

/**
 * Paginación compartida de los listados. Lee el `meta` del paginador de Laravel y emite el cambio de página.
 *
 * No se pinta con una sola página: una barra de «Página 1 de 1» es ruido. La cuenta total usa el nombre del recurso
 * (`itemLabel`) para que diga «120 artículos» y no «120 registros».
 */
const props = defineProps({
    meta: { type: Object, default: () => ({}) },
    page: { type: Number, required: true },
    itemLabel: { type: String, default: 'registros' },
});

const emit = defineEmits(['update:page']);

const lastPage = computed(() => Number(props.meta.last_page ?? 1));
</script>

<template>
    <div v-if="lastPage > 1" class="pag">
        <button type="button" class="pag__btn" :disabled="page <= 1" @click="emit('update:page', page - 1)">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6" />
            </svg>
            Anterior
        </button>

        <span class="pag__info">
            Página {{ meta.current_page }} de {{ lastPage }}
            <span class="pag__total">· {{ meta.total }} {{ itemLabel }}</span>
        </span>

        <button type="button" class="pag__btn" :disabled="page >= lastPage" @click="emit('update:page', page + 1)">
            Siguiente
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
.pag {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    margin-top: 1rem;
    font-size: 0.85rem;
    color: var(--color-suave);
}
.pag__btn {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    padding: 0.35rem 0.7rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
}
.pag__btn:hover:not(:disabled) {
    border-color: var(--color-acento);
    color: var(--color-acento);
    background: color-mix(in srgb, var(--color-acento) 8%, transparent);
}
.pag__btn:disabled { opacity: 0.4; cursor: not-allowed; }
.pag__info { font-variant-numeric: tabular-nums; }
.pag__total { color: var(--color-suave); }

@media (max-width: 30rem) {
    .pag__total { display: none; }
}
</style>
