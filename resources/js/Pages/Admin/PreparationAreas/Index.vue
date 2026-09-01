<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import Paginacion from '../../../components/Paginacion.vue';

const view = ref('list');

// El orden importa: es cómo se listan las áreas en el POS. Se muestran POR `sort_order` para que arrastrar tenga sentido.
const ordenadas = computed(() => [...list.items.value].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0)));

const reorderError = ref(null);
const reordenando = ref(false);

/**
 * Reordenar arrastrando: renumera las áreas por su nueva posición (10, 20, 30… deja hueco para insertar a mano) y
 * persiste sólo las que cambiaron. Un solo PATCH por área cambiada; son pocas.
 */
async function reordenar(nuevas) {
    reorderError.value = null;
    reordenando.value = true;

    try {
        const cambios = nuevas
            .map((area, i) => ({ ulid: area.ulid, sort_order: (i + 1) * 10, antes: Number(area.sort_order ?? 0) }))
            .filter((c) => c.sort_order !== c.antes);

        await Promise.all(cambios.map((c) => api.patch(`/preparation-areas/${c.ulid}`, { sort_order: c.sort_order })));
        await list.load();
    } catch (e) {
        if (e instanceof ApiError) {
            reorderError.value = e.title;
        } else {
            throw e;
        }
    } finally {
        reordenando.value = false;
    }
}

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
const printers = ref([]);

onMounted(async () => {
    await list.load();

    const [sucursales, almacenes, impresoras] = await Promise.all([
        api.get('/branches', { status: 'active', per_page: 100 }),
        api.get('/warehouses', { status: 'active', per_page: 100 }),
        api.get('/printers', { status: 'active', per_page: 100 }),
    ]);

    branches.value = sucursales.data;
    warehouses.value = almacenes.data;
    printers.value = impresoras.data;
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

            // Cadena vacía = «sin impresora», y se manda como `null`: es lo que el servidor entiende por desasignar.
            printer_ulid: form.value.printer_ulid === '' ? null : form.value.printer_ulid,
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
        printer_ulid: area.printer?.ulid ?? '',
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
    { key: 'printer', label: 'Imprime en', width: '10rem' },
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

        <ViewToggle v-model="view" persist-key="comandia:view:areas" class="toolbar__view" />
    </div>

    <p v-if="reorderError" class="alert">{{ reorderError }}</p>
    <p v-if="view === 'list'" class="reorder-hint">Arrastra ⠿ para cambiar el orden en que se listan en el POS.</p>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="ordenadas"
        :loading="list.loading.value"
        :error="list.error.value"
        reorderable
        empty-message="Todavía no hay áreas de preparación."
        @reorder="reordenar"
    >
        <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>

        <template #cell:warehouse="{ row }">
            {{ row.warehouse?.name ?? '—' }}
            <span v-if="row.warehouse?.is_central" class="badge badge--warn">Central</span>
        </template>

        <template #cell:printer="{ row }">
            <!--
                «Sin asignar» con palabras y no un guion: un área sin impresora es un caso legítimo —el cocinero puede
                estar a dos metros— pero también es lo que hay que ver antes de esperar comandas en papel.
            -->
            <span v-if="row.printer">{{ row.printer.name }}</span>
            <span v-else class="muted-cell">Sin asignar</span>
        </template>

        <template #cell:actions="{ row }">
            <button v-can.write="'organization.preparation_areas.manage'" class="link-button" type="button" @click="startEdit(row)">
                Editar
            </button>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="ordenadas"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay áreas de preparación."
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__code">{{ item.code }} · orden {{ item.sort_order }}</span>
                <span class="card__title">{{ item.name }}</span>
                <span class="card__meta">{{ item.branch?.name ?? '—' }}</span>
                <span class="card__foot">
                    <span class="card__meta">Descuenta de: {{ item.warehouse?.name ?? '—' }}</span>
                    <span v-if="item.warehouse?.is_central" class="badge badge--warn">Central</span>
                </span>
                <span class="card__meta">Imprime en: {{ item.printer ? item.printer.name : 'sin impresora' }}</span>
                <div class="card__actions">
                    <button v-can.write="'organization.preparation_areas.manage'" class="link-button" type="button" @click="startEdit(item)">
                        Editar
                    </button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="áreas" />

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

            <label v-if="editing !== 'new'" class="field">
                <span class="field__label">Imprime sus comandas en</span>
                <select v-model="form.printer_ulid" class="input">
                    <option value="">Sin impresora</option>
                    <option v-for="p in printers" :key="p.ulid" :value="p.ulid">
                        {{ p.name }} ({{ p.code }})
                    </option>
                </select>
                <span class="field__hint">
                    Las comandas de esta área salen por aquí, sin importar quién las capture. Un área sin impresora no
                    imprime nada y el punto de venta lo dice al comandar.
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

.muted-cell {
    color: #6b7280;
    font-size: 0.85rem;
}

.reorder-hint { margin: 0 0 0.6rem; font-size: 0.8rem; color: var(--color-suave); }
</style>
