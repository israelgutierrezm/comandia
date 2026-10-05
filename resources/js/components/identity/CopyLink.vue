<script setup>
import { ref, useId } from 'vue';
import Icon from '../Icon.vue';

/**
 * Un enlace para copiar (diseño de acceso, fase 3): el de una invitación recién enviada.
 *
 * Se ve UNA vez —el servidor no lo vuelve a dar, guarda su hash—, para mandarlo por WhatsApp o en persona si el correo no
 * llega. Quien lo tenga puede aceptar la invitación, así que se dice.
 */
defineProps({
    link: { type: String, required: true },
});

const copiado = ref(false);
const id = useId();

async function copiar(link) {
    try {
        await navigator.clipboard.writeText(link);
        copiado.value = true;
        setTimeout(() => (copiado.value = false), 2500);
    } catch {
        // Sin permiso de portapapeles: el campo queda seleccionado para copiarlo a mano.
        document.getElementById(id)?.select();
    }
}
</script>

<template>
    <div class="copiar">
        <label class="field__label" :for="id">Enlace de la invitación</label>
        <div class="copiar__fila">
            <input :id="id" class="input" type="text" :value="link" readonly @focus="$event.target.select()" />
            <button type="button" class="button button--neutral" @click="copiar(link)">
                <Icon :name="copiado ? 'check' : 'copy'" /> {{ copiado ? 'Copiado' : 'Copiar' }}
            </button>
        </div>
        <span class="field__hint">
            Sólo se muestra ahora. Quien lo tenga puede aceptar la invitación: mándaselo sólo a esa persona.
        </span>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.copiar { display: grid; gap: 0.3rem; }
.copiar__fila { display: flex; gap: 0.5rem; }
.copiar__fila .input { flex: 1 1 auto; min-width: 0; font-family: var(--fuente-mono, monospace); font-size: 0.8rem; }
</style>
