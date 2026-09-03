<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import FormHeader from '../../../components/FormHeader.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import Paginacion from '../../../components/Paginacion.vue';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

const view = ref('list');

/**
 * Terminales de punto de venta.
 *
 * `Vista por última vez` es lo primero que se pregunta cuando una sucursal reporta un problema: el POS
 * se detiene sin internet —riesgo aceptado (§6.9)— y ese dato distingue "se cayó la red" de "está
 * apagada".
 *
 * Dar de baja una terminal surte efecto en la petición siguiente del POS: el contexto valida la
 * cabecera `X-Terminal` contra las terminales activas.
 */
const list = useResourceList('/terminals', { initialFilters: { status: '' }, });

const filtrosActivos = computed(() => (list.filters.status !== '' ? 1 : 0));
function limpiarFiltros() {
    list.filters.status = '';
}
const branches = ref([]);
const printers = ref([]);

onMounted(async () => {
    await list.load();

    const [sucursales, impresoras] = await Promise.all([
        api.get('/branches', { status: 'active', per_page: 100 }),
        api.get('/printers', { status: 'active', per_page: 100 }),
    ]);

    branches.value = sucursales.data;
    printers.value = impresoras.data;
});

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    if (editing.value === 'new') {
        await api.post('/terminals', form.value);
    } else {
        // Ni sucursal ni código: toda sesión de caja pertenece a una terminal concreta, y moverla
        // reatribuiría los cortes ya cerrados.
        await api.patch(`/terminals/${editing.value.ulid}`, {
            name: form.value.name,

            // Cadena vacía significa «sin impresora», y se manda como `null` porque es lo que el servidor entiende por
            // desasignar. Mandar `''` haría que la regla de existencia lo rechazara como un ULID inválido.
            printer_ulid: form.value.printer_ulid === '' ? null : form.value.printer_ulid,
        });
    }
});

const archive = useApiForm(async (terminal) => {
    await api.post(`/terminals/${terminal.ulid}/archive`);
});

function startCreate() {
    editing.value = 'new';
    form.value = { branch_ulid: branches.value[0]?.ulid ?? '', code: '', name: '' };
}

function startEdit(terminal) {
    editing.value = terminal;
    form.value = { name: terminal.name, printer_ulid: terminal.printer?.ulid ?? '' };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

async function confirmArchive(terminal) {
    if (!window.confirm(`¿Dar de baja «${terminal.name}»? Dejará de poder cobrar de inmediato.`)) {
        return;
    }

    if (await archive.submit(terminal)) {
        await list.load();
    }
}

function formatSeen(iso) {
    if (!iso) return 'Nunca';

    return new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
}

const columns = [
    { key: 'code', label: 'Código', width: '7rem' },
    { key: 'name', label: 'Terminal' },
    { key: 'branch', label: 'Sucursal' },
    { key: 'printer', label: 'Impresora', width: '10rem' },
    { key: 'last_seen_at', label: 'Vista por última vez', width: '12rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '9rem' },
];
</script>

<template>
    <Head title="Terminales" />

    <ListHeader
        title="Terminales"
        subtitle="Dar de baja una terminal la deja fuera en su siguiente petición. El código no se puede cambiar: las sesiones de caja cerradas pertenecen a una terminal concreta."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todas</option>
                <option value="active">Activas</option>
                <option value="inactive">Dadas de baja</option>
            </select>
        </template>

        <template #view>
            <ViewToggle v-model="view" persist-key="comandia:view:terminals" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'organization.terminals.manage'" class="button" type="button" @click="startCreate">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Nueva terminal
            </button>
        </template>
    </ListHeader>

    <p v-if="archive.generalError.value" class="alert">{{ archive.generalError.value }}</p>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay terminales."
    >
        <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>

        <template #cell:printer="{ row }">
            <!-- «Sin asignar» con palabras y no un guion: una caja sin impresora cobra igual, pero no da ticket. -->
            <span v-if="row.printer">{{ row.printer.name }}</span>
            <span v-else class="muted-cell">Sin asignar</span>
        </template>

        <template #cell:last_seen_at="{ row }">{{ formatSeen(row.last_seen_at) }}</template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ row.status === 'active' ? 'Activa' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button v-can.write="'organization.terminals.manage'" class="link-button link-button--warning" type="button" @click="startEdit(row)"><Icon name="edit" /> Editar</button>
                <button
                    v-if="row.status === 'active'"
                    v-can.write="'organization.terminals.manage'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="confirmArchive(row)"
                ><Icon name="trash" /> Dar de baja</button>
            </div>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay terminales."
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__code">{{ item.code }}</span>
                <span class="card__title">{{ item.name }}</span>
                <span class="card__meta">{{ item.branch?.name ?? '—' }} · {{ item.printer ? item.printer.name : 'Sin impresora' }}</span>
                <span class="card__foot">
                    <span class="badge" :class="item.status === 'active' ? 'badge--ok' : 'badge--off'">
                        {{ item.status === 'active' ? 'Activa' : 'Baja' }}
                    </span>
                    <span class="card__meta">Vista: {{ formatSeen(item.last_seen_at) }}</span>
                </span>
                <div class="card__actions">
                    <button v-can.write="'organization.terminals.manage'" class="link-button link-button--warning" type="button" @click="startEdit(item)"><Icon name="edit" /> Editar</button>
                    <button
                        v-if="item.status === 'active'"
                        v-can.write="'organization.terminals.manage'"
                        class="link-button link-button--danger"
                        type="button"
                        @click="confirmArchive(item)"
                    ><Icon name="trash" /> Dar de baja</button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="terminales" />

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <FormHeader :title="editing === 'new' ? 'Nueva terminal' : `Editar ${editing.name}`" />

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <template v-if="editing === 'new'">
                <label class="field">
                    <span class="field__label">Sucursal</span>
                    <select v-model="form.branch_ulid" class="input" required>
                        <option v-for="branch in branches" :key="branch.ulid" :value="branch.ulid">
                            {{ branch.name }}
                        </option>
                    </select>
                </label>

                <label class="field">
                    <span class="field__label">Código</span>
                    <input v-model="form.code" class="input" maxlength="20" required />
                    <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
                </label>
            </template>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="80" required placeholder="Caja 1" />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <label v-if="editing !== 'new'" class="field">
                <span class="field__label">Impresora de tickets</span>
                <select v-model="form.printer_ulid" class="input">
                    <option value="">Sin impresora</option>
                    <option v-for="p in printers" :key="p.ulid" :value="p.ulid">
                        {{ p.name }} ({{ p.code }})
                    </option>
                </select>
                <span class="field__hint">
                    Por aquí salen el ticket de cierre, el ticket final y la apertura del cajón de dinero.
                </span>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value"><Icon name="check" /> Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.muted-cell {
    color: var(--color-suave);
    font-size: 0.85rem;
}
</style>
