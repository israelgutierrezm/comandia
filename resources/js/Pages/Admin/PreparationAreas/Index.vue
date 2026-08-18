<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';

/**
 * Áreas de preparación (§3, D11).
 *
 * Un área es dos cosas a la vez: destino de comandas **y** punto de consumo de inventario. La segunda
 * es la que la pantalla tiene que dejar clara, porque es la que produce errores costosos: el almacén
 * del que descuenta es obligatorio y tiene que ser alcanzable desde su sucursal —el propio o un
 * central—. Si no, la cocina de una sucursal descontaría del almacén de otra.
 */
const list = useResourceList('/preparation-areas', { initialFilters: { status: '' } });
const branches = ref([]);
const warehouses = ref([]);

onMounted(async () => {
    await list.load();

    branches.value = (await api.get('/branches', { status: 'active', per_page: 100 })).data;
    warehouses.value = (await api.get('/warehouses', { status: 'active', per_page: 100 })).data;
});

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    if (editing.value === 'new') {
        await api.post('/preparation-areas', form.value);
    } else {
        // Ni sucursal ni código: el área es destino de comandas ya impresas. El almacén SÍ se puede
        // cambiar — es el ajuste que D11 prevé al pasar de un almacén por sucursal a consumo fino.
        await api.patch(`/preparation-areas/${editing.value.ulid}`, {
            name: form.value.name,
            sort_order: form.value.sort_order,
            warehouse_ulid: form.value.warehouse_ulid,
        });
    }
});

function startCreate() {
    editing.value = 'new';
    form.value = {
        branch_ulid: branches.value[0]?.ulid ?? '',
        warehouse_ulid: warehouses.value[0]?.ulid ?? '',
        code: '',
        name: '',
        sort_order: 0,
    };
}

function startEdit(area) {
    editing.value = area;
    form.value = {
        name: area.name,
        sort_order: area.sort_order,
        warehouse_ulid: area.warehouse?.ulid ?? '',
    };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

const columns = [
    { key: 'sort_order', label: 'Orden', width: '5rem' },
    { key: 'code', label: 'Código', width: '7rem' },
    { key: 'name', label: 'Área' },
    { key: 'branch', label: 'Sucursal' },
    { key: 'warehouse', label: 'Descuenta de' },
    { key: 'actions', label: '', width: '6rem' },
];
</script>

<template>
    <Head title="Áreas de preparación" />

    <header class="page-header">
        <div>
            <h1>Áreas de preparación</h1>
            <p class="page-header__hint">
                Cada área es destino de comandas y punto de consumo de inventario: lo que se prepara
                aquí se descuenta del almacén indicado. El orden define cómo se listan en el POS.
            </p>
        </div>

        <button v-can.write="'organization.preparation_areas.manage'" class="button" type="button" @click="startCreate">
            Nueva área
        </button>
    </header>

    <div class="toolbar">
        <input v-model="list.filters.search" type="search" class="input" placeholder="Buscar…" />

        <select v-model="list.filters.status" class="input input--select">
            <option value="">Todas</option>
            <option value="active">Activas</option>
            <option value="inactive">Dadas de baja</option>
        </select>
    </div>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay áreas de preparación."
    >
        <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>

        <template #cell:warehouse="{ row }">
            {{ row.warehouse?.name ?? '—' }}
            <span v-if="row.warehouse?.is_central" class="badge badge--warn">Central</span>
        </template>

        <template #cell:actions="{ row }">
            <button v-can.write="'organization.preparation_areas.manage'" class="link-button" type="button" @click="startEdit(row)">
                Editar
            </button>
        </template>
    </DataTable>

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <h2>{{ editing === 'new' ? 'Nueva área' : `Editar ${editing.name}` }}</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <template v-if="editing === 'new'">
                <label class="field">
                    <span class="field__label">Sucursal</span>
                    <select v-model="form.branch_ulid" class="input" required>
                        <option v-for="branch in branches" :key="branch.ulid" :value="branch.ulid">
                            {{ branch.name }}
                        </option>
                    </select>
                    <span class="field__hint">No se podrá cambiar: el área es destino de comandas.</span>
                </label>

                <label class="field">
                    <span class="field__label">Código</span>
                    <input v-model="form.code" class="input" maxlength="20" required />
                    <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
                </label>
            </template>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="80" required />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <label class="field">
                <span class="field__label">Descuenta del almacén</span>
                <select v-model="form.warehouse_ulid" class="input" required>
                    <option v-for="warehouse in warehouses" :key="warehouse.ulid" :value="warehouse.ulid">
                        {{ warehouse.name }}{{ warehouse.is_central ? ' (central)' : '' }}
                    </option>
                </select>
                <span class="field__hint">
                    Debe ser un almacén de la misma sucursal o uno central.
                </span>
                <span v-if="save.fieldErrors.value.warehouse_ulid" class="field__error">
                    {{ save.fieldErrors.value.warehouse_ulid }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Orden</span>
                <input v-model.number="form.sort_order" type="number" min="0" class="input" />
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
