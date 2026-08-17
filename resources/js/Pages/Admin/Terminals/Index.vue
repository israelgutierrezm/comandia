<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';

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
const branches = ref([]);

onMounted(async () => {
    await list.load();
    branches.value = (await api.get('/branches', { status: 'active', per_page: 100 })).data;
});

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    if (editing.value === 'new') {
        await api.post('/terminals', form.value);
    } else {
        // Ni sucursal ni código: toda sesión de caja pertenece a una terminal concreta, y moverla
        // reatribuiría los cortes ya cerrados.
        await api.patch(`/terminals/${editing.value.ulid}`, { name: form.value.name });
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
    form.value = { name: terminal.name };
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
    { key: 'last_seen_at', label: 'Vista por última vez', width: '12rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '9rem' },
];
</script>

<template>
    <Head title="Terminales" />

    <header class="page-header">
        <div>
            <h1>Terminales</h1>
            <p class="page-header__hint">
                Dar de baja una terminal la deja fuera en su siguiente petición. El código no se puede
                cambiar: las sesiones de caja cerradas pertenecen a una terminal concreta.
            </p>
        </div>

        <button v-can.write="'organization.terminals.manage'" class="button" type="button" @click="startCreate">
            Nueva terminal
        </button>
    </header>

    <div class="toolbar">
        <input v-model="list.filters.search" type="search" class="input" placeholder="Buscar…" />

        <select v-model="list.filters.status" class="input input--select">
            <option value="">Todas</option>
            <option value="active">Activas</option>
            <option value="inactive">Dadas de baja</option>
        </select>
    </div>

    <p v-if="archive.generalError" class="alert">{{ archive.generalError }}</p>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay terminales."
    >
        <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>

        <template #cell:last_seen_at="{ row }">{{ formatSeen(row.last_seen_at) }}</template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ row.status === 'active' ? 'Activa' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button v-can.write="'organization.terminals.manage'" class="link-button" type="button" @click="startEdit(row)">
                    Editar
                </button>
                <button
                    v-if="row.status === 'active'"
                    v-can.write="'organization.terminals.manage'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="confirmArchive(row)"
                >
                    Dar de baja
                </button>
            </div>
        </template>
    </DataTable>

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <h2>{{ editing === 'new' ? 'Nueva terminal' : `Editar ${editing.name}` }}</h2>

            <p v-if="save.generalError" class="alert">{{ save.generalError }}</p>

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

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';
</style>
