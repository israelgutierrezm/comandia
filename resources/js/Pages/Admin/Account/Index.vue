<script setup>
import { computed, ref, useId } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import AppSessionsPanel from '../../../components/identity/AppSessionsPanel.vue';
import ConfirmDialog from '../../../components/organization/ConfirmDialog.vue';

/**
 * Mi cuenta (diseño de acceso): lo que es de la PERSONA y no del negocio.
 *
 * La cuenta es de la plataforma —una sola contraseña y las mismas sesiones para todos los negocios donde la persona
 * trabaja—, así que esta pantalla no depende del rol activo ni pide permisos: la ve cualquiera que haya entrado, y el
 * servidor actúa siempre sobre la cuenta de quien pide.
 *
 * - **Contraseña:** cambiarla conserva esta sesión y cierra todas las demás (fase 2).
 * - **Dispositivos con la app:** cada teléfono con la sesión abierta, en cualquiera de mis negocios, con «Cerrar».
 * - **Navegadores:** «Cerrar mis otras sesiones» sin lista —en producción las sesiones web viven donde no se pueden
 *   listar por persona—. Pide la contraseña: una sesión robada no puede echar a la dueña de su cuenta.
 */
const page = usePage();
const nombre = computed(() => page.props.context?.membership?.display_name ?? '');

// ---- Contraseña (fase 2) ----
//
// Conserva esta sesión y cierra todas las demás —navegadores y app, en todos mis negocios—: si la cambio porque sospecho
// algo, el otro queda fuera en ese momento. Diez caracteres o más, regla de la plataforma.
const clave = ref({ current_password: '', password: '', password_confirmation: '' });
const guardandoClave = ref(false);
const erroresClave = ref({});
const errorClave = ref(null);
const avisoClave = ref(null);
const idActual = useId();
const idNueva = useId();
const idRepetida = useId();

async function cambiarClave() {
    if (guardandoClave.value) {
        return;
    }

    guardandoClave.value = true;
    erroresClave.value = {};
    errorClave.value = null;
    avisoClave.value = null;

    try {
        await api.put('/me/password', clave.value);
        avisoClave.value = 'Contraseña cambiada. Esta sesión sigue abierta; en los demás navegadores y en la app se te pedirá entrar otra vez.';
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        if (e.isValidation) {
            erroresClave.value = e.fieldErrors ?? {};
        } else {
            errorClave.value = e.message;
        }
    } finally {
        guardandoClave.value = false;
        clave.value = { current_password: '', password: '', password_confirmation: '' };
    }
}

const cerrandoWeb = ref(false);
const password = ref('');
const procesandoWeb = ref(false);
const errorWeb = ref(null);
const avisoWeb = ref(null);
const idPassword = useId();

function preguntarWeb() {
    password.value = '';
    errorWeb.value = null;
    avisoWeb.value = null;
    cerrandoWeb.value = true;
}

async function cerrarOtrasWeb() {
    if (procesandoWeb.value || password.value === '') {
        errorWeb.value = password.value === '' ? 'Escribe tu contraseña actual.' : errorWeb.value;

        return;
    }

    procesandoWeb.value = true;
    errorWeb.value = null;

    try {
        await api.post('/me/sessions/close-other-web', { password: password.value });
        cerrandoWeb.value = false;
        avisoWeb.value = 'Listo: en los demás navegadores se te pedirá entrar otra vez. Esta sesión sigue abierta.';
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        errorWeb.value = e.fieldErrors?.password ?? e.message;
    } finally {
        procesandoWeb.value = false;
        password.value = '';
    }
}
</script>

<template>
    <Head title="Mi cuenta" />

    <header class="page-header">
        <h1>Mi cuenta</h1>
        <p class="page-header__hint">
            {{ nombre }}. Tu cuenta es la misma en todos los negocios donde trabajas: lo que cierres aquí se cierra en todos.
        </p>
    </header>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Contraseña</h2>
        <p class="page-header__hint">
            Es la misma para todos tus negocios. Al cambiarla, esta sesión sigue abierta y todas las demás se cierran.
        </p>

        <p v-if="avisoClave" class="alert alert--ok" role="status">{{ avisoClave }}</p>
        <p v-if="errorClave" class="alert" role="alert">{{ errorClave }}</p>

        <form class="clave" @submit.prevent="cambiarClave">
            <label class="field" :for="idActual">
                <span class="field__label">Contraseña actual</span>
                <input :id="idActual" v-model="clave.current_password" class="input" type="password" autocomplete="current-password" required />
                <span v-if="erroresClave.current_password" class="field__error">{{ erroresClave.current_password }}</span>
            </label>

            <label class="field" :for="idNueva">
                <span class="field__label">Contraseña nueva</span>
                <input :id="idNueva" v-model="clave.password" class="input" type="password" autocomplete="new-password" minlength="10" required />
                <span v-if="erroresClave.password" class="field__error">{{ erroresClave.password }}</span>
                <span v-else class="field__hint">10 caracteres o más.</span>
            </label>

            <label class="field" :for="idRepetida">
                <span class="field__label">Repite la nueva</span>
                <input :id="idRepetida" v-model="clave.password_confirmation" class="input" type="password" autocomplete="new-password" required />
            </label>

            <button type="submit" class="button" :disabled="guardandoClave">
                {{ guardandoClave ? 'Guardando…' : 'Cambiar contraseña' }}
            </button>
        </form>
    </section>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Dispositivos con la app</h2>
        <p class="page-header__hint">
            Cada teléfono o tableta donde tienes la app abierta. Si perdiste uno, ciérralo aquí: quien lo tenga tendrá que
            entrar con tu contraseña. Una sesión que nadie usa en 60 días se cierra sola.
        </p>

        <AppSessionsPanel list-url="/me/sessions" revoke-base="/me/sessions" mine />
    </section>

    <section class="tarjeta">
        <h2 class="tarjeta__titulo">Navegadores</h2>
        <p class="page-header__hint">
            Si entraste en una computadora que no es tuya y no saliste, cierra desde aquí tus sesiones en todos los demás
            navegadores. Ésta se queda abierta.
        </p>

        <p v-if="avisoWeb" class="alert alert--ok" role="status">{{ avisoWeb }}</p>

        <button type="button" class="button button--warning" @click="preguntarWeb">
            Cerrar mis otras sesiones
        </button>
    </section>

    <ConfirmDialog
        v-if="cerrandoWeb"
        title="¿Cerrar tus sesiones en los demás navegadores?"
        confirm-label="Cerrar las demás"
        processing-label="Cerrando…"
        icon="x"
        :processing="procesandoWeb"
        :error="errorWeb"
        @confirm="cerrarOtrasWeb"
        @cancel="cerrandoWeb = false"
    >
        <p>Para confirmar que eres tú, escribe tu contraseña actual.</p>
        <label class="field" :for="idPassword">
            <span class="field__label">Contraseña actual</span>
            <input
                :id="idPassword"
                v-model="password"
                class="input"
                type="password"
                autocomplete="current-password"
                required
                @keydown.enter.prevent="cerrarOtrasWeb"
            />
        </label>
    </ConfirmDialog>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.tarjeta {
    display: grid;
    gap: 0.75rem;
    justify-items: start;
    padding: 1.25rem;
    margin-bottom: 1.25rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-md, 0.75rem);
    background: var(--color-superficie);
}

.tarjeta > :deep(.sesiones) { justify-self: stretch; }

.tarjeta__titulo {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 650;
}

.tarjeta .page-header__hint { margin: 0; }

.clave {
    display: grid;
    gap: 0.25rem;
    width: min(24rem, 100%);
}

.clave .button { justify-self: start; }
</style>
