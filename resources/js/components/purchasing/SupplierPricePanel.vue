<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';

/**
 * Precios de proveedor de un artículo (D26, D201–D207).
 *
 * ## La pregunta que contesta
 *
 * «¿Quién me lo vende más barato, y quién me subió el precio?» Las dos mitades importan y por eso el dato es un
 * **historial** y no un precio vigente por proveedor: con una sola fila, la segunda mitad no tiene respuesta.
 *
 * ## Comparar por unidad base, no por presentación
 *
 * Un proveedor lo vende en caja de 12 kg y otro en costal de 25 kg. Comparar 480 contra 900 no dice nada; comparar
 * 0.0400 contra 0.0360 por gramo sí. Todo el historial se guarda **por unidad base** y por eso se puede comparar entre
 * proveedores que no venden lo mismo.
 *
 * ## Dos monedas no se comparan
 *
 * No hay tipo de cambio en el sistema, así que la comparación agrupa por moneda en lugar de mezclar. Un precio en
 * dólares se registra para que el dato sea cierto, no para promediarlo con pesos.
 *
 * ## Quién ve y quién captura son distintos a propósito
 *
 * Ver es de `purchasing.supplier_prices.view` y lo tiene el almacenista: recibe la mercancía con la factura en la mano
 * y necesita poder comparar lo que le están cobrando. **Capturar** es de `purchasing.suppliers.manage`, más
 * restringido: registrar una cotización es tomar una posición sobre a quién comprarle, y eso es de quien negocia.
 *
 * ## Lo que llega solo
 *
 * Una recepción de compra confirmada registra su propia observación (D204), marcada como compra confirmada. Ésas son
 * las que pesan: una cotización es una promesa y una compra es un hecho, así que el origen se muestra siempre.
 */
const props = defineProps({
    article: { type: Object, required: true },
});

const { canWrite } = useAuthorization();

const comparison = ref([]);
const suppliers = ref([]);
const sources = ref([]);
const presentations = ref([]);
const loading = ref(true);
const error = ref(null);

const capturing = ref(false);
const form = ref({});

const puedeCapturar = computed(() => canWrite('purchasing.suppliers.manage'));

onMounted(load);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        comparison.value = (await api.get(`/articles/${props.article.ulid}/supplier-prices`)).data.suppliers ?? [];
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudieron cargar los precios de proveedor.';
    } finally {
        loading.value = false;
    }
}

/**
 * Se piden proveedores, orígenes y presentaciones ANTES de abrir el formulario.
 *
 * Es la lección de D220: una presentación que se autoselecciona cuando vuelve la petición cambia el significado del
 * campo de precio bajo las manos de quien escribe. No hay carrera si nada está a la vista todavía.
 */
async function startCapture() {
    if (!puedeCapturar.value) {
        return;
    }

    const [suppliersResponse, sourcesResponse, presentationsResponse] = await Promise.all([
        api.get('/suppliers', { status: 'active', per_page: 100 }),
        api.get('/supplier-price-sources'),
        api.get(`/articles/${props.article.ulid}/presentations`),
    ]);

    suppliers.value = suppliersResponse.data;
    // Sólo los que una persona puede capturar: `receipt` lo escribe el sistema al confirmar una recepción, y
    // ofrecerlo daría un 422 sobre una elección que la pantalla nunca debió presentar.
    sources.value = (sourcesResponse.data ?? []).filter((s) => s.capturable_by_hand);
    presentations.value = presentationsResponse.data ?? [];

    form.value = {
        supplier_ulid: suppliers.value[0]?.ulid ?? '',
        presentation_ulid: '',
        price: '',
        currency: 'MXN',
        observed_at: '',
        source: sources.value[0]?.value ?? 'quote',
        notes: '',
    };

    capturing.value = true;
}

const save = useApiForm(async () => {
    await api.post(`/suppliers/${form.value.supplier_ulid}/prices`, {
        article_ulid: props.article.ulid,
        presentation_ulid: form.value.presentation_ulid || null,
        price: form.value.price,
        currency: form.value.currency,
        observed_at: form.value.observed_at || null,
        source: form.value.source,
        notes: form.value.notes || null,
    });
});

async function submit() {
    if (await save.submit()) {
        capturing.value = false;
        await load();
    }
}

/** La presentación elegida, para decir en el campo de precio qué unidad significa lo que se teclea. */
const presentacionElegida = computed(() => presentations.value.find((p) => p.ulid === form.value.presentation_ulid) ?? null);

/**
 * El más barato de cada moneda, para marcarlo.
 *
 * Se calcula por moneda y no en general: señalar el más barato mezclando pesos y dólares sería exactamente el error que
 * la agrupación evita.
 */
const masBaratoPorMoneda = computed(() => {
    const best = {};

    for (const row of comparison.value) {
        const actual = best[row.currency];

        if (actual === undefined || Number(row.latest.unit_price) < Number(actual)) {
            best[row.currency] = row.latest.unit_price;
        }
    }

    return best;
});

function esMasBarato(row) {
    return comparison.value.length > 1 && masBaratoPorMoneda.value[row.currency] === row.latest.unit_price;
}

function precio(valor, currency = 'MXN') {
    return valor === null || valor === undefined
        ? '—'
        : new Intl.NumberFormat('es-MX', { style: 'currency', currency, minimumFractionDigits: 4 }).format(Number(valor));
}

function fecha(iso) {
    return iso === null || iso === undefined ? '—' : new Date(`${iso}T00:00:00`).toLocaleDateString('es-MX');
}
</script>

<template>
    <section>
        <header class="panel-header">
            <div>
                <h2>Precios de proveedor</h2>
                <p class="panel-hint">
                    Todo se compara <strong>por {{ props.article.base_unit?.code ?? 'unidad base' }}</strong>, así que un
                    proveedor que vende en caja y otro que vende en costal se pueden poner uno al lado del otro. Las
                    monedas distintas no se mezclan: no hay tipo de cambio en el sistema.
                </p>
            </div>

            <button v-if="puedeCapturar" type="button" class="button" @click="startCapture">
                Registrar cotización
            </button>
        </header>

        <p v-if="loading" class="state">Cargando…</p>
        <p v-else-if="error" class="alert">{{ error }}</p>

        <p v-else-if="comparison.length === 0" class="alert alert--notice">
            Todavía no hay precios de este artículo. Se registran de dos formas: capturando una cotización, o
            <strong>solas</strong>, cuando se confirma una recepción de compra que lo incluya.
        </p>

        <table v-else class="table">
            <thead>
                <tr>
                    <th>Proveedor</th>
                    <th style="width: 10rem">Último precio</th>
                    <th style="width: 9rem">Cambio</th>
                    <th style="width: 8rem">Observado</th>
                    <th style="width: 11rem">Origen</th>
                    <th style="width: 6rem">Datos</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in comparison" :key="row.supplier.ulid">
                    <td>
                        {{ row.supplier.name }}
                        <span class="muted">{{ row.supplier.code }}</span>
                        <span v-if="!row.supplier.is_active" class="badge badge--off">inactivo</span>
                        <!--
                            El más barato se marca sólo cuando hay con quién comparar: con un único proveedor, la etiqueta
                            diría que es el más barato de una lista de uno.
                        -->
                        <span v-if="esMasBarato(row)" class="badge badge--ok">más barato</span>
                    </td>
                    <td>{{ precio(row.latest.unit_price, row.currency) }}</td>
                    <td>
                        <span v-if="row.change === null" class="muted">primer dato</span>
                        <span v-else :class="row.change.direction === 'up' ? 'is-up' : 'is-down'">
                            {{ row.change.direction === 'up' ? '▲' : row.change.direction === 'down' ? '▼' : '=' }}
                            {{ row.change.percent }}%
                        </span>
                    </td>
                    <td>{{ fecha(row.latest.observed_at) }}</td>
                    <td>
                        {{ row.latest.source_label }}
                        <!-- Una compra confirmada es un hecho; una cotización es una promesa. Se distingue a la vista. -->
                        <span v-if="row.latest.is_confirmed_purchase" class="badge badge--ok">compra</span>
                    </td>
                    <td>{{ row.observations }}</td>
                </tr>
            </tbody>
        </table>

        <div v-if="capturing" class="drawer-backdrop" @click.self="capturing = false">
            <form class="drawer" @submit.prevent="submit">
                <h2>Registrar cotización</h2>

                <p class="drawer__hint">
                    El precio se guarda <strong>por unidad base</strong>. Si eliges una presentación, captura lo que te
                    cuesta esa presentación completa y el sistema hace la división — así el dato queda comparable con
                    proveedores que venden en otro formato.
                </p>

                <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

                <label class="field">
                    <span class="field__label">Proveedor</span>
                    <select v-model="form.supplier_ulid" class="input" required>
                        <option v-for="s in suppliers" :key="s.ulid" :value="s.ulid">{{ s.display_name }} ({{ s.code }})</option>
                    </select>
                </label>

                <label v-if="presentations.length > 0" class="field">
                    <span class="field__label">Presentación</span>
                    <select v-model="form.presentation_ulid" class="input">
                        <option value="">Precio por {{ props.article.base_unit?.code }}</option>
                        <option v-for="p in presentations" :key="p.ulid" :value="p.ulid">{{ p.name }}</option>
                    </select>
                </label>

                <label class="field">
                    <span class="field__label">
                        Precio
                        <span class="muted">
                            {{ presentacionElegida ? `por ${presentacionElegida.name}` : `por ${props.article.base_unit?.code}` }}
                        </span>
                    </span>
                    <input v-model="form.price" class="input" inputmode="decimal" required />
                    <span v-if="save.fieldErrors.value.price" class="field__error">{{ save.fieldErrors.value.price }}</span>
                </label>

                <div class="fields">
                    <label class="field">
                        <span class="field__label">Moneda</span>
                        <input v-model="form.currency" class="input" maxlength="3" required />
                        <span v-if="save.fieldErrors.value.currency" class="field__error">
                            {{ save.fieldErrors.value.currency }}
                        </span>
                    </label>

                    <label class="field">
                        <span class="field__label">Observado el</span>
                        <input v-model="form.observed_at" type="date" class="input" />
                        <span class="field__hint">Vacío = hoy.</span>
                    </label>
                </div>

                <label class="field">
                    <span class="field__label">Origen</span>
                    <select v-model="form.source" class="input">
                        <option v-for="s in sources" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <span class="field__hint">
                        Las observaciones de compras confirmadas las registra el sistema; a mano sólo se capturan
                        cotizaciones y estimaciones.
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Notas</span>
                    <textarea v-model="form.notes" class="input" rows="2" maxlength="300"></textarea>
                </label>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="capturing = false">Cancelar</button>
                    <button type="submit" class="button" :disabled="save.processing.value">Registrar</button>
                </div>
            </form>
        </div>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.panel-header h2 {
    margin: 0;
    font-size: 1.05rem;
}

.panel-hint {
    margin: 0.25rem 0 0;
    color: #6b7280;
    font-size: 0.85rem;
    max-width: 46rem;
}

.state {
    padding: 1rem 0;
    color: #6b7280;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.table th,
.table td {
    padding: 0.45rem 0.5rem;
    text-align: left;
    border-bottom: 1px solid #e7e5e4;
}

.muted {
    color: #6b7280;
    font-size: 0.85rem;
}

.drawer__hint {
    margin: 0 0 0.9rem;
    color: #6b7280;
    font-size: 0.85rem;
}

.fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.is-up {
    color: #b91c1c;
}

.is-down {
    color: #166534;
}
</style>
