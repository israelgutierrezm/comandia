<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import { useAuthorization } from '../../../../composables/useAuthorization';
import DataTable from '../../../../components/DataTable.vue';
import FormHeader from '../../../../components/FormHeader.vue';
import ResourceGrid from '../../../../components/ResourceGrid.vue';
import ViewToggle from '../../../../components/ViewToggle.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import Icon from '../../../../components/Icon.vue';

const view = ref('list');

/**
 * Proveedores (D26).
 *
 * ## El código no se puede editar, y la pantalla lo dice
 *
 * Es el identificador con el que la gente llama al proveedor en papeles y conversaciones, así que
 * reasignarlo haría que los documentos viejos parecieran ser de otro (D201). El campo sólo aparece al
 * crear, igual que el tipo de un almacén.
 *
 * ## Y no se borra: se da de baja
 *
 * Sus recepciones y su historial de precios lo citan. No hay botón de borrar porque no hay endpoint —
 * la baja conserva el historial consultable y sólo impide compras nuevas.
 *
 * ## Sus precios: el historial, de sólo lectura
 *
 * «Precios» abre lo que devuelve `GET /suppliers/{proveedor}/prices` (permiso `purchasing.supplier_prices.view`): cada
 * observación —compra confirmada, cotización o captura—, lo más reciente primero. Es un HISTORIAL y así se presenta: no
 * se deduce aquí «el precio vigente» de cada artículo, porque eso exigiría recorrer todo el historial en el cliente y
 * repetir la regla de `CompareSupplierPrices`, que vive en el servidor. La comparación entre proveedores de un artículo
 * ya existe en su ficha (`SupplierPricePanel`); este panel contesta la otra pregunta: «¿qué me ha cobrado éste?».
 */
const list = useResourceList('/suppliers', { initialFilters: { status: '' } });

const { can } = useAuthorization();
const puedeVerPrecios = computed(() => can('purchasing.supplier_prices.view'));

const filtrosActivos = computed(() => (list.filters.status !== '' ? 1 : 0));
function limpiarFiltros() {
    list.filters.status = '';
}

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    const payload = {
        legal_name: form.value.legal_name,
        trade_name: form.value.trade_name || null,
        rfc: form.value.rfc || null,
        contact_name: form.value.contact_name || null,
        phone: form.value.phone || null,
        email: form.value.email || null,
        // Cadena vacía a `null`: «no se sabe» y «de contado» son cosas distintas, y un cero significa
        // la segunda. Mandar `''` haría que el servidor lo tomara por cero.
        payment_terms_days: form.value.payment_terms_days === '' ? null : Number(form.value.payment_terms_days),
        notes: form.value.notes || null,
    };

    if (editing.value === 'new') {
        await api.post('/suppliers', { ...payload, code: form.value.code });
    } else {
        await api.patch(`/suppliers/${editing.value.ulid}`, payload);
    }
});

const changeStatus = useApiForm(async (supplier, status) => {
    await api.patch(`/suppliers/${supplier.ulid}`, { status });
});

onMounted(() => list.load());

function startCreate() {
    editing.value = 'new';
    form.value = {
        code: '',
        legal_name: '',
        trade_name: '',
        rfc: '',
        contact_name: '',
        phone: '',
        email: '',
        payment_terms_days: '',
        notes: '',
    };
}

function startEdit(supplier) {
    editing.value = supplier;
    form.value = {
        legal_name: supplier.legal_name,
        trade_name: supplier.trade_name ?? '',
        rfc: supplier.rfc ?? '',
        contact_name: supplier.contact_name ?? '',
        phone: supplier.phone ?? '',
        email: supplier.email ?? '',
        payment_terms_days: supplier.payment_terms_days ?? '',
        notes: supplier.notes ?? '',
    };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

async function toggleStatus(supplier) {
    const next = supplier.is_active ? 'inactive' : 'active';

    if (supplier.is_active && !window.confirm(`¿Dar de baja a «${supplier.display_name}»?`)) {
        return;
    }

    if (await changeStatus.submit(supplier, next)) {
        await list.load();
    }
}

// ---- Precios del proveedor (sólo lectura) ----

// El proveedor cuyo historial está abierto; `null` = panel cerrado.
const pricesOf = ref(null);
const prices = ref([]);
const pricesMeta = ref({});
const pricesPage = ref(1);
const pricesLoading = ref(false);
const pricesError = ref(null);
const pricesClose = ref(null);

// Cada petición lleva su número: si el usuario cambia de página o de proveedor antes de que vuelva la anterior, la
// respuesta tardía se descarta en lugar de pintar los precios de otro proveedor bajo este nombre.
let pricesRequest = 0;

async function openPrices(supplier) {
    pricesOf.value = supplier;
    prices.value = [];
    pricesMeta.value = {};
    pricesPage.value = 1;
    pricesError.value = null;
    // Desde ya, y no hasta que salga la petición: si no, el aviso de «todavía no hay precios» parpadea al abrir.
    pricesLoading.value = true;

    await nextTick();
    pricesClose.value?.focus();
    await loadPrices();
}

async function loadPrices() {
    const supplier = pricesOf.value;

    if (supplier === null) {
        return;
    }

    const ticket = ++pricesRequest;
    pricesLoading.value = true;
    pricesError.value = null;

    try {
        const response = await api.get(`/suppliers/${supplier.ulid}/prices`, { page: pricesPage.value, per_page: 50 });

        if (ticket === pricesRequest) {
            prices.value = response.data ?? [];
            pricesMeta.value = response.meta ?? {};
        }
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (ticket === pricesRequest) {
            pricesError.value = e.title;
            prices.value = [];
        }
    } finally {
        if (ticket === pricesRequest) {
            pricesLoading.value = false;
        }
    }
}

function changePricesPage(page) {
    pricesPage.value = page;
    loadPrices();
}

function closePrices() {
    pricesOf.value = null;
    pricesRequest++;
    pricesLoading.value = false;
}

/**
 * Un precio de proveedor, con hasta cuatro decimales y en SU moneda.
 *
 * `formatMoney` es para importes en pesos a dos decimales, y aquí no alcanza: el precio por unidad base se guarda con
 * cuatro («0.0425 el gramo» se leería «$0.04», que es el error que la normalización existe para evitar) y puede venir en
 * dólares. Sólo presenta: el valor ya viene calculado del servidor. Mismo formato que la comparación de la ficha del
 * artículo (`SupplierPricePanel`).
 */
function precio(valor, currency, minimoDecimales = 2) {
    if (valor === null || valor === undefined || valor === '') {
        return '—';
    }

    try {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: currency || 'MXN',
            minimumFractionDigits: minimoDecimales,
            maximumFractionDigits: 4,
        }).format(valor);
    } catch {
        // Una moneda que `Intl` no reconozca no debe tumbar el panel: se pinta el dato crudo.
        return `${valor} ${currency ?? ''}`.trim();
    }
}

/** Una fecha sin hora (`observed_at`): a medianoche LOCAL, para que la zona horaria no la recorra un día. */
function fecha(iso) {
    return iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('es-MX') : '—';
}

const columns = [
    { key: 'code', label: 'Código', width: '9rem' },
    { key: 'name', label: 'Proveedor' },
    { key: 'rfc', label: 'RFC', width: '10rem' },
    { key: 'contact', label: 'Contacto' },
    { key: 'terms', label: 'Crédito', width: '7rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '14rem' },
];
</script>

<template>
    <Head title="Proveedores" />

    <ListHeader
        title="Proveedores"
        subtitle="El código no se puede cambiar después: es como lo identifican los documentos ya capturados. Y un proveedor no se borra, se da de baja — sus compras y su historial de precios lo citan."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar por nombre, código o RFC…"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todos</option>
                <option value="active">Activos</option>
                <option value="inactive">Dados de baja</option>
            </select>
        </template>

        <template #view>
            <ViewToggle v-model="view" persist-key="comandia:view:suppliers" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'purchasing.suppliers.manage'" class="button" type="button" @click="startCreate">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Nuevo proveedor
            </button>
        </template>
    </ListHeader>

    <p v-if="changeStatus.generalError.value" class="alert">{{ changeStatus.generalError.value }}</p>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay proveedores que coincidan."
    >
        <template #cell:name="{ row }">
            <div>
                <strong>{{ row.display_name }}</strong>
                <!-- La razón social aparte cuando difiere: va en la factura, mientras el nombre
                     comercial es como lo llama la cocina. -->
                <small v-if="row.trade_name" class="muted">{{ row.legal_name }}</small>
            </div>
        </template>

        <template #cell:rfc="{ row }">
            <span v-if="row.rfc">{{ row.rfc }}</span>
            <span v-else class="muted">—</span>
        </template>

        <template #cell:contact="{ row }">
            <div class="contact">
                <span v-if="row.contact_name">{{ row.contact_name }}</span>
                <small v-if="row.phone" class="muted">{{ row.phone }}</small>
                <small v-if="row.email" class="muted">{{ row.email }}</small>
                <span v-if="!row.contact_name && !row.phone && !row.email" class="muted">—</span>
            </div>
        </template>

        <template #cell:terms="{ row }">
            <!-- `null` y cero son distintos: «no se sabe» frente a «de contado». -->
            <span v-if="row.payment_terms_days === null" class="muted">—</span>
            <span v-else-if="row.payment_terms_days === 0">De contado</span>
            <span v-else>{{ row.payment_terms_days }} días</span>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.is_active ? 'badge--ok' : 'badge--off'">
                {{ row.is_active ? 'Activo' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button v-can.write="'purchasing.suppliers.manage'" class="link-button link-button--warning" type="button" @click="startEdit(row)"><Icon name="edit" /> Editar</button>
                <button
                    v-can.write="'purchasing.suppliers.manage'"
                    class="link-button"
                    :class="{ 'link-button--danger': row.is_active }"
                    type="button"
                    @click="toggleStatus(row)"
                >
                    {{ row.is_active ? 'Dar de baja' : 'Reactivar' }}
                </button>
                <!-- También con el proveedor dado de baja: conservar su historial consultable es el punto de la baja. -->
                <button v-if="puedeVerPrecios" class="link-button" type="button" @click="openPrices(row)"><Icon name="tag" /> Precios</button>
            </div>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay proveedores que coincidan."
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__code">{{ item.code }}</span>
                <span class="card__title">{{ item.display_name }}</span>
                <span v-if="item.rfc" class="card__meta">{{ item.rfc }}</span>
                <span v-if="item.contact_name || item.phone" class="card__meta">
                    {{ item.contact_name }}{{ item.contact_name && item.phone ? ' · ' : '' }}{{ item.phone }}
                </span>
                <span class="card__foot">
                    <span class="badge" :class="item.is_active ? 'badge--ok' : 'badge--off'">
                        {{ item.is_active ? 'Activo' : 'Baja' }}
                    </span>
                    <span v-if="item.payment_terms_days === 0" class="card__meta">De contado</span>
                    <span v-else-if="item.payment_terms_days" class="card__meta">{{ item.payment_terms_days }} días crédito</span>
                </span>
                <div class="card__actions">
                    <button v-can.write="'purchasing.suppliers.manage'" class="link-button link-button--warning" type="button" @click="startEdit(item)"><Icon name="edit" /> Editar</button>
                    <button
                        v-can.write="'purchasing.suppliers.manage'"
                        class="link-button"
                        :class="{ 'link-button--danger': item.is_active }"
                        type="button"
                        @click="toggleStatus(item)"
                    >
                        {{ item.is_active ? 'Dar de baja' : 'Reactivar' }}
                    </button>
                    <button v-if="puedeVerPrecios" class="link-button" type="button" @click="openPrices(item)"><Icon name="tag" /> Precios</button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="proveedores" />

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <FormHeader :title="editing === 'new' ? 'Nuevo proveedor' : `Editar ${editing.display_name}`" />

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label v-if="editing === 'new'" class="field">
                <span class="field__label">Código</span>
                <input v-model="form.code" class="input" maxlength="20" required placeholder="DON-BETO" />
                <span class="field__hint">No se puede cambiar después.</span>
                <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
            </label>

            <label class="field">
                <span class="field__label">Razón social</span>
                <input v-model="form.legal_name" class="input" maxlength="200" required />
                <span class="field__hint">La que va en la factura.</span>
                <span v-if="save.fieldErrors.value.legal_name" class="field__error">
                    {{ save.fieldErrors.value.legal_name }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Nombre comercial</span>
                <input v-model="form.trade_name" class="input" maxlength="120" placeholder="Don Beto" />
                <span class="field__hint">Como lo llama la cocina. Opcional.</span>
            </label>

            <label class="field">
                <span class="field__label">RFC</span>
                <input v-model="form.rfc" class="input" maxlength="13" placeholder="DAB120315ABC" />
                <span class="field__hint">Opcional, y único: dos proveedores con el mismo RFC son el mismo.</span>
                <span v-if="save.fieldErrors.value.rfc" class="field__error">{{ save.fieldErrors.value.rfc }}</span>
            </label>

            <label class="field">
                <span class="field__label">Contacto</span>
                <input v-model="form.contact_name" class="input" maxlength="120" />
            </label>

            <div class="field-row">
                <label class="field">
                    <span class="field__label">Teléfono</span>
                    <input v-model="form.phone" class="input" maxlength="30" />
                </label>

                <label class="field">
                    <span class="field__label">Correo</span>
                    <input v-model="form.email" type="email" class="input" maxlength="160" />
                    <span v-if="save.fieldErrors.value.email" class="field__error">{{ save.fieldErrors.value.email }}</span>
                </label>
            </div>

            <label class="field">
                <span class="field__label">Días de crédito</span>
                <input v-model="form.payment_terms_days" type="number" min="0" max="365" class="input" />
                <span class="field__hint">Vacío = no se sabe. Cero = de contado.</span>
                <span v-if="save.fieldErrors.value.payment_terms_days" class="field__error">
                    {{ save.fieldErrors.value.payment_terms_days }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Notas</span>
                <textarea v-model="form.notes" class="input" rows="2" maxlength="500"></textarea>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value"><Icon name="check" /> Guardar</button>
            </div>
        </form>
    </div>

    <!-- Precios del proveedor: sólo lectura. Se cierra con «Cerrar», con Esc o tocando fuera. -->
    <div v-if="pricesOf" class="drawer-backdrop" @click.self="closePrices" @keydown.esc="closePrices">
        <section class="drawer drawer--precios" role="dialog" :aria-label="`Precios de ${pricesOf.display_name}`">
            <FormHeader
                :title="`Precios de ${pricesOf.display_name}`"
                subtitle="Lo que ha cobrado, lo más reciente primero."
                icon="tag"
            />

            <p class="drawer__hint">
                Cada renglón es una observación: una <strong>compra</strong> confirmada (la registra el sistema al confirmar
                la recepción), una cotización o una captura a mano. Es un historial, no una lista de precios vigentes: un
                artículo aparece tantas veces como se observó. Para ponerlo junto a otros proveedores, abre el artículo en
                el catálogo: su pestaña «Precios de proveedor» los compara por unidad base.
            </p>

            <p v-if="pricesError" class="alert" role="alert">{{ pricesError }}</p>
            <p v-else-if="pricesLoading && prices.length === 0" class="muted" role="status">Cargando precios…</p>
            <p v-else-if="prices.length === 0" class="alert alert--notice">
                Todavía no hay precios de este proveedor. Se registran solos al confirmar una recepción de compra, o
                capturando una cotización desde la ficha del artículo.
            </p>

            <div v-else class="precios__envoltura" :aria-busy="pricesLoading ? 'true' : 'false'">
                <table class="precios">
                    <thead>
                        <tr>
                            <th scope="col">Artículo</th>
                            <th scope="col">Presentación</th>
                            <th scope="col" class="num">Precio</th>
                            <th scope="col">Fecha</th>
                            <th scope="col">Origen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in prices" :key="p.ulid">
                            <td>
                                {{ p.article?.name ?? '—' }}
                                <small v-if="p.notes" class="muted">{{ p.notes }}</small>
                            </td>
                            <td>
                                <template v-if="p.presentation">{{ p.presentation.name }}</template>
                                <span v-else class="muted">Por {{ p.article?.base_unit_code ?? 'unidad base' }}</span>
                            </td>
                            <td class="num">
                                <!-- Con presentación: lo que cuesta la presentación, y debajo el precio por unidad base, que es
                                     el comparable. Sin ella, lo capturado YA es por unidad base. -->
                                <template v-if="p.presentation && p.observed_price !== null">
                                    {{ precio(p.observed_price, p.currency) }}
                                    <small class="muted">{{ precio(p.unit_price, p.currency, 4) }} / {{ p.article?.base_unit_code }}</small>
                                </template>
                                <template v-else>
                                    {{ precio(p.unit_price, p.currency, 4) }} / {{ p.article?.base_unit_code }}
                                </template>
                            </td>
                            <td class="fecha">{{ fecha(p.observed_at) }}</td>
                            <td>
                                {{ p.source_label }}
                                <!-- Una compra confirmada es un hecho; una cotización, una promesa. -->
                                <span v-if="p.is_confirmed_purchase" class="badge badge--ok">compra</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Paginacion :meta="pricesMeta" :page="pricesPage" item-label="precios" @update:page="changePricesPage" />

            <div class="drawer__actions">
                <button ref="pricesClose" type="button" class="link-button" @click="closePrices"><Icon name="x" /> Cerrar</button>
            </div>
        </section>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.muted {
    color: var(--color-suave);
    display: block;
    font-size: 0.8rem;
}

.contact {
    display: flex;
    flex-direction: column;
}

.field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

/* Con tres acciones, la celda las acomoda en dos renglones antes que desbordar la tabla. */
.row-actions {
    flex-wrap: wrap;
}

/* ---- Precios del proveedor ---- */

.drawer--precios {
    width: min(46rem, 100%);
}

.drawer__hint {
    margin: 0.5rem 0 1rem;
    color: var(--color-suave);
    font-size: 0.85rem;
    line-height: 1.5;
}

/* En un teléfono la tabla se desliza dentro del panel, no la página. */
.precios__envoltura {
    overflow-x: auto;
}

.precios__envoltura[aria-busy='true'] {
    opacity: 0.6;
}

.precios {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}

.precios th,
.precios td {
    padding: 0.45rem 0.5rem;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid var(--color-borde);
}

.precios th {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-suave);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    white-space: nowrap;
}

.precios .num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.precios .fecha {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
</style>
