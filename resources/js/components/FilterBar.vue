<script setup>
import { computed, ref, useSlots } from 'vue';

/**
 * Barra de filtros de un listado, con los filtros ESCONDIDOS tras un botón (como en Acadion).
 *
 * ## Por qué ocultarlos
 *
 * Una pantalla que muestra siempre cinco selectores le grita al usuario opciones que casi nunca toca y le come el
 * espacio a lo que vino a ver: la lista. Lo que se usa a diario —la búsqueda y el cambio de vista— queda a la vista; el
 * resto vive en un panel que se abre con «Filtros». Para que esconderlos no oculte que ESTÁN aplicados, el botón lleva
 * un contador: la pantalla dice cuántos filtros están activos (`active-count`) y aquí se pinta.
 *
 * ## Qué pone cada pantalla
 *
 * - `v-model:search` (opcional): si se enlaza, se dibuja el buscador; si no, no hay buscador (hay listas que sólo
 *   filtran, como Auditoría). Se puede sustituir por completo con el slot `#search`.
 * - slot `#filters`: los selectores/casillas/fechas que se esconden. Sin este slot no aparece el botón «Filtros».
 * - slot `#view`: el conmutador lista/cuadrícula; queda SIEMPRE visible, a la derecha.
 * - `active-count` y `@clear`: cuántos filtros hay puestos y cómo limpiarlos (el botón «Limpiar» sólo aparece si hay).
 */
const props = defineProps({
    // `undefined` (no enlazado) = esta pantalla no tiene buscador. Cadena vacía = buscador vacío.
    search: { type: String, default: undefined },
    searchPlaceholder: { type: String, default: 'Buscar…' },
    activeCount: { type: Number, default: 0 },
    startOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['update:search', 'clear']);
const slots = useSlots();

const abierto = ref(props.startOpen);
const hasFilters = computed(() => !! slots.filters);
</script>

<template>
    <div class="fb">
        <div class="fb__row">
            <slot name="search">
                <input
                    v-if="props.search !== undefined"
                    :value="props.search"
                    type="search"
                    class="input fb__search"
                    :placeholder="searchPlaceholder"
                    @input="emit('update:search', $event.target.value)"
                />
            </slot>

            <button
                v-if="hasFilters"
                type="button"
                class="button button--neutral fb__btn"
                :class="{ 'fb__btn--on': abierto || activeCount > 0 }"
                :aria-expanded="abierto"
                @click="abierto = ! abierto"
            >
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16M7 12h10M10 19h4" />
                </svg>
                Filtros
                <span v-if="activeCount > 0" class="fb__count">{{ activeCount }}</span>
            </button>

            <span v-if="!! slots.view" class="fb__view"><slot name="view" /></span>
        </div>

        <div v-if="hasFilters" v-show="abierto" class="fb__panel">
            <slot name="filters" />

            <button v-if="activeCount > 0" type="button" class="link-button fb__clear" @click="emit('clear')">
                Limpiar filtros
            </button>
        </div>
    </div>
</template>

<style scoped>
.fb {
    margin-bottom: 1rem;
}

.fb__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
}

/* El buscador crece y empuja «Filtros» al borde; sin buscador el botón queda a la izquierda, que es donde se le
   busca. El conmutador de vista, cuando existe, siempre a la derecha del todo. */
.fb__search {
    flex: 1 1 18rem;
}

.fb__view {
    display: inline-flex;
    margin-left: auto;
}

.fb__btn {
    /* Un pelín más contenido que un botón de acción: es un control secundario. */
    font-weight: 500;
}

.fb__btn--on {
    border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde));
    color: var(--color-acento);
}

.fb__count {
    display: inline-grid;
    place-items: center;
    min-width: 1.15rem;
    height: 1.15rem;
    padding: 0 0.3rem;
    border-radius: 999px;
    background: var(--color-acento);
    color: var(--color-acento-contraste, #fff);
    font-size: 0.7rem;
    font-weight: 700;
    line-height: 1;
}

/* El panel replica el toolbar de antes: los mismos campos, ahora dentro. */
.fb__panel {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
    margin-top: 0.6rem;
    padding: 0.75rem 0.85rem;
    background: color-mix(in srgb, var(--color-suave) 5%, var(--color-superficie));
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
}

.fb__clear {
    margin-left: auto;
}
</style>
