<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import ListHeader from '../../../components/ListHeader.vue';

/**
 * Configuración de la pasarela de pago (Iteración 8, Tanda C, ADR-007/D49). Es el secreto financiero del negocio: exige
 * `ecommerce.gateways.configure`, el permiso más restringido, fuera hasta del gerente (§10.4). Una pasarela activa a la
 * vez; los secretos se cifran en reposo y NUNCA vuelven por la API —por eso el formulario nace vacío y un secreto en
 * blanco conserva el guardado, igual que el correo de la It.7—.
 */
const GATEWAYS = [
    { value: 'mercadopago', label: 'Mercado Pago' },
    { value: 'stripe', label: 'Stripe' },
    { value: 'fake', label: 'Simulada (pruebas)' },
];

// Las que ofrece este despliegue (`meta.available_gateways`): la simulada no cobra, y en producción viene apagada.
const available = ref([]);
const gatewayOptions = computed(() => GATEWAYS.filter((g) => available.value.includes(g.value)));

const form = ref({ active_gateway: '', public_key: '', secret_key: '', webhook_secret: '' });
const hasSecretKey = ref(false);
const hasWebhookSecret = ref(false);
const error = ref(null);
const saved = ref(false);
const saving = ref(false);
// Guardar exige haber leído lo guardado: con la lectura fallida el formulario queda en «— Sin pasarela —», y
// guardarlo así apagaría la pasarela que sí está configurada.
const loaded = ref(false);
const loadError = ref(null);

onMounted(async () => {
    try {
        const { data, meta } = await api.get('/payment-gateway');
        available.value = meta?.available_gateways ?? GATEWAYS.map((g) => g.value).filter((v) => v !== 'fake');
        if (data) {
            form.value.active_gateway = data.active_gateway ?? '';
            form.value.public_key = data.public_key ?? '';
            hasSecretKey.value = data.has_secret_key;
            hasWebhookSecret.value = data.has_webhook_secret;
        }
        loaded.value = true;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    }
});

async function save() {
    saving.value = true;
    error.value = null;
    saved.value = false;
    try {
        const { data } = await api.put('/payment-gateway', form.value);
        hasSecretKey.value = data.has_secret_key;
        hasWebhookSecret.value = data.has_webhook_secret;
        form.value.secret_key = '';       // nunca se relee; el campo vuelve a vacío
        form.value.webhook_secret = '';
        form.value.public_key = data.public_key ?? '';
        saved.value = true;
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Pasarela de pago" />

    <div class="pasarela animar-entrada">
        <ListHeader
            title="Pasarela de pago"
            subtitle="Con qué pasarela cobra tu tienda en línea. Sólo una a la vez. Las credenciales se guardan cifradas y no se vuelven a mostrar: deja un secreto en blanco para conservar el que ya guardaste."
        />

        <p v-if="loadError" class="alert" role="alert">
            No se pudo leer la configuración guardada, así que Guardar queda desactivado para no sobrescribirla con un
            formulario vacío. Recarga la página para intentarlo de nuevo. Detalle: {{ loadError }}
        </p>
        <p v-if="error" class="alert" role="alert">{{ error }}</p>
        <p v-else-if="saved" class="alert alert--ok" role="status">Cambios guardados.</p>

        <section class="tarjeta bloque">
            <div class="field">
                <label class="field__label" for="gw-active">Pasarela activa</label>
                <select id="gw-active" v-model="form.active_gateway" class="input">
                    <option value="">— Sin pasarela —</option>
                    <option v-for="g in gatewayOptions" :key="g.value" :value="g.value">{{ g.label }}</option>
                </select>
            </div>

            <div class="field">
                <label class="field__label" for="gw-public">Llave pública</label>
                <input id="gw-public" v-model="form.public_key" class="input" type="text" maxlength="255" placeholder="pk_..." autocomplete="off" />
            </div>

            <div class="field">
                <label class="field__label" for="gw-secret">Llave secreta</label>
                <input id="gw-secret" v-model="form.secret_key" class="input" type="password" maxlength="500" autocomplete="off"
                       :placeholder="hasSecretKey ? '•••••• (guardada)' : 'sk_...'" />
            </div>

            <div class="field">
                <label class="field__label" for="gw-webhook">Secreto del webhook</label>
                <input id="gw-webhook" v-model="form.webhook_secret" class="input" type="password" maxlength="500" autocomplete="off"
                       :placeholder="hasWebhookSecret ? '•••••• (guardado)' : 'whsec_...'" />
            </div>
        </section>

        <div class="acciones">
            <button type="button" class="button" :disabled="saving || !loaded" @click="save">
                {{ saving ? 'Guardando…' : 'Guardar' }}
            </button>
        </div>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.pasarela {
    display: grid;
    gap: 1rem;
    max-width: 40rem;
}

.bloque {
    display: grid;
    gap: 0.85rem;
    padding: 1.15rem;
    border: 1px solid var(--color-borde);
    max-width: 28rem;
}

.field {
    margin-bottom: 0;
}

.acciones {
    display: flex;
    gap: 0.85rem;
    align-items: center;
}
</style>
