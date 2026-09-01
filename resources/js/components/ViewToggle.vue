<script setup>
import { onMounted } from 'vue';

/**
 * Alternador lista / cuadrícula (referencia Acadion). Un control segmentado de dos iconos.
 *
 * La preferencia se recuerda por navegador con `persistKey`: quien prefiere la cuadrícula la vuelve a encontrar al
 * regresar a la pantalla, sin que sea un ajuste del negocio.
 */
const props = defineProps({
    modelValue: { type: String, default: 'list' },
    persistKey: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

function set(vista) {
    emit('update:modelValue', vista);

    if (props.persistKey) {
        try {
            localStorage.setItem(props.persistKey, vista);
        } catch {
            // Almacenamiento bloqueado (modo privado): el toggle funciona igual, sólo no se recuerda.
        }
    }
}

onMounted(() => {
    if (! props.persistKey) {
        return;
    }

    try {
        const guardado = localStorage.getItem(props.persistKey);
        if ((guardado === 'list' || guardado === 'grid') && guardado !== props.modelValue) {
            emit('update:modelValue', guardado);
        }
    } catch {
        // Sin memoria del navegador: se queda con el valor por omisión.
    }
});
</script>

<template>
    <div class="vt" role="group" aria-label="Vista de la lista">
        <button
            type="button"
            class="vt__btn"
            :class="{ 'vt__btn--on': modelValue === 'list' }"
            :aria-pressed="modelValue === 'list'"
            title="Vista de lista"
            @click="set('list')"
        >
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01" />
            </svg>
        </button>
        <button
            type="button"
            class="vt__btn"
            :class="{ 'vt__btn--on': modelValue === 'grid' }"
            :aria-pressed="modelValue === 'grid'"
            title="Vista de cuadrícula"
            @click="set('grid')"
        >
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8">
                <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
                <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
                <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
                <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
.vt {
    display: inline-flex;
    flex: none;
    /* Sin esto, dentro de un toolbar `flex` el toggle se estira a la altura de los inputs (más altos) y el resalte
       activo no llega al borde inferior: asoma una línea del fondo. Centrado, el toggle mantiene su altura natural. */
    align-self: center;
    border: 1px solid var(--color-borde);
    border-radius: 0.55rem;
    overflow: hidden;
    background: var(--color-superficie);
}
.vt__btn {
    display: grid;
    place-items: center;
    width: 2.15rem;
    height: 2.15rem;
    border: 0;
    background: transparent;
    color: var(--color-suave);
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.vt__btn + .vt__btn { border-left: 1px solid var(--color-borde); }
.vt__btn:hover { color: var(--color-contenido); }
.vt__btn--on { background: color-mix(in srgb, var(--color-acento) 14%, transparent); color: var(--color-acento); }
</style>
