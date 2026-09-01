<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Agentes de impresión (Iteración 4 · módulo de impresión).
 *
 * ## Qué es un agente
 *
 * El servidor NO habla con las impresoras: solo encola trabajos. Quien los recoge y los manda a la impresora es un
 * **agente** —un dispositivo con la app de Comandia— que autentica con un token propio, ligado a **una sucursal**. Esta
 * pantalla da de alta esos agentes y rota su token.
 *
 * ## El token se ve UNA vez
 *
 * Al alta y al rotar, y nunca más: la base guarda solo su hash (misma disciplina que un PIN). Por eso el token aparece
 * en un aviso aparte que hay que copiar en el momento; si se pierde, se rota. La lista jamás lo trae —publicarlo lo
 * dejaría en cualquier caché del navegador.
 */
const list = useResourceList('/print-agents', { initialFilters: { status: '', branch: '' } });

const filtrosActivos = computed(
    () => [list.filters.branch !== '', list.filters.status !== ''].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.branch = '';
    list.filters.status = '';
}

const branches = ref([]);

onMounted(async () => {
    await list.load();

    const sucursales = await api.get('/branches', { status: 'active', per_page: 100 });
    branches.value = sucursales.data;
});

// El token recién emitido: se muestra en un aviso y se olvida al cerrarlo.
const revealed = ref(null);
const copied = ref(false);

function reveal(res) {
    revealed.value = { name: res.data.name, token: res.data.token, notice: res.data.token_notice };
    copied.value = false;
}

async function copyToken() {
    try {
        await navigator.clipboard.writeText(revealed.value.token);
        copied.value = true;
    } catch {
        // Sin permiso de portapapeles: el token está a la vista para copiarlo a mano.
        copied.value = false;
    }
}

const creating = ref(false);
const form = ref({ branch_ulid: '', name: '' });

const save = useApiForm(async () => {
    reveal(await api.post('/print-agents', form.value));
});

const rotate = useApiForm(async (agent) => {
    reveal(await api.post(`/print-agents/${agent.ulid}/rotate-token`));
});

const archive = useApiForm(async (agent) => {
    await api.post(`/print-agents/${agent.ulid}/archive`);
});

function startCreate() {
    creating.value = true;
    form.value = { branch_ulid: branches.value[0]?.ulid ?? '', name: '' };
}

async function submitCreate() {
    if (await save.submit()) {
        creating.value = false;
        await list.load();
    }
}

async function confirmRotate(agent) {
    // Rotar corta el acceso del token viejo AL INSTANTE: el dispositivo que lo tenía deja de imprimir hasta que se le
    // captura el nuevo. Se dice antes de hacerlo para que nadie rote «la de cocina» y la deje muda a media comida.
    if (!window.confirm(`¿Rotar el token de «${agent.name}»?\n\nEl token actual dejará de servir de inmediato; hay que capturar el nuevo en ese dispositivo.`)) {
        return;
    }

    if (await rotate.submit(agent)) {
        await list.load();
    }
}

async function confirmArchive(agent) {
    if (!window.confirm(`¿Dar de baja «${agent.name}»?\n\nDejará de recibir trabajos. Podrás dar de alta otro cuando lo necesites.`)) {
        return;
    }

    if (await archive.submit(agent)) {
        await list.load();
    }
}

/** Fecha corta en horario local del navegador. */
function fecha(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

const columns = [
    { key: 'name', label: 'Agente' },
    { key: 'branch', label: 'Sucursal' },
    { key: 'activity', label: 'Actividad' },
    { key: 'created_at', label: 'Alta', width: '11rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '12rem' },
];
</script>

<template>
    <Head title="Agentes de impresión" />

    <Link href="/admin/impresoras" class="volver">‹ Impresoras</Link>

    <ListHeader
        title="Agentes de impresión"
        subtitle="Un agente es un dispositivo con la app de Comandia que recoge los trabajos y los manda a una impresora de red. Cada agente autentica con un token propio ligado a una sucursal. El token se ve una sola vez al crearlo o rotarlo: cópialo en el momento; si se pierde, se rota."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar por nombre…"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.branch" class="input input--select">
                <option value="">Todas las sucursales</option>
                <option v-for="b in branches" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
            </select>

            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todos</option>
                <option value="active">Activos</option>
                <option value="inactive">Dados de baja</option>
            </select>
        </template>

        <template #action>
            <button v-can.write="'organization.printers.manage'" class="button" type="button" @click="startCreate">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Nuevo agente
            </button>
        </template>
    </ListHeader>

    <p v-if="rotate.generalError.value" class="alert">{{ rotate.generalError.value }}</p>
    <p v-if="archive.generalError.value" class="alert">{{ archive.generalError.value }}</p>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay agentes. Sin uno, los trabajos quedan encolados pero nadie los imprime."
    >
        <template #cell:branch="{ row }">{{ row.branch?.name ?? '—' }}</template>

        <template #cell:activity="{ row }">
            <!--
                La pregunta de la cocina es «¿está vivo el agente?», no «¿falló un trabajo?». `is_alive` lo resuelve el
                servidor (visto hace menos de 2 min); sin esto, uno apagado y uno sin trabajos se ven igual.
            -->
            <span v-if="row.is_alive" class="badge badge--ok">En línea</span>
            <span v-else class="muted">{{ row.last_seen_at ? `Visto ${fecha(row.last_seen_at)}` : 'Nunca conectó' }}</span>
        </template>

        <template #cell:created_at="{ row }">
            <span class="muted">{{ fecha(row.created_at) }}</span>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ row.status === 'active' ? 'Activo' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div v-if="row.status === 'active'" class="row-actions">
                <button v-can.write="'organization.printers.manage'" class="link-button" type="button" @click="confirmRotate(row)"><Icon name="refresh" /> Rotar token</button>
                <button
                    v-can.write="'organization.printers.manage'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="confirmArchive(row)"
                ><Icon name="trash" /> Dar de baja</button>
            </div>
        </template>
    </DataTable>

    <!-- Alta -->
    <div v-if="creating" class="drawer-backdrop" @click.self="creating = false">
        <form class="drawer" @submit.prevent="submitCreate">
            <h2>Nuevo agente</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Sucursal</span>
                <select v-model="form.branch_ulid" class="input" required>
                    <option v-for="b in branches" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                </select>
                <span class="field__hint">El agente imprimirá solo lo de esta sucursal. No se cambia después: se da de baja y se crea otro.</span>
            </label>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="60" required placeholder="Tableta de la barra" />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
                <span class="field__hint">Queda escrito en cada trabajo: «Tableta de la barra» dice dónde buscar cuando algo no salió.</span>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="creating = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value"><Icon name="plus" /> Crear</button>
            </div>
        </form>
    </div>

    <!-- El token, una sola vez -->
    <div v-if="revealed" class="drawer-backdrop" @click.self="revealed = null">
        <div class="drawer">
            <h2>Token de «{{ revealed.name }}»</h2>
            <p class="token-notice">{{ revealed.notice }}</p>

            <div class="token-box">
                <code class="token">{{ revealed.token }}</code>
                <button class="button button--sm" type="button" @click="copyToken">
                    {{ copied ? 'Copiado' : 'Copiar' }}
                </button>
            </div>

            <p class="field__hint">
                Pégalo en la app: pestaña <strong>Impresión → Guardar token</strong>. Este aviso no vuelve a aparecer.
            </p>

            <div class="drawer__actions">
                <button type="button" class="button" @click="revealed = null"><Icon name="check" /> Listo</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.volver {
    display: inline-block;
    margin-bottom: 0.35rem;
    color: #6b7280;
    font-size: 0.85rem;
    text-decoration: none;
}

.volver:hover {
    color: var(--color-accent, #7c2d12);
}

.muted {
    display: block;
    color: #6b7280;
    font-size: 0.8rem;
}

.token-notice {
    margin: 0 0 0.75rem;
    color: #b45309;
    font-size: 0.9rem;
}

.token-box {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    background: #f3f4f6;
    border-radius: 0.5rem;
}

.token {
    flex: 1;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.9rem;
    word-break: break-all;
}

.button--sm {
    padding: 0.35rem 0.75rem;
    font-size: 0.85rem;
    white-space: nowrap;
}
</style>
