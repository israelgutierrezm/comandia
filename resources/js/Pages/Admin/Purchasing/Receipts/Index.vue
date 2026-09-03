<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';
import FormHeader from '../../../../components/FormHeader.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import ArticlePicker from '../../../../components/catalog/ArticlePicker.vue';
import Icon from '../../../../components/Icon.vue';

/**
 * Recepciones de compra (D26, §3.2).
 *
 * ## El borrador existe para cuadrar con el papel
 *
 * Capturar no mueve nada: la recepción nace en borrador y ahí se comparan los totales con la factura
 * antes de aplicar. Confirmar es lo que mueve existencia y deja un costo en un historial inmutable, y va
 * en otra pantalla —la del documento— porque es la decisión, no la captura.
 *
 * ## Los importes los calcula el servidor
 *
 * Aquí se teclea cantidad, precio sin IVA y tasa; el subtotal y el total llegan en la respuesta. Un
 * total calculado en el cliente podría no cuadrar con sus renglones, y ese documento no se puede
 * conciliar con la factura — que es lo único que la recepción existe para hacer.
 */
const list = useResourceList('/purchase-receipts', {
    initialFilters: { status: '', supplier: '', only_drafts: '' },
});

const filtrosActivos = computed(
    () => [list.filters.supplier !== '', list.filters.status !== ''].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.supplier = '';
    list.filters.status = '';
}

const suppliers = ref([]);
const warehouses = ref([]);
const capturing = ref(false);
const form = ref({ supplier_ulid: '', warehouse_ulid: '', received_at: '', supplier_document_number: '', lines: [] });

onMounted(async () => {
    await list.load();

    const [suppliersResponse, warehousesResponse] = await Promise.all([
        api.get('/suppliers', { status: 'active', per_page: 200 }),
        api.get('/warehouses', { status: 'active', per_page: 100 }),
    ]);

    suppliers.value = suppliersResponse.data;

    // El almacén de tránsito no recibe compras: lo escriben sólo las transferencias (D190).
    warehouses.value = warehousesResponse.data.filter((w) => w.kind !== 'transit');
});

const save = useApiForm(async () => {
    const receipt = await api.post('/purchase-receipts', {
        supplier_ulid: form.value.supplier_ulid,
        warehouse_ulid: form.value.warehouse_ulid,
        received_at: form.value.received_at,
        supplier_document_number: form.value.supplier_document_number || null,
        lines: form.value.lines.map((line) => ({
            article_ulid: line.article.ulid,
            presentation_ulid: line.presentation_ulid || null,
            quantity: line.quantity,
            unit_price: line.unit_price,
            tax_rate: line.tax_rate === '' ? undefined : line.tax_rate,
            lot_code: line.lot_code || null,
            expires_at: line.expires_at || null,
        })),
    });

    return receipt.data;
});

function startCapture() {
    capturing.value = true;
    form.value = {
        supplier_ulid: suppliers.value[0]?.ulid ?? '',
        warehouse_ulid: warehouses.value[0]?.ulid ?? '',
        received_at: new Date().toISOString().slice(0, 10),
        supplier_document_number: '',
        lines: [],
    };
}

/**
 * Agrega un renglón con el artículo elegido.
 *
 * La presentación se ofrece sólo si el artículo tiene: el precio se captura **por presentación** cuando
 * hay una («la caja a 480») y **por unidad base** cuando no («el kilo a 42»). La distinción es del
 * servidor y aquí sólo se refleja, con el sufijo del campo diciéndolo.
 */
async function addLine(article) {
    if (form.value.lines.some((line) => line.article.ulid === article.ulid)) {
        return;
    }

    // Las presentaciones se piden ANTES de agregar el renglón, y el orden importa.
    //
    // La primera versión lo agregaba primero y las cargaba después. El renglón aparecía diciendo «Precio sin
    // IVA por g» y, cuando la petición volvía, se autoseleccionaba la primera presentación y la etiqueta
    // pasaba a «por presentación» — **cambiando el significado del campo de precio bajo las manos de quien
    // estaba escribiendo**. Alguien que teclea «0.0320 por gramo» acabaría capturando «0.0320 por caja».
    //
    // Lo encontré en el navegador: capturé 5000 leyendo «por g» y el documento guardó 60 000 000 g, porque el
    // selector ya se había puesto en «Caja de 12 kg» sin que yo lo viera. La suite no podía verlo — no hay
    // carrera cuando nadie está leyendo la pantalla.
    //
    // El recurso del artículo NO trae las presentaciones, así que hay que pedirlas: suponerlo habría dejado el
    // selector vacío sin que nada avisara.
    const presentations = (await api.get(`/articles/${article.ulid}/presentations`)).data ?? [];

    form.value.lines.push({
        article,
        presentations,

        // La primera por omisión: recibir «una caja» es más común que recibir «doce mil gramos», y quien compra
        // a granel puede cambiarlo a unidad base. Ya está puesta cuando el renglón aparece, así que la etiqueta
        // del precio dice la verdad desde el primer momento.
        presentation_ulid: presentations[0]?.ulid ?? '',

        quantity: '1',
        unit_price: '',

        // Vacío a propósito: el servidor aplica la tasa del negocio cuando el renglón la omite. Escribir «16»
        // aquí duplicaría un dato de configuración en el cliente, y el día que el negocio cambie de tasa la
        // pantalla seguiría diciendo 16 sin que nada avisara.
        //
        // La primera versión leía el ajuste con `GET /settings/tax.vat_rate`, que no existe — y el índice que sí
        // existe exige un permiso que el almacenista no tiene, o sea justo quien captura facturas.
        tax_rate: '',

        lot_code: '',
        expires_at: '',
    });
}

function removeLine(index) {
    form.value.lines.splice(index, 1);
}

async function submit() {
    const created = await save.submit();

    if (created) {
        capturing.value = false;
        // Al documento: es donde se revisa y se confirma.
        router.visit(`/admin/recepciones/${created.ulid}`);
    }
}

const columns = [
    { key: 'folio', label: 'Folio', width: '7rem' },
    { key: 'supplier', label: 'Proveedor' },
    { key: 'warehouse', label: 'Almacén', width: '11rem' },
    { key: 'received_at', label: 'Recibida', width: '8rem' },
    { key: 'document', label: 'Factura', width: '9rem' },
    { key: 'total', label: 'Total', width: '9rem', align: 'right' },
    { key: 'status', label: 'Estado', width: '10rem' },
];
</script>

<template>
    <Head title="Recepciones de compra" />

    <ListHeader
        title="Recepciones de compra"
        subtitle="Capturar no mueve nada: la recepción nace en borrador para poder cuadrar los totales con la factura. Confirmarla es lo que da entrada al inventario y fija el costo, y eso se hace desde el documento."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar por folio de factura…"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.supplier" class="input input--select">
                <option value="">Todos los proveedores</option>
                <option v-for="supplier in suppliers" :key="supplier.ulid" :value="supplier.ulid">
                    {{ supplier.display_name }}
                </option>
            </select>

            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todos los estados</option>
                <option value="draft">Borradores</option>
                <option value="confirmed">Confirmadas</option>
                <option value="cancelled">Canceladas</option>
            </select>
        </template>

        <template #action>
            <button v-can.write="'purchasing.receipts.create'" class="button" type="button" @click="startCapture">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Capturar factura
            </button>
        </template>
    </ListHeader>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay recepciones que coincidan."
    >
        <template #cell:folio="{ row }">
            <Link :href="`/admin/recepciones/${row.ulid}`" class="link-button">{{ row.folio }}</Link>
        </template>

        <template #cell:supplier="{ row }">
            {{ row.supplier?.name ?? '—' }}
        </template>

        <template #cell:warehouse="{ row }">
            {{ row.warehouse?.name ?? '—' }}
        </template>

        <template #cell:received_at="{ row }">
            {{ row.received_at }}
        </template>

        <template #cell:document="{ row }">
            <span v-if="row.supplier_document_number">{{ row.supplier_document_number }}</span>
            <span v-else class="muted">sin factura</span>
        </template>

        <template #cell:total="{ row }">
            <!-- `null` en borrador: los totales se calculan al confirmar. Se dice «pendiente» en lugar de
                 pintar un cero, que afirmaría que la factura no cuesta nada. -->
            <span v-if="row.total !== null">{{ row.total }}</span>
            <span v-else class="muted">pendiente</span>
        </template>

        <template #cell:status="{ row }">
            <span
                class="badge"
                :class="{
                    'badge--warn': row.status === 'draft',
                    'badge--ok': row.status === 'confirmed',
                    'badge--off': row.status === 'cancelled',
                }"
            >
                {{ row.status_label }}
            </span>
            <small v-if="row.is_reversal" class="muted">reversa de {{ row.reverses?.folio }}</small>
        </template>
    </DataTable>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="recepciones" />

    <div v-if="capturing" class="drawer-backdrop" @click.self="capturing = false">
        <form class="drawer drawer--wide" @submit.prevent="submit">
            <FormHeader title="Capturar factura" />

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <div class="field-row">
                <label class="field">
                    <span class="field__label">Proveedor</span>
                    <select v-model="form.supplier_ulid" class="input" required>
                        <option v-for="supplier in suppliers" :key="supplier.ulid" :value="supplier.ulid">
                            {{ supplier.display_name }}
                        </option>
                    </select>
                    <span v-if="save.fieldErrors.value.supplier_ulid" class="field__error">
                        {{ save.fieldErrors.value.supplier_ulid }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Almacén</span>
                    <select v-model="form.warehouse_ulid" class="input" required>
                        <option v-for="warehouse in warehouses" :key="warehouse.ulid" :value="warehouse.ulid">
                            {{ warehouse.name }}
                        </option>
                    </select>
                </label>
            </div>

            <div class="field-row">
                <label class="field">
                    <span class="field__label">Fecha de recepción</span>
                    <input v-model="form.received_at" type="date" class="input" required />
                    <span class="field__hint">Cuándo llegó la mercancía, no cuándo se captura.</span>
                    <span v-if="save.fieldErrors.value.received_at" class="field__error">
                        {{ save.fieldErrors.value.received_at }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Folio de la factura</span>
                    <input v-model="form.supplier_document_number" class="input" maxlength="60" placeholder="A-12345" />
                    <span class="field__hint">Opcional, y único por proveedor.</span>
                    <span v-if="save.fieldErrors.value.supplier_document_number" class="field__error">
                        {{ save.fieldErrors.value.supplier_document_number }}
                    </span>
                </label>
            </div>

            <h3>Renglones</h3>

            <ArticlePicker placeholder="Buscar artículo a recibir…" @picked="addLine" />

            <p v-if="form.lines.length === 0" class="muted">
                Agrega al menos un renglón: una recepción sin renglones no recibe nada.
            </p>

            <div v-for="(line, index) in form.lines" :key="line.article.ulid" class="line">
                <div class="line__head">
                    <strong>{{ line.article.name }}</strong>
                    <button class="link-button link-button--danger" type="button" @click="removeLine(index)"><Icon name="trash" /> Quitar</button>
                </div>

                <div class="line__grid">
                    <label v-if="line.presentations.length" class="field">
                        <span class="field__label">Presentación</span>
                        <select v-model="line.presentation_ulid" class="input">
                            <option value="">Por unidad base ({{ line.article.base_unit?.code }})</option>
                            <option
                                v-for="presentation in line.presentations"
                                :key="presentation.ulid"
                                :value="presentation.ulid"
                            >
                                {{ presentation.name }}
                            </option>
                        </select>
                    </label>

                    <label class="field">
                        <span class="field__label">Cantidad</span>
                        <input v-model="line.quantity" class="input" required />
                    </label>

                    <label class="field">
                        <span class="field__label">
                            Precio sin IVA
                            <small>{{ line.presentation_ulid ? 'por presentación' : `por ${line.article.base_unit?.code}` }}</small>
                        </span>
                        <input v-model="line.unit_price" class="input" required />
                        <span v-if="save.fieldErrors.value[`lines.${index}.unit_price`]" class="field__error">
                            {{ save.fieldErrors.value[`lines.${index}.unit_price`] }}
                        </span>
                    </label>

                    <label class="field">
                        <span class="field__label">IVA %</span>
                        <input v-model="line.tax_rate" class="input" />
                        <span class="field__hint">
                            Vacío = la tasa del negocio. Se captura por renglón porque una factura mezcla
                            tasas: preparados al 16 % y despensa al 0 %.
                        </span>
                    </label>
                </div>

                <div v-if="line.article.tracks_lots" class="line__grid">
                    <label class="field">
                        <span class="field__label">Lote</span>
                        <input v-model="line.lot_code" class="input" maxlength="60" />
                        <span class="field__hint">Como viene escrito en la caja. Se crea al confirmar.</span>
                    </label>

                    <label class="field">
                        <span class="field__label">Caducidad</span>
                        <input v-model="line.expires_at" type="date" class="input" />
                    </label>
                </div>
            </div>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="capturing = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value || form.lines.length === 0"><Icon name="check" /> Guardar borrador</button>
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

.drawer--wide {
    max-width: 46rem;
}

.field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.line {
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 0.7rem;
    margin-top: 0.6rem;
}

.line__head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 0.4rem;
}

.line__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    gap: 0.6rem;
}

.field__label small {
    color: #6b7280;
    font-weight: 400;
}
</style>
