<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import Paginacion from '../../../components/Paginacion.vue';
import ListHeader from '../../../components/ListHeader.vue';
import StaffForm from '../../../components/identity/StaffForm.vue';

const view = ref('list');

/** Iniciales para el avatar de la tarjeta: las primeras letras de hasta dos palabras del nombre. */
function iniciales(nombre) {
    return (nombre ?? '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0] ?? '').join('').toUpperCase() || '?';
}

/**
 * Personal (§4.1).
 *
 * La pantalla muestra las dos naturalezas que el producto exige y que suelen confundirse: quien
 * **entra al sistema** y quien sólo **existe en nómina** —el lavaloza que nunca inicia sesión—. La
 * columna de acceso lo hace explícito, porque de ahí se derivan casi todas las reglas: sin
 * credenciales no hay roles, y sin roles el PIN no podría autorizar nada.
 *
 * El PIN se administra aquí y nunca se muestra: se guarda hasheado y no hay forma de recuperarlo.
 * Quien lo olvida recibe uno nuevo.
 */
const list = useResourceList('/memberships', { initialFilters: { status: '' } });

const filtrosActivos = computed(() => (list.filters.status !== '' ? 1 : 0));
function limpiarFiltros() {
    list.filters.status = '';
}

/** Datos de referencia del formulario de alta: los roles entre los que elegir y las sucursales. */
const roles = ref([]);
const branches = ref([]);

onMounted(async () => {
    await list.load();

    // Cada uno con su propio `catch`: quien ve al personal no necesariamente ve los roles, y un 403 en
    // uno no puede dejar la pantalla sin cargar.
    const [rls, brs] = await Promise.all([
        api.get('/roles', { per_page: 100 }).catch(() => ({ data: [] })),
        api.get('/branches', { status: 'active', per_page: 100 }).catch(() => ({ data: [] })),
    ]);

    roles.value = rls.data ?? [];
    branches.value = brs.data ?? [];
});

const creating = ref(false);

function openPerson(membership) {
    router.visit(`/admin/personal/${membership.ulid}`);
}

async function afterCreate() {
    creating.value = false;
    await list.load();
}

const pinTarget = ref(null);
const pinForm = ref({ pin: '', pin_confirmation: '' });

const setPin = useApiForm(async () => {
    await api.put(`/memberships/${pinTarget.value.ulid}/pin`, pinForm.value);
});

const pinAction = useApiForm(async (membership, action) => {
    if (action === 'unlock') {
        await api.post(`/memberships/${membership.ulid}/pin/unlock`);
    } else {
        await api.delete(`/memberships/${membership.ulid}/pin`);
    }
});

const statusAction = useApiForm(async (membership, action) => {
    await api.post(`/memberships/${membership.ulid}/${action}`);
});

function startPin(membership) {
    pinTarget.value = membership;
    pinForm.value = { pin: '', pin_confirmation: '' };
}

async function submitPin() {
    if (await setPin.submit()) {
        pinTarget.value = null;
        await list.load();
    }
}

async function runPinAction(membership, action) {
    if (await pinAction.submit(membership, action)) {
        await list.load();
    }
}

async function changeStatus(membership, action) {
    const verb = action === 'suspend' ? 'suspender' : 'reactivar';

    if (!window.confirm(`¿Seguro que quieres ${verb} a ${membership.display_name}?`)) {
        return;
    }

    // Puede fallar con 409 al intentar suspenderse a sí mismo: el servidor lo impide para que nadie
    // se quede fuera de su propio sistema con un clic.
    if (await statusAction.submit(membership, action)) {
        await list.load();
    }
}

/**
 * El estado llega como el valor del enum del dominio (`active`, `invited`, …) y así debe llegar: el
 * código que compara estados no puede depender de texto traducible. La traducción es de la vista, y
 * faltaba: la columna pintaba «active» en crudo junto a columnas que sí traducían.
 */
const STATUS_LABELS = {
    invited: 'Invitado',
    active: 'Activo',
    suspended: 'Suspendido',
    terminated: 'Baja',
};

const columns = [
    { key: 'display_name', label: 'Nombre' },
    { key: 'employee_code', label: 'Código', width: '7rem' },
    { key: 'access', label: 'Acceso', width: '10rem' },
    { key: 'default_role', label: 'Rol por omisión' },
    { key: 'scope', label: 'Sucursales' },
    { key: 'pin', label: 'PIN', width: '8rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '16rem' },
];
</script>

<template>
    <Head title="Personal" />

    <ListHeader
        title="Personal"
        subtitle="Sin código de empleado no se puede autorizar con PIN: la autorización identifica a la persona por su código."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar por código…"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todos</option>
                <option value="active">Activos</option>
                <option value="invited">Invitados</option>
                <option value="suspended">Suspendidos</option>
                <option value="terminated">Baja</option>
            </select>
        </template>

        <template #view>
            <ViewToggle v-model="view" persist-key="comandia:view:staff" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'identity.users.create'" class="button" type="button" @click="creating = true">
                Nueva persona
            </button>
        </template>
    </ListHeader>

    <p v-if="statusAction.generalError.value" class="alert">{{ statusAction.generalError.value }}</p>
    <p v-if="pinAction.generalError.value" class="alert">{{ pinAction.generalError.value }}</p>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay personal que coincida."
    >
        <template #cell:access="{ row }">
            <span class="badge" :class="row.has_credentials ? 'badge--ok' : 'badge--off'">
                {{ row.has_credentials ? 'Inicia sesión' : 'Sólo nómina' }}
            </span>
        </template>

        <template #cell:display_name="{ row }">
            <button class="row-link" type="button" @click="openPerson(row)">{{ row.display_name }}</button>
        </template>

        <template #cell:default_role="{ row }">
            {{ row.default_role?.name ?? '—' }}
        </template>

        <template #cell:scope="{ row }">
            <!--
                «Todas» no es «las que hay»: incluye las futuras. La columna lo dice porque es la
                diferencia que nadie nota hasta que abre otra sucursal.
            -->
            <span v-if="row.has_all_branches" class="badge badge--warn">Todas</span>
            <span v-else-if="(row.branch_scopes ?? []).length" class="muted">
                {{ (row.branch_scopes ?? []).map((s) => s.name).join(', ') }}
            </span>
            <span v-else class="muted">Ninguna</span>
        </template>

        <template #cell:pin="{ row }">
            <span v-if="row.pin_locked" class="badge badge--warn">Bloqueado</span>
            <span v-else-if="row.has_pin" class="badge badge--ok">Asignado</span>
            <span v-else class="muted">Sin PIN</span>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ STATUS_LABELS[row.status] ?? row.status }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button class="link-button" type="button" @click="openPerson(row)">Ver ficha</button>

                <button
                    v-if="row.has_credentials"
                    v-can.write="'identity.memberships.reset_pin'"
                    class="link-button"
                    type="button"
                    @click="startPin(row)"
                >
                    {{ row.has_pin ? 'Cambiar PIN' : 'Asignar PIN' }}
                </button>

                <button
                    v-if="row.pin_locked"
                    v-can.write="'identity.memberships.reset_pin'"
                    class="link-button"
                    type="button"
                    @click="runPinAction(row, 'unlock')"
                >
                    Desbloquear
                </button>

                <button
                    v-if="row.status === 'active'"
                    v-can.write="'identity.users.suspend'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="changeStatus(row, 'suspend')"
                >
                    Suspender
                </button>

                <button
                    v-else-if="row.status === 'suspended' || row.status === 'invited'"
                    v-can.write="'identity.users.suspend'"
                    class="link-button"
                    type="button"
                    @click="changeStatus(row, 'reactivate')"
                >
                    Activar
                </button>
            </div>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay personal que coincida."
    >
        <template #card="{ item }">
            <button class="card card--link staff-card" type="button" @click="openPerson(item)">
                <span class="staff-card__avatar" aria-hidden="true">{{ iniciales(item.display_name) }}</span>
                <span class="card__title">{{ item.display_name }}</span>
                <span class="card__meta">{{ item.employee_code ?? 'sin código' }} · {{ item.default_role?.name ?? 'sin rol' }}</span>
                <span class="card__foot staff-card__foot">
                    <span class="badge" :class="item.has_credentials ? 'badge--ok' : 'badge--off'">
                        {{ item.has_credentials ? 'Inicia sesión' : 'Sólo nómina' }}
                    </span>
                    <span class="badge" :class="item.status === 'active' ? 'badge--ok' : 'badge--off'">
                        {{ STATUS_LABELS[item.status] ?? item.status }}
                    </span>
                </span>
            </button>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="personas" />

    <div v-if="pinTarget" class="drawer-backdrop" @click.self="pinTarget = null">
        <form class="drawer" @submit.prevent="submitPin">
            <h2>PIN de {{ pinTarget.display_name }}</h2>

            <p class="field__hint">
                El PIN se guarda cifrado y no se puede consultar después. Si se olvida, se asigna uno
                nuevo.
            </p>

            <p v-if="setPin.generalError.value" class="alert">{{ setPin.generalError.value }}</p>

            <label class="field">
                <span class="field__label">PIN (4 a 6 dígitos)</span>
                <input
                    v-model="pinForm.pin"
                    type="password"
                    inputmode="numeric"
                    autocomplete="off"
                    class="input"
                    required
                />
                <span v-if="setPin.fieldErrors.value.pin" class="field__error">{{ setPin.fieldErrors.value.pin }}</span>
            </label>

            <label class="field">
                <span class="field__label">Confirmar PIN</span>
                <input
                    v-model="pinForm.pin_confirmation"
                    type="password"
                    inputmode="numeric"
                    autocomplete="off"
                    class="input"
                    required
                />
                <span v-if="setPin.fieldErrors.value.pin_confirmation" class="field__error">
                    {{ setPin.fieldErrors.value.pin_confirmation }}
                </span>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="pinTarget = null">Cancelar</button>
                <button type="submit" class="button" :disabled="setPin.processing.value">Guardar PIN</button>
            </div>
        </form>
    </div>

    <StaffForm
        v-if="creating"
        :roles="roles"
        :branches="branches"
        @close="creating = false"
        @saved="afterCreate"
    />
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.muted {
    color: #a8a29e;
    font-size: 0.85rem;
}

.row-link {
    background: none;
    border: 0;
    padding: 0;
    font: inherit;
    font-weight: 500;
    color: #1c1917;
    text-align: left;
    cursor: pointer;
    text-decoration: underline;
    text-decoration-color: #d6d3d1;
}

/* Tarjeta de persona: avatar de iniciales centrado arriba. */
.staff-card { align-items: center; text-align: center; }
.staff-card__avatar {
    display: grid;
    place-items: center;
    width: 3rem;
    height: 3rem;
    border-radius: 50%;
    background: color-mix(in srgb, var(--color-acento) 16%, var(--color-superficie));
    color: var(--color-acento);
    font-weight: 700;
    font-size: 1rem;
}
.staff-card__foot { justify-content: center; }
</style>
