<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError, getAllPages } from '../../../api/client';
import { useApiForm, useResourceList } from '../../../stores/useResourceList';
import { pushToast } from '../../../stores/useToasts';
import { useAuthorization } from '../../../composables/useAuthorization';
import { formatInBranchTime } from '../../../support/datetime';
import { formatMoney } from '../../../support/money';
import DataTable from '../../../components/DataTable.vue';
import ListHeader from '../../../components/ListHeader.vue';
import Paginacion from '../../../components/Paginacion.vue';
import Icon from '../../../components/Icon.vue';
import ExpenseForm from '../../../components/finance/ExpenseForm.vue';

/**
 * Gastos y su catálogo de categorías (§6.5).
 *
 * ## Dos orígenes que no se mezclan
 *
 * Un gasto DESDE CAJA sale del efectivo del turno y entra en el arqueo; uno FUERA DE CAJA es gasto del negocio y no
 * toca la caja de nadie. La pantalla los distingue en todo momento —al registrar, en la lista y en el detalle— porque
 * confundirlos haría que el arqueo del cajero cargara con la renta del local.
 *
 * ## Inmutables
 *
 * Un gasto registrado no se edita ni se borra: es dinero que ya salió, y el corte ya lo contó. Aquí no hay «Editar» ni
 * «Eliminar», y no es un olvido.
 *
 * ## Cuatro permisos, una pantalla
 *
 * Ver la lista usa el permiso del DIARIO (un gasto es un asiento visto desde otro ángulo); registrar, el de gasto desde
 * caja —y además el de fuera de caja para ese origen—; leer las categorías, el de registrar; administrarlas, el de
 * fuera de caja, que es el más restringido. Cada bloque aparece sólo con el suyo: ofrecer lo que respondería 403
 * enseña a desconfiar de la interfaz.
 *
 * ## Las categorías viven aquí
 *
 * Quien registra necesita la categoría que le falta en el momento en que le falta (el criterio de las mermas, D171).
 * Una categoría se desactiva, no se borra: los gastos que la citan tienen que poder seguir diciendo en qué se gastó.
 */
const page = usePage();
const { can, canWrite } = useAuthorization();

const puedeVerGastos = computed(() => can('finance.journal.view'));
const puedeRegistrar = computed(() => canWrite('finance.expenses.create_from_cash'));
const puedeVerCategorias = computed(() => can('finance.expenses.create_from_cash'));
const puedeAdministrarCategorias = computed(() => canWrite('finance.expenses.create_outside_cash'));
const puedeFueraDeCaja = computed(
    () => canWrite('finance.expenses.create_from_cash') && canWrite('finance.expenses.create_outside_cash'),
);

const pestana = ref('gastos');

// ---- Datos de referencia ----

const sucursales = ref([]);
const errorSucursales = ref(null);

/** El catálogo COMPLETO, con las inactivas: se listan para reactivarlas y sirven para filtrar gastos viejos. */
const categorias = ref([]);
const categoriasCargadas = ref(false);
const errorCategorias = ref(null);

/** Registrar ofrece sólo las activas: con una inactiva el servidor responde 422. */
const categoriasActivas = computed(() => categorias.value.filter((c) => c.status === 'active'));

const metodos = ref([]);
const errorMetodos = ref(null);

// ---- Listado de gastos ----

const FILTROS = ['source', 'category', 'branch', 'occurred_from', 'occurred_to'];

const list = useResourceList('/expenses', {
    initialFilters: Object.fromEntries(FILTROS.map((f) => [f, ''])),
});

onMounted(async () => {
    if (puedeVerGastos.value) {
        list.load();
    }

    await Promise.all([
        cargarSucursales(),
        puedeVerCategorias.value ? cargarCategorias() : null,
        puedeFueraDeCaja.value ? cargarMetodos() : null,
    ]);
});

/** Las sucursales del alcance de la persona (las mismas del selector del shell), con su zona horaria. */
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

async function cargarCategorias() {
    errorCategorias.value = null;

    try {
        categorias.value = await getAllPages('/expense-categories');
        categoriasCargadas.value = true;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        errorCategorias.value = e;
    }
}

/**
 * Los métodos ACTIVOS, para el gasto fuera de caja. Si el rol no puede verlos (403) se tratan como vacíos: el
 * formulario lo dice y el resto de la pantalla sigue sirviendo. Cualquier otro fallo se dice aparte, para que el
 * formulario no afirme «no hay métodos activos» de algo que no se pudo saber.
 */
async function cargarMetodos() {
    errorMetodos.value = null;

    try {
        metodos.value = await getAllPages('/payment-methods', { status: 'active' });
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        metodos.value = [];

        if (e.status !== 403) {
            errorMetodos.value = e.title;
        }
    }
}

const filtrosActivos = computed(
    () => FILTROS.filter((f) => list.filters[f] !== '').length + (list.filters.sort !== '' ? 1 : 0),
);

function limpiarFiltros() {
    FILTROS.forEach((f) => { list.filters[f] = ''; });
    list.filters.sort = '';
}

/**
 * El error del listado, con el motivo concreto cuando es de validación. Un rango de fechas invertido responde 422 y el
 * título genérico («Los datos enviados no son válidos») no decía qué corregir.
 */
const errorListado = computed(() => {
    const e = list.error.value;

    if (! e || ! e.isValidation) {
        return e;
    }

    return { isForbidden: false, message: Object.values(e.fieldErrors ?? {})[0] ?? e.message };
});

/** La hora de la sucursal DEL GASTO, no la del navegador: en un corte, la hora decide la jornada. */
function fecha(iso, branchUlid) {
    const zona = sucursales.value.find((b) => b.ulid === branchUlid)?.timezone ?? page.props.context?.branch_timezone;

    return formatInBranchTime(iso, zona) || '—';
}

function persona(p) {
    if (! p) {
        return '—';
    }

    return p.employee_code ? `${p.name} (${p.employee_code})` : p.name;
}

// ---- Registrar ----

const registrando = ref(false);

async function onGastoRegistrado() {
    registrando.value = false;

    if (puedeVerGastos.value) {
        await list.load();
    }
}

// ---- Detalle ----

/** El gasto abierto en el panel lateral. Viene completo en la fila: no hace falta pedirlo otra vez (es inmutable). */
const detalle = ref(null);

// ---- Categorías ----

const vaciaCategoria = () => ({ name: '', sort_order: '' });
const categoriaForm = ref(vaciaCategoria());
const categoriaFormAbierto = ref(false);
const categoriaEditando = ref(null);
const categoriaCambiando = ref(null);
const errorCategoriaAccion = ref(null);

const guardarCategoria = useApiForm(async () => {
    const cuerpo = { name: categoriaForm.value.name.trim() };
    const orden = String(categoriaForm.value.sort_order ?? '').trim();

    // Tal como se tecleó: el servidor valida el entero. Vacío = no se toca (en el alta, el servidor pone 500).
    if (orden !== '') {
        cuerpo.sort_order = orden;
    }

    const accion = categoriaEditando.value ? 'update' : 'create';

    if (categoriaEditando.value) {
        await api.patch(`/expense-categories/${categoriaEditando.value.ulid}`, cuerpo);
    } else {
        await api.post('/expense-categories', cuerpo);
    }

    cerrarCategoria();
    await cargarCategorias();

    return accion;
}, { success: (accion) => ({ kind: accion, entity: 'Categoría de gasto', gender: 'f' }) });

function limpiarErroresCategoria() {
    guardarCategoria.fieldErrors.value = {};
    guardarCategoria.generalError.value = null;
}

function nuevaCategoria() {
    categoriaEditando.value = null;
    categoriaForm.value = vaciaCategoria();
    limpiarErroresCategoria();
    categoriaFormAbierto.value = true;
}

function editarCategoria(categoria) {
    categoriaEditando.value = categoria;
    categoriaForm.value = { name: categoria.name, sort_order: categoria.sort_order ?? '' };
    limpiarErroresCategoria();
    categoriaFormAbierto.value = true;
}

function cerrarCategoria() {
    categoriaFormAbierto.value = false;
    categoriaEditando.value = null;
    categoriaForm.value = vaciaCategoria();
}

async function alternarCategoria(categoria) {
    const activa = categoria.status === 'active';
    const texto = activa
        ? `¿Desactivar la categoría «${categoria.name}»? Ya no se podrá elegir al registrar gastos nuevos. Los gastos ya `
            + 'registrados con ella se conservan tal cual.'
        : `¿Activar la categoría «${categoria.name}»? Volverá a ofrecerse al registrar gastos.`;

    if (categoriaCambiando.value !== null || ! window.confirm(texto)) {
        return;
    }

    categoriaCambiando.value = categoria.ulid;
    errorCategoriaAccion.value = null;

    try {
        const { data } = await api.post(`/expense-categories/${categoria.ulid}/toggle`);

        pushToast(
            data.status === 'active' ? `Categoría «${data.name}» activada.` : `Categoría «${data.name}» desactivada.`,
            data.status === 'active' ? 'success' : 'danger',
        );

        await cargarCategorias();
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        // El 409 de la última categoría activa se muestra tal cual: dice qué hacer antes.
        errorCategoriaAccion.value = e.message;
    } finally {
        categoriaCambiando.value = null;
    }
}

// ---- Encabezado ----

const contador = computed(() => {
    if (pestana.value === 'categorias') {
        return categoriasCargadas.value ? categorias.value.length : null;
    }

    return puedeVerGastos.value ? (list.meta.value?.total ?? null) : null;
});

const conFiltros = computed(() => pestana.value === 'gastos' && puedeVerGastos.value);

const columnas = [
    { key: 'occurred_at', label: 'Fecha', width: '9rem' },
    { key: 'concepto', label: 'Concepto' },
    { key: 'branch', label: 'Sucursal', width: '10rem' },
    { key: 'source', label: 'Origen', width: '12rem' },
    { key: 'amount', label: 'Monto', width: '8rem', align: 'right' },
    { key: 'created_by', label: 'Registró', width: '11rem' },
    { key: 'actions', label: '', width: '6rem' },
];

const columnasCategorias = [
    { key: 'name', label: 'Categoría' },
    { key: 'sort_order', label: 'Orden', width: '6rem', align: 'right' },
    { key: 'status', label: 'Estado', width: '8rem' },
    { key: 'actions', label: '', width: '15rem' },
];
</script>

<template>
    <Head title="Gastos" />

    <div class="gastos animar-entrada">
        <ListHeader
            title="Gastos"
            subtitle="Lo que sale del negocio: desde caja (del efectivo del turno, entra en el arqueo) o fuera de caja (transferencia, tarjeta de la empresa). Un gasto registrado no se edita ni se borra."
            :count="contador"
            :search="conFiltros ? list.filters.search : undefined"
            search-placeholder="Buscar en el concepto…"
            :active-count="conFiltros ? filtrosActivos : 0"
            @update:search="(valor) => (list.filters.search = valor)"
            @clear="limpiarFiltros"
        >
            <template v-if="conFiltros" #filters>
                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-origen">Origen</label>
                    <select id="filtro-origen" v-model="list.filters.source" class="input input--select">
                        <option value="">Todos</option>
                        <option value="cash_session">Desde caja</option>
                        <option value="outside_cash">Fuera de caja</option>
                    </select>
                </div>

                <div v-if="categorias.length" class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-categoria">Categoría</label>
                    <select id="filtro-categoria" v-model="list.filters.category" class="input input--select">
                        <option value="">Todas</option>
                        <option v-for="c in categorias" :key="c.ulid" :value="c.ulid">
                            {{ c.name }}{{ c.status === 'active' ? '' : ' (inactiva)' }}
                        </option>
                    </select>
                </div>

                <div v-if="sucursales.length > 1" class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-sucursal">Sucursal</label>
                    <select id="filtro-sucursal" v-model="list.filters.branch" class="input input--select">
                        <option value="">Todas</option>
                        <option v-for="b in sucursales" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                    </select>
                </div>

                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-desde">Desde</label>
                    <input id="filtro-desde" v-model="list.filters.occurred_from" class="input" type="date" />
                </div>

                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-hasta">Hasta</label>
                    <input id="filtro-hasta" v-model="list.filters.occurred_to" class="input" type="date" />
                </div>

                <div class="filtro-campo">
                    <label class="filtro-campo__label" for="filtro-orden">Orden</label>
                    <select id="filtro-orden" v-model="list.filters.sort" class="input input--select">
                        <option value="">Más recientes primero</option>
                        <option value="occurred_at">Más antiguos primero</option>
                        <option value="-amount">Mayor monto primero</option>
                        <option value="amount">Menor monto primero</option>
                    </select>
                </div>
            </template>

            <template v-if="(pestana === 'gastos' && puedeRegistrar) || (pestana === 'categorias' && puedeAdministrarCategorias)" #action>
                <button
                    v-if="pestana === 'gastos'"
                    type="button"
                    class="button"
                    :disabled="registrando"
                    @click="registrando = true"
                >
                    <Icon name="plus" /> Registrar gasto
                </button>
                <button v-else type="button" class="button" @click="nuevaCategoria">
                    <Icon name="plus" /> Nueva categoría
                </button>
            </template>
        </ListHeader>

        <div v-if="puedeVerCategorias" class="filtros" role="tablist" aria-label="Sección de gastos">
            <button
                id="pestana-gastos"
                type="button"
                role="tab"
                class="filtro"
                :class="{ 'filtro--activo': pestana === 'gastos' }"
                :aria-selected="pestana === 'gastos'"
                aria-controls="panel-gastos"
                @click="pestana = 'gastos'"
            >
                Gastos
            </button>
            <button
                id="pestana-categorias"
                type="button"
                role="tab"
                class="filtro"
                :class="{ 'filtro--activo': pestana === 'categorias' }"
                :aria-selected="pestana === 'categorias'"
                aria-controls="panel-categorias"
                @click="pestana = 'categorias'"
            >
                Categorías
            </button>
        </div>

        <!-- ================= GASTOS ================= -->
        <div
            v-show="pestana === 'gastos'"
            id="panel-gastos"
            class="panel"
            :role="puedeVerCategorias ? 'tabpanel' : undefined"
            :aria-labelledby="puedeVerCategorias ? 'pestana-gastos' : undefined"
        >
            <section v-if="registrando" class="tarjeta bloque" aria-labelledby="registro-titulo">
                <h2 id="registro-titulo" class="bloque__titulo">Registrar gasto</h2>

                <p v-if="errorSucursales" class="alert" role="alert">
                    No se pudieron cargar tus sucursales, así que por ahora no se puede registrar un gasto. Detalle:
                    {{ errorSucursales }}
                </p>
                <p v-else-if="errorCategorias" class="alert" role="alert">
                    No se pudieron cargar las categorías de gasto. Detalle: {{ errorCategorias.message }}
                </p>
                <p v-else-if="! categoriasCargadas" class="page-header__hint">Cargando…</p>

                <ExpenseForm
                    v-else
                    id-prefix="gasto-nuevo"
                    :branches="sucursales"
                    :categories="categoriasActivas"
                    :payment-methods="metodos"
                    cancellable
                    @cancel="registrando = false"
                    @registered="onGastoRegistrado"
                />

                <p v-if="errorMetodos" class="alert" role="alert">
                    No se pudieron cargar los métodos de pago, así que un gasto fuera de caja no se puede registrar por
                    ahora. Detalle: {{ errorMetodos }}
                </p>
            </section>

            <p v-if="! puedeVerGastos" class="alert alert--notice" role="status">
                Tu rol no puede consultar los gastos registrados: se ven con el permiso del diario financiero.
                <template v-if="puedeRegistrar">Sí puedes registrar uno con «Registrar gasto».</template>
            </p>

            <template v-else>
                <DataTable
                    :columns="columnas"
                    :rows="list.items.value"
                    :loading="list.loading.value"
                    :error="errorListado"
                    empty-message="No hay gastos que coincidan."
                >
                    <template #cell:occurred_at="{ row }">{{ fecha(row.occurred_at, row.branch?.ulid) }}</template>

                    <template #cell:concepto="{ row }">
                        <div class="concepto">
                            <span class="concepto__categoria">{{ row.category?.name ?? '—' }}</span>
                            <span class="concepto__texto" :title="row.description">{{ row.description }}</span>
                        </div>
                    </template>

                    <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>

                    <template #cell:source="{ row }">
                        <span class="badge" :class="row.affects_cash_drawer ? 'badge--warn' : 'badge--off'">
                            {{ row.source_label }}
                        </span>
                        <span v-if="row.payment_method" class="muted"> · {{ row.payment_method.name }}</span>
                    </template>

                    <template #cell:amount="{ row }">{{ formatMoney(row.amount) }}</template>

                    <template #cell:created_by="{ row }">
                        <div class="personas">
                            <span>{{ row.created_by?.name ?? '—' }}</span>
                            <small v-if="row.authorized_by" class="muted">autorizó {{ row.authorized_by.name }}</small>
                        </div>
                    </template>

                    <template #cell:actions="{ row }">
                        <button type="button" class="link-button" @click="detalle = row">
                            <Icon name="eye" /> Ver
                        </button>
                    </template>
                </DataTable>

                <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="gastos" />
            </template>
        </div>

        <!-- ================= CATEGORÍAS ================= -->
        <div
            v-if="puedeVerCategorias"
            v-show="pestana === 'categorias'"
            id="panel-categorias"
            class="panel"
            role="tabpanel"
            aria-labelledby="pestana-categorias"
        >
            <p class="page-header__hint">
                Un solo catálogo para los gastos desde caja y los de fuera: la diferencia entre ellos es de dónde salió el
                dinero, no en qué se gastó. Una categoría se desactiva; no se borra, porque los gastos que la citan tienen
                que seguir diciendo en qué se gastó. Las del sistema también se pueden renombrar.
            </p>

            <p v-if="errorCategoriaAccion" class="alert" role="alert">{{ errorCategoriaAccion }}</p>

            <section v-if="categoriaFormAbierto" class="tarjeta bloque" aria-labelledby="categoria-titulo">
                <h2 id="categoria-titulo" class="bloque__titulo">
                    {{ categoriaEditando ? `Editar «${categoriaEditando.name}»` : 'Nueva categoría de gasto' }}
                </h2>

                <form class="editor" @submit.prevent="guardarCategoria.submit()">
                    <div class="editor__rejilla">
                        <div class="field">
                            <label class="field__label" for="categoria-nombre">Nombre</label>
                            <input
                                id="categoria-nombre"
                                v-model="categoriaForm.name"
                                class="input"
                                type="text"
                                maxlength="60"
                                autocomplete="off"
                                placeholder="p. ej. Gas, Mantenimiento, Renta"
                                required
                            />
                            <span v-if="guardarCategoria.fieldErrors.value.name" class="field__error">
                                {{ guardarCategoria.fieldErrors.value.name }}
                            </span>
                        </div>

                        <div class="field">
                            <label class="field__label" for="categoria-orden">Orden</label>
                            <input
                                id="categoria-orden"
                                v-model="categoriaForm.sort_order"
                                class="input"
                                type="number"
                                inputmode="numeric"
                                min="0"
                                max="9999"
                                step="1"
                                :placeholder="categoriaEditando ? '' : '500'"
                                aria-describedby="categoria-orden-ayuda"
                            />
                            <span id="categoria-orden-ayuda" class="field__hint">
                                Menor sale primero en la lista al registrar un gasto.
                            </span>
                            <span v-if="guardarCategoria.fieldErrors.value.sort_order" class="field__error">
                                {{ guardarCategoria.fieldErrors.value.sort_order }}
                            </span>
                        </div>
                    </div>

                    <p v-if="guardarCategoria.generalError.value" class="alert" role="alert">
                        {{ guardarCategoria.generalError.value }}
                    </p>

                    <div class="acciones">
                        <button
                            type="button"
                            class="link-button"
                            :disabled="guardarCategoria.processing.value"
                            @click="cerrarCategoria"
                        >
                            <Icon name="x" /> Cancelar
                        </button>
                        <button type="submit" class="button" :disabled="guardarCategoria.processing.value">
                            <Icon name="check" />
                            {{ guardarCategoria.processing.value ? 'Guardando…' : (categoriaEditando ? 'Guardar cambios' : 'Crear categoría') }}
                        </button>
                    </div>
                </form>
            </section>

            <DataTable
                :columns="columnasCategorias"
                :rows="categorias"
                :loading="! categoriasCargadas && ! errorCategorias"
                :error="errorCategorias"
                empty-message="Todavía no hay categorías de gasto."
            >
                <template #cell:name="{ row }">
                    <span class="categoria">
                        {{ row.name }}
                        <span v-if="row.is_system" class="badge badge--off">del sistema</span>
                    </span>
                </template>

                <template #cell:sort_order="{ row }">{{ row.sort_order }}</template>

                <template #cell:status="{ row }">
                    <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                        {{ row.status === 'active' ? 'Activa' : 'Inactiva' }}
                    </span>
                </template>

                <template #cell:actions="{ row }">
                    <div v-if="puedeAdministrarCategorias" class="row-actions">
                        <button type="button" class="link-button link-button--warning" @click="editarCategoria(row)">
                            <Icon name="edit" /> Editar
                        </button>
                        <button
                            type="button"
                            class="link-button"
                            :class="{ 'link-button--danger': row.status === 'active' }"
                            :disabled="categoriaCambiando !== null"
                            @click="alternarCategoria(row)"
                        >
                            <Icon :name="row.status === 'active' ? 'x' : 'check'" />
                            {{ row.status === 'active' ? 'Desactivar' : 'Activar' }}
                        </button>
                    </div>
                </template>
            </DataTable>
        </div>
    </div>

    <!-- Fuera del contenedor animado: el panel es `position: fixed`, y un ancestro con `transform` (la animación de
         entrada deja uno puesto) lo mediría contra ese contenedor en vez de contra la pantalla. -->
    <div v-if="detalle" class="drawer-backdrop" @click.self="detalle = null">
        <aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="detalle-titulo">
            <h2 id="detalle-titulo">Gasto de {{ formatMoney(detalle.amount) }}</h2>

            <dl class="detalle">
                <div>
                    <dt>Fecha</dt>
                    <dd>{{ fecha(detalle.occurred_at, detalle.branch?.ulid) }}</dd>
                </div>
                <div>
                    <dt>Sucursal</dt>
                    <dd>{{ detalle.branch?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Categoría</dt>
                    <dd>{{ detalle.category?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Concepto</dt>
                    <dd class="detalle__texto">{{ detalle.description }}</dd>
                </div>
                <div>
                    <dt>Origen</dt>
                    <dd>
                        {{ detalle.source_label }}:
                        {{ detalle.affects_cash_drawer ? 'salió del efectivo de un turno y se descontó en su arqueo.' : 'no tocó el arqueo de ninguna caja.' }}
                    </dd>
                </div>
                <div v-if="detalle.payment_method">
                    <dt>Pagado con</dt>
                    <dd>{{ detalle.payment_method.name }}</dd>
                </div>
                <div>
                    <dt>Registró</dt>
                    <dd>{{ persona(detalle.created_by) }}</dd>
                </div>
                <div>
                    <dt>Autorizó</dt>
                    <dd>{{ detalle.authorized_by ? persona(detalle.authorized_by) : 'Sin firma: no pasó del umbral de la sucursal.' }}</dd>
                </div>
                <div>
                    <dt>Comprobante</dt>
                    <dd class="detalle__texto">{{ detalle.receipt_path || 'Sin comprobante' }}</dd>
                </div>
            </dl>

            <p class="field__hint">
                Un gasto no se edita ni se borra: es dinero que ya salió y el corte ya lo contó.
            </p>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="detalle = null"><Icon name="x" /> Cerrar</button>
            </div>
        </aside>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.gastos {
    display: grid;
    gap: 1rem;
    min-width: 0;
}

.panel {
    display: grid;
    gap: 1rem;
    min-width: 0;
}

/* Dentro de las rejillas el `gap` ya separa: sin esto, el margen inferior de `.alert` y de la pista se sumaría. */
.panel > .alert,
.panel > .page-header__hint,
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

/* Filtros con su etiqueta encima, para que se sepa qué fecha es «desde» y cuál «hasta». */
.filtro-campo {
    display: grid;
    gap: 0.2rem;
    min-width: 0;
}

.filtro-campo__label {
    font-size: 0.75rem;
    color: var(--color-suave);
}

.concepto {
    display: grid;
    gap: 0.1rem;
}

.concepto__categoria {
    font-weight: 500;
}

/* El concepto puede llegar a 300 caracteres: se recorta en la tabla y se lee completo en el detalle. */
.concepto__texto {
    max-width: 22rem;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 0.82rem;
    color: var(--color-suave);
}

.personas {
    display: grid;
    gap: 0.1rem;
}

.muted {
    color: var(--color-suave);
}

.categoria {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.detalle {
    display: grid;
    gap: 0.75rem;
    margin: 0 0 1rem;
}

.detalle dt {
    font-size: 0.75rem;
    color: var(--color-suave);
    margin-bottom: 0.1rem;
}

.detalle dd {
    margin: 0;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.detalle__texto {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>
