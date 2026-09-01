<script setup>
import { useToasts } from '../stores/useToasts';
import Icon from './Icon.vue';

/**
 * El único punto donde se pintan los toasts (ADR de UI, no de dominio): montado una vez en el layout, lee la cola
 * compartida de `useToasts`. Los mensajes los encola cualquiera con `pushToast`; aquí sólo se dibujan y se cierran.
 */
const { toasts, dismissToast } = useToasts();

const icono = (tipo) => (tipo === 'error' ? 'x' : tipo === 'info' ? 'eye' : 'check');
</script>

<template>
    <transition-group tag="div" name="toastr" class="toastr" aria-live="polite">
        <div
            v-for="t in toasts"
            :key="t.id"
            class="toastr__item"
            :class="`toastr__item--${t.type}`"
            :role="t.type === 'error' ? 'alert' : 'status'"
        >
            <span class="toastr__icono"><Icon :name="icono(t.type)" :size="16" /></span>
            <span class="toastr__texto">{{ t.text }}</span>
            <button type="button" class="toastr__x" aria-label="Cerrar" @click="dismissToast(t.id)">
                <Icon name="x" :size="14" />
            </button>
        </div>
    </transition-group>
</template>

<style scoped>
/* Arriba a la derecha: no choca con la barra inferior fija del POS ni con el pie de las pantallas. */
.toastr {
    position: fixed;
    top: 4.75rem;
    right: 1rem;
    z-index: 60;
    display: grid;
    gap: 0.5rem;
    width: min(22rem, calc(100vw - 2rem));
    pointer-events: none;
}
.toastr__item {
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.7rem 0.85rem;
    border-radius: 0.6rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-left: 3px solid var(--color-acento);
    box-shadow: 0 8px 24px rgb(0 0 0 / 0.14);
    color: var(--color-contenido);
    font-size: 0.9rem;
}
.toastr__item--ok { border-left-color: var(--color-exito); }
.toastr__item--error { border-left-color: var(--color-peligro); }
.toastr__item--info { border-left-color: var(--color-acento); }

.toastr__icono { display: grid; place-items: center; flex: none; }
.toastr__item--ok .toastr__icono { color: var(--color-exito); }
.toastr__item--error .toastr__icono { color: var(--color-peligro); }
.toastr__item--info .toastr__icono { color: var(--color-acento); }

.toastr__texto { flex: 1; min-width: 0; font-weight: 550; }

.toastr__x {
    flex: none;
    display: grid;
    place-items: center;
    width: 1.5rem;
    height: 1.5rem;
    border: 0;
    border-radius: 0.4rem;
    background: transparent;
    color: var(--color-suave);
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.toastr__x:hover { background: color-mix(in srgb, var(--color-contenido) 8%, transparent); color: var(--color-contenido); }

.toastr-enter-active, .toastr-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.toastr-enter-from, .toastr-leave-to { opacity: 0; transform: translateX(1.5rem); }
/* Al salir, se saca del flujo para que los de abajo suban sin salto. */
.toastr-leave-active { position: absolute; right: 0; width: 100%; }

@media (prefers-reduced-motion: reduce) {
    .toastr-enter-active, .toastr-leave-active { transition: opacity 0.2s ease; }
    .toastr-enter-from, .toastr-leave-to { transform: none; }
}
</style>
