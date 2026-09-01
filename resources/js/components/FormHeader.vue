<script setup>
import { computed, inject, ref } from 'vue';
import { ICON_PATHS } from '../icons';

/**
 * Encabezado de un formulario (alta o edición): el icono de la entidad que se está creando + el título.
 *
 * Reutiliza el MISMO lenguaje del encabezado de listado (`ListHeader`): el icono en un chip tenue con el color del
 * tema, para que la ficha y su listado se sientan la misma pantalla. Y como el icono se deriva de la sección activa
 * —inyectada por el shell, igual que en `ListHeader`—, un formulario dentro de «Etiquetas» toma el de etiqueta sin
 * pasar nada: reemplazar `<h2>Título</h2>` por `<FormHeader title="Título" />` basta. `icon` sólo hace falta cuando el
 * formulario crea algo distinto de su sección.
 */
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },

    // Nombre de un trazo de `../icons`. Vacío = el de la sección activa.
    icon: { type: String, default: '' },
});

const iconoSeccion = inject('seccionActivaIcono', ref('dot'));
const iconPath = computed(() => ICON_PATHS[props.icon || iconoSeccion.value] ?? ICON_PATHS.dot);
</script>

<template>
    <header class="fh">
        <span class="fh__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath" />
            </svg>
        </span>
        <div class="fh__texto">
            <h2 class="fh__title">{{ title }}</h2>
            <p v-if="subtitle" class="fh__sub">{{ subtitle }}</p>
        </div>
    </header>
</template>

<style scoped>
.fh { display: flex; align-items: center; gap: 0.75rem; margin: 0 0 0.35rem; }
.fh__icon {
    flex: none;
    display: grid;
    place-items: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.7rem;
    color: var(--color-acento);
    background: color-mix(in srgb, var(--color-acento) 12%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-acento) 22%, transparent);
}
.fh__texto { min-width: 0; }
.fh__title { margin: 0; font-size: 1.15rem; font-weight: 650; letter-spacing: -0.01em; }
.fh__sub { margin: 0.1rem 0 0; font-size: 0.82rem; color: var(--color-suave); }
</style>
