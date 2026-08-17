<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import DataTable from '../../../components/DataTable.vue';

/**
 * Bitácora de auditoría (§6.7).
 *
 * Los tres filtros que existen son los tres que §9 necesita para detectar robo hormiga: por acción,
 * por persona y por rango de fechas. No hay buscador libre porque no hay uno en el servidor: un
 * `LIKE` sobre una tabla de este volumen sería un recorrido completo.
 *
 * Paginación por **cursor**: la tabla puede tener millones de filas y no hay "página 500" a la que
 * saltar. La UI ofrece "más antiguas" en lugar de números de página, que es cómo se audita de verdad.
 */
const entries = ref([]);
const nextCursor = ref(null);
const loading = ref(false);
const error = ref(null);

const filters = ref({ action: '', occurred_from: '', occurred_to: '' });

async function load({ append = false } = {}) {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.get('/audit-entries', {
            ...filters.value,
            cursor: append ? nextCursor.value : undefined,
            per_page: 50,
        });

        entries.value = append ? [...entries.value, ...response.data] : response.data;
        nextCursor.value = response.meta?.next_cursor ?? null;
    } catch (e) {
        if (!(e instanceof ApiError)) throw e;
        error.value = e;

        if (!append) entries.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

function applyFilters() {
    nextCursor.value = null;
    load();
}

const columns = [
    { key: 'occurred_at', label: 'Cuándo', width: '11rem' },
    { key: 'action', label: 'Acción' },
    { key: 'actor', label: 'Quién lo hizo' },
    { key: 'authorized_by', label: 'Quién lo autorizó' },
    { key: 'auditable', label: 'Sobre qué' },
];

function formatDate(iso) {
    if (!iso) return '—';

    // Se presenta con la zona horaria del navegador. En la base todo está en UTC (§7); las vistas
    // "del día" de los cortes usarán la zona de la sucursal, no ésta.
    return new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'medium' });
}
</script>

<template>
    <Head title="Auditoría" />

    <header class="page-header">
        <div>
            <h1>Auditoría</h1>
            <p class="page-header__hint">
                Registro inmutable de accesos, cambios de configuración y acciones sensibles. La
                columna <strong>quién lo autorizó</strong> es la que distingue "lo hizo el gerente"
                de "el gerente autorizó que lo hiciera otra persona".
            </p>
        </div>
    </header>

    <div class="toolbar">
        <input
            v-model="filters.action"
            class="input"
            placeholder="Acción exacta, p. ej. auth.pin_authorization_granted"
            @change="applyFilters"
        />

        <label class="date-field">
            <span>Desde</span>
            <input v-model="filters.occurred_from" type="date" class="input" @change="applyFilters" />
        </label>

        <label class="date-field">
            <span>Hasta</span>
            <input v-model="filters.occurred_to" type="date" class="input" @change="applyFilters" />
        </label>
    </div>

    <DataTable
        :columns="columns"
        :rows="entries"
        :loading="loading && entries.length === 0"
        :error="error"
        empty-message="No hay registros con esos filtros."
    >
        <template #cell:occurred_at="{ row }">{{ formatDate(row.occurred_at) }}</template>

        <template #cell:action="{ row }">
            <!-- El texto para leer, y debajo el identificador: es el valor exacto que se escribe en
                 el filtro de arriba, así que sigue haciendo falta a la vista. -->
            <span>{{ row.action_label ?? row.action }}</span>
            <code class="action">{{ row.action }}</code>
        </template>

        <template #cell:actor="{ row }">
            <!-- Sin actor significa que lo hizo el sistema. No se inventa un nombre: sería
                 indistinguible de una persona real llamada así. -->
            <template v-if="row.actor">
                {{ row.actor.name }}
                <span v-if="row.actor.employee_code" class="muted">({{ row.actor.employee_code }})</span>
            </template>
            <span v-else class="muted">Sistema</span>
        </template>

        <template #cell:authorized_by="{ row }">
            <span v-if="row.was_authorized_by_another" class="badge badge--warn">
                {{ row.authorized_by.name }}
            </span>
            <span v-else class="muted">—</span>
        </template>

        <!-- Tipo de entidad, sin identificador: el ID de la bitácora es la PK interna y no se
             expone. Identificar la entidad concreta exige guardar su ULID en el asiento — decisión
             pendiente, ver el comentario de AuditEntryResource. -->
        <template #cell:auditable="{ row }">
            <template v-if="row.auditable">{{ row.auditable.type }}</template>
            <span v-else class="muted">—</span>
        </template>
    </DataTable>

    <div v-if="nextCursor" class="pagination">
        <button class="link-button" type="button" :disabled="loading" @click="load({ append: true })">
            {{ loading ? 'Cargando…' : 'Ver registros más antiguos' }}
        </button>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.action {
    display: block;
    font-size: 0.72rem;
    color: #a8a29e;
}

.muted {
    color: #a8a29e;
    font-size: 0.85rem;
}

.date-field {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8rem;
    color: #78716c;
}
</style>
