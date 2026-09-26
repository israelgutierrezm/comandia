<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../api/client';
import { useAuthorization } from '../../composables/useAuthorization';
import { formatMoney } from '../../support/money';
import { formatInBranchTime } from '../../support/datetime';
import Paginacion from '../Paginacion.vue';

/**
 * El estado de cuenta del cliente: cada cargo, abono y ajuste de su crédito, más recientes primero, con el saldo que
 * dejó cada uno (§8.3, D280).
 *
 * ## El importe viene CON signo, y así se pinta
 *
 * Un cargo suma a lo que debe; un abono resta. El servidor publica el signo a propósito para que la pantalla no lo
 * deduzca del tipo —ahí es donde un abono acaba pintado como deuda—, así que aquí manda el signo de la cadena: a lo
 * positivo se le antepone «+» y lo negativo ya trae el suyo. No se suma ni se resta nada: el saldo de cada renglón es el
 * `balance_after` que calculó el servidor bajo lock.
 *
 * ## Inmutable
 *
 * Los movimientos no se editan ni se borran; esta tabla sólo lee.
 *
 * ## Sólo con `finance.customer_credit.view`
 *
 * Sin el permiso el bloque no se pinta ni se pide: el servidor respondería 403.
 */
const props = defineProps({
    customerUlid: { type: String, required: true },

    /**
     * Las cuentas del expediente (los consumos de la ficha), para nombrar un cargo con el folio de su cuenta. Opcional:
     * sólo trae las recientes, así que un cargo viejo se queda con su tipo y nada más.
     */
    accounts: { type: Array, default: () => [] },

    /** Sube cuando algo movió el saldo (un abono): el estado de cuenta se relee desde su primera página. */
    version: { type: Number, default: 0 },
});

const PER_PAGE = 10;

/** Los tipos que el servidor admite como filtro (`type`), en el orden en que se leen. */
const TYPES = [
    { value: '', label: 'Todos' },
    { value: 'charge', label: 'Cargos' },
    { value: 'repayment', label: 'Abonos' },
    { value: 'adjustment', label: 'Ajustes' },
];

const page = usePage();
const { can } = useAuthorization();
const canView = computed(() => can('finance.customer_credit.view'));

const movements = ref([]);
const meta = ref({});
const currentPage = ref(1);
const type = ref('');
const loading = ref(false);
const error = ref(null);

// Cada carga lleva su número: si se cambia de página o de filtro rápido, la respuesta vieja se descarta en lugar de
// pintarse encima de la nueva.
let lastRequest = 0;

async function load() {
    if (! canView.value) {
        return;
    }

    const request = ++lastRequest;

    loading.value = true;
    error.value = null;

    try {
        const response = await api.get(`/customers/${props.customerUlid}/credit-movements`, {
            type: type.value,
            page: currentPage.value,
            per_page: PER_PAGE,
        });

        if (request !== lastRequest) {
            return;
        }

        movements.value = response.data ?? [];
        meta.value = response.meta ?? {};
    } catch (e) {
        if (request !== lastRequest) {
            return;
        }

        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e;
        movements.value = [];
        meta.value = {};
    } finally {
        if (request === lastRequest) {
            loading.value = false;
        }
    }
}

onMounted(load);

watch(() => props.version, () => {
    currentPage.value = 1;
    load();
});

function selectType(value) {
    if (type.value === value) {
        return;
    }

    type.value = value;
    currentPage.value = 1;
    load();
}

function goToPage(value) {
    currentPage.value = value;
    load();
}

const accountsByUlid = computed(() => new Map(props.accounts.map((a) => [a.account_ulid, a])));

/** La cuenta del POS de la que salió un cargo, si está entre las recientes del expediente. */
function accountOf(movement) {
    return movement.source_ulid ? accountsByUlid.value.get(movement.source_ulid) ?? null : null;
}

/**
 * La fecha en la hora de la sucursal: la de la cuenta cuando se sabe de cuál salió el cargo y, si no, la de la sucursal
 * activa (el movimiento no publica su sucursal).
 */
function dateOf(movement) {
    const timezone = accountOf(movement)?.branch_timezone ?? page.props.context?.branch_timezone ?? null;

    return formatInBranchTime(movement.created_at, timezone) || '—';
}

/** ¿Resta de lo que debe? Lo dice el signo de la cadena, no el tipo. */
function isNegative(amount) {
    return String(amount ?? '').trim().startsWith('-');
}

/** «+$300.00» un cargo, «-$200.00» un abono. Sin aritmética: el signo es el de la cadena del servidor. */
function signedAmount(amount) {
    const formatted = formatMoney(amount);

    return formatted === '—' || isNegative(amount) ? formatted : `+${formatted}`;
}
</script>

<template>
    <section v-if="canView" class="panel" aria-labelledby="estado-cuenta-titulo">
        <h2 id="estado-cuenta-titulo">Estado de cuenta</h2>
        <p class="nota">
            Los cargos y abonos de su crédito, más recientes primero, con el saldo que dejó cada uno. No se editan ni se
            borran. Las fechas están en la hora de la sucursal.
        </p>

        <div class="filtros" role="group" aria-label="Filtrar los movimientos por tipo">
            <button
                v-for="t in TYPES"
                :key="t.value || 'todos'"
                type="button"
                class="filtro"
                :class="{ 'filtro--activo': type === t.value }"
                :aria-pressed="type === t.value ? 'true' : 'false'"
                @click="selectType(t.value)"
            >{{ t.label }}</button>
        </div>

        <p v-if="error" class="error" role="alert">{{ error.title }}</p>

        <div v-else-if="movements.length" class="tabla-envoltura">
            <table class="tabla" aria-labelledby="estado-cuenta-titulo" :aria-busy="loading ? 'true' : 'false'">
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Concepto</th>
                        <th scope="col" class="der">Importe</th>
                        <th scope="col" class="der">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in movements" :key="m.ulid">
                        <td class="nowrap">{{ dateOf(m) }}</td>
                        <td>
                            {{ m.type_label }}
                            <span v-if="accountOf(m)" class="detalle">
                                · cuenta {{ accountOf(m).reference }}<template v-if="accountOf(m).branch_name">, {{ accountOf(m).branch_name }}</template>
                            </span>
                        </td>
                        <td class="der nowrap">
                            <span class="importe" :class="{ 'importe--resta': isNegative(m.amount) }">{{ signedAmount(m.amount) }}</span>
                        </td>
                        <td class="der nowrap">{{ formatMoney(m.balance_after) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else-if="loading" class="nota">Cargando el estado de cuenta…</p>
        <p v-else class="nota">
            {{ type === '' ? 'Todavía no tiene movimientos de crédito.' : 'No tiene movimientos de este tipo.' }}
        </p>

        <Paginacion :meta="meta" :page="currentPage" item-label="movimientos" @update:page="goToPage" />
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

/* La misma tarjeta que el resto de la ficha: superficie, borde, radio y sombra de los tokens. */
.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.1rem 1.25rem;
}
.panel h2 { margin-top: 0; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.error { color: var(--color-peligro); }
.filtros { margin: 0.25rem 0 0.75rem; }

/* En pantalla angosta la tabla se desplaza dentro de su panel en lugar de ensanchar la página entera. */
.tabla-envoltura { overflow-x: auto; }
.tabla { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.tabla th, .tabla td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--color-borde); }
.tabla th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-suave); }
.tabla .der { text-align: right; font-variant-numeric: tabular-nums; }
.nowrap { white-space: nowrap; }
.detalle { color: var(--color-suave); font-size: 0.85rem; }
/*
 * Lo que resta de la deuda (un abono) en una pastilla verde. El signo ya lo dice; el color sólo ayuda a encontrarlo. Va
 * como texto `-texto` sobre su tinte —la pareja que el tema mantiene legible en claro y en oscuro—, no como verde puro
 * sobre la superficie, que apenas da 3:1.
 */
.importe { display: inline-block; padding: 0.05rem 0.45rem; border-radius: 999px; }
.importe--resta { background: var(--color-exito-tenue); color: var(--color-exito-texto); }
</style>
