<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import Icon from '../Icon.vue';

/**
 * Confirmación de una acción que no se deshace (dar de baja un área, quitar una regla de ruteo).
 *
 * ## Por qué no `window.confirm`
 *
 * Porque aquí la consecuencia es el contenido: cuántas reglas siguen apuntando al área, a dónde irá lo que decidía la
 * regla, qué pasa con las comandas sin terminar. Eso se lee mejor como lista que como un párrafo del navegador, y el
 * diálogo se queda abierto mientras corre la petición —botones deshabilitados— para mostrar el error ahí mismo, donde
 * la persona está mirando, en lugar de cerrarse y dejarlo en otro rincón de la pantalla.
 *
 * El foco arranca en «Cancelar»: en una acción destructiva, el Enter distraído no debe ejecutarla.
 */
const props = defineProps({
    title: { type: String, required: true },
    confirmLabel: { type: String, required: true },
    processingLabel: { type: String, default: 'Procesando…' },
    processing: { type: Boolean, default: false },

    // Por ejemplo, mientras se averigua la consecuencia: no se confirma lo que todavía no se ha podido leer.
    confirmDisabled: { type: Boolean, default: false },

    error: { type: String, default: null },
    icon: { type: String, default: 'trash' },
});

const emit = defineEmits(['confirm', 'cancel']);

const titleId = useId();
const bodyId = useId();
const dialog = ref(null);
const cancelButton = ref(null);

let previousFocus = null;

onMounted(() => {
    previousFocus = document.activeElement;
    nextTick(() => cancelButton.value?.focus());
});

// El foco vuelve a donde estaba (el botón que abrió el diálogo), si sigue en la página.
onBeforeUnmount(() => {
    if (previousFocus instanceof HTMLElement && document.contains(previousFocus)) {
        previousFocus.focus();
    }
});

function cancel() {
    // Con la petición en curso no se cierra: el resultado —o el error— tiene que verse aquí.
    if (!props.processing) {
        emit('cancel');
    }
}

/** El tabulador no sale del diálogo mientras está abierto (`aria-modal`). */
function keepFocusInside(event) {
    const focusables = [
        ...dialog.value.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])'),
    ];

    if (focusables.length === 0) {
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}
</script>

<template>
    <div class="drawer-backdrop" @click.self="cancel">
        <div
            ref="dialog"
            class="drawer confirm"
            role="alertdialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            :aria-describedby="bodyId"
            @keydown.esc.prevent="cancel"
            @keydown.tab="keepFocusInside"
        >
            <h2 :id="titleId" class="confirm__title">{{ props.title }}</h2>

            <div :id="bodyId" class="confirm__body">
                <slot />
            </div>

            <p v-if="props.error" class="alert" role="alert">{{ props.error }}</p>

            <div class="drawer__actions">
                <button ref="cancelButton" type="button" class="link-button" :disabled="props.processing" @click="cancel">
                    <Icon name="x" /> Cancelar
                </button>
                <button
                    type="button"
                    class="button button--danger"
                    :disabled="props.processing || props.confirmDisabled"
                    @click="emit('confirm')"
                >
                    <Icon :name="props.icon" /> {{ props.processing ? props.processingLabel : props.confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.confirm {
    width: min(28rem, 100%);
}

.confirm__title {
    margin: 0 0 0.9rem;
    font-size: 1.1rem;
    font-weight: 650;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.confirm__body {
    font-size: 0.9rem;
    line-height: 1.5;
    color: var(--color-contenido);
}

.confirm__body :deep(p) {
    margin: 0 0 0.75rem;
}

.confirm__body :deep(ul) {
    margin: 0 0 0.75rem;
    padding-left: 1.15rem;
}

.confirm__body :deep(li + li) {
    margin-top: 0.55rem;
}
</style>
