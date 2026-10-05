<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../api/client';
import { formatInBranchTime } from '../../support/datetime';
import Icon from '../Icon.vue';
import ConfirmDialog from '../organization/ConfirmDialog.vue';

/**
 * Las sesiones de la app de una persona: qué teléfono, en qué negocio, cuándo entró y cuándo se usó por última vez, con
 * «Cerrar» por cada una (diseño de acceso, fase 1).
 *
 * Sirve a dos pantallas con el mismo dibujo y distinto alcance, que decide el SERVIDOR:
 *
 * - **«Mi cuenta»** (`/me/sessions`): las mías, en todos mis negocios, más «Cerrar todas».
 * - **La ficha de una persona** (`/memberships/{ulid}/app-sessions`): las suyas en ESTE negocio, con el permiso de
 *   suspender. El negocio no se pinta: es éste.
 *
 * Cerrar una sesión la revoca en el servidor: la siguiente petición de ese teléfono pide entrar otra vez.
 */
const props = defineProps({
    /** URL de la lista (relativa a /api/v1). */
    listUrl: { type: String, required: true },

    /** Base para cerrar una: se le agrega `/{ulid}`. */
    revokeBase: { type: String, required: true },

    /** «Mi cuenta» pinta el negocio de cada sesión y ofrece «Cerrar todas». */
    mine: { type: Boolean, default: false },

    /** Si quien mira puede cerrar (en la ficha, con el permiso de suspender). */
    canRevoke: { type: Boolean, default: true },
});

const page = usePage();
const zona = computed(() => page.props.context?.branch_timezone ?? null);

const sesiones = ref([]);
const cargando = ref(true);
const error = ref(null);

const cerrando = ref(null); // la sesión a confirmar, o 'todas'
const procesando = ref(false);
const errorCerrar = ref(null);
const aviso = ref(null);

async function cargar() {
    cargando.value = true;
    error.value = null;

    try {
        sesiones.value = (await api.get(props.listUrl))?.data ?? [];
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e.message;
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);

function preguntar(objetivo) {
    errorCerrar.value = null;
    aviso.value = null;
    cerrando.value = objetivo;
}

const tituloConfirmacion = computed(() => {
    if (cerrando.value === 'todas') {
        return '¿Cerrar todas tus sesiones de la app?';
    }

    return cerrando.value ? `¿Cerrar la sesión de «${cerrando.value.device_name}»?` : '';
});

async function confirmar() {
    procesando.value = true;
    errorCerrar.value = null;

    try {
        if (cerrando.value === 'todas') {
            const respuesta = await api.delete(props.listUrl);
            const cerradas = respuesta?.data?.closed ?? 0;
            aviso.value = cerradas === 1 ? 'Se cerró 1 sesión de la app.' : `Se cerraron ${cerradas} sesiones de la app.`;
        } else {
            await api.delete(`${props.revokeBase}/${cerrando.value.ulid}`);
            aviso.value = `Se cerró la sesión de «${cerrando.value.device_name}».`;
        }

        cerrando.value = null;
        await cargar();
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        errorCerrar.value = e.message;
    } finally {
        procesando.value = false;
    }
}

function fecha(iso) {
    return formatInBranchTime(iso, zona.value) || '—';
}
</script>

<template>
    <div class="sesiones">
        <p v-if="aviso" class="alert alert--ok" role="status">{{ aviso }}</p>

        <p v-if="cargando" class="page-header__hint">Cargando…</p>
        <p v-else-if="error" class="alert" role="alert">{{ error }}</p>

        <p v-else-if="sesiones.length === 0" class="muted">
            {{ mine ? 'No tienes la app abierta en ningún dispositivo.' : 'No tiene la app abierta en ningún dispositivo de este negocio.' }}
        </p>

        <ul v-else class="filas">
            <li v-for="s in sesiones" :key="s.ulid" class="fila">
                <div class="fila__datos">
                    <span class="fila__titulo">
                        {{ s.device_name }}
                        <span v-if="s.is_current" class="badge badge--ok">Éste</span>
                    </span>
                    <span class="fila__meta">
                        <template v-if="mine && s.business">{{ s.business.name }} · </template>
                        Entró el {{ fecha(s.created_at) }} · último uso: {{ s.last_used_at ? fecha(s.last_used_at) : 'nunca' }}
                    </span>
                    <span class="fila__meta muted">Se cierra sola el {{ fecha(s.closes_idle_at) }} si nadie la usa.</span>
                </div>

                <button
                    v-if="canRevoke"
                    type="button"
                    class="button button--neutral"
                    @click="preguntar(s)"
                >
                    <Icon name="x" /> Cerrar
                </button>
            </li>
        </ul>

        <button
            v-if="mine && sesiones.length > 1"
            type="button"
            class="button button--warning"
            @click="preguntar('todas')"
        >
            Cerrar todas
        </button>

        <ConfirmDialog
            v-if="cerrando"
            :title="tituloConfirmacion"
            confirm-label="Cerrar"
            processing-label="Cerrando…"
            icon="x"
            :processing="procesando"
            :error="errorCerrar"
            @confirm="confirmar"
            @cancel="cerrando = null"
        >
            <p v-if="cerrando === 'todas'">
                Cada teléfono te pedirá entrar otra vez, en todos tus negocios<template v-if="sesiones.some((s) => s.is_current)">, incluido éste</template>.
            </p>
            <p v-else>
                La próxima vez que se use, la app pedirá entrar otra vez. Si el teléfono se perdió, nadie más podrá usarlo
                con esta sesión.
            </p>
        </ConfirmDialog>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.sesiones { display: grid; gap: 0.75rem; justify-items: start; }
.sesiones > .filas { justify-self: stretch; }

.filas {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.4rem;
}

.fila {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    padding: 0.55rem 0.75rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-sm);
    background: var(--color-superficie);
    font-size: 0.9rem;
}

.fila__datos { display: grid; gap: 0.15rem; flex: 1 1 14rem; min-width: 0; }
.fila__titulo { font-weight: 600; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.fila__meta { font-size: 0.82rem; color: var(--color-suave); }
.muted { color: var(--color-suave); }
</style>
