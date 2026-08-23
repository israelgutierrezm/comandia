<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';

/**
 * Configuración de correo del negocio (Tanda D1).
 *
 * Con qué cuenta SMTP/Gmail envía el negocio sus avisos y reportes programados. La contraseña se guarda cifrada y no
 * vuelve del servidor: si ya hay una, el campo se deja vacío y sólo se re-teclea para cambiarla.
 */
const configured = ref(false);
const verifiedAt = ref(null);
const testEmail = ref('');
const testSent = ref(false);

const form = ref({ host: '', port: 587, encryption: 'tls', username: '', password: '', from_address: '', from_name: '' });

onMounted(load);

async function load() {
    const { data } = await api.get('/mail-settings');
    configured.value = data.configured;
    verifiedAt.value = data.verified_at ?? null;

    if (data.configured) {
        form.value = {
            host: data.host, port: data.port, encryption: data.encryption,
            username: data.username, password: '', from_address: data.from_address, from_name: data.from_name,
        };
    }
}

/** Rellena los datos del servidor de Gmail; el usuario sólo pone su correo y su contraseña de aplicación. */
function usarGmail() {
    form.value.host = 'smtp.gmail.com';
    form.value.port = 587;
    form.value.encryption = 'tls';
}

const save = useApiForm(async () => {
    const cuerpo = { ...form.value };
    if (! cuerpo.password) delete cuerpo.password; // vacío = conservar la guardada
    await api.put('/mail-settings', cuerpo);
    await load();
});

const sendTest = useApiForm(async () => {
    await api.post('/mail-settings/test', { email: testEmail.value });
    testSent.value = true;
    await load();
});
</script>

<template>
    <Head title="Correo" />

    <div class="correo">
        <h1>Correo del negocio</h1>
        <p class="nota">
            Con qué cuenta se envían los avisos y los reportes programados. Si usas <strong>Gmail</strong>, necesitas una
            <strong>Contraseña de aplicación</strong> (no tu contraseña normal): actívala en tu cuenta de Google con la
            verificación en dos pasos encendida.
        </p>

        <section class="panel">
            <div class="presets">
                <button type="button" class="enlace" @click="usarGmail">Usar Gmail</button>
                <span v-if="configured" class="estado">
                    Configurado<template v-if="verifiedAt"> · verificado ✓</template><template v-else> · sin verificar</template>
                </span>
            </div>

            <form @submit.prevent="save.submit()">
                <div class="fila">
                    <label>Servidor (host) <input v-model="form.host" type="text" required placeholder="smtp.gmail.com" /></label>
                    <label>Puerto <input v-model.number="form.port" type="number" required /></label>
                    <label>Cifrado
                        <select v-model="form.encryption">
                            <option value="tls">TLS</option>
                            <option value="ssl">SSL</option>
                            <option value="none">Ninguno</option>
                        </select>
                    </label>
                </div>
                <label>Usuario <input v-model="form.username" type="text" required placeholder="ventas@tunegocio.com" /></label>
                <label>
                    Contraseña <input v-model="form.password" type="password" :placeholder="configured ? 'Sin cambios' : ''" :required="! configured" autocomplete="new-password" />
                </label>
                <div class="fila">
                    <label>Remitente (correo) <input v-model="form.from_address" type="email" required /></label>
                    <label>Remitente (nombre) <input v-model="form.from_name" type="text" required /></label>
                </div>

                <p v-if="save.generalError.value" class="error">{{ save.generalError.value }}</p>
                <div v-for="(msgs, campo) in save.fieldErrors.value" :key="campo" class="error">{{ msgs[0] }}</div>

                <button type="submit" :disabled="save.processing.value">Guardar</button>
            </form>
        </section>

        <section v-if="configured" class="panel">
            <h2>Probar</h2>
            <p class="nota">Envía un correo de prueba para confirmar que la configuración funciona.</p>
            <form class="prueba" @submit.prevent="sendTest.submit()">
                <label>Enviar a <input v-model="testEmail" type="email" required placeholder="tu@correo.com" /></label>
                <button type="submit" :disabled="sendTest.processing.value">Enviar prueba</button>
            </form>
            <p v-if="testSent" class="ok">Correo de prueba enviado. Revisa la bandeja.</p>
            <p v-if="sendTest.generalError.value" class="error">{{ sendTest.generalError.value }}</p>
        </section>
    </div>
</template>

<style scoped>
.correo { display: grid; gap: 1rem; max-width: 44rem; }
.correo h1 { margin: 0; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
.panel h2 { margin-top: 0; }
.presets { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.estado { font-size: 0.85rem; color: #137333; }
form { display: grid; gap: 0.6rem; }
.fila { display: flex; gap: 0.75rem; }
.fila label { flex: 1; }
label { display: grid; gap: 0.2rem; font-size: 0.85rem; }
.prueba { display: flex; gap: 0.75rem; align-items: flex-end; }
.nota { color: #555; font-size: 0.9rem; }
.ok { color: #137333; font-size: 0.9rem; }
.error { color: #a11; font-size: 0.9rem; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; padding: 0; font: inherit; }
</style>
