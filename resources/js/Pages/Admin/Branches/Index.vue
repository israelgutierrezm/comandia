<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import DataTable from '../../../components/DataTable.vue';

/**
 * Sucursales: la pantalla completa, y el patrón que siguen las demás.
 *
 * Todos los datos vienen de `/api/v1/branches` (D59). Los filtros que se mandan son exactamente los
 * que la whitelist del endpoint declara: mandar uno que no reconoce devuelve 422, y eso es lo
 * correcto —un filtro ignorado daría la lista completa a quien cree verla filtrada—.
 */
const { canWrite } = useAuthorization();

const list = useResourceList('/branches', { initialFilters: { status: '' } });

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

    <header class="page-header">
        <div>
            <h1>Sucursales</h1>
            <p class="page-header__hint">
                El código entra en los folios de los documentos, así que no se puede cambiar después.
            </p>
        </div>

        <!-- `.write` además del permiso: un tenant en sólo lectura no crea nada. -->
        <button v-can.write="'organization.branches.manage'" class="button" type="button" @click="startCreate">
            Nueva sucursal
        </button>
    </header>

    <div class="toolbar">
        <input
            v-model="list.filters.search"
            type="search"
            class="input"
            placeholder="Buscar por nombre, código o municipio…"
        />

        <select v-model="list.filters.status" class="input input--select">
            <option value="">Todas</option>
            <option value="active">Activas</option>
            <option value="inactive">Dadas de baja</option>
        </select>
    </div>

    <p v-if="archive.generalError.value" class="alert">{{ archive.generalError.value }}</p>

    <DataTable
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
                    class="link-button"
                    type="button"
                    @click="startEdit(row)"
                >
                    Editar
                </button>

                <button
                    v-if="row.status === 'active'"
                    v-can.write="'organization.branches.manage'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="confirmArchive(row)"
                >
                    Dar de baja
                </button>
            </div>
        </template>
    </DataTable>

    <nav v-if="list.meta.value.last_page > 1" class="pagination">
        <button
            class="link-button"
            type="button"
            :disabled="list.filters.page <= 1"
            @click="list.filters.page--"
        >
            Anterior
        </button>

        <span class="pagination__info">
            Página {{ list.meta.value.current_page }} de {{ list.meta.value.last_page }}
        </span>

        <button
            class="link-button"
            type="button"
            :disabled="list.filters.page >= list.meta.value.last_page"
            @click="list.filters.page++"
        >
            Siguiente
        </button>
    </nav>

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
                <input v-model="form.timezone" class="input" required />
                <span class="field__hint">
                    Determina qué es "el día" en los cortes de esta sucursal.
                </span>
                <span v-if="save.fieldErrors.value.timezone" class="field__error">
                    {{ save.fieldErrors.value.timezone }}
                </span>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null">Cancelar</button>
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
