<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';
import { useAuthorization } from '../../../../composables/useAuthorization';

/**
 * Conteos físicos (§6.2, D172–D177).
 *
 * ## Un conteo abierto por almacén, y por eso la lista importa
 *
 * La base lo garantiza con una columna generada (D173): no puede haber dos conteos abiertos del mismo almacén. Así que
 * esta pantalla no es un archivo histórico — es el sitio donde se ve **qué se está contando ahora mismo**, y quien
 * intente abrir un segundo conteo del mismo almacén recibe un 422 que aquí se explica antes de que ocurra.
 *
 * ## Lo que esta lista NO muestra a quien cuenta
 *
 * La columna de diferencia sólo existe para quien tiene `inventory.counts.close`. Es el conteo ciego (D174), y se
 * respeta también en el listado: un total de diferencias de un conteo abierto sería la misma pista que la diferencia
 * renglón por renglón. El servidor omite el campo; aquí se omite la columna, porque una columna vacía delataría que hay
 * algo que no se está viendo.
 */
const { can } = useAuthorization();

const list = useResourceList('/stock-counts', { initialFilters: { status: '' } });

const warehouses = ref([]);
const opening = ref(false);
const form = ref({ warehouse_ulid: '', notes: '' });

/** Quien cierra ve las diferencias; quien sólo cuenta, no. Decide columnas, no sólo botones. */
const puedeVerDiferencias = computed(() => can('inventory.counts.close'));

onMounted(async () => {
    // Sin el de tránsito: no se cuenta lo que está en camino (D190). Contarlo daría un ajuste contra un almacén que
    // nadie puede visitar.
    warehouses.value = (await api.get('/warehouses', { status: 'active', per_page: 100 })).data
        .filter((w) => w.kind !== 'transit');

    await list.load();
});

/** Los almacenes que ya tienen un conteo abierto: abrir otro daría 422, así que se dice antes. */
const almacenesOcupados = computed(() => new Set(
    list.items.value.filter((c) => c.is_open).map((c) => c.warehouse?.ulid),
));

const disponibles = computed(() => warehouses.value.filter((w) => !almacenesOcupados.value.has(w.ulid)));

const save = useApiForm(async () => {
    // Sin `article_ulids`: el conteo completo del almacén es el caso normal y el servicio lo arma con lo que tenga
    // existencia. Contar un subconjunto es una operación distinta, y la trae la pantalla del conteo.
    const created = await api.post('/stock-counts', {
        warehouse_ulid: form.value.warehouse_ulid,
        notes: form.value.notes || null,
    });

    return created.data;
});

async function submit() {
    const created = await save.submit();

    if (created?.ulid) {
        router.visit(`/admin/conteos/${created.ulid}`);
    }
}

function startOpen() {
    form.value = { warehouse_ulid: disponibles.value[0]?.ulid ?? '', notes: '' };
    opening.value = true;
}

const columns = computed(() => [
    { key: 'warehouse', label: 'Almacén' },
    { key: 'status', label: 'Estado', width: '9rem' },
    { key: 'started', label: 'Abierto', width: '11rem' },
    { key: 'people', label: 'Quién' },
    ...puedeVerDiferencias.value ? [{ key: 'variance', label: 'Diferencia', width: '9rem' }] : [],
]);

/** Las clases de badge que el CSS compartido ya tiene: no se inventan tres nuevas para tres estados. */
const BADGES = { open: 'warn', closed: 'ok', cancelled: 'off' };

function fecha(iso) {
    return iso === null || iso === undefined ? '—' : new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
}

function dinero(valor) {
    return valor === null || valor === undefined
        ? '—'
        : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(valor));
}
</script>

<template>
    <Head title="Conteos físicos" />

    <header class="page-header">
        <div>
            <h1>Conteos físicos</h1>
            <p class="page-header__hint">
                Un conteo es la única forma de corregir el inventario contra la realidad. Se cuenta
                <strong>a ciegas</strong>: quien captura no ve lo que el sistema esperaba, porque un número a la vista
                deja de contarse y se confirma.
            </p>
        </div>

        <button
            v-can.write="'inventory.counts.create'"
            class="button"
            type="button"
            :disabled="disponibles.length === 0"
            :title="disponibles.length === 0 ? 'Todos los almacenes tienen ya un conteo abierto.' : ''"
            @click="startOpen"
        >
            Abrir conteo
        </button>
    </header>

    <p v-if="!list.loading.value && disponibles.length === 0 && warehouses.length > 0" class="alert alert--notice">
        Todos los almacenes tienen un conteo abierto. Sólo puede haber <strong>uno por almacén</strong>: dos hojas de
        conteo simultáneas del mismo estante producen dos ajustes que se pisan, y el segundo corrige contra un saldo que
        el primero ya cambió.
    </p>

    <div class="toolbar">
        <select v-model="list.filters.status" class="input input--select">
            <option value="">Todos los estados</option>
            <option value="open">Abiertos</option>
            <option value="closed">Cerrados</option>
            <option value="cancelled">Cancelados</option>
        </select>
    </div>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay conteos que coincidan."
    >
        <template #cell:warehouse="{ row }">
            <a :href="`/admin/conteos/${row.ulid}`" class="link">{{ row.warehouse?.name ?? '—' }}</a>
            <span class="muted"> {{ row.warehouse?.code }}</span>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="`badge--${BADGES[row.status] ?? 'off'}`">{{ row.status_label }}</span>
        </template>

        <template #cell:started="{ row }">{{ fecha(row.started_at) }}</template>

        <template #cell:people="{ row }">
            <span>{{ row.started_by?.name ?? '—' }}</span>
            <span v-if="row.closed_by" class="muted"> · cerró {{ row.closed_by.name }}</span>
        </template>

        <template #cell:variance="{ row }">
            <!--
                `variance_value` no viaja cuando el conteo está abierto y quien mira no puede cerrar. El guion dice
                «todavía no hay cifra», que es distinto de cero.
            -->
            <span :class="{ 'is-negative': Number(row.variance_value) < 0 }">{{ dinero(row.variance_value) }}</span>
        </template>
    </DataTable>

    <div v-if="opening" class="drawer-backdrop" @click.self="opening = false">
        <form class="drawer drawer--narrow" @submit.prevent="submit">
            <h2>Abrir conteo</h2>

            <p class="drawer__hint">
                La hoja se arma con los artículos que hoy tienen existencia en el almacén, y con el costo vigente
                congelado en ese instante: si el costo cambia mientras se cuenta, el ajuste se valúa con el de cuando se
                abrió — que es el que corresponde a la mercancía contada.
            </p>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Almacén</span>
                <select v-model="form.warehouse_ulid" class="input" required>
                    <option v-for="w in disponibles" :key="w.ulid" :value="w.ulid">{{ w.name }} ({{ w.code }})</option>
                </select>
                <span v-if="save.fieldErrors.value.warehouse_ulid" class="field__error">
                    {{ save.fieldErrors.value.warehouse_ulid }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Notas</span>
                <textarea v-model="form.notes" class="input" rows="2" maxlength="300"></textarea>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="opening = false">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Abrir y capturar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.is-negative {
    color: #b91c1c;
}

.muted {
    color: #6b7280;
    font-size: 0.85rem;
}

.link {
    color: #1d4ed8;
    text-decoration: none;
}

.link:hover {
    text-decoration: underline;
}

.drawer__hint {
    margin: 0 0 0.9rem;
    color: #6b7280;
    font-size: 0.85rem;
}
</style>
