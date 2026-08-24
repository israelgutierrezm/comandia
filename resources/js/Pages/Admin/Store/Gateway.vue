<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

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

const form = ref({ active_gateway: '', public_key: '', secret_key: '', webhook_secret: '' });
const hasSecretKey = ref(false);
const hasWebhookSecret = ref(false);
const error = ref(null);
const saved = ref(false);
const saving = ref(false);

onMounted(async () => {
    const { data } = await api.get('/payment-gateway');
    if (data) {
        form.value.active_gateway = data.active_gateway ?? '';
        form.value.public_key = data.public_key ?? '';
        hasSecretKey.value = data.has_secret_key;
        hasWebhookSecret.value = data.has_webhook_secret;
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

    <div class="pasarela">
        <h1>Pasarela de pago</h1>
        <p class="nota">
            Con qué pasarela cobra tu tienda en línea. Sólo una a la vez. Las credenciales se guardan cifradas y no se
            vuelven a mostrar: deja un secreto en blanco para conservar el que ya guardaste.
        </p>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="saved" class="ok">Guardado.</p>

        <div class="campos">
            <label>
                Pasarela activa
                <select v-model="form.active_gateway">
                    <option value="">— Sin pasarela —</option>
                    <option v-for="g in GATEWAYS" :key="g.value" :value="g.value">{{ g.label }}</option>
                </select>
            </label>

            <label>
                Llave pública
                <input v-model="form.public_key" type="text" maxlength="255" placeholder="pk_..." autocomplete="off" />
            </label>

            <label>
                Llave secreta
                <input v-model="form.secret_key" type="password" maxlength="500" autocomplete="off"
                       :placeholder="hasSecretKey ? '•••••• (guardada)' : 'sk_...'" />
            </label>

            <label>
                Secreto del webhook
                <input v-model="form.webhook_secret" type="password" maxlength="500" autocomplete="off"
                       :placeholder="hasWebhookSecret ? '•••••• (guardado)' : 'whsec_...'" />
            </label>
        </div>

        <div class="acciones">
            <button type="button" :disabled="saving" @click="save">Guardar</button>
        </div>
    </div>
</template>

<style scoped>
.pasarela { display: grid; gap: 1rem; max-width: 40rem; }
.pasarela h1 { margin: 0; }
.nota { color: #555; font-size: 0.9rem; margin: 0; max-width: 34rem; }
.error { color: #a11; }
.ok { color: #166534; }
.campos { display: grid; gap: 0.75rem; }
.campos label { display: grid; gap: 0.2rem; font-size: 0.85rem; max-width: 24rem; }
.campos input, .campos select { padding: 0.35rem 0.5rem; border: 1px solid #d6d3d1; border-radius: 4px; font: inherit; }
.acciones { display: flex; gap: 1rem; align-items: center; }
</style>
