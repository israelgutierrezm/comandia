<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import Icon from '../../../components/Icon.vue';

/**
 * Ficha de una persona: roles, alcance por sucursal y perfil laboral.
 *
 * Cierra los tres huecos que quedaron abiertos al construir la UI del kernel. Los endpoints existían y
 * estaban probados; faltaban las pantallas — salvo el alcance por sucursal, que **tampoco tenía
 * endpoint**: el permiso `identity.memberships.manage_branch_scopes` estaba en el catálogo cerrado desde
 * la Iteración 1 y ninguna ruta lo usaba.
 *
 * ## Tres permisos distintos para tres cosas distintas
 *
 * Asignar roles, definir alcance y editar el perfil laboral tienen permiso propio, y los datos fiscales
 * —CURP, RFC, NSS— exigen uno más. No es burocracia: en un negocio con varias sucursales, quién puede
 * decidir dónde cobra alguien es la diferencia entre poder auditar y no poder.
 *
 * Cada panel se pide por separado por eso mismo: un 403 en el perfil no puede esconder los roles.
 */
const props = defineProps({
    membershipUlid: { type: String, required: true },
});

const { can, canWrite } = useAuthorization();

const membership = ref(null);
const profile = ref(null);
const profileForbidden = ref(false);
const roles = ref([]);
const branches = ref([]);
const loading = ref(true);
const error = ref(null);
const ready = ref(false);

async function loadMembership() {
    try {
        membership.value = (await api.get(`/memberships/${props.membershipUlid}`)).data;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        error.value = e;
        membership.value = null;
    } finally {
        loading.value = false;
    }
}

/**
 * El perfil se pide al abrir SU pestaña, no al abrir la ficha.
 *
 * Y no es una optimización: cuando el rol activo puede ver la CURP, el RFC y el NSS, la respuesta los
 * incluye y el servidor **registra en la bitácora que se consultaron datos sensibles**. Pidiéndolo al
 * montar la página, abrir la ficha de cualquier persona dejaba ese asiento aunque nadie hubiera mirado
 * los datos fiscales.
 *
 * Un registro de accesos a datos personales que se llena de consultas que no ocurrieron es peor que
 * inútil: diluye las que sí. Lo encontró el navegador, al mirar la bitácora justo después de entrar a
 * una ficha.
 */
let profileLoaded = false;

async function loadProfile() {
    if (!can('identity.employee_profiles.view')) {
        return;
    }

    profileLoaded = true;

    try {
        profile.value = (await api.get(`/memberships/${props.membershipUlid}/employee-profile`)).data;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        // 404 = no tiene perfil, que es normal para quien entra con correo. 403 = no se puede ver, y eso
        // sí hay que decirlo en lugar de mostrar una ficha vacía.
        profileForbidden.value = e.isForbidden;
        profile.value = null;
    }
}

onMounted(async () => {
    // Todo en una tanda, por lo mismo que la ficha del artículo: pintar por partes movía las pestañas
    // bajo el cursor.
    const [, rls, brs] = await Promise.all([
        loadMembership(),
        can('identity.roles.view')
            ? api.get('/roles', { per_page: 100 }).catch(() => ({ data: [] }))
            : Promise.resolve({ data: [] }),
        api.get('/branches', { status: 'active', per_page: 100 }).catch(() => ({ data: [] })),
    ]);

    roles.value = rls.data ?? [];
    branches.value = brs.data ?? [];
    ready.value = true;
});

const tabs = computed(() => {
    if (membership.value === null) {
        return [];
    }

    return [
        { key: 'general', label: 'General', show: true },
        {
            key: 'roles',
            label: 'Roles',
            // Sin credenciales no hay roles: los roles son del usuario y quien no inicia sesión no
            // ejerce permisos. La pestaña no aparece en lugar de aparecer vacía y sin explicación.
            show: membership.value.has_credentials && can('identity.roles.view'),
        },
        { key: 'branches', label: 'Sucursales', show: true },
        { key: 'profile', label: 'Perfil laboral', show: can('identity.employee_profiles.view') },
    ].filter((tab) => tab.show);
});

const activeTab = ref('general');
const currentTab = computed(() =>
    tabs.value.some((tab) => tab.key === activeTab.value) ? activeTab.value : 'general',
);

/** Abrir una pestaña. La del perfil pide sus datos la primera vez, y no antes. */
async function openTab(key) {
    activeTab.value = key;

    if (key === 'profile' && !profileLoaded) {
        await loadProfile();
    }
}

// ---- Roles ----

const roleDraft = ref([]);
const editingRoles = ref(false);

function startRoles() {
    editingRoles.value = true;
    roleDraft.value = (membership.value.default_role ? [membership.value.default_role.ulid] : []).concat(
        // Los roles que ya tiene, con el activo primero: el orden ES la decisión de cuál queda activo.
        (membership.value.roles ?? [])
            .map((role) => role.ulid)
            .filter((ulid) => ulid !== membership.value.default_role?.ulid),
    );
}

const saveRoles = useApiForm(async () => {
    await api.put(`/memberships/${props.membershipUlid}/roles`, { role_ulids: roleDraft.value });
});

function toggleRole(ulid) {
    const index = roleDraft.value.indexOf(ulid);

    if (index === -1) {
        roleDraft.value.push(ulid);
    } else {
        roleDraft.value.splice(index, 1);
    }
}

function moveRole(index, delta) {
    const target = index + delta;

    if (target < 0 || target >= roleDraft.value.length) {
        return;
    }

    const [item] = roleDraft.value.splice(index, 1);
    roleDraft.value.splice(target, 0, item);
}

function roleName(ulid) {
    return roles.value.find((role) => role.ulid === ulid)?.name ?? ulid;
}

async function submitRoles() {
    if (await saveRoles.submit()) {
        editingRoles.value = false;
        await loadMembership();
    }
}

// ---- Alcance por sucursal ----

const editingScope = ref(false);
const scopeDraft = ref({ all: true, ulids: [] });

function startScope() {
    editingScope.value = true;
    scopeDraft.value = {
        all: membership.value.has_all_branches,
        ulids: (membership.value.branch_scopes ?? []).map((scope) => scope.ulid),
    };
}

const saveScope = useApiForm(async () => {
    await api.put(`/memberships/${props.membershipUlid}/branches`, {
        has_all_branches: scopeDraft.value.all,
        // Con la bandera puesta NO se manda lista: el servidor rechaza las dos juntas a propósito, para
        // que nadie deje una lista que la bandera ignora.
        branch_ulids: scopeDraft.value.all ? [] : scopeDraft.value.ulids,
    });
});

function toggleScopeBranch(ulid) {
    const index = scopeDraft.value.ulids.indexOf(ulid);

    if (index === -1) {
        scopeDraft.value.ulids.push(ulid);
    } else {
        scopeDraft.value.ulids.splice(index, 1);
    }
}

async function submitScope() {
    if (await saveScope.submit()) {
        editingScope.value = false;
        await loadMembership();
    }
}

// ---- Perfil laboral ----

const editingProfile = ref(false);
const profileDraft = ref({});

function startProfile() {
    editingProfile.value = true;
    profileDraft.value = {
        legal_first_name: profile.value?.legal_name?.first_name ?? '',
        legal_paternal_surname: profile.value?.legal_name?.paternal_surname ?? '',
        legal_maternal_surname: profile.value?.legal_name?.maternal_surname ?? '',
        is_foreigner: profile.value?.is_foreigner ?? false,

        // Los datos fiscales llegan al NIVEL SUPERIOR de la respuesta, no dentro de un objeto: el
        // recurso usa `mergeWhen` a propósito para que la LLAVE falte cuando no hay permiso. Su
        // ausencia dice «no puedes verlo», y un `null` diría «no hay dato» — dos cosas distintas, y
        // mostrar «sin CURP» a quien simplemente no puede verla sería mentirle.
        curp: profile.value?.curp ?? '',
        rfc: profile.value?.rfc ?? '',
        nss: profile.value?.nss ?? '',
        birth_date: profile.value?.birth_date ?? '',
        hired_at: profile.value?.hired_at ?? '',
        terminated_at: profile.value?.terminated_at ?? '',
    };
}

const saveProfile = useApiForm(async () => {
    const body = { ...profileDraft.value };

    // Los vacíos se mandan como `null` y no como cadena vacía: «no lo sé» y «es una cadena vacía» son
    // cosas distintas, y una CURP vacía fallaría la validación de tamaño.
    for (const key of ['curp', 'rfc', 'nss', 'birth_date', 'hired_at', 'terminated_at', 'legal_maternal_surname']) {
        if (body[key] === '') {
            body[key] = null;
        }
    }

    await api.put(`/memberships/${props.membershipUlid}/employee-profile`, body);
});

async function submitProfile() {
    if (await saveProfile.submit()) {
        editingProfile.value = false;
        await loadProfile();
        await loadMembership();
    }
}
</script>

<template>
    <Head :title="membership?.display_name ?? 'Persona'" />

    <p class="breadcrumb">
        <Link href="/admin/personal">← Personal</Link>
    </p>

    <template v-if="loading || (!ready && error === null)"></template>

    <div v-else-if="error" class="card card--error">
        <p v-if="error.status === 404">Esta persona no existe, o pertenece a otro negocio.</p>
        <p v-else-if="error.isForbidden">No tienes permiso para ver al personal.</p>
        <p v-else>{{ error.message }}</p>
    </div>

    <template v-else-if="membership">
        <header class="page-header">
            <div>
                <h1>{{ membership.display_name }}</h1>
                <p class="meta">
                    <span class="code">{{ membership.employee_code }}</span>
                    <span v-if="membership.email">{{ membership.email }}</span>
                    <span v-else class="muted">Sin acceso al sistema</span>
                    <span class="badge" :class="membership.status === 'active' ? 'badge--ok' : 'badge--off'">
                        {{ membership.status === 'active' ? 'Activa' : membership.status }}
                    </span>
                </p>
            </div>
        </header>

        <nav class="tabs">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                class="tab"
                :class="{ 'tab--current': currentTab === tab.key }"
                type="button"
                @click="openTab(tab.key)"
            >
                {{ tab.label }}
            </button>
        </nav>

        <div class="card">
            <!-- ---- General ---- -->
            <template v-if="currentTab === 'general'">
                <dl class="facts">
                    <dt>Nombre completo</dt>
                    <dd>{{ membership.full_name }}</dd>

                    <dt>Acceso al sistema</dt>
                    <dd>
                        <template v-if="membership.has_credentials">
                            Sí, con {{ membership.email }}
                        </template>
                        <span v-else class="muted">
                            No inicia sesión. Existe en nómina, y su nombre sale del perfil laboral.
                        </span>
                    </dd>

                    <dt>PIN de terminal</dt>
                    <dd>
                        <template v-if="membership.pin_locked">
                            <span class="badge badge--warn">Bloqueado</span>
                        </template>
                        <template v-else-if="membership.has_pin">Configurado</template>
                        <span v-else class="muted">Sin PIN</span>
                        <span class="muted"> · se administra desde el listado</span>
                    </dd>

                    <dt>Rol activo por omisión</dt>
                    <dd>{{ membership.default_role?.name ?? '—' }}</dd>

                    <dt>Dada de alta</dt>
                    <dd>{{ new Date(membership.created_at).toLocaleString('es-MX') }}</dd>
                </dl>
            </template>

            <!-- ---- Roles ---- -->
            <template v-else-if="currentTab === 'roles'">
                <template v-if="!editingRoles">
                    <p v-if="membership.default_role" class="lead">
                        Rol activo al entrar: <strong>{{ membership.default_role.name }}</strong>
                    </p>
                    <p v-else class="muted">
                        Sin rol asignado: esta persona puede entrar y no puede hacer nada.
                    </p>

                    <p class="hint">
                        Las decisiones se toman por <strong>rol activo</strong>, nunca sumando los permisos
                        de varios roles. Quien tiene dos roles elige con cuál opera, y eso queda en la
                        bitácora.
                    </p>

                    <button
                        v-if="canWrite('identity.memberships.assign_roles')"
                        class="button button--warning"
                        type="button"
                        @click="startRoles"
                    ><Icon name="edit" /> Cambiar roles</button>
                </template>

                <form v-else @submit.prevent="submitRoles">
                    <p v-if="saveRoles.generalError.value" class="alert">{{ saveRoles.generalError.value }}</p>

                    <p class="hint">El primero de la lista es el que quedará activo al entrar.</p>

                    <ol v-if="roleDraft.length" class="ordering">
                        <li v-for="(ulid, index) in roleDraft" :key="ulid">
                            <span>{{ roleName(ulid) }}</span>
                            <span class="ordering__actions">
                                <button class="link-button" type="button" :disabled="index === 0" @click="moveRole(index, -1)">
                                    ↑
                                </button>
                                <button
                                    class="link-button"
                                    type="button"
                                    :disabled="index === roleDraft.length - 1"
                                    @click="moveRole(index, 1)"
                                >
                                    ↓
                                </button>
                                <button class="link-button link-button--danger" type="button" @click="toggleRole(ulid)"><Icon name="trash" /> Quitar</button>
                            </span>
                        </li>
                    </ol>

                    <p v-else class="muted small">Ningún rol seleccionado.</p>

                    <fieldset class="block">
                        <legend class="field__label">Roles del negocio</legend>
                        <label v-for="role in roles" :key="role.ulid" class="choice">
                            <input
                                type="checkbox"
                                :checked="roleDraft.includes(role.ulid)"
                                @change="toggleRole(role.ulid)"
                            />
                            <span>{{ role.name }} <span class="muted">· {{ role.description }}</span></span>
                        </label>
                    </fieldset>

                    <span v-if="saveRoles.fieldErrors.value.role_ulids" class="field__error">
                        {{ saveRoles.fieldErrors.value.role_ulids }}
                    </span>

                    <div class="actions">
                        <button type="button" class="link-button" @click="editingRoles = false"><Icon name="x" /> Cancelar</button>
                        <button type="submit" class="button" :disabled="saveRoles.processing.value"><Icon name="check" /> Guardar</button>
                    </div>
                </form>
            </template>

            <!-- ---- Alcance por sucursal ---- -->
            <template v-else-if="currentTab === 'branches'">
                <template v-if="!editingScope">
                    <p v-if="membership.has_all_branches" class="lead">
                        Opera en <strong>todas las sucursales</strong>, incluidas las que se abran después.
                    </p>

                    <template v-else>
                        <p class="lead">
                            Opera sólo en
                            <strong>{{ (membership.branch_scopes ?? []).length }} sucursal(es)</strong>:
                        </p>
                        <ul class="scopes">
                            <li v-for="scope in membership.branch_scopes ?? []" :key="scope.ulid">
                                {{ scope.name }}
                            </li>
                            <li v-if="(membership.branch_scopes ?? []).length === 0" class="muted">
                                Ninguna. Esta persona no puede operar en ningún sitio.
                            </li>
                        </ul>
                        <p class="hint">
                            Una sucursal nueva <strong>no</strong> la incluirá automáticamente.
                        </p>
                    </template>

                    <button
                        v-if="canWrite('identity.memberships.manage_branch_scopes')"
                        class="button button--warning"
                        type="button"
                        @click="startScope"
                    ><Icon name="edit" /> Cambiar alcance</button>
                </template>

                <form v-else @submit.prevent="submitScope">
                    <p v-if="saveScope.generalError.value" class="alert">{{ saveScope.generalError.value }}</p>

                    <label class="choice">
                        <input v-model="scopeDraft.all" type="radio" :value="true" />
                        <span>
                            <span class="choice__label">En todas las sucursales</span>
                            <span class="choice__hint">Incluye las que se abran después.</span>
                        </span>
                    </label>

                    <label class="choice">
                        <input v-model="scopeDraft.all" type="radio" :value="false" />
                        <span>
                            <span class="choice__label">Sólo en las que elija</span>
                            <span class="choice__hint">Una sucursal nueva NO la incluirá.</span>
                        </span>
                    </label>

                    <div v-if="!scopeDraft.all" class="block block--indent">
                        <label v-for="branch in branches" :key="branch.ulid" class="choice choice--tight">
                            <input
                                type="checkbox"
                                :checked="scopeDraft.ulids.includes(branch.ulid)"
                                @change="toggleScopeBranch(branch.ulid)"
                            />
                            <span>{{ branch.name }} <span class="muted">({{ branch.code }})</span></span>
                        </label>
                    </div>

                    <span v-if="saveScope.fieldErrors.value.branch_ulids" class="field__error">
                        {{ saveScope.fieldErrors.value.branch_ulids }}
                    </span>

                    <div class="actions">
                        <button type="button" class="link-button" @click="editingScope = false"><Icon name="x" /> Cancelar</button>
                        <button type="submit" class="button" :disabled="saveScope.processing.value"><Icon name="check" /> Guardar</button>
                    </div>
                </form>
            </template>

            <!-- ---- Perfil laboral ---- -->
            <template v-else-if="currentTab === 'profile'">
                <p v-if="profileForbidden" class="alert">
                    No tienes permiso para ver perfiles laborales.
                </p>

                <template v-else-if="!editingProfile">
                    <template v-if="profile">
                        <dl class="facts">
                            <dt>Nombre legal</dt>
                            <dd>{{ profile.legal_name.full }}</dd>

                            <dt>Extranjero</dt>
                            <dd>{{ profile.is_foreigner ? 'Sí' : 'No' }}</dd>

                            <dt>Alta</dt>
                            <dd>{{ profile.hired_at ?? '—' }}</dd>

                            <dt>Baja</dt>
                            <dd>{{ profile.terminated_at ?? '—' }}</dd>
                        </dl>

                        <!--
                            Los datos fiscales sólo si el rol activo tiene el permiso PROPIO de verlos, y
                            el servidor decide: si no lo tiene, no viajan en la respuesta. Verlos deja
                            entrada en la bitácora, porque son datos personales.
                        -->
                        <template v-if="profile.can_view_sensitive">
                            <h3 class="subtitle">Datos fiscales</h3>
                            <dl class="facts">
                                <dt>CURP</dt>
                                <dd>{{ profile.curp ?? '—' }}</dd>
                                <dt>RFC</dt>
                                <dd>{{ profile.rfc ?? '—' }}</dd>
                                <dt>NSS</dt>
                                <dd>{{ profile.nss ?? '—' }}</dd>
                                <dt>Nacimiento</dt>
                                <dd>{{ profile.birth_date ?? '—' }}</dd>
                            </dl>
                            <p class="hint">
                                Consultar estos datos queda registrado en la bitácora: son datos personales.
                            </p>
                        </template>

                        <p v-else class="hint">
                            La CURP, el RFC y el NSS exigen un permiso propio para verse, y tu rol activo no
                            lo tiene.
                        </p>
                    </template>

                    <p v-else class="muted">
                        Esta persona no tiene perfil laboral. Sólo es obligatorio para quien no inicia
                        sesión, porque de ahí sale su nombre.
                    </p>

                    <button
                        v-if="canWrite('identity.employee_profiles.manage')"
                        class="button"
                        type="button"
                        @click="startProfile"
                    >
                        {{ profile ? 'Editar perfil' : 'Crear perfil' }}
                    </button>
                </template>

                <form v-else @submit.prevent="submitProfile">
                    <p v-if="saveProfile.generalError.value" class="alert">{{ saveProfile.generalError.value }}</p>

                    <div class="pair">
                        <label class="field">
                            <span class="field__label">Nombre legal</span>
                            <input v-model="profileDraft.legal_first_name" class="input" maxlength="60" required />
                            <span v-if="saveProfile.fieldErrors.value.legal_first_name" class="field__error">
                                {{ saveProfile.fieldErrors.value.legal_first_name }}
                            </span>
                        </label>

                        <label class="field">
                            <span class="field__label">Apellido paterno legal</span>
                            <input v-model="profileDraft.legal_paternal_surname" class="input" maxlength="60" required />
                            <span v-if="saveProfile.fieldErrors.value.legal_paternal_surname" class="field__error">
                                {{ saveProfile.fieldErrors.value.legal_paternal_surname }}
                            </span>
                        </label>
                    </div>

                    <div class="pair">
                        <label class="field">
                            <span class="field__label">Apellido materno legal</span>
                            <input v-model="profileDraft.legal_maternal_surname" class="input" maxlength="60" />
                        </label>

                        <label class="field field--check">
                            <input v-model="profileDraft.is_foreigner" type="checkbox" />
                            <span>
                                <span class="field__label">Extranjero</span>
                                <span class="field__hint">Sin CURP mexicana.</span>
                            </span>
                        </label>
                    </div>

                    <div class="pair">
                        <label class="field">
                            <span class="field__label">CURP</span>
                            <input v-model="profileDraft.curp" class="input" maxlength="18" />
                            <span v-if="saveProfile.fieldErrors.value.curp" class="field__error">
                                {{ saveProfile.fieldErrors.value.curp }}
                            </span>
                        </label>

                        <label class="field">
                            <span class="field__label">RFC</span>
                            <input v-model="profileDraft.rfc" class="input" maxlength="13" />
                            <span v-if="saveProfile.fieldErrors.value.rfc" class="field__error">
                                {{ saveProfile.fieldErrors.value.rfc }}
                            </span>
                        </label>
                    </div>

                    <div class="pair">
                        <label class="field">
                            <span class="field__label">NSS</span>
                            <input v-model="profileDraft.nss" class="input" maxlength="11" />
                            <span v-if="saveProfile.fieldErrors.value.nss" class="field__error">
                                {{ saveProfile.fieldErrors.value.nss }}
                            </span>
                        </label>

                        <label class="field">
                            <span class="field__label">Nacimiento</span>
                            <input v-model="profileDraft.birth_date" type="date" class="input" />
                            <span v-if="saveProfile.fieldErrors.value.birth_date" class="field__error">
                                {{ saveProfile.fieldErrors.value.birth_date }}
                            </span>
                        </label>
                    </div>

                    <div class="pair">
                        <label class="field">
                            <span class="field__label">Fecha de alta</span>
                            <input v-model="profileDraft.hired_at" type="date" class="input" />
                        </label>

                        <label class="field">
                            <span class="field__label">Fecha de baja</span>
                            <input v-model="profileDraft.terminated_at" type="date" class="input" />
                            <span v-if="saveProfile.fieldErrors.value.terminated_at" class="field__error">
                                {{ saveProfile.fieldErrors.value.terminated_at }}
                            </span>
                        </label>
                    </div>

                    <div class="actions">
                        <button type="button" class="link-button" @click="editingProfile = false"><Icon name="x" /> Cancelar</button>
                        <button type="submit" class="button" :disabled="saveProfile.processing.value"><Icon name="check" /> Guardar</button>
                    </div>
                </form>
            </template>
        </div>
    </template>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.breadcrumb {
    margin: 0 0 0.6rem;
    font-size: 0.85rem;
}

.breadcrumb a {
    color: #78716c;
    text-decoration: none;
}

.meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.85rem;
    margin: 0.15rem 0 0;
    font-size: 0.8rem;
    opacity: 0.75;
}

.code {
    font-family: ui-monospace, monospace;
}

.tabs {
    display: flex;
    gap: 0.15rem;
    margin-bottom: -1px;
    overflow-x: auto;
}

.tab {
    padding: 0.45rem 0.85rem;
    background: transparent;
    border: 1px solid transparent;
    border-bottom: 0;
    border-radius: 0.375rem 0.375rem 0 0;
    font: inherit;
    font-size: 0.87rem;
    color: #78716c;
    cursor: pointer;
    white-space: nowrap;
}

.tab--current {
    background: #fff;
    border-color: #e7e5e4;
    color: #1c1917;
    font-weight: 600;
}

.card {
    background: #fff;
    border: 1px solid #e7e5e4;
    border-radius: 0 0.5rem 0.5rem 0.5rem;
    padding: 1.1rem;
}

.card--quiet {
    opacity: 0.7;
    border-radius: 0.5rem;
}

.card--error {
    border-color: var(--color-peligro-tenue);
    color: var(--color-peligro);
    border-radius: 0.5rem;
}

.facts {
    display: grid;
    grid-template-columns: minmax(10rem, max-content) 1fr;
    gap: 0.4rem 1.25rem;
    margin: 0 0 1rem;
    font-size: 0.88rem;
}

.facts dt {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.55;
    align-self: center;
}

.facts dd {
    margin: 0;
}

.lead {
    margin: 0 0 0.6rem;
    font-size: 0.95rem;
}

.subtitle {
    margin: 0.4rem 0 0.6rem;
    font-size: 0.95rem;
}

.hint {
    margin: 0 0 0.9rem;
    font-size: 0.8rem;
    opacity: 0.65;
    max-width: 44rem;
}

.scopes {
    margin: 0 0 0.6rem;
    padding-left: 1.2rem;
    font-size: 0.88rem;
}

.ordering {
    margin: 0 0 0.9rem;
    padding-left: 1.2rem;
    font-size: 0.9rem;
    max-width: 28rem;
}

.ordering li {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.2rem 0;
}

.ordering__actions {
    margin-left: auto;
    display: flex;
    gap: 0.6rem;
}

.block {
    border: 1px solid #e7e5e4;
    border-radius: 0.375rem;
    padding: 0.75rem;
    margin: 0 0 0.9rem;
    max-width: 28rem;
}

.block--indent {
    margin-left: 1.4rem;
}

.choice {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.25rem 0;
    font-size: 0.88rem;
}

.choice--tight {
    padding: 0.1rem 0;
}

.choice input {
    margin-top: 0.25rem;
}

.choice__label {
    display: block;
    font-weight: 500;
}

.choice__hint {
    display: block;
    font-size: 0.78rem;
    opacity: 0.6;
}

.pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    max-width: 34rem;
}

.field--check {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.field--check input {
    margin-top: 0.2rem;
}

.actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.muted {
    opacity: 0.55;
}

.small {
    font-size: 0.8rem;
}
</style>
