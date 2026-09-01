<script setup>
import { computed, inject, ref, useSlots } from 'vue';
import { ICON_PATHS } from '../icons';

/**
 * Encabezado de un listado, al estilo de Acadion: una TARJETA que reúne el encabezado (icono de la sección, título,
 * subtítulo y contador) y la barra de herramientas (Filtros, búsqueda, vista y la acción principal), con los filtros
 * escondidos tras un botón.
 *
 * ## Por qué una sola pieza y no encabezado + barra sueltos
 *
 * Antes el título, el botón de «Nuevo» y la barra de filtros eran tres bloques que cada pantalla acomodaba a mano. Reunir
 * las cuatro cosas —quién eres, cuántos hay, cómo buscas y qué puedes crear— en un recuadro las lee de un vistazo y hace
 * que TODAS las pantallas se vean igual sin que cada una repita el layout. El icono se deriva de la sección activa (lo
 * inyecta el shell), así que no hay que ponerlo pantalla por pantalla.
 *
 * ## Qué pone cada pantalla
 *
 * - `title` / `subtitle`: lo que antes eran el `<h1>` y su pista.
 * - `count`: el total; se pinta «N en total» a la derecha del encabezado. Sin `count`, sin contador (o usa el slot).
 * - `v-model:search`: si se enlaza, hay buscador; si no, no.
 * - slot `#filters`: los filtros que se esconden. Sin este slot no aparece el botón «Filtros».
 * - slot `#view`: el conmutador lista/cuadrícula. slot `#action`: el botón de acción (p. ej. «Nuevo»). Van a la derecha.
 * - `active-count` y `@clear`: cuántos filtros hay puestos y cómo limpiarlos.
 */
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    count: { type: [Number, String], default: null },
    // Nombre de un trazo de `../icons`. Vacío = usar el de la sección activa (inyectado por el shell).
    icon: { type: String, default: '' },
    search: { type: String, default: undefined },
    searchPlaceholder: { type: String, default: 'Buscar…' },
    activeCount: { type: Number, default: 0 },
    startOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['update:search', 'clear']);
const slots = useSlots();

const abierto = ref(props.startOpen);
const hasFilters = computed(() => !! slots.filters);

// Icono: el que imponga la pantalla, o el de la sección activa que inyecta el shell (`AdminLayout`).
const iconoSeccion = inject('seccionActivaIcono', ref('dot'));
const iconPath = computed(() => ICON_PATHS[props.icon || iconoSeccion.value] ?? ICON_PATHS.dot);
</script>

<template>
    <section class="lh">
        <div class="lh__head">
            <div class="lh__id">
                <span class="lh__icon" aria-hidden="true">
                    <slot name="icon">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath" />
                        </svg>
                    </slot>
                </span>

                <div class="lh__text">
                    <h1 class="lh__title">{{ title }}</h1>
                    <p v-if="subtitle" class="lh__sub">{{ subtitle }}</p>
                </div>
            </div>

            <slot name="count">
                <span v-if="count !== null" class="lh__count">{{ count }} en total</span>
            </slot>
        </div>

        <div class="lh__bar">
            <button
                v-if="hasFilters"
                type="button"
                class="button button--neutral lh__filtros"
                :class="{ 'lh__filtros--on': abierto || activeCount > 0 }"
                :aria-expanded="abierto"
                @click="abierto = ! abierto"
            >
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 8h16M4 16h16" />
                    <path stroke-linecap="round" d="M10 6v4M15 14v4" />
                </svg>
                Filtros
                <span v-if="activeCount > 0" class="lh__fcount">{{ activeCount }}</span>
            </button>

            <input
                v-if="props.search !== undefined"
                :value="props.search"
                type="search"
                class="input lh__search"
                :placeholder="searchPlaceholder"
                @input="emit('update:search', $event.target.value)"
            />

            <div v-if="!! slots.view || !! slots.action" class="lh__right">
                <span v-if="!! slots.view" class="lh__view"><slot name="view" /></span>
                <slot name="action" />
            </div>
        </div>

        <div v-if="hasFilters" v-show="abierto" class="lh__panel">
            <slot name="filters" />

            <button v-if="activeCount > 0" type="button" class="link-button lh__clear" @click="emit('clear')">
                Limpiar filtros
            </button>
        </div>
    </section>
</template>

<style scoped>
.lh {
    /* La tarjeta baja SIEMPRE debajo de las migajas, que flotan a la derecha del `main` (así lo eligió el usuario): sin
       esto, el contador del encabezado se encimaría con ellas. */
    clear: both;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.9rem;
    padding: 1rem 1.15rem;
    margin-bottom: 1.25rem;
}

.lh__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.lh__id {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    min-width: 0;
}

.lh__icon {
    flex: none;
    width: 2.5rem;
    height: 2.5rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: color-mix(in srgb, var(--color-acento) 12%, transparent);
    color: var(--color-acento);
}

.lh__text {
    min-width: 0;
}

.lh__title {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 600;
    letter-spacing: -0.01em;
    line-height: 1.2;
}

.lh__sub {
    margin: 0.15rem 0 0;
    font-size: 0.85rem;
    color: var(--color-suave);
    line-height: 1.45;
}

.lh__count {
    flex: none;
    white-space: nowrap;
    padding: 0.28rem 0.7rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-acento) 12%, transparent);
    color: var(--color-acento);
    font-size: 0.8rem;
    font-weight: 600;
}

.lh__bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
    margin-top: 0.9rem;
}

.lh__filtros {
    font-weight: 500;
}

.lh__filtros--on {
    border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde));
    color: var(--color-acento);
}

.lh__fcount {
    display: inline-grid;
    place-items: center;
    min-width: 1.15rem;
    height: 1.15rem;
    padding: 0 0.3rem;
    border-radius: 999px;
    background: var(--color-acento);
    color: var(--color-acento-texto, #fff);
    font-size: 0.7rem;
    font-weight: 700;
    line-height: 1;
}

.lh__search {
    flex: 1 1 16rem;
}

/* Vista + acción, siempre juntas a la derecha: con buscador porque éste se come el espacio; sin buscador, por el margen. */
.lh__right {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-left: auto;
}

.lh__panel {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
    margin-top: 0.8rem;
    padding-top: 0.8rem;
    border-top: 1px solid var(--color-borde);
}

.lh__clear {
    margin-left: auto;
}
</style>
