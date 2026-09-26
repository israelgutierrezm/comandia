<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm, useResourceList } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import { formatInBranchTime } from '../../../support/datetime';
import { formatMoney } from '../../../support/money';
import DataTable from '../../../components/DataTable.vue';
import ListHeader from '../../../components/ListHeader.vue';
import Paginacion from '../../../components/Paginacion.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Depósitos bancarios (§6.5, D38): la otra mitad del retiro.
 *
 * El efectivo sale de la caja con un retiro —en la pantalla de Caja— y llega al banco con esto. Sin la segunda mitad,
 * un retiro de diez mil pesos es una salida declarada que no llega a ningún sitio: el arqueo cuadra y nadie puede decir
 * dónde está el dinero.
 *
 * ## No exige caja abierta
 *
 * Es la única operación de finanzas así (D285): el depósito lo registra quien fue al banco, con el comprobante en la
 * mano, horas o días después de que el dinero saliera de la caja. Por eso la fecha la dice quien captura, y el servidor
 * sólo rechaza una fecha futura.
 *
 * ## Inmutable
 *
 * Queda en el diario como salida del negocio hacia el banco. No se edita ni se borra: alguien ya lo concilió a mano
 * contra el estado de cuenta.
 */
const page = usePage();
const { can, canWrite } = useAuthorization();

const puedeVer = computed(() => can('finance.journal.view'));
const puedeRegistrar = computed(() => canWrite('finance.deposits.create'));

const sucursales = ref([]);
const errorSucursales = ref(null);

const FILTROS = ['branch', 'deposited_from', 'deposited_to'];

const list = useResourceList('/bank-deposits', {
    initialFilters: Object.fromEntries(FILTROS.map((f) => [f, ''])),
});

onMounted(async () => {
    if (puedeVer.value) {
        list.load();
    }

    await cargarSucursales();
});

async function cargarSucursales() {
    errorSucursales.value = null;

    try {
        const { data } = await api.get('/context');
        sucursales.value = data?.branches ?? [];
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        errorSucursales.value = e.title;
    }
}

const zonaDe = (branchUlid) => sucursales.value.find((b) => b.ulid === branchUlid)?.timezone
    ?? page.props.context?.branch_timezone;

/** El instante de la captura, en la hora de la sucursal del depósito. */
function momento(iso, branchUlid) {
    return formatInBranchTime(iso, zonaDe(branchUlid)) || '—';
}

const FORMATO_DIA = new Intl.DateTimeFormat('es-MX', { dateStyle: 'short', timeZone: 'UTC' });

/**
 * La fecha del depósito tal como se capturó. Es un DÍA, no un instante: se lee a mediodía en UTC para que ninguna zona
 * horaria la corra al día anterior.
 */
function dia(fecha) {
    if (! fecha) {
        return '—';
    }

    const instante = new Date(`${fecha}T12:00:00Z`);

    return Number.isNaN(instante.getTime()) ? fecha : FORMATO_DIA.format(instante);
}

/** «Hoy» en la zona de la sucursal (AAAA-MM-DD): la fecha por omisión y el tope del campo. */
function hoyEn(zona) {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: zona || undefined,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date());
    } catch {
        return new Date().toISOString().slice(0, 10);
    }
}

const filtrosActivos = computed(
    () => FILTROS.filter((f) => list.filters[f] !== '').length + (list.filters.sort !== '' ? 1 : 0),
);

function limpiarFiltros() {
    FILTROS.forEach((f) => { list.filters[f] = ''; });
    list.filters.sort = '';
}

/** El error del listado con su motivo concreto: un rango de fechas invertido responde 422 con el porqué. */
const errorListado = computed(() => {
    const e = list.error.value;

    if (! e || ! e.isValidation) {
        return e;
    }

    return { isForbidden: false, message: Object.values(e.fieldErrors ?? {})[0] ?? e.message };
});

// ---- Registrar ----

const sucursalInicial = () => {
    const activa = page.props.context?.branch_ulid;

    return sucursales.value.some((b) => b.ulid === activa) ? activa : (sucursales.value[0]?.ulid ?? '');
};

const vacio = () => {
    const branch = sucursalInicial();

    return { branch_ulid: branch, amount: '', bank_name: '', reference: '', deposited_on: hoyEn(zonaDe(branch)) };
};

const form = ref(vacio());
const registrando = ref(false);

/** Tope del campo de fecha: hoy, en la zona de la sucursal elegida. */
const hoy = computed(() => hoyEn(zonaDe(form.value.branch_ulid)));

// Las sucursales llegan después de montar: si el formulario ya estaba abierto sin sucursal, se completa.
watch(sucursales, () => {
    if (! form.value.branch_ulid) {
        form.value.branch_ulid = sucursalInicial();
    }
});

function abrir() {
    form.value = vacio();
    guardar.fieldErrors.value = {};
    guardar.generalError.value = null;
    registrando.value = true;
}

const guardar = useApiForm(async () => {
    const { data } = await api.post('/bank-deposits', {
        branch_ulid: form.value.branch_ulid,
        amount: String(form.value.amount).trim(),
        bank_name: form.value.bank_name.trim(),
        reference: form.value.reference.trim(),
        deposited_on: form.value.deposited_on,
    });

    registrando.value = false;

    if (puedeVer.value) {
        await list.load();
    }

    return data;
}, { success: (data) => `Depósito de ${formatMoney(data.amount)} en ${data.bank_name} registrado.` });

function montoLegible(valor) {
    const formateado = formatMoney(valor);

    return formateado === '—' ? String(valor ?? '').trim() : formateado;
}

async function registrar() {
    const texto = `¿Registrar el depósito de ${montoLegible(form.value.amount)} en ${form.value.bank_name.trim()} `
        + `(referencia ${form.value.reference.trim()}) del ${dia(form.value.deposited_on)}? Queda asentado en el diario `
        + 'financiero como salida del negocio hacia el banco y no se puede editar ni borrar.';

    if (! window.confirm(texto)) {
        return;
    }

    await guardar.submit();
}

const columnas = [
    { key: 'deposited_on', label: 'Depositado', width: '8rem' },
    { key: 'branch', label: 'Sucursal', width: '10rem' },
    { key: 'bank_name', label: 'Banco', width: '10rem' },
    { key: 'reference', label: 'Referencia', width: '10rem' },
    { key: 'amount', label: 'Monto', width: '9rem', align: 'right' },
    { key: 'created_by', label: 'Registró', width: '10rem' },
    { key: 'created_at', label: 'Capturado', width: '9rem' },
];
</script>

<template>
    <Head title="Depósitos bancarios" />

    <div class="depositos animar-entrada">
        <ListHeader
            title="Depósitos bancarios"
            subtitle="El efectivo que se retira de la caja llega al banco con un depósito. No hace falta caja abierta: se registra con el comprobante en la mano, aunque sea días después."
            :count="puedeVer ? (list.meta.value?.total ?? null) : null"
            :search="puedeVer ? list.filters.search : undefined"
            search-placeholder="Buscar por banco o referencia…"
            :active-count="puedeVer ? filtrosActivos : 0"
            @update:search="(valor) => (list.filters.search = valor)"
            @clear="limpiarFiltros"
        >
            <template v-if="puedeVer" #filters>
                <div v-if="sucursales.length > 1" class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-sucursal">Sucursal</label>
                    <select id="filtro-sucursal" v-model="list.filters.branch" class="input input--select">
                        <option value="">Todas</option>
                        <option v-for="b in sucursales" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                    </select>
                </div>

                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-desde">Depositado desde</label>
                    <input id="filtro-desde" v-model="list.filters.deposited_from" class="input" type="date" />
                </div>

                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-hasta">Hasta</label>
                    <input id="filtro-hasta" v-model="list.filters.deposited_to" class="input" type="date" />
                </div>

                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-orden">Orden</label>
                    <select id="filtro-orden" v-model="list.filters.sort" class="input input--select">
                        <option value="">Más recientes primero</option>
                        <option value="deposited_on">Más antiguos primero</option>
                        <option value="-amount">Mayor monto primero</option>
                        <option value="amount">Menor monto primero</option>
                    </select>
                </div>
            </template>

            <template v-if="puedeRegistrar" #action>
                <button type="button" class="button" :disabled="registrando" @click="abrir">
                    <Icon name="plus" /> Registrar depósito
                </button>
            </template>
        </ListHeader>

        <section v-if="registrando" class="tarjeta bloque" aria-labelledby="deposito-titulo">
            <h2 id="deposito-titulo" class="bloque__titulo">Registrar depósito</h2>

            <p class="page-header__hint">
                Cierra el recorrido de un retiro: el dinero que salió de la caja entra aquí al banco. La referencia es el
                folio del comprobante, con el que se busca en el estado de cuenta.
            </p>

            <p v-if="errorSucursales" class="alert" role="alert">
                No se pudieron cargar tus sucursales, así que por ahora no se puede registrar un depósito. Detalle:
                {{ errorSucursales }}
            </p>

            <form v-else class="editor" @submit.prevent="registrar">
                <div class="editor__rejilla">
                    <div class="field">
                        <label class="field__label" for="deposito-sucursal">Sucursal</label>
                        <select id="deposito-sucursal" v-model="form.branch_ulid" class="input" required>
                            <option value="" disabled>Elige sucursal…</option>
                            <option v-for="b in sucursales" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                        </select>
                        <span v-if="guardar.fieldErrors.value.branch_ulid" class="field__error">
                            {{ guardar.fieldErrors.value.branch_ulid }}
                        </span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="deposito-monto">Monto</label>
                        <input
                            id="deposito-monto"
                            v-model="form.amount"
                            class="input"
                            type="text"
                            inputmode="decimal"
                            placeholder="0.00"
                            autocomplete="off"
                            required
                        />
                        <span v-if="guardar.fieldErrors.value.amount" class="field__error">
                            {{ guardar.fieldErrors.value.amount }}
                        </span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="deposito-banco">Banco</label>
                        <input
                            id="deposito-banco"
                            v-model="form.bank_name"
                            class="input"
                            type="text"
                            minlength="2"
                            maxlength="60"
                            placeholder="p. ej. BBVA"
                            required
                        />
                        <span v-if="guardar.fieldErrors.value.bank_name" class="field__error">
                            {{ guardar.fieldErrors.value.bank_name }}
                        </span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="deposito-referencia">Referencia del comprobante</label>
                        <input
                            id="deposito-referencia"
                            v-model="form.reference"
                            class="input"
                            type="text"
                            maxlength="60"
                            autocomplete="off"
                            placeholder="Folio de la ficha"
                            required
                        />
                        <span v-if="guardar.fieldErrors.value.reference" class="field__error">
                            {{ guardar.fieldErrors.value.reference }}
                        </span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="deposito-fecha">Fecha del depósito</label>
                        <input
                            id="deposito-fecha"
                            v-model="form.deposited_on"
                            class="input"
                            type="date"
                            :max="hoy"
                            required
                            aria-describedby="deposito-fecha-ayuda"
                        />
                        <span id="deposito-fecha-ayuda" class="field__hint">La del comprobante; no puede ser futura.</span>
                        <span v-if="guardar.fieldErrors.value.deposited_on" class="field__error">
                            {{ guardar.fieldErrors.value.deposited_on }}
                        </span>
                    </div>
                </div>

                <p v-if="guardar.generalError.value" class="alert" role="alert">{{ guardar.generalError.value }}</p>

                <div class="acciones">
                    <button
                        type="button"
                        class="link-button"
                        :disabled="guardar.processing.value"
                        @click="registrando = false"
                    >
                        <Icon name="x" /> Cancelar
                    </button>
                    <button type="submit" class="button" :disabled="guardar.processing.value">
                        <Icon name="check" /> {{ guardar.processing.value ? 'Registrando…' : 'Registrar depósito' }}
                    </button>
                </div>
            </form>
        </section>

        <p v-if="! puedeVer" class="alert alert--notice" role="status">
            Tu rol no puede consultar los depósitos registrados: se ven con el permiso del diario financiero.
            <template v-if="puedeRegistrar">Sí puedes registrar uno con «Registrar depósito».</template>
        </p>

        <template v-else>
            <DataTable
                :columns="columnas"
                :rows="list.items.value"
                :loading="list.loading.value"
                :error="errorListado"
                empty-message="No hay depósitos que coincidan."
            >
                <template #cell:deposited_on="{ row }">{{ dia(row.deposited_on) }}</template>
                <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>
                <template #cell:amount="{ row }">{{ formatMoney(row.amount) }}</template>
                <template #cell:created_by="{ row }">{{ row.created_by?.name ?? '—' }}</template>
                <template #cell:created_at="{ row }">
                    <span class="muted">{{ momento(row.created_at, row.branch?.ulid) }}</span>
                </template>
            </DataTable>

            <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="depósitos" />
        </template>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.depositos {
    display: grid;
    gap: 1rem;
    min-width: 0;
}

.depositos > .alert,
.bloque .alert,
.bloque .page-header__hint {
    margin: 0;
}

.bloque {
    display: grid;
    gap: 0.85rem;
    padding: 1.15rem;
}

.bloque__titulo {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--color-contenido);
}

.editor {
    display: grid;
    gap: 0.85rem;
}

.editor__rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
    gap: 0.85rem;
}

.editor .field,
.editor .alert {
    margin: 0;
}

.acciones {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
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

.muted {
    color: var(--color-suave);
}
</style>
