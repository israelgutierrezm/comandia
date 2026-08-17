<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';

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

onMounted(list.load);

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
    { key: 'pin', label: 'PIN', width: '8rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '14rem' },
];
</script>

<template>
    <Head title="Personal" />

    <header class="page-header">
        <div>
            <h1>Personal</h1>
            <p class="page-header__hint">
                Sin <strong>código de empleado</strong> no se puede autorizar con PIN: la
                autorización identifica a la persona por su código.
            </p>
        </div>
    </header>

    <div class="toolbar">
        <input v-model="list.filters.search" type="search" class="input" placeholder="Buscar por código…" />

        <select v-model="list.filters.status" class="input input--select">
            <option value="">Todos</option>
            <option value="active">Activos</option>
            <option value="invited">Invitados</option>
            <option value="suspended">Suspendidos</option>
            <option value="terminated">Baja</option>
        </select>
    </div>

    <p v-if="statusAction.generalError" class="alert">{{ statusAction.generalError }}</p>
    <p v-if="pinAction.generalError" class="alert">{{ pinAction.generalError }}</p>

    <DataTable
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

        <template #cell:default_role="{ row }">
            {{ row.default_role?.name ?? '—' }}
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

    <div v-if="pinTarget" class="drawer-backdrop" @click.self="pinTarget = null">
        <form class="drawer" @submit.prevent="submitPin">
            <h2>PIN de {{ pinTarget.display_name }}</h2>

            <p class="field__hint">
                El PIN se guarda cifrado y no se puede consultar después. Si se olvida, se asigna uno
                nuevo.
            </p>

            <p v-if="setPin.generalError" class="alert">{{ setPin.generalError }}</p>

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
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.muted {
    color: #a8a29e;
    font-size: 0.85rem;
}
</style>
