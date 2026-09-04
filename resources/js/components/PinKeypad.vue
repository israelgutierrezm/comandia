<script setup>
import { computed } from 'vue';

/**
 * Teclado numérico en pantalla para capturar un PIN (D54: 4–6 dígitos).
 *
 * Sólo CAPTURA: llena el mismo valor que un `input`, y quien decide sigue siendo el servidor. Por eso no sabe de
 * autorizaciones ni de quién autoriza; recibe un `v-model` y avisa cuando el PIN está listo para enviarse.
 *
 * Envío híbrido, acordado para respetar la longitud variable: **autoenvío al llegar al máximo** (6 dígitos, no hay
 * más que teclear) y **Aceptar** habilitado a partir del mínimo (4–5). Por debajo del mínimo no se puede enviar.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    min: { type: Number, default: 4 },
    max: { type: Number, default: 6 },
    /** Bloquea el teclado mientras hay una petición en vuelo, para no enviar dos veces. */
    procesando: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'submit']);

const teclas = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

const puntos = computed(() => Array.from({ length: props.max }, (_, i) => i < props.modelValue.length));
const puedeAceptar = computed(() => props.modelValue.length >= props.min);

function picar(digito) {
    if (props.procesando || props.modelValue.length >= props.max) {
        return;
    }

    const nuevo = props.modelValue + digito;
    emit('update:modelValue', nuevo);

    // Autoenvío al máximo: con 6 dígitos ya no queda nada que teclear.
    if (nuevo.length >= props.max) {
        emit('submit');
    }
}

function borrar() {
    if (props.procesando || props.modelValue.length === 0) {
        return;
    }

    emit('update:modelValue', props.modelValue.slice(0, -1));
}

function aceptar() {
    if (props.procesando || ! puedeAceptar.value) {
        return;
    }

    emit('submit');
}
</script>

<template>
    <div class="keypad">
        <!-- Lo capturado, enmascarado: se ve cuántos dígitos llevas, no cuáles. -->
        <div class="keypad__display" role="status" :aria-label="`${modelValue.length} dígitos de ${max}`">
            <span
                v-for="(lleno, i) in puntos"
                :key="i"
                class="keypad__punto"
                :class="{ 'keypad__punto--lleno': lleno }"
            />
        </div>

        <div class="keypad__grid">
            <button
                v-for="d in teclas"
                :key="d"
                type="button"
                class="keypad__tecla"
                :disabled="procesando"
                @click="picar(d)"
            >
                {{ d }}
            </button>

            <button
                type="button"
                class="keypad__tecla keypad__tecla--sec"
                :disabled="procesando || modelValue.length === 0"
                aria-label="Borrar"
                @click="borrar"
            >
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 6h11a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-6-6z" />
                    <path stroke-linecap="round" d="M18 9.5l-5 5M13 9.5l5 5" />
                </svg>
            </button>

            <button
                type="button"
                class="keypad__tecla"
                :disabled="procesando"
                @click="picar('0')"
            >
                0
            </button>

            <button
                type="button"
                class="keypad__tecla keypad__tecla--ok"
                :disabled="! puedeAceptar || procesando"
                aria-label="Aceptar"
                @click="aceptar"
            >
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7" />
                </svg>
            </button>
        </div>
    </div>
</template>

<style scoped>
.keypad {
    display: grid;
    gap: 1rem;
    justify-items: center;
}

.keypad__display {
    display: flex;
    gap: 0.6rem;
    min-height: 1.2rem;
    align-items: center;
}
.keypad__punto {
    width: 0.85rem;
    height: 0.85rem;
    border-radius: 50%;
    border: 2px solid color-mix(in srgb, var(--color-suave) 55%, transparent);
    background: transparent;
    transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.12s ease;
}
.keypad__punto--lleno {
    background: var(--color-acento);
    border-color: var(--color-acento);
    transform: scale(1.05);
}

.keypad__grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.7rem;
    width: 100%;
    max-width: 20rem;
}
.keypad__tecla {
    display: grid;
    place-items: center;
    min-height: 3.75rem;
    font-size: 1.6rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--color-contenido);
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    box-shadow: var(--sombra-sm);
    cursor: pointer;
    user-select: none;
    transition: transform 0.08s ease, border-color 0.12s ease, background-color 0.12s ease;
}
.keypad__tecla:hover:not(:disabled) {
    border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde));
}
.keypad__tecla:active:not(:disabled) {
    transform: translateY(1px) scale(0.98);
    background: color-mix(in srgb, var(--color-acento) 10%, var(--color-superficie));
}
.keypad__tecla:disabled {
    opacity: 0.45;
    cursor: default;
    box-shadow: none;
}
.keypad__tecla--sec { color: var(--color-suave); }
.keypad__tecla--ok {
    color: #fff;
    background: var(--color-acento);
    border-color: var(--color-acento);
}
.keypad__tecla--ok:hover:not(:disabled) {
    background: color-mix(in srgb, var(--color-acento) 88%, #000);
    border-color: color-mix(in srgb, var(--color-acento) 88%, #000);
}
.keypad__tecla--ok:disabled { background: var(--color-superficie); color: var(--color-suave); border-color: var(--color-borde); }

@media (prefers-reduced-motion: reduce) {
    .keypad__tecla:active:not(:disabled) { transform: none; }
    .keypad__punto--lleno { transform: none; }
}
</style>
