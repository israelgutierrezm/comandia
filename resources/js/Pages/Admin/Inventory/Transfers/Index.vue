<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import ArticlePicker from '../../../../components/catalog/ArticlePicker.vue';
import Icon from '../../../../components/Icon.vue';

/**
 * Transferencias entre almacenes (D25, D178–D190).
 *
 * ## La lista abre por lo que espera acción
 *
 * `only_open` es el filtro por omisión: una transferencia recibida hace tres meses no le interesa a nadie que esté
 * trabajando, y una solicitada ayer que nadie preparó sí. El archivo histórico se consulta apagando el filtro.
 *
 * ## Origen y destino, no «entrada» y «salida»
 *
 * El mismo documento es salida para uno y entrada para otro, así que la lista no toma partido: muestra los dos extremos
 * y el estado. Quien mira sabe cuál de los dos almacenes es el suyo.
 */
const list = useResourceList('/transfers', { initialFilters: { status: '', only_open: 1 } });

// «Sólo las que esperan acción» viene activada por defecto: cuenta como filtro cuando se APAGA.
const filtrosActivos = computed(
    () => [list.filters.status !== '', Number(list.filters.only_open) !== 1].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.status = '';
    list.filters.only_open = 1;
}

const warehouses = ref([]);
const requesting = ref(false);
const form = ref({ origin_warehouse_ulid: '', destination_warehouse_ulid: '', notes: '', lines: [] });

onMounted(async () => {
    // El de tránsito NO es elegible: no es un almacén donde nadie ponga ni saque mercancía a mano — lo escribe la
    // propia transferencia al enviar (D190). Ofrecerlo daría un 422.
    warehouses.value = (await api.get('/warehouses', { status: 'active', per_page: 100 })).data
        .filter((w) => w.kind !== 'transit');

    await list.load();
});

/**
 * Los destinos posibles, sin el origen elegido.
 *
 * Y hay una restricción más que el servidor impone (D188): al menos uno de los dos extremos tiene que ser de sucursal.
 * Central a central se rechaza en v1 porque el folio se emite por sucursal y no habría cuál usar. No se filtra aquí
 * —serían dos reglas que mantener— pero se explica antes de enviar.
 */
const destinos = computed(() => warehouses.value.filter((w) => w.ulid !== form.value.origin_warehouse_ulid));

const save = useApiForm(async () => {
    const created = await api.post('/transfers', {
        origin_warehouse_ulid: form.value.origin_warehouse_ulid,
        destination_warehouse_ulid: form.value.destination_warehouse_ulid,
        notes: form.value.notes || null,
        lines: form.value.lines.map((l) => ({ article_ulid: l.article.ulid, quantity: l.quantity })),
    });

    return created.data;
});

async function submit() {
    const created = await save.submit();

    if (created?.ulid) {
        router.visit(`/admin/transferencias/${created.ulid}`);
    }
}

function startRequest() {
    form.value = {
        origin_warehouse_ulid: warehouses.value[0]?.ulid ?? '',
        destination_warehouse_ulid: '',
        notes: '',
        lines: [],
    };

    requesting.value = true;
}

/**
 * Sólo se transfiere lo INVENTARIABLE, y se dice aquí en lugar de dejar que el 422 lo explique.
 *
 * El buscador de artículos es genérico —lo comparten compras, recetas y esta pantalla— y filtrar por capacidad dentro de
 * él obligaría a cada llamador a declarar la suya. Se filtra al agregar: el artículo elegido trae sus capacidades.
 */
const rechazado = ref(null);

function addLine(article) {
    if (article.capabilities?.inventoriable === false) {
        rechazado.value = `${article.name} no es un artículo inventariable, así que no tiene existencia que mover de un almacén a otro.`;

        return;
    }

    rechazado.value = null;

    if (form.value.lines.some((l) => l.article.ulid === article.ulid)) {
        return;
    }

    form.value.lines.push({ article, quantity: '' });
}

const BADGES = {
    requested: 'warn',
    authorized: 'warn',
    preparing: 'warn',
    shipped: 'warn',
    received: 'ok',
    received_with_differences: 'warn',
    cancelled: 'off',
};

const columns = [
    { key: 'folio', label: 'Folio', width: '8rem' },
    { key: 'route', label: 'Ruta' },
    { key: 'status', label: 'Estado', width: '12rem' },
    { key: 'when', label: 'Solicitada', width: '11rem' },
];

function fecha(iso) {
    return iso === null || iso === undefined
        ? '—'
        : new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <Head title="Transferencias" />

    <ListHeader
        title="Transferencias"
        subtitle="Mientras la mercancía viaja no está en ningún almacén de nadie: está en el almacén de tránsito. Así lo que salió y todavía no llega sigue siendo tuyo y se puede contar, en lugar de desaparecer entre dos sucursales."
        :count="list.meta.value?.total ?? null"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todos los estados</option>
                <option value="requested">Solicitadas</option>
                <option value="authorized">Autorizadas</option>
                <option value="preparing">En preparación</option>
                <option value="shipped">Enviadas</option>
                <option value="received">Recibidas</option>
                <option value="received_with_differences">Recibidas con diferencias</option>
                <option value="cancelled">Canceladas</option>
            </select>

            <label class="checkbox">
                <input v-model="list.filters.only_open" type="checkbox" :true-value="1" :false-value="0" />
                Sólo las que esperan acción
            </label>
        </template>

        <template #action>
            <button v-can.write="'inventory.transfers.request'" class="button" type="button" @click="startRequest">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Solicitar transferencia
            </button>
        </template>
    </ListHeader>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay transferencias que coincidan."
    >
        <template #cell:folio="{ row }">
            <a :href="`/admin/transferencias/${row.ulid}`" class="link">{{ row.folio }}</a>
        </template>

        <template #cell:route="{ row }">
            {{ row.origin_warehouse?.name ?? '—' }}
            <span class="muted">→</span>
            {{ row.destination_warehouse?.name ?? '—' }}
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="`badge--${BADGES[row.status] ?? 'off'}`">{{ row.status_label }}</span>
        </template>

        <template #cell:when="{ row }">{{ fecha(row.steps?.requested?.at) }}</template>
    </DataTable>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="transferencias" />

    <div v-if="requesting" class="drawer-backdrop" @click.self="requesting = false">
        <form class="drawer" @submit.prevent="submit">
            <h2>Solicitar transferencia</h2>

            <p class="drawer__hint">
                Solicitar no mueve nada: sólo pide. El inventario del origen baja cuando alguien
                <strong>envía</strong>, y el del destino sube cuando alguien <strong>recibe</strong> — porque son dos
                hechos físicos distintos y entre ellos la mercancía está en camino.
            </p>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <div class="fields">
                <label class="field">
                    <span class="field__label">Origen</span>
                    <select v-model="form.origin_warehouse_ulid" class="input" required>
                        <option v-for="w in warehouses" :key="w.ulid" :value="w.ulid">{{ w.name }}</option>
                    </select>
                    <span v-if="save.fieldErrors.value.origin_warehouse_ulid" class="field__error">
                        {{ save.fieldErrors.value.origin_warehouse_ulid }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Destino</span>
                    <select v-model="form.destination_warehouse_ulid" class="input" required>
                        <option value="" disabled>Elige el destino</option>
                        <option v-for="w in destinos" :key="w.ulid" :value="w.ulid">{{ w.name }}</option>
                    </select>
                    <span v-if="save.fieldErrors.value.destination_warehouse_ulid" class="field__error">
                        {{ save.fieldErrors.value.destination_warehouse_ulid }}
                    </span>
                    <span class="field__hint">
                        Al menos uno de los dos extremos tiene que ser de sucursal: el folio se emite por sucursal, y
                        entre dos almacenes centrales no habría cuál usar.
                    </span>
                </label>
            </div>

            <div class="field">
                <span class="field__label">Artículos</span>
                <ArticlePicker placeholder="Buscar artículo a transferir…" @picked="addLine" />
                <span v-if="rechazado" class="field__error">{{ rechazado }}</span>
            </div>

            <table v-if="form.lines.length > 0" class="lines">
                <thead>
                    <tr>
                        <th>Artículo</th>
                        <th style="width: 9rem">Cantidad</th>
                        <th style="width: 3rem"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in form.lines" :key="line.article.ulid">
                        <td>
                            {{ line.article.name }}
                            <span class="muted">{{ line.article.base_unit?.code }}</span>
                        </td>
                        <td>
                            <input v-model="line.quantity" class="input" inputmode="decimal" required />
                            <span v-if="save.fieldErrors.value[`lines.${index}.quantity`]" class="field__error">
                                {{ save.fieldErrors.value[`lines.${index}.quantity`] }}
                            </span>
                        </td>
                        <td>
                            <button type="button" class="link-button link-button--danger" @click="form.lines.splice(index, 1)"><Icon name="trash" /> Quitar</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <label class="field">
                <span class="field__label">Notas</span>
                <textarea v-model="form.notes" class="input" rows="2" maxlength="300"></textarea>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="requesting = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value || form.lines.length === 0"><Icon name="plus" /> Solicitar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

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

.checkbox {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.9rem;
}

.fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.lines {
    width: 100%;
    border-collapse: collapse;
    margin: 0.5rem 0 0.9rem;
    font-size: 0.9rem;
}

.lines th,
.lines td {
    padding: 0.35rem 0.4rem;
    text-align: left;
    border-bottom: 1px solid #e7e5e4;
}
</style>
