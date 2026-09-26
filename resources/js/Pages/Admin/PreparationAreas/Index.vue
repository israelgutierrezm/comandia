<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError, getAllPages, orEmptyWhenForbidden } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import ListHeader from '../../../components/ListHeader.vue';
import FormHeader from '../../../components/FormHeader.vue';
import Paginacion from '../../../components/Paginacion.vue';
import Icon from '../../../components/Icon.vue';
import AreaRoutingPanel from '../../../components/organization/AreaRoutingPanel.vue';
import ConfirmDialog from '../../../components/organization/ConfirmDialog.vue';

const view = ref('list');

/**
 * Dos pestañas: las áreas y lo que va a cada una (el ruteo, D240). Van juntas porque sin ruteo un área no recibe nada:
 * crear «Barra» sin decir que las bebidas van a ella deja la barra muda.
 */
const TABS = [
    { value: 'areas', label: 'Áreas' },
    { value: 'routing', label: '¿Qué va a cada área?' },
];

const tab = ref('areas');

function onTabKeydown(event, position) {
    const target = { ArrowRight: position + 1, ArrowLeft: position - 1, Home: 0, End: TABS.length - 1 }[event.key];

    if (target === undefined) {
        return;
    }

    event.preventDefault();

    const next = (target + TABS.length) % TABS.length;

    tab.value = TABS[next].value;
    event.currentTarget.parentElement?.querySelectorAll('[role="tab"]')[next]?.focus();
}

// El panel de ruteo se monta desde el principio (oculto) para poder avisar aquí de lo que le falta a una sucursal.
const routingPanel = ref(null);
const routingSummary = ref({ ready: false, branchesWithoutRules: [], rulesToArchivedAreas: 0 });

function listNames(names) {
    return names.length <= 1 ? (names[0] ?? '') : `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`;
}

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

const filtrosActivos = computed(() => (list.filters.status !== '' ? 1 : 0));
function limpiarFiltros() {
    list.filters.status = '';
}
const branches = ref([]);
const warehouses = ref([]);
const printers = ref([]);
const lookupError = ref(null);

onMounted(async () => {
    await list.load();

    // Los tres catálogos alimentan el formulario, no la lista, y cada uno tiene su propio permiso de lectura. Un rol que
    // ve áreas pero no, digamos, impresoras (403) sigue viendo la lista con ese selector vacío; cualquier otro fallo se
    // dice, en lugar de perderse en la consola.
    try {
        const [sucursales, almacenes, impresoras] = await Promise.all([
            orEmptyWhenForbidden(api.get('/branches', { status: 'active', per_page: 100 })),
            orEmptyWhenForbidden(api.get('/warehouses', { status: 'active', per_page: 100 })),
            orEmptyWhenForbidden(api.get('/printers', { status: 'active', per_page: 100 })),
        ]);

        branches.value = sucursales.data;
        warehouses.value = almacenes.data;
        printers.value = impresoras.data;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        lookupError.value = e.title;
    }
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

        // Un área nueva —o renombrada— cambia a qué se puede rutear y cómo se nombra en las reglas.
        routingPanel.value?.reload();
    }
}

/**
 * Dar de baja un área (POST archive).
 *
 * ## Lo que el servidor hace, y lo que NO
 *
 * Sólo cambia su estado. No quita sus reglas de ruteo, y el resolutor sigue mandando a ella lo que coincida: sus
 * comandas se emiten igual —por su impresora, si tiene— pero el tablero de cocina sólo muestra áreas activas, así que
 * dejan de verse en él. Tampoco hay forma de reactivarla ni de reusar su código en la sucursal. La confirmación lo dice
 * con los datos de ESTA área —cuántas reglas la usan, cuántas comandas tiene sin terminar— antes de dejar confirmar.
 */
const archiving = ref(null);
const impact = ref({ loading: false, rules: null, liveTickets: null });

const archive = useApiForm(
    async (area) => {
        await api.post(`/preparation-areas/${area.ulid}/archive`);
    },
    { success: { kind: 'archive', entity: 'Área de preparación' } },
);

/** Un 403 o un fallo al averiguar la consecuencia no impide confirmar: la confirmación lo dice sin la cifra. */
function unknownOnApiError(e) {
    if (e instanceof ApiError) {
        return null;
    }

    throw e;
}

async function askArchive(area) {
    archive.generalError.value = null;
    archiving.value = area;
    impact.value = { loading: true, rules: null, liveTickets: null };

    const [areaRules, liveTickets] = await Promise.all([
        getAllPages('/pos-area-routes', { area: area.ulid }).catch(unknownOnApiError),

        // Las comandas vivas del tablero. Un área sin tablero no tiene comandas que «desaparezcan» de él.
        area.uses_kds
            ? api.get(`/kds/areas/${area.ulid}/tickets`).then((r) => (r?.data ?? []).length).catch(unknownOnApiError)
            : Promise.resolve(null),
    ]);

    // Se cerró o se abrió otra mientras tanto.
    if (archiving.value?.ulid !== area.ulid) {
        return;
    }

    impact.value = { loading: false, rules: areaRules, liveTickets };
}

async function confirmArchive() {
    if (await archive.submit(archiving.value)) {
        archiving.value = null;
        await list.load();
        routingPanel.value?.reload();
    }
}

function routeName(rule) {
    return rule.is_article_override ? `Artículo ${rule.article?.name ?? '—'}` : `Categoría ${rule.category?.name ?? '—'}`;
}

const archivingRulesText = computed(() => {
    const found = impact.value.rules ?? [];
    const names = found.slice(0, 4).map(routeName);
    const rest = found.length - names.length;

    return rest > 0 ? `${names.join(', ')} y ${rest} más` : listNames(names);
});

const columns = [
    { key: 'sort_order', label: 'Orden', width: '5rem' },
    { key: 'code', label: 'Código', width: '7rem' },
    { key: 'name', label: 'Área' },
    { key: 'branch', label: 'Sucursal' },
    { key: 'warehouse', label: 'Descuenta de' },
    { key: 'printer', label: 'Imprime en', width: '10rem' },
    { key: 'status', label: 'Estado', width: '8rem' },
    { key: 'actions', label: '', width: '13rem' },
];
</script>

<template>
    <Head title="Áreas de preparación" />

    <!--
        `:key="tab"`: el buscador, los filtros y la vista sólo aplican a la lista de áreas, así que en la otra pestaña no
        se pasan. ListHeader decide si pinta «Filtros» con un `computed` sobre sus slots, que no son reactivos: sin
        remontarlo, el botón se quedaría como estaba en la pestaña anterior.
    -->
    <ListHeader
        :key="tab"
        title="Áreas de preparación"
        subtitle="Cada área es destino de comandas y punto de consumo de inventario: lo que se prepara aquí se descuenta del almacén indicado. El orden define cómo se listan en el POS."
        :count="tab === 'areas' ? (list.meta.value?.total ?? null) : null"
        :search="tab === 'areas' ? list.filters.search : undefined"
        :active-count="filtrosActivos"
        @update:search="(valor) => (list.filters.search = valor)"
        @clear="limpiarFiltros"
    >
        <template v-if="tab === 'areas'" #filters>
            <select v-model="list.filters.status" class="input input--select" aria-label="Estado">
                <option value="">Todas</option>
                <option value="active">Activas</option>
                <option value="inactive">Dadas de baja</option>
            </select>
        </template>

        <template v-if="tab === 'areas'" #view>
            <ViewToggle v-model="view" persist-key="comandia:view:areas" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'organization.preparation_areas.manage'" class="button" type="button" @click="startCreate">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Nueva área
            </button>
        </template>
    </ListHeader>

    <div class="filtros pestanas" role="tablist" aria-label="Secciones de áreas de preparación">
        <button
            v-for="(item, position) in TABS"
            :id="`areas-tab-${item.value}`"
            :key="item.value"
            type="button"
            role="tab"
            class="filtro"
            :class="{ 'filtro--activo': tab === item.value }"
            :aria-selected="tab === item.value"
            :aria-controls="`areas-panel-${item.value}`"
            :tabindex="tab === item.value ? 0 : -1"
            @click="tab = item.value"
            @keydown="onTabKeydown($event, position)"
        >
            {{ item.label }}
        </button>
    </div>

    <p v-if="lookupError" class="alert" role="alert">
        No se pudieron cargar las sucursales, los almacenes o las impresoras: el formulario no podrá ofrecerlos.
        Detalle: {{ lookupError }}
    </p>

    <div v-show="tab === 'areas'" id="areas-panel-areas" role="tabpanel" aria-labelledby="areas-tab-areas">
        <!-- Lo que falta en el ruteo se avisa aquí también: quien crea áreas es quien tiene que decidir qué va a ellas. -->
        <p v-if="routingSummary.ready && routingSummary.branchesWithoutRules.length > 0" class="alert alert--notice">
            <strong>
                {{ listNames(routingSummary.branchesWithoutRules) }}
                {{ routingSummary.branchesWithoutRules.length === 1 ? 'no tiene' : 'no tienen' }} reglas de ruteo:
            </strong>
            sus órdenes no se comandan a ningún área, así que no sale nada en la cocina, la barra ni el tablero.
            <button type="button" class="link-button alert__action" @click="tab = 'routing'">Decidir qué va a cada área</button>
        </p>

        <p v-if="routingSummary.ready && routingSummary.rulesToArchivedAreas > 0" class="alert alert--notice">
            {{ routingSummary.rulesToArchivedAreas === 1
                ? 'Una regla de ruteo manda'
                : `${routingSummary.rulesToArchivedAreas} reglas de ruteo mandan` }}
            artículos a un área dada de baja: sus comandas ya no aparecen en ningún tablero.
            <button type="button" class="link-button alert__action" @click="tab = 'routing'">Revisar el ruteo</button>
        </p>

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

            <template #cell:status="{ row }">
                <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                    {{ row.status === 'active' ? 'Activa' : 'Dada de baja' }}
                </span>
            </template>

            <template #cell:actions="{ row }">
                <div class="row-actions">
                    <button v-can.write="'organization.preparation_areas.manage'" class="link-button link-button--warning" type="button" @click="startEdit(row)"><Icon name="edit" /> Editar</button>
                    <button
                        v-if="row.status === 'active'"
                        v-can.write="'organization.preparation_areas.manage'"
                        class="link-button link-button--danger"
                        type="button"
                        @click="askArchive(row)"
                    ><Icon name="trash" /> Dar de baja</button>
                </div>
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
                        <span class="badge" :class="item.status === 'active' ? 'badge--ok' : 'badge--off'">
                            {{ item.status === 'active' ? 'Activa' : 'Dada de baja' }}
                        </span>
                    </span>
                    <span class="card__meta">Imprime en: {{ item.printer ? item.printer.name : 'sin impresora' }}</span>
                    <div class="card__actions">
                        <button v-can.write="'organization.preparation_areas.manage'" class="link-button link-button--warning" type="button" @click="startEdit(item)"><Icon name="edit" /> Editar</button>
                        <button
                            v-if="item.status === 'active'"
                            v-can.write="'organization.preparation_areas.manage'"
                            class="link-button link-button--danger"
                            type="button"
                            @click="askArchive(item)"
                        ><Icon name="trash" /> Dar de baja</button>
                    </div>
                </div>
            </template>
        </ResourceGrid>

        <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="áreas" />
    </div>

    <AreaRoutingPanel
        v-show="tab === 'routing'"
        id="areas-panel-routing"
        ref="routingPanel"
        role="tabpanel"
        aria-labelledby="areas-tab-routing"
        :branches="branches"
        @summary="(valor) => (routingSummary = valor)"
    />

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <FormHeader :title="editing === 'new' ? 'Nueva área de preparación' : `Editar ${editing.name}`" />

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
                <button type="button" class="link-button" @click="editing = null"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value"><Icon name="check" /> Guardar</button>
            </div>
        </form>
    </div>

    <!-- La consecuencia REAL de dar de baja, con los datos de esta área (v. `askArchive`). -->
    <ConfirmDialog
        v-if="archiving"
        :title="`¿Dar de baja «${archiving.name}»?`"
        confirm-label="Dar de baja"
        processing-label="Dando de baja…"
        :processing="archive.processing.value"
        :confirm-disabled="impact.loading"
        :error="archive.generalError.value"
        @confirm="confirmArchive"
        @cancel="archiving = null"
    >
        <ul>
            <li>
                <strong>Es definitivo:</strong> no se puede reactivar, y su código «{{ archiving.code }}» no se podrá volver
                a usar en {{ archiving.branch?.name ?? 'su sucursal' }}. Las comandas que ya emitió se conservan en el
                historial.
            </li>

            <li v-if="impact.loading">Revisando qué reglas de ruteo mandan artículos a esta área…</li>
            <li v-else-if="impact.rules === null">
                <strong>No se pudo revisar si alguna regla de ruteo la usa.</strong> Si hay, no se quitan: lo que coincida
                con ellas se seguirá mandando a esta área, pero ya no aparecerá en ningún tablero de cocina.
            </li>
            <li v-else-if="impact.rules.length === 0">Ninguna regla de ruteo manda artículos a esta área.</li>
            <li v-else>
                <strong>
                    {{ impact.rules.length === 1
                        ? 'Su regla de ruteo NO se quita'
                        : `Sus ${impact.rules.length} reglas de ruteo NO se quitan` }}
                </strong>
                ({{ archivingRulesText }}). Lo que coincida con {{ impact.rules.length === 1 ? 'ella' : 'ellas' }} se
                seguirá mandando a esta área:
                <template v-if="archiving.printer">
                    su comanda saldrá por «{{ archiving.printer.name }}», pero no aparecerá en ningún tablero de cocina.
                </template>
                <template v-else>
                    como no tiene impresora, su comanda no saldrá en papel ni en ningún tablero: nadie la verá.
                </template>
                Si no quieres eso, antes quita {{ impact.rules.length === 1 ? 'esa regla' : 'esas reglas' }} en
                «¿Qué va a cada área?».
            </li>

            <template v-if="archiving.uses_kds">
                <li v-if="impact.loading">Revisando sus comandas sin terminar…</li>
                <li v-else-if="impact.liveTickets === null">
                    Si tiene comandas sin terminar, desaparecerán del tablero de cocina, que sólo muestra áreas activas.
                </li>
                <li v-else-if="impact.liveTickets > 0">
                    <strong>
                        Tiene {{ impact.liveTickets === 1 ? 'una comanda' : `${impact.liveTickets} comandas` }} sin terminar
                        en el tablero:
                    </strong>
                    {{ impact.liveTickets === 1 ? 'desaparecerá' : 'desaparecerán' }} de él al darla de baja. Termínalas
                    antes.
                </li>
                <li v-else>No tiene comandas sin terminar en el tablero de cocina.</li>
            </template>

            <li>
                Lo que ya se capturó hacia esta área y aún no se manda a preparar se le comandará igual: el área queda fija
                al capturar. Lo ya impreso no cambia.
            </li>
        </ul>
    </ConfirmDialog>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.muted-cell {
    color: var(--color-suave);
    font-size: 0.85rem;
}

.reorder-hint { margin: 0 0 0.6rem; font-size: 0.8rem; color: var(--color-suave); }

.pestanas { margin: 0 0 1rem; }

.alert__action { margin-left: 0.35rem; }
</style>
