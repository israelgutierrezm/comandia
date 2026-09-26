<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { formatInBranchTime } from '../../../support/datetime';
import { formatMoney } from '../../../support/money';
import DataTable from '../../../components/DataTable.vue';
import ListHeader from '../../../components/ListHeader.vue';

/**
 * El diario financiero (ADR-004): SÓLO LECTURA.
 *
 * ## Por qué no hay nada que editar
 *
 * Al diario escriben únicamente los oyentes de eventos de dominio: el POS cobra y `Finance` asienta; el gasto, el
 * depósito y la liquidación asientan dentro de su propia transacción. Una corrección no se edita: se asienta su
 * reversa, enlazada al movimiento que corrige, y aquí se ve como tal. Esta pantalla no tiene —ni debe tener— ningún
 * botón que cambie algo.
 *
 * ## Nada se suma aquí
 *
 * Cada renglón trae su monto CON SIGNO tal como quedó asentado. Los totales y el efectivo esperado son del corte, que
 * se calcula en el servidor (§6.9): una suma en el navegador daría una segunda cifra que reconciliar.
 *
 * ## Paginación por cursor
 *
 * Es la tabla que más crece del sistema. El servidor pagina por cursor —un `OFFSET` grande obliga a MySQL a contar
 * todas las filas anteriores—, así que aquí no hay «página 40 de 900», sólo «más recientes» y «más antiguos». Los dos
 * cursores los da el servidor.
 *
 * ## Los filtros son los de la whitelist del endpoint
 *
 * Tipo, sucursal, rango de fechas y «sólo lo que mueve el cajón». No hay búsqueda de texto: el endpoint la rechaza (no
 * hay nada textual que buscar en un asiento). El filtro por sesión existe en la API pero recibe la llave INTERNA de la
 * sesión, que la API no publica en ningún otro lado, así que no se ofrece.
 */
const page = usePage();

/**
 * Los tipos del diario, para el filtro.
 *
 * DEUDA DECLARADA: es copia de `FinancialMovementType::label()` porque la API no publica el catálogo (no existe un
 * `GET /financial-movement-types` como el `/stock-movement-kinds` del kardex). Es justo lo que D139 pide evitar, y por
 * eso sólo se usa en el filtro: las etiquetas de la TABLA vienen del servidor (`type_label`). Cuando exista el
 * endpoint, esta lista se sustituye por una petición.
 */
const TIPOS = [
    { value: 'sale', label: 'Venta' },
    { value: 'online_sale', label: 'Venta en línea' },
    { value: 'marketplace_commission', label: 'Comisión de marketplace' },
    { value: 'payment', label: 'Pago' },
    { value: 'change', label: 'Cambio' },
    { value: 'tip', label: 'Propina' },
    { value: 'tip_settlement', label: 'Liquidación de propina' },
    { value: 'discount', label: 'Descuento' },
    { value: 'courtesy', label: 'Cortesía' },
    { value: 'promotion', label: 'Promoción' },
    { value: 'expense', label: 'Gasto' },
    { value: 'withdrawal', label: 'Retiro' },
    { value: 'deposit', label: 'Depósito' },
    { value: 'credit_granted', label: 'Crédito concedido' },
    { value: 'credit_repayment', label: 'Abono de crédito' },
    { value: 'opening_float', label: 'Fondo de caja' },
    { value: 'count_difference', label: 'Diferencia de corte' },
    { value: 'reversal', label: 'Reversa' },
];

/**
 * El documento que originó cada asiento. El servidor publica el nombre de la clase (`PosAccount`, `Expense`…) sin
 * traducir; esto sólo lo presenta en español, y lo que no reconoce lo muestra tal cual en lugar de esconderlo.
 */
const DOCUMENTOS = {
    PosAccount: 'Cuenta',
    PosPayment: 'Pago de cuenta',
    PosDiscount: 'Descuento',
    PosSession: 'Turno de caja',
    PosSessionWithdrawal: 'Retiro de caja',
    Expense: 'Gasto',
    BankDeposit: 'Depósito bancario',
    TipSettlement: 'Liquidación de propina',
    Order: 'Pedido en línea',
    Payment: 'Pago en línea',
    CustomerCreditMovement: 'Movimiento de crédito',
};

const POR_PAGINA = 50;

const vacios = () => ({ type: '', branch: '', occurred_from: '', occurred_to: '', cash_only: false });
const filtros = ref(vacios());

const movimientos = ref([]);
const cargando = ref(true);
const error = ref(null);
const siguiente = ref(null);
const anterior = ref(null);

const sucursales = ref([]);

// Cada carga lleva su número: si mientras tanto se pidió otra (se cambió un filtro), la respuesta vieja se descarta en
// lugar de pisar la nueva. Sin esto, una respuesta lenta podía dejar la tabla mostrando otro filtro del que dice.
let peticion = 0;
let espera = null;

onMounted(() => {
    cargar();
    cargarSucursales();
});

onBeforeUnmount(() => clearTimeout(espera));

// Un cursor pertenece a la consulta que lo produjo: al cambiar un filtro se vuelve al principio.
watch(filtros, () => {
    clearTimeout(espera);
    espera = setTimeout(() => cargar(null), 250);
}, { deep: true });

async function cargar(cursor = null) {
    const mia = ++peticion;

    cargando.value = true;
    error.value = null;

    try {
        const respuesta = await api.get('/financial-movements', {
            type: filtros.value.type,
            branch: filtros.value.branch,
            occurred_from: filtros.value.occurred_from,
            occurred_to: filtros.value.occurred_to,
            // Vacío = sin filtro (el cliente omite los vacíos); «1» = sólo lo que mueve el cajón.
            cash_only: filtros.value.cash_only ? 1 : '',
            per_page: POR_PAGINA,
            cursor,
        });

        if (mia !== peticion) {
            return;
        }

        movimientos.value = respuesta.data ?? [];
        siguiente.value = respuesta.meta?.next_cursor ?? null;
        anterior.value = respuesta.meta?.prev_cursor ?? null;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        if (mia !== peticion) {
            return;
        }

        error.value = e;
        movimientos.value = [];
        siguiente.value = null;
        anterior.value = null;
    } finally {
        if (mia === peticion) {
            cargando.value = false;
        }
    }
}

/** Las sucursales del alcance, para el filtro y para leer cada fecha en la hora de su sucursal. */
async function cargarSucursales() {
    try {
        const { data } = await api.get('/context');
        sucursales.value = data?.branches ?? [];
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        // Sin la lista, el filtro de sucursal no se ofrece y las fechas caen a la zona de la sucursal activa: la tabla
        // sigue sirviendo, así que no se pinta como error.
        sucursales.value = [];
    }
}

const filtrosActivos = computed(() => {
    const f = filtros.value;

    return [f.type, f.branch, f.occurred_from, f.occurred_to].filter((v) => v !== '').length + (f.cash_only ? 1 : 0);
});

function limpiarFiltros() {
    filtros.value = vacios();
}

/** El error con su motivo concreto: un rango de fechas invertido responde 422 y el título genérico no decía qué. */
const errorTabla = computed(() => {
    const e = error.value;

    if (! e || ! e.isValidation) {
        return e;
    }

    return { isForbidden: false, message: Object.values(e.fieldErrors ?? {})[0] ?? e.message };
});

function fecha(iso, branchUlid) {
    const zona = sucursales.value.find((b) => b.ulid === branchUlid)?.timezone ?? page.props.context?.branch_timezone;

    return formatInBranchTime(iso, zona) || '—';
}

const esSalida = (monto) => String(monto ?? '').trim().startsWith('-');

function documento(origen) {
    if (! origen?.type) {
        return '—';
    }

    return DOCUMENTOS[origen.type] ?? origen.type;
}

const columnas = [
    { key: 'occurred_at', label: 'Fecha', width: '9rem' },
    { key: 'type', label: 'Movimiento', width: '13rem' },
    { key: 'amount', label: 'Monto', width: '9rem', align: 'right' },
    { key: 'affects_cash_drawer', label: 'Cajón', width: '5rem', align: 'center' },
    { key: 'payment_method', label: 'Método', width: '9rem' },
    { key: 'branch', label: 'Sucursal', width: '10rem' },
    { key: 'actor', label: 'Quién', width: '10rem' },
    { key: 'source', label: 'Documento' },
];
</script>

<template>
    <Head title="Movimientos" />

    <div class="diario animar-entrada">
        <ListHeader
            title="Movimientos del diario"
            subtitle="Todo el dinero del negocio, asiento por asiento: tipo, monto con signo, si movió el cajón y el documento que lo originó. Es de sólo lectura: un asiento no se edita ni se borra; una corrección es una reversa enlazada."
            :active-count="filtrosActivos"
            @clear="limpiarFiltros"
        >
            <template #filters>
                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-tipo">Movimiento</label>
                    <select id="filtro-tipo" v-model="filtros.type" class="input input--select">
                        <option value="">Todos</option>
                        <option v-for="t in TIPOS" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>

                <div v-if="sucursales.length > 1" class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-sucursal">Sucursal</label>
                    <select id="filtro-sucursal" v-model="filtros.branch" class="input input--select">
                        <option value="">Todas</option>
                        <option v-for="b in sucursales" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                    </select>
                </div>

                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-desde">Desde</label>
                    <input id="filtro-desde" v-model="filtros.occurred_from" class="input" type="date" />
                </div>

                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-hasta">Hasta</label>
                    <input id="filtro-hasta" v-model="filtros.occurred_to" class="input" type="date" />
                </div>

                <label class="filtro-casilla" for="filtro-cajon">
                    <input id="filtro-cajon" v-model="filtros.cash_only" type="checkbox" />
                    Sólo lo que mueve el cajón
                </label>
            </template>
        </ListHeader>

        <DataTable
            :columns="columnas"
            :rows="movimientos"
            :loading="cargando"
            :error="errorTabla"
            empty-message="No hay movimientos que coincidan."
        >
            <template #cell:occurred_at="{ row }">{{ fecha(row.occurred_at, row.branch?.ulid) }}</template>

            <template #cell:type="{ row }">
                <div class="tipo">
                    <span>
                        {{ row.type_label }}
                        <span v-if="row.is_reversal" class="badge badge--warn">Reversa</span>
                    </span>
                    <!-- Una reversa conserva el tipo de lo que corrige y lleva el signo contrario: basta con decir cuál. -->
                    <small v-if="row.reverses" class="muted">corrige el asiento de {{ formatMoney(row.reverses.amount) }}</small>
                </div>
            </template>

            <template #cell:amount="{ row }">
                <span :class="esSalida(row.amount) ? 'monto--salida' : 'monto--entrada'">{{ formatMoney(row.amount) }}</span>
            </template>

            <template #cell:affects_cash_drawer="{ row }">
                <span v-if="row.affects_cash_drawer" class="badge badge--ok">Sí</span>
                <span v-else class="muted">No</span>
            </template>

            <template #cell:payment_method="{ row }">{{ row.payment_method?.name ?? '—' }}</template>

            <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>

            <template #cell:actor="{ row }">
                <!-- `null` = asiento automático (una venta en línea, una comisión): no lo hizo nadie del personal. -->
                <span v-if="row.actor">{{ row.actor.name }}</span>
                <span v-else class="muted">Automático</span>
            </template>

            <template #cell:source="{ row }">
                <span :title="row.source?.ulid ?? ''">{{ documento(row.source) }}</span>
            </template>
        </DataTable>

        <nav v-if="anterior || siguiente" class="paginador" aria-label="Páginas del diario">
            <button type="button" class="link-button" :disabled="cargando || ! anterior" @click="cargar(anterior)">
                ← Más recientes
            </button>
            <button type="button" class="link-button" :disabled="cargando || ! siguiente" @click="cargar(siguiente)">
                Más antiguos →
            </button>
        </nav>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.diario {
    display: grid;
    gap: 1rem;
    min-width: 0;
}

.filtro-campo {
    display: grid;
    gap: 0.2rem;
    min-width: 0;
}

.filtro-campo__label {
    font-size: 0.75rem;
    color: var(--color-suave);
}

.filtro-casilla {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.88rem;
    color: var(--color-contenido);
    white-space: nowrap;
    cursor: pointer;
}

.tipo {
    display: grid;
    gap: 0.1rem;
}

.muted {
    color: var(--color-suave);
}

.tipo small {
    font-size: 0.78rem;
}

/* El signo lo pone el servidor; el color sólo ayuda a leerlo: lo que entra y lo que sale del negocio. */
.monto--entrada {
    color: var(--color-exito);
    font-weight: 600;
}

.monto--salida {
    color: var(--color-aviso);
    font-weight: 600;
}

.paginador {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 0.75rem;
}
</style>
