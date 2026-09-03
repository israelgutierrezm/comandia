<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';
import FormHeader from '../../../../components/FormHeader.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import ArticlePicker from '../../../../components/catalog/ArticlePicker.vue';
import Icon from '../../../../components/Icon.vue';

/**
 * Órdenes de producción (§6.2, D192–D200).
 *
 * ## Producir es una salida y una entrada a la vez
 *
 * Una orden consume sus componentes según la receta y da de alta el producible. No es un ajuste ni un traspaso: es la
 * transformación que explica por qué bajaron tres insumos y subió una salsa.
 *
 * ## El borrador se planea y NO consume nada
 *
 * Mientras la orden es borrador puede verse qué consumiría con la receta de hoy, pero nada se mueve. El consumo ocurre
 * al **completar**, con la receta congelada en ese instante (D196): si la receta cambia mañana, la orden de hoy sigue
 * explicando lo que de verdad se consumió.
 */
const list = useResourceList('/production-orders', { initialFilters: { status: '', only_planned: 1 } });

// «Sólo lo que falta» viene activada por defecto: cuenta como filtro cuando se APAGA (deja ver todo).
const filtrosActivos = computed(
    () => [list.filters.status !== '', Number(list.filters.only_planned) !== 1].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.status = '';
    list.filters.only_planned = 1;
}

const warehouses = ref([]);
const planning = ref(false);
const form = ref({ warehouse_ulid: '', article: null, planned_quantity: '', notes: '' });
const rechazado = ref(null);

onMounted(async () => {
    warehouses.value = (await api.get('/warehouses', { status: 'active', per_page: 100 })).data
        .filter((w) => w.kind !== 'transit');

    await list.load();
});

const save = useApiForm(async () => {
    const created = await api.post('/production-orders', {
        warehouse_ulid: form.value.warehouse_ulid,
        article_ulid: form.value.article.ulid,
        planned_quantity: form.value.planned_quantity,
        notes: form.value.notes || null,
    });

    return created.data;
});

async function submit() {
    const created = await save.submit();

    if (created?.ulid) {
        router.visit(`/admin/produccion/${created.ulid}`);
    }
}

function startPlan() {
    form.value = { warehouse_ulid: warehouses.value[0]?.ulid ?? '', article: null, planned_quantity: '', notes: '' };
    rechazado.value = null;
    planning.value = true;
}

/**
 * Sólo se produce lo PRODUCIBLE, y se dice al elegir.
 *
 * El buscador es genérico; la capacidad viene con el artículo. Dejarlo pasar hasta el 422 haría que el error apareciera
 * después de teclear la cantidad, cuando la elección equivocada ya está hecha.
 */
function pickArticle(article) {
    if (article.capabilities?.producible === false) {
        rechazado.value = `${article.name} no es un artículo producible: no tiene receta que decir qué consumir para hacerlo.`;
        form.value.article = null;

        return;
    }

    rechazado.value = null;
    form.value.article = article;
}

const BADGES = { draft: 'warn', completed: 'ok', cancelled: 'off' };

const columns = [
    { key: 'article', label: 'Producible' },
    { key: 'warehouse', label: 'Almacén' },
    { key: 'quantities', label: 'Planeado / producido', width: '12rem' },
    { key: 'status', label: 'Estado', width: '9rem' },
    { key: 'cost', label: 'Valor', width: '9rem', align: 'right' },
];

function cantidad(valor) {
    return valor === null || valor === undefined
        ? '—'
        : Number(valor).toLocaleString('es-MX', { maximumFractionDigits: 4 });
}

function dinero(valor) {
    return valor === null || valor === undefined
        ? '—'
        : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(valor));
}
</script>

<template>
    <Head title="Producción" />

    <ListHeader
        title="Producción"
        subtitle="Producir consume y da de alta a la vez: bajan los insumos según la receta y sube el producible. Un borrador no mueve nada — el consumo ocurre al completar, con la receta congelada en ese momento."
        :count="list.meta.value?.total ?? null"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todos los estados</option>
                <option value="draft">Borradores</option>
                <option value="completed">Completadas</option>
                <option value="cancelled">Canceladas</option>
            </select>

            <label class="checkbox">
                <input v-model="list.filters.only_planned" type="checkbox" :true-value="1" :false-value="0" />
                Sólo lo que falta producir
            </label>
        </template>

        <template #action>
            <button v-can.write="'inventory.production.create'" class="button" type="button" @click="startPlan">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Planear producción
            </button>
        </template>
    </ListHeader>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay órdenes de producción que coincidan."
    >
        <template #cell:article="{ row }">
            <a :href="`/admin/produccion/${row.ulid}`" class="link">{{ row.article?.name ?? '—' }}</a>
            <span class="muted">{{ row.article?.base_unit_code }}</span>
        </template>

        <template #cell:warehouse="{ row }">{{ row.warehouse?.name ?? '—' }}</template>

        <template #cell:quantities="{ row }">
            {{ cantidad(row.planned_quantity) }}
            <span class="muted">/</span>
            <!-- `null` en lo producido es «todavía no se produjo», y por eso no se pinta un cero. -->
            <strong>{{ cantidad(row.produced_quantity) }}</strong>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="`badge--${BADGES[row.status] ?? 'off'}`">{{ row.status_label }}</span>
        </template>

        <template #cell:cost="{ row }">{{ dinero(row.total_cost) }}</template>
    </DataTable>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="órdenes" />

    <div v-if="planning" class="drawer-backdrop" @click.self="planning = false">
        <form class="drawer" @submit.prevent="submit">
            <FormHeader title="Planear producción" />

            <p class="drawer__hint">
                Planear no consume nada: al guardar verás qué consumiría con la receta de hoy, y podrás completarla
                cuando de verdad se produzca. El inventario se mueve entonces, no ahora.
            </p>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Almacén</span>
                <select v-model="form.warehouse_ulid" class="input" required>
                    <option v-for="w in warehouses" :key="w.ulid" :value="w.ulid">{{ w.name }} ({{ w.code }})</option>
                </select>
                <span class="field__hint">
                    De aquí salen los insumos y aquí entra el producible: la producción no cruza almacenes, y mover
                    después es una transferencia.
                </span>
            </label>

            <div class="field">
                <span class="field__label">Qué se produce</span>
                <ArticlePicker placeholder="Buscar artículo producible…" @picked="pickArticle" />
                <span v-if="rechazado" class="field__error">{{ rechazado }}</span>
                <p v-if="form.article" class="picked">
                    {{ form.article.name }}
                    <span class="muted">se mide en {{ form.article.base_unit?.code }}</span>
                </p>
                <span v-if="save.fieldErrors.value.article_ulid" class="field__error">
                    {{ save.fieldErrors.value.article_ulid }}
                </span>
            </div>

            <label class="field">
                <span class="field__label">
                    Cantidad a producir
                    <span v-if="form.article" class="muted">en {{ form.article.base_unit?.code }}</span>
                </span>
                <input v-model="form.planned_quantity" class="input" inputmode="decimal" required />
                <span v-if="save.fieldErrors.value.planned_quantity" class="field__error">
                    {{ save.fieldErrors.value.planned_quantity }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Notas</span>
                <textarea v-model="form.notes" class="input" rows="2" maxlength="300"></textarea>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="planning = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value || form.article === null"><Icon name="plus" /> Planear</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.muted {
    color: var(--color-suave);
    font-size: 0.85rem;
}

.link {
    color: #1d4ed8;
    text-decoration: none;
}

.link:hover {
    text-decoration: underline;
}

.drawer__hint {
    margin: 0 0 0.9rem;
    color: var(--color-suave);
    font-size: 0.85rem;
}

.checkbox {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.9rem;
}

.picked {
    margin: 0.4rem 0 0;
    font-size: 0.9rem;
}
</style>
