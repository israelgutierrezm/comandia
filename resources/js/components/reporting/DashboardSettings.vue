<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { api, ApiError, getAllPages, orEmptyWhenForbidden } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';
import Icon from '../Icon.vue';

/**
 * Edición de un tablero por su AUTOR (D46): renombrarlo, publicarlo a un rol y eliminarlo, por
 * `PATCH/DELETE /dashboards/{ulid}`. El servidor exige `dashboards.dashboards.manage` escribible y que el tablero sea de
 * quien lo pide; quien monta esto ya comprobó las dos cosas.
 *
 * ## Renombrar y publicar viajan por separado
 *
 * El servidor pide `dashboards.dashboards.publish` en cuanto el cuerpo TRAE `published_role_ulid` —aunque no cambie— y
 * rechaza la petición entera. Si el nombre viajara junto, quien construye tableros pero no publica no podría ni
 * renombrarlos. Por eso cada bloque manda sólo su campo.
 *
 * ## Publicar
 *
 * Un tablero se publica a UN rol o a ninguno: lo ven quienes operan con ese rol ACTIVO, sin poder cambiarlo, y cada
 * indicador se calcula con los permisos de quien lo mira (ADR-006). Los roles salen de `GET /roles`, que exige
 * `identity.roles.view`: sin él no hay de dónde elegir y se dice así, en vez de pintar una lista vacía.
 */
const props = defineProps({ dashboard: { type: Object, required: true } });
const emit = defineEmits(['updated', 'close']);

const { can, canWrite } = useAuthorization();
const page = usePage();
const uid = useId();

// --- Nombre ---
const name = ref(props.dashboard.name);
watch(() => props.dashboard.name, (value) => { name.value = value; });

// El servidor ignora un nombre vacío sin avisar (responde 200 con el anterior): se evita desde aquí.
const nameChanged = computed(() => name.value.trim() !== '' && name.value.trim() !== props.dashboard.name);

const rename = useApiForm(async () => {
    const { data } = await api.patch(`/dashboards/${props.dashboard.ulid}`, { name: name.value.trim() });
    emit('updated', data);
}, { success: { kind: 'update', entity: 'Tablero', gender: 'm' } });

// --- Publicación ---
const canPublish = computed(() => canWrite('dashboards.dashboards.publish'));
const canListRoles = computed(() => can('identity.roles.view'));

const roles = ref([]);
const rolesLoading = ref(false);
const rolesError = ref(null);
const selectedRole = ref(props.dashboard.published_role_ulid ?? '');
watch(() => props.dashboard.published_role_ulid, (ulid) => { selectedRole.value = ulid ?? ''; });

onMounted(loadRoles);

async function loadRoles() {
    if (! canPublish.value || ! canListRoles.value) return;

    rolesLoading.value = true;

    try {
        // Paginado en el servidor (tope de 100 por página): se recorren todas. Un 403 —permisos que cambiaron desde que
        // cargó el shell— se lee como «no hay de dónde elegir», no como falla de la pantalla.
        roles.value = (await orEmptyWhenForbidden(getAllPages('/roles').then((data) => ({ data })))).data;
    } catch (e) {
        if (e instanceof ApiError) rolesError.value = e.title; else throw e;
    } finally {
        rolesLoading.value = false;
    }
}

function roleName(ulid) {
    if (! ulid) return null;

    // Sin la lista de roles, el único que se puede nombrar es el activo de quien mira (viene en el shell).
    const context = page.props.context ?? {};

    return roles.value.find((r) => r.ulid === ulid)?.name ?? (ulid === context.role_ulid ? context.role_name : null);
}

const publishedRoleName = computed(() => roleName(props.dashboard.published_role_ulid));

const publicationStatus = computed(() => {
    if (! props.dashboard.published_role_ulid) return 'Sin publicar: sólo tú lo ves.';

    return publishedRoleName.value
        ? `Publicado al rol «${publishedRoleName.value}»: lo ven quienes operan con ese rol activo, sin poder cambiarlo.`
        : 'Publicado a un rol que no aparece en la lista que puedes ver.';
});

const publicationChanged = computed(() => selectedRole.value !== (props.dashboard.published_role_ulid ?? ''));

// Lo que se pidió, fijado al ENVIAR: al responder, el tablero actualizado vuelve a poner la selección en el estado del
// servidor, y el aviso de éxito tiene que hablar de lo que se pidió.
let requested = { ulid: '', role: null };

const publish = useApiForm(async () => {
    requested = { ulid: selectedRole.value, role: roleName(selectedRole.value) };

    // Si el rol ya no existe (lo borraron después de cargar la lista), el servidor responde 422 por campo y el tablero se
    // queda como estaba: el error lo pinta `useApiForm`.
    const { data } = await api.patch(`/dashboards/${props.dashboard.ulid}`, { published_role_ulid: requested.ulid || null });
    emit('updated', data);
}, {
    success: () => (requested.ulid
        ? `Tablero publicado${requested.role ? ` al rol «${requested.role}»` : ''}.`
        : 'El tablero dejó de estar publicado: sólo tú lo ves.'),
});

function unpublish() {
    selectedRole.value = '';
    publish.submit();
}

// --- Eliminar ---
const remove = useApiForm(async () => {
    await api.delete(`/dashboards/${props.dashboard.ulid}`);
    router.visit('/admin/tableros');
}, { success: { kind: 'delete', entity: 'Tablero', gender: 'm' } });

function confirmRemove() {
    // Borrado definitivo (sin papelera), y los indicadores se van con él (FK en cascada). Si está publicado, también lo
    // pierde el rol: la pregunta dice las dos cosas.
    const n = props.dashboard.widgets?.length ?? 0;
    const indicators = n === 0 ? '' : n === 1 ? ' junto con su indicador' : ` junto con sus ${n} indicadores`;

    let audience = '';
    if (props.dashboard.published_role_ulid) {
        audience = publishedRoleName.value
            ? ` y quienes operan con el rol «${publishedRoleName.value}» dejarán de verlo`
            : ' y el rol al que está publicado dejará de verlo';
    }

    if (! window.confirm(`¿Eliminar el tablero «${props.dashboard.name}»? Se borra definitivamente${indicators}${audience}. Los reportes y sus datos no cambian.`)) {
        return;
    }

    remove.submit();
}
</script>

<template>
    <section class="ajustes" :aria-labelledby="`${uid}-titulo`">
        <div class="ajustes__cabecera">
            <h2 :id="`${uid}-titulo`">Editar tablero</h2>
            <button type="button" class="link-button" @click="emit('close')"><Icon name="x" /> Cerrar</button>
        </div>

        <form class="ajustes__bloque" @submit.prevent="nameChanged && rename.submit()">
            <label :for="`${uid}-nombre`" class="field__label">Nombre</label>
            <div class="ajustes__fila">
                <input :id="`${uid}-nombre`" v-model="name" class="input" type="text" required maxlength="80" />
                <button type="submit" class="button" :disabled="rename.processing.value || ! nameChanged">
                    {{ rename.processing.value ? 'Guardando…' : 'Guardar nombre' }}
                </button>
            </div>
            <p v-if="rename.generalError.value" class="alert" role="alert">{{ rename.generalError.value }}</p>
        </form>

        <div v-if="canPublish" class="ajustes__bloque">
            <h3>Publicación</h3>
            <p class="nota">{{ publicationStatus }}</p>

            <form v-if="canListRoles" class="ajustes__publicar" @submit.prevent="publicationChanged && publish.submit()">
                <p v-if="rolesLoading" class="nota">Cargando roles…</p>
                <template v-else>
                    <label :for="`${uid}-rol`" class="field__label">Rol que lo ve</label>
                    <div class="ajustes__fila">
                        <select :id="`${uid}-rol`" v-model="selectedRole" class="input">
                            <option value="">Ninguno: sólo yo</option>
                            <option v-for="r in roles" :key="r.ulid" :value="r.ulid">{{ r.name }}</option>
                        </select>
                        <button type="submit" class="button" :disabled="publish.processing.value || ! publicationChanged">
                            {{ selectedRole ? 'Publicar' : 'Dejar de publicar' }}
                        </button>
                    </div>
                    <span class="field__hint">
                        Cada indicador se calcula con los permisos de quien lo mira: si su rol no puede ver un reporte, ese
                        indicador le muestra un aviso en vez de cifras.
                    </span>
                </template>
            </form>

            <template v-else>
                <p class="nota">Para elegir a qué rol publicarlo hace falta además el permiso «Ver los roles».</p>
                <button
                    v-if="dashboard.published_role_ulid"
                    type="button"
                    class="button button--neutral"
                    :disabled="publish.processing.value"
                    @click="unpublish"
                >
                    Dejar de publicar
                </button>
            </template>

            <p v-if="rolesError" class="alert" role="alert">{{ rolesError }}</p>
            <p v-if="publish.generalError.value" class="alert" role="alert">{{ publish.generalError.value }}</p>
        </div>

        <div class="ajustes__bloque">
            <h3>Eliminar tablero</h3>
            <p class="nota">Se borra definitivamente con sus indicadores; no hay papelera. Los reportes no cambian.</p>
            <button type="button" class="button button--danger" :disabled="remove.processing.value" @click="confirmRemove">
                <Icon name="trash" /> {{ remove.processing.value ? 'Eliminando…' : 'Eliminar tablero' }}
            </button>
            <p v-if="remove.generalError.value" class="alert" role="alert">{{ remove.generalError.value }}</p>
        </div>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.ajustes {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.15rem 1.25rem;
    display: grid;
    gap: 1rem;
}
.ajustes__cabecera { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.ajustes__cabecera h2 { margin: 0; font-size: 1rem; font-weight: 650; }
.ajustes__bloque, .ajustes__publicar { display: grid; gap: 0.45rem; }
.ajustes__bloque { padding-top: 1rem; border-top: 1px solid var(--color-borde); }
.ajustes__bloque h3 { margin: 0; font-size: 0.9rem; font-weight: 600; }
/* Campo y botón en una línea; en un teléfono el botón baja a la siguiente. */
.ajustes__fila { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.ajustes__fila .input { flex: 1 1 14rem; max-width: 24rem; }
.ajustes__bloque > .button { justify-self: start; }
/* En la rejilla el espacio lo pone el `gap`; el margen propio del aviso lo duplicaría. */
.ajustes .alert { margin: 0; }
.ajustes .field__label { margin: 0; }
.ajustes .field__hint { margin: 0; }
.nota { margin: 0; color: var(--color-suave); font-size: 0.85rem; }
</style>
