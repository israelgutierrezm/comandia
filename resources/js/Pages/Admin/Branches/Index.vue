<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import DataTable from '../../../components/DataTable.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import Paginacion from '../../../components/Paginacion.vue';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

const view = ref('list');

/**
 * Sucursales: la pantalla completa, y el patrón que siguen las demás.
 *
 * Todos los datos vienen de `/api/v1/branches` (D59). Los filtros que se mandan son exactamente los
 * que la whitelist del endpoint declara: mandar uno que no reconoce devuelve 422, y eso es lo
 * correcto —un filtro ignorado daría la lista completa a quien cree verla filtrada—.
 */
const { canWrite } = useAuthorization();

const list = useResourceList('/branches', { initialFilters: { status: '' } });

const filtrosActivos = computed(() => (list.filters.status !== '' ? 1 : 0));
function limpiarFiltros() {
    list.filters.status = '';
}

/**
 * Catálogo de zonas horarias: TODAS las que reconoce el navegador (IANA, vía `Intl.supportedValuesOf`), para no mantener
 * una lista a mano que se desactualiza. En un navegador viejo que no lo soporte, un puñado de zonas de México como
 * respaldo — nunca un campo vacío.
 */
const timezones = typeof Intl.supportedValuesOf === 'function'
    ? Intl.supportedValuesOf('timeZone')
    : ['America/Mexico_City', 'America/Cancun', 'America/Merida', 'America/Monterrey', 'America/Matamoros',
        'America/Mazatlan', 'America/Chihuahua', 'America/Ojinaga', 'America/Hermosillo', 'America/Tijuana',
        'America/Bahia_Banderas'];

onMounted(list.load);

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    const payload = {
        name: form.value.name,
        timezone: form.value.timezone,
    };

    if (editing.value === 'new') {
        await api.post('/branches', { ...payload, code: form.value.code });
    } else {
        // El código NO se envía al editar: entra en los folios ya emitidos y el servidor lo rechaza
        // explícitamente. La UI ni lo ofrece, para no invitar a intentarlo.
        await api.patch(`/branches/${editing.value.ulid}`, payload);
    }
});

const archive = useApiForm(async (branch) => {
    await api.post(`/branches/${branch.ulid}/archive`);
});

function startCreate() {
    editing.value = 'new';
    form.value = { code: '', name: '', timezone: 'America/Mexico_City' };
}

function startEdit(branch) {
    editing.value = branch;
    form.value = { name: branch.name, timezone: branch.timezone };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

async function confirmArchive(branch) {
    // Confirmación explícita: es una baja, y aunque no borre nada —cambia el estado (D80)— deja de
    // poder operarse ahí.
    if (!window.confirm(`¿Dar de baja la sucursal «${branch.name}»? Dejará de poder operar.`)) {
        return;
    }

    if (await archive.submit(branch)) {
        await list.load();
    }
}

const columns = [
    { key: 'code', label: 'Código', width: '7rem' },
    { key: 'name', label: 'Nombre' },
    { key: 'timezone', label: 'Zona horaria' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '9rem' },
];
</script>

<template>
    <Head title="Sucursales" />

    <ListHeader
        title="Sucursales"
        subtitle="El código entra en los folios de los documentos, así que no se puede cambiar después."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar por nombre, código o municipio…"
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
            <ViewToggle v-model="view" persist-key="comandia:view:branches" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'organization.branches.manage'" class="button" type="button" @click="startCreate">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Nueva sucursal
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
        empty-message="Todavía no hay sucursales que coincidan."
    >
        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ row.status === 'active' ? 'Activa' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button
                    v-can.write="'organization.branches.manage'"
                    class="link-button link-button--warning"
                    type="button"
                    @click="startEdit(row)"
                ><Icon name="edit" /> Editar</button>

                <button
                    v-if="row.status === 'active'"
                    v-can.write="'organization.branches.manage'"
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
        empty-message="Todavía no hay sucursales que coincidan."
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__code">{{ item.code }}</span>
                <span class="card__title">{{ item.name }}</span>
                <span class="card__meta">{{ item.timezone }}</span>
                <span class="card__foot">
                    <span class="badge" :class="item.status === 'active' ? 'badge--ok' : 'badge--off'">
                        {{ item.status === 'active' ? 'Activa' : 'Baja' }}
                    </span>
                </span>
                <div class="card__actions">
                    <button v-can.write="'organization.branches.manage'" class="link-button link-button--warning" type="button" @click="startEdit(item)"><Icon name="edit" /> Editar</button>
                    <button
                        v-if="item.status === 'active'"
                        v-can.write="'organization.branches.manage'"
                        class="link-button link-button--danger"
                        type="button"
                        @click="confirmArchive(item)"
                    ><Icon name="trash" /> Dar de baja</button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="sucursales" />

    <!-- Formulario en panel lateral: mantiene la lista visible, que es el contexto de la edición. -->
    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <h2>{{ editing === 'new' ? 'Nueva sucursal' : `Editar ${editing.name}` }}</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label v-if="editing === 'new'" class="field">
                <span class="field__label">Código</span>
                <input v-model="form.code" class="input" maxlength="10" required />
                <span class="field__hint">Entra en los folios. No se podrá cambiar.</span>
                <span v-if="save.fieldErrors.value.code" class="field__error">
                    {{ save.fieldErrors.value.code }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="120" required />
                <span v-if="save.fieldErrors.value.name" class="field__error">
                    {{ save.fieldErrors.value.name }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Zona horaria</span>
                <select v-model="form.timezone" class="input input--select" required>
                    <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                </select>
                <span class="field__hint">
                    Determina qué es "el día" en los cortes de esta sucursal.
                </span>
                <span v-if="save.fieldErrors.value.timezone" class="field__error">
                    {{ save.fieldErrors.value.timezone }}
                </span>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">
                    {{ save.processing.value ? 'Guardando…' : 'Guardar' }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';
</style>
