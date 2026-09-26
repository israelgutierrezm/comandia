<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import ListHeader from '../../../components/ListHeader.vue';

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
const loading = ref(true);
const loadError = ref(null);

const form = ref({ host: '', port: 587, encryption: 'tls', username: '', password: '', from_address: '', from_name: '' });

// Si la carga falla, el formulario NO se muestra: vacío se leería como «sin configurar», y guardarlo así pisaría la cuenta
// que sí existe.
onMounted(async () => {
    try {
        await load();
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
});

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
    // Se apaga el «enviado» de un intento anterior: si éste falla, no deben verse el éxito viejo y el error juntos.
    testSent.value = false;
    await api.post('/mail-settings/test', { email: testEmail.value });
    testSent.value = true;
    await load();
});
</script>

<template>
    <Head title="Correo" />

    <div class="correo">
        <ListHeader
            title="Correo del negocio"
            subtitle="La cuenta con la que se envían los avisos y los reportes programados."
        />

        <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
        <p v-else-if="loading" class="nota">Cargando…</p>

        <template v-else>
            <section class="panel">
                <p class="nota gmail">
                    Si usas <strong>Gmail</strong>, necesitas una <strong>Contraseña de aplicación</strong> (no tu contraseña
                    normal): actívala en tu cuenta de Google con la verificación en dos pasos encendida.
                </p>

                <div class="presets">
                    <button type="button" class="link-button" @click="usarGmail">Usar Gmail</button>
                    <span v-if="configured" class="badge" :class="verifiedAt ? 'badge--ok' : 'badge--warn'">
                        {{ verifiedAt ? 'Configurado · verificado' : 'Configurado · sin verificar' }}
                    </span>
                </div>

                <form @submit.prevent="save.submit()">
                    <p v-if="save.generalError.value" class="alert" role="alert">{{ save.generalError.value }}</p>

                    <!-- El detalle de cada falla va junto a su campo; arriba, el resumen. -->
                    <div class="fila">
                        <label>Servidor (host) <input v-model="form.host" type="text" required placeholder="smtp.gmail.com" />
                            <span v-if="save.fieldErrors.value.host" class="field__error">{{ save.fieldErrors.value.host }}</span>
                        </label>
                        <label>Puerto <input v-model.number="form.port" type="number" required />
                            <span v-if="save.fieldErrors.value.port" class="field__error">{{ save.fieldErrors.value.port }}</span>
                        </label>
                        <label>Cifrado
                            <select v-model="form.encryption">
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">Ninguno</option>
                            </select>
                            <span v-if="save.fieldErrors.value.encryption" class="field__error">{{ save.fieldErrors.value.encryption }}</span>
                        </label>
                    </div>
                    <label>Usuario <input v-model="form.username" type="text" required placeholder="ventas@tunegocio.com" />
                        <span v-if="save.fieldErrors.value.username" class="field__error">{{ save.fieldErrors.value.username }}</span>
                    </label>
                    <label>
                        Contraseña <input v-model="form.password" type="password" :placeholder="configured ? 'Sin cambios' : ''" :required="! configured" autocomplete="new-password" />
                        <span v-if="save.fieldErrors.value.password" class="field__error">{{ save.fieldErrors.value.password }}</span>
                    </label>
                    <div class="fila">
                        <label>Remitente (correo) <input v-model="form.from_address" type="email" required />
                            <span v-if="save.fieldErrors.value.from_address" class="field__error">{{ save.fieldErrors.value.from_address }}</span>
                        </label>
                        <label>Remitente (nombre) <input v-model="form.from_name" type="text" required />
                            <span v-if="save.fieldErrors.value.from_name" class="field__error">{{ save.fieldErrors.value.from_name }}</span>
                        </label>
                    </div>

                    <button type="submit" class="button" :disabled="save.processing.value">
                        {{ save.processing.value ? 'Guardando…' : 'Guardar' }}
                    </button>
                </form>
            </section>

            <section v-if="configured" class="panel">
                <h2>Probar</h2>
                <p class="nota">Envía un correo de prueba para confirmar que la configuración funciona.</p>
                <form class="prueba" @submit.prevent="sendTest.submit()">
                    <label>Enviar a <input v-model="testEmail" type="email" required placeholder="tu@correo.com" /></label>
                    <button type="submit" class="button" :disabled="sendTest.processing.value">
                        {{ sendTest.processing.value ? 'Enviando…' : 'Enviar prueba' }}
                    </button>
                </form>
                <p v-if="testSent" class="alert alert--ok resultado" role="status">Correo de prueba enviado. Revisa la bandeja.</p>
                <p v-if="sendTest.generalError.value" class="alert resultado" role="alert">{{ sendTest.generalError.value }}</p>
            </section>
        </template>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.correo { display: grid; gap: 1rem; max-width: 44rem; }
/* En las rejillas el espacio lo pone el `gap`; el margen propio del aviso lo duplicaría. */
.correo > .alert, form .alert { margin: 0; }
.panel { background: var(--color-superficie); border: 1px solid var(--color-borde); border-radius: var(--radio-lg); box-shadow: var(--sombra-sm); padding: 1.15rem 1.25rem; }
/* El reset de Tailwind deja los encabezados al tamaño del texto: sin esto, «Probar» no se distinguía como título. */
.panel h2 { margin: 0 0 0.35rem; font-size: 1rem; font-weight: 650; }
.gmail { margin: 0 0 0.75rem; }
.presets { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
form { display: grid; gap: 0.6rem; }
/* Un botón como hijo directo de la rejilla se estiraría a todo el ancho. */
form > .button { justify-self: start; }
.fila { display: flex; gap: 0.75rem; }
.fila label { flex: 1; }
label { display: grid; gap: 0.2rem; font-size: 0.85rem; }
.field__error { margin-top: 0; }
.prueba { display: flex; gap: 0.75rem; align-items: flex-end; margin-top: 0.75rem; }
.resultado { margin: 0.75rem 0 0; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
</style>
