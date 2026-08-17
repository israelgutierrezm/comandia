<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';

/**
 * Almacenes (D11).
 *
 * La topología va de lo simple —un almacén por sucursal— a lo fino —consumo por área—, y la pantalla
 * la refleja: el tipo se elige al crear y no se puede cambiar después, porque cambiarlo
 * reinterpretaría todo el histórico de existencias del almacén.
 */
const list = useResourceList('/warehouses', { initialFilters: { status: '', kind: '' } });
const branches = ref([]);

onMounted(async () => {
    await list.load();

    // Para el selector del formulario: sólo activas, porque crear un almacén en una sucursal dada de
    // baja no tiene sentido.
    branches.value = (await api.get('/branches', { status: 'active', per_page: 100 })).data;
});

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    if (editing.value === 'new') {
        await api.post('/warehouses', {
            code: form.value.code,
            name: form.value.name,
            kind: form.value.kind,
            // Un almacén central no pertenece a ninguna sucursal: surte a todas. El servidor lo
            // rechaza si se manda, así que la UI ni lo envía.
            branch_ulid: form.value.kind === 'central' ? undefined : form.value.branch_ulid,
        });
    } else {
        await api.patch(`/warehouses/${editing.value.ulid}`, { name: form.value.name });
    }
});

const archive = useApiForm(async (warehouse) => {
    await api.post(`/warehouses/${warehouse.ulid}/archive`);
});

function startCreate() {
    editing.value = 'new';
    form.value = { code: '', name: '', kind: 'branch', branch_ulid: branches.value[0]?.ulid ?? '' };
}

function startEdit(warehouse) {
    editing.value = warehouse;
    form.value = { name: warehouse.name };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

async function confirmArchive(warehouse) {
    if (!window.confirm(`¿Dar de baja el almacén «${warehouse.name}»?`)) {
        return;
    }

    // Puede fallar con 409 si un área activa consume de él: el mensaje del servidor explica qué
    // reconfigurar, así que se muestra tal cual.
    if (await archive.submit(warehouse)) {
        await list.load();
    }
}

const columns = [
    { key: 'code', label: 'Código', width: '8rem' },
    { key: 'name', label: 'Nombre' },
    { key: 'kind', label: 'Tipo', width: '9rem' },
    { key: 'branch', label: 'Sucursal' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '9rem' },
];
</script>

<template>
    <Head title="Almacenes" />

    <header class="page-header">
        <div>
            <h1>Almacenes</h1>
            <p class="page-header__hint">
                Un almacén <strong>central</strong> no pertenece a ninguna sucursal: surte a todas.
                El tipo no se puede cambiar después, porque reinterpretaría su histórico de
                existencias.
            </p>
        </div>

        <button v-can.write="'organization.warehouses.manage'" class="button" type="button" @click="startCreate">
            Nuevo almacén
        </button>
    </header>

    <div class="toolbar">
        <input v-model="list.filters.search" type="search" class="input" placeholder="Buscar…" />

        <select v-model="list.filters.kind" class="input input--select">
            <option value="">Todos los tipos</option>
            <option value="branch">De sucursal</option>
            <option value="central">Centrales</option>
        </select>

        <select v-model="list.filters.status" class="input input--select">
            <option value="">Todos</option>
            <option value="active">Activos</option>
            <option value="inactive">Dados de baja</option>
        </select>
    </div>

    <p v-if="archive.generalError" class="alert">{{ archive.generalError }}</p>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay almacenes que coincidan."
    >
        <template #cell:kind="{ row }">
            <span class="badge" :class="row.is_central ? 'badge--warn' : 'badge--off'">
                {{ row.is_central ? 'Central' : 'De sucursal' }}
            </span>
        </template>

        <template #cell:branch="{ row }">
            {{ row.branch?.name ?? 'Surte a todas' }}
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ row.status === 'active' ? 'Activo' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button v-can.write="'organization.warehouses.manage'" class="link-button" type="button" @click="startEdit(row)">
                    Editar
                </button>
                <button
                    v-if="row.status === 'active'"
                    v-can.write="'organization.warehouses.manage'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="confirmArchive(row)"
                >
                    Dar de baja
                </button>
            </div>
        </template>
    </DataTable>

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <h2>{{ editing === 'new' ? 'Nuevo almacén' : `Editar ${editing.name}` }}</h2>

            <p v-if="save.generalError" class="alert">{{ save.generalError }}</p>

            <template v-if="editing === 'new'">
                <label class="field">
                    <span class="field__label">Código</span>
                    <input v-model="form.code" class="input" maxlength="20" required />
                    <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
                </label>

                <label class="field">
                    <span class="field__label">Tipo</span>
                    <select v-model="form.kind" class="input">
                        <option value="branch">De sucursal</option>
                        <option value="central">Central (surte a todas)</option>
                    </select>
                </label>

                <label v-if="form.kind === 'branch'" class="field">
                    <span class="field__label">Sucursal</span>
                    <select v-model="form.branch_ulid" class="input" required>
                        <option v-for="branch in branches" :key="branch.ulid" :value="branch.ulid">
                            {{ branch.name }}
                        </option>
                    </select>
                    <span v-if="save.fieldErrors.value.branch_ulid" class="field__error">
                        {{ save.fieldErrors.value.branch_ulid }}
                    </span>
                </label>
            </template>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="120" required />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';
</style>
