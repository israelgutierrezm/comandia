<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import Paginacion from '../../../components/Paginacion.vue';
import ListHeader from '../../../components/ListHeader.vue';

const view = ref('list');

/**
 * Impresoras de la sucursal (§9.1 de la Iteración 4).
 *
 * ## Qué se configura aquí, y qué no
 *
 * Aquí se dice **qué impresoras hay y cómo llegar a ellas**. A qué impresora sale cada cosa se decide en el área de
 * preparación —para las comandas— y en la terminal —para los tickets—, porque el destino no lo elige quien imprime sino
 * **qué** se imprime: la comanda de bebidas sale por la barra aunque la capture la caja, y el ticket sale por la caja
 * donde está el cliente.
 *
 * ## El destino es texto libre a propósito
 *
 * Una impresora de red se identifica por IP y puerto, una USB por su nombre de dispositivo y una compartida por su ruta
 * de Windows. Son tres formas que no comparten estructura. La pista de qué escribir **viene del servidor** (D139): si
 * la escribiéramos aquí, acabaría diciendo algo distinto de lo que el servidor valida.
 *
 * ## El cajón de dinero se abre por la impresora
 *
 * No tiene cable propio: se abre mandando una secuencia a la impresora de tickets, y sólo algunas llevan ese conector.
 * Por eso `supports_cash_drawer` es una propiedad de la impresora y no una capacidad del sistema — y por eso la
 * pantalla lo pregunta al dar de alta.
 */
const list = useResourceList('/printers', { initialFilters: { status: '', connection: '' } });

const filtrosActivos = computed(
    () => [list.filters.connection !== '', list.filters.status !== ''].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.connection = '';
    list.filters.status = '';
}

const branches = ref([]);
const connections = ref([]);

onMounted(async () => {
    await list.load();

    const [sucursales, conexiones] = await Promise.all([
        api.get('/branches', { status: 'active', per_page: 100 }),
        api.get('/printers/connections'),
    ]);

    branches.value = sucursales.data;
    connections.value = conexiones.data;
});

const editing = ref(null);
const form = ref({});

/** La pista del destino, según la conexión elegida. Viene del servidor, no de aquí. */
const targetHint = computed(
    () => connections.value.find((c) => c.value === form.value.connection)?.target_hint ?? '',
);

const save = useApiForm(async () => {
    if (editing.value === 'new') {
        await api.post('/printers', form.value);

        return;
    }

    // Ni sucursal ni código: la impresora es hardware que está en un sitio, y el código es el nombre con el que la
    // citan las áreas. Lo demás sí cambia — se quema y se sustituye por otra con distinta IP.
    await api.patch(`/printers/${editing.value.ulid}`, {
        name: form.value.name,
        connection: form.value.connection,
        target: form.value.target,
        paper_width: form.value.paper_width,
        supports_cash_drawer: form.value.supports_cash_drawer,
    });
});

const archive = useApiForm(async (printer) => {
    await api.post(`/printers/${printer.ulid}/archive`);
});

function startCreate() {
    editing.value = 'new';
    form.value = {
        branch_ulid: branches.value[0]?.ulid ?? '',
        code: '',
        name: '',
        connection: connections.value[0]?.value ?? 'network',
        target: '',
        paper_width: 80,
        supports_cash_drawer: false,
    };
}

function startEdit(printer) {
    editing.value = printer;
    form.value = {
        name: printer.name,
        connection: printer.connection,
        target: printer.target,
        paper_width: printer.paper_width,
        supports_cash_drawer: printer.supports_cash_drawer,
    };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

async function confirmArchive(printer) {
    const usos = (printer.assignments?.preparation_areas ?? 0) + (printer.assignments?.terminals ?? 0);

    // Se dice CUÁNTOS destinos dependen de ella antes de preguntar. «Esta imprime las comandas de tres áreas» cambia
    // la decisión, y saberlo después no sirve de nada.
    const aviso = usos > 0
        ? `\n\nAhora mismo la usan ${usos} destino(s): dejarán de imprimir hasta que les asignes otra.`
        : '';

    if (!window.confirm(`¿Dar de baja «${printer.name}»?${aviso}`)) {
        return;
    }

    if (await archive.submit(printer)) {
        await list.load();
    }
}

const columns = [
    { key: 'code', label: 'Código', width: '7rem' },
    { key: 'name', label: 'Impresora' },
    { key: 'branch', label: 'Sucursal' },
    { key: 'target', label: 'Destino' },
    { key: 'paper_width', label: 'Papel', width: '6rem' },
    { key: 'drawer', label: 'Cajón', width: '6rem' },
    { key: 'assignments', label: 'La usan', width: '7rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '9rem' },
];
</script>

<template>
    <Head title="Impresoras" />

    <ListHeader
        title="Impresoras"
        subtitle="Aquí se dice qué impresoras hay. A cuál sale cada cosa se decide en el área —para las comandas— y en la terminal —para los tickets—, porque el destino lo decide qué se imprime y no quién lo manda. El cajón de dinero se abre por la impresora de tickets, así que sólo puede abrirlo una que lleve ese conector."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar por nombre o destino…"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.connection" class="input input--select">
                <option value="">Todas las conexiones</option>
                <option v-for="c in connections" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>

            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todas</option>
                <option value="active">Activas</option>
                <option value="inactive">Dadas de baja</option>
            </select>
        </template>

        <template #view>
            <ViewToggle v-model="view" persist-key="comandia:view:printers" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'organization.printers.manage'" class="button" type="button" @click="startCreate">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Nueva impresora
            </button>
        </template>
    </ListHeader>

    <!-- El servidor solo encola: quien imprime es un AGENTE. Su alta y su token viven en su propia pantalla. -->
    <Link href="/admin/impresoras/agentes" class="ir-agentes">Agentes de impresión ›</Link>

    <p v-if="archive.generalError.value" class="alert">{{ archive.generalError.value }}</p>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay impresoras. Sin al menos una, las comandas no se pueden imprimir."
    >
        <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>

        <template #cell:target="{ row }">
            <span class="mono">{{ row.target }}</span>
            <span class="muted">{{ row.connection_label }}</span>
        </template>

        <template #cell:paper_width="{ row }">{{ row.paper_width }} mm</template>

        <template #cell:drawer="{ row }">
            <!--
                Se muestra `can_open_cash_drawer`, que el servidor resuelve: «tiene el conector Y está activa». Pintar
                sólo el conector diría que una impresora dada de baja puede abrir el cajón.
            -->
            <span v-if="row.can_open_cash_drawer" class="badge badge--ok">Sí</span>
            <span v-else-if="row.supports_cash_drawer" class="badge badge--off">De baja</span>
            <span v-else class="muted">No</span>
        </template>

        <template #cell:assignments="{ row }">
            <span v-if="(row.assignments?.preparation_areas ?? 0) + (row.assignments?.terminals ?? 0) === 0" class="muted">
                Nadie
            </span>
            <span v-else>
                {{ row.assignments.preparation_areas }} área(s) · {{ row.assignments.terminals }} caja(s)
            </span>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ row.status === 'active' ? 'Activa' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button v-can.write="'organization.printers.manage'" class="link-button" type="button" @click="startEdit(row)">
                    Editar
                </button>
                <button
                    v-if="row.status === 'active'"
                    v-can.write="'organization.printers.manage'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="confirmArchive(row)"
                >
                    Dar de baja
                </button>
            </div>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay impresoras. Sin al menos una, las comandas no se pueden imprimir."
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__code">{{ item.code }} · {{ item.branch?.name ?? '—' }}</span>
                <span class="card__title">{{ item.name }}</span>
                <span class="card__meta mono">{{ item.target }} · {{ item.connection_label }}</span>
                <span class="card__foot">
                    <span class="badge badge--off">{{ item.paper_width }} mm</span>
                    <span v-if="item.can_open_cash_drawer" class="badge badge--ok">Cajón</span>
                    <span class="badge" :class="item.status === 'active' ? 'badge--ok' : 'badge--off'">
                        {{ item.status === 'active' ? 'Activa' : 'Baja' }}
                    </span>
                </span>
                <span class="card__meta">
                    {{ (item.assignments?.preparation_areas ?? 0) + (item.assignments?.terminals ?? 0) === 0
                        ? 'No la usa nadie'
                        : `${item.assignments.preparation_areas} área(s) · ${item.assignments.terminals} caja(s)` }}
                </span>
                <div class="card__actions">
                    <button v-can.write="'organization.printers.manage'" class="link-button" type="button" @click="startEdit(item)">
                        Editar
                    </button>
                    <button
                        v-if="item.status === 'active'"
                        v-can.write="'organization.printers.manage'"
                        class="link-button link-button--danger"
                        type="button"
                        @click="confirmArchive(item)"
                    >
                        Dar de baja
                    </button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="impresoras" />

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <h2>{{ editing === 'new' ? 'Nueva impresora' : `Editar ${editing.name}` }}</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <template v-if="editing === 'new'">
                <label class="field">
                    <span class="field__label">Sucursal</span>
                    <select v-model="form.branch_ulid" class="input" required>
                        <option v-for="branch in branches" :key="branch.ulid" :value="branch.ulid">
                            {{ branch.name }}
                        </option>
                    </select>
                    <span class="field__hint">La impresora está físicamente en una sucursal y no se muda.</span>
                </label>

                <label class="field">
                    <span class="field__label">Código</span>
                    <input v-model="form.code" class="input" maxlength="20" required placeholder="COCINA" />
                    <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
                    <span class="field__hint">Es el nombre con el que la citan las áreas. No se puede cambiar después.</span>
                </label>
            </template>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="60" required placeholder="Impresora de cocina" />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <label class="field">
                <span class="field__label">Conexión</span>
                <select v-model="form.connection" class="input" required>
                    <option v-for="c in connections" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
            </label>

            <label class="field">
                <span class="field__label">Destino</span>
                <input v-model="form.target" class="input" maxlength="120" required />
                <span v-if="save.fieldErrors.value.target" class="field__error">{{ save.fieldErrors.value.target }}</span>
                <!-- La pista viene del servidor: es lo que evita capturar una IP donde va una ruta compartida. -->
                <span v-if="targetHint" class="field__hint">{{ targetHint }}</span>
            </label>

            <div class="fields">
                <label class="field">
                    <span class="field__label">Ancho de papel</span>
                    <select v-model.number="form.paper_width" class="input">
                        <option :value="80">80 mm</option>
                        <option :value="58">58 mm</option>
                    </select>
                    <span v-if="save.fieldErrors.value.paper_width" class="field__error">
                        {{ save.fieldErrors.value.paper_width }}
                    </span>
                </label>

                <label class="field checkbox-field">
                    <span class="field__label">Cajón de dinero</span>
                    <label class="checkbox">
                        <input v-model="form.supports_cash_drawer" type="checkbox" />
                        Tiene el conector del cajón
                    </label>
                    <span class="field__hint">Sólo las impresoras de tickets suelen tenerlo.</span>
                </label>
            </div>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.muted {
    display: block;
    color: #6b7280;
    font-size: 0.8rem;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.85rem;
}

.ir-agentes {
    display: inline-block;
    margin-top: 0.5rem;
    color: var(--color-accent, #7c2d12);
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
}

.ir-agentes:hover {
    text-decoration: underline;
}

.fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.checkbox {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.9rem;
}

.checkbox-field {
    align-self: start;
}
</style>
