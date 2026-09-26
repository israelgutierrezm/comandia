<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import Icon from '../Icon.vue';

/**
 * El marco común de las ventanas de operación de una cuenta del POS (dividir, juntar, mover de mesa, cancelar…).
 *
 * Una sola pieza para el velo, el título, la ✕ y la franja de botones, con el lenguaje de las ventanas que la pantalla de
 * la cuenta ya pintaba (cancelar un comandado, descuento): centrada, con el borde, el radio y la sombra de las tarjetas.
 * El velo es el del panel lateral compartido (`.drawer-backdrop`), para no inventar un color fuera de los tokens.
 *
 * - `formulario`: el panel es un `<form>` y la franja de botones queda DENTRO de él, así que Enter en un campo envía
 *   (`enviar`) y los `required` del navegador funcionan como en cualquier formulario.
 * - `bloqueada`: mientras una operación corre, ni la ✕, ni el velo, ni Escape la cierran. Cerrar a media escritura dejaría
 *   a quien opera sin saber si la operación se hizo.
 */
const props = defineProps({
    titulo: { type: String, required: true },
    /** 'chico' (una confirmación), 'normal' o 'amplio' (listas para elegir). */
    ancho: { type: String, default: 'normal' },
    formulario: { type: Boolean, default: false },
    bloqueada: { type: Boolean, default: false },
});

const emit = defineEmits(['cerrar', 'enviar']);

const idTitulo = useId();
const panel = ref(null);
let focoPrevio = null;

function cerrar() {
    if (! props.bloqueada) {
        emit('cerrar');
    }
}

onMounted(async () => {
    focoPrevio = document.activeElement;
    await nextTick();

    // Si la ventana no llevó el foco a su primer campo, va al panel: así Escape funciona desde el primer momento y el
    // lector de pantalla empieza por el título.
    if (panel.value && ! panel.value.contains(document.activeElement)) {
        panel.value.focus();
    }
});

// El foco vuelve a donde estaba si ese control sigue en la página: cerrar no debe dejar al teclado en el vacío.
onBeforeUnmount(() => {
    if (focoPrevio instanceof HTMLElement && focoPrevio.isConnected) {
        focoPrevio.focus();
    }
});
</script>

<template>
    <div class="drawer-backdrop pos-dialogo" @click.self="cerrar">
        <component
            :is="formulario ? 'form' : 'section'"
            ref="panel"
            class="pos-dialogo__panel"
            :class="`pos-dialogo__panel--${ancho}`"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="idTitulo"
            :aria-busy="bloqueada ? 'true' : 'false'"
            tabindex="-1"
            @keydown.esc.stop="cerrar"
            @submit.prevent="emit('enviar')"
        >
            <header class="pos-dialogo__cab">
                <h2 :id="idTitulo">{{ titulo }}</h2>
                <button type="button" class="pos-dialogo__x" aria-label="Cerrar" :disabled="bloqueada" @click="cerrar">
                    <Icon name="x" :size="18" />
                </button>
            </header>

            <div class="pos-dialogo__cuerpo">
                <slot />
            </div>

            <footer v-if="$slots.acciones" class="pos-dialogo__acciones">
                <slot name="acciones" />
            </footer>
        </component>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

/* Centrada, como las ventanas de la cuenta; del panel lateral compartido sólo se toma el velo. */
.pos-dialogo {
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.pos-dialogo__panel {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 30rem;
    max-height: 90vh;
    max-height: 90dvh;
    margin: 0;
    background: var(--color-superficie);
    color: var(--color-contenido);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-lg);
    outline: none;
    /* Arranca visible (0.6) y no en cero: si el navegador no pinta la animación, la ventana igual se ve. */
    animation: pos-dialogo-entra 0.22s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.pos-dialogo__panel--chico { max-width: 24rem; }
.pos-dialogo__panel--amplio { max-width: 38rem; }

.pos-dialogo__cab {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1rem 0.5rem 1.25rem;
}

.pos-dialogo__cab h2 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 650;
    letter-spacing: -0.01em;
}

/* La ✕ con el tamaño de un dedo (44 px): es lo primero que se busca para salir. */
.pos-dialogo__x {
    flex: none;
    display: grid;
    place-items: center;
    width: 2.75rem;
    height: 2.75rem;
    border: 0;
    border-radius: var(--radio);
    background: transparent;
    color: var(--color-suave);
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}

.pos-dialogo__x:hover:not(:disabled) {
    background: color-mix(in srgb, var(--color-contenido) 8%, transparent);
    color: var(--color-contenido);
}

.pos-dialogo__x:disabled { opacity: 0.45; cursor: not-allowed; }

/* El cuerpo es lo que se desplaza: la franja de botones queda siempre a la vista, aunque la lista sea larga. */
.pos-dialogo__cuerpo {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    display: grid;
    align-content: start;
    gap: 0.85rem;
    padding: 0.25rem 1.25rem 1rem;
}

.pos-dialogo__acciones {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.6rem;
    padding: 0.85rem 1.25rem 1.1rem;
    border-top: 1px solid var(--color-borde);
}

/* Los botones de la franja, táctiles (≈44 px) en todas las ventanas sin que cada una lo repita. */
.pos-dialogo__acciones :slotted(.button) {
    min-height: 2.75rem;
    padding: 0.55rem 1.05rem;
    font-size: 0.9rem;
}

@keyframes pos-dialogo-entra {
    from { opacity: 0.6; transform: translateY(8px); }
    to { opacity: 1; transform: none; }
}

@media (prefers-reduced-motion: reduce) {
    .pos-dialogo__panel { animation: none; }
}
</style>
