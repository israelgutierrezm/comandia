<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';
import ResourceGrid from '../../../../components/ResourceGrid.vue';
import ViewToggle from '../../../../components/ViewToggle.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import ArticleForm from '../../../../components/catalog/ArticleForm.vue';

// Lista o cuadrícula. La cuadrícula luce las fotos de publicación del artículo; se recuerda por navegador.
const view = ref('list');

/**
 * Listado de artículos (D17).
 *
 * ## El listado no muestra costos, y no es un olvido
 *
 * `ArticleResource` no trae costo ni precio sugerido: el costo es del módulo `Costing` y `Catalog` no
 * puede depender de él (P1). Traerlos aquí serían N+1 llamadas para pintar una tabla — una por
 * artículo—, así que el costo se ve en la ficha, donde se pide una vez y con su desglose.
 *
 * El precio SÍ está: es dato maestro del catálogo.
 *
 * ## El filtro por sucursal cambia lo que significa la columna de precio
 *
 * Sin sucursal, la tabla muestra el dato maestro del negocio, que es lo que se administra. Con
 * sucursal, muestra el precio EFECTIVO allí y marca cuáles están sobrescritos: es la vista que
 * contesta «¿qué va a cobrar Polanco?». Que las dos vistas se distingan importa, porque «hereda $85»
 * y «Polanco decidió $85» se ven igual hasta el día que cambie el precio del negocio.
 */
const list = useResourceList('/articles', {
    initialFilters: { status: 'active', capability: '', category: '', branch: '' },
});

const categories = ref([]);
const branches = ref([]);
const units = ref([]);
const tags = ref([]);

onMounted(async () => {
    await list.load();

    // Los datos de referencia para filtros y formulario, en paralelo: son cuatro consultas
    // independientes y encadenarlas multiplicaría por cuatro la espera de la primera pintada.
    //
    // `catch` por separado en cada una: quien puede ver artículos puede leerlas todas (D99), pero si
    // una fallara, la pantalla sigue sirviendo con ese selector vacío en lugar de no cargar.
    const [cats, brs, uns, tgs] = await Promise.all([
        api.get('/article-categories').catch(() => ({ data: [] })),
        api.get('/branches', { status: 'active', per_page: 100 }).catch(() => ({ data: [] })),
        api.get('/units', { status: 'active', per_page: 100 }).catch(() => ({ data: [] })),
        api.get('/tags').catch(() => ({ data: [] })),
    ]);

    categories.value = cats.data ?? [];
    branches.value = brs.data ?? [];
    units.value = uns.data ?? [];
    tags.value = tgs.data ?? [];
});

/** Categorías aplanadas con sangría, para que un `<select>` muestre la jerarquía de dos niveles. */
const categoryOptions = computed(() =>
    categories.value.flatMap((root) => [
        { ulid: root.ulid, label: root.name },
        ...(root.children ?? []).map((child) => ({ ulid: child.ulid, label: `   ${child.name}` })),
    ]),
);

const viewingBranch = computed(() =>
    branches.value.find((branch) => branch.ulid === list.filters.branch) ?? null,
);

const editing = ref(null);

const archive = useApiForm(async (article) => {
    await api.post(`/articles/${article.ulid}/archive`);
});

async function confirmArchive(article) {
    if (!window.confirm(`¿Archivar «${article.name}»? Dejará de aparecer en el punto de venta.`)) {
        return;
    }

    if (await archive.submit(article)) {
        await list.load();
    }
}

function openArticle(article) {
    router.visit(`/admin/articulos/${article.ulid}`);
}

async function afterSave() {
    editing.value = null;
    await list.load();
}

/** Las capacidades como iniciales: cuatro columnas de sí/no serían ilegibles en una tabla. */
function capabilityBadges(article) {
    const caps = article.capabilities ?? {};

    return [
        { on: caps.sellable, letter: 'V', title: 'Vendible' },
        { on: caps.inventoriable, letter: 'I', title: 'Inventariable' },
        { on: caps.supply, letter: 'S', title: 'Insumo' },
        { on: caps.producible, letter: 'P', title: 'Producible' },
    ];
}

const columns = computed(() => [
    { key: 'code', label: 'Código', width: '8rem' },
    { key: 'name', label: 'Artículo' },
    { key: 'capabilities', label: 'Capacidades', width: '8rem' },
    { key: 'category', label: 'Categoría' },
    { key: 'price', label: viewingBranch.value ? 'Precio efectivo' : 'Precio', width: '9rem' },
    { key: 'pos', label: 'En el POS', width: '7rem' },
    { key: 'actions', label: '', width: '10rem' },
]);
</script>

<template>
    <Head title="Artículos" />

    <header class="page-header">
        <div>
            <h1>Artículos</h1>
            <p class="page-header__hint">
                Un solo catálogo con <strong>capacidades</strong>: lo que se vende, lo que se
                inventaría, lo que se consume y lo que se produce pueden ser el mismo artículo. Una
                cerveza es vendible e inventariable; un jitomate es insumo; una salsa es producible e
                insumo a la vez.
            </p>
        </div>

        <button v-can.write="'catalog.articles.manage'" class="button" type="button" @click="editing = 'new'">
            Nuevo artículo
        </button>
    </header>

    <div class="toolbar">
        <input v-model="list.filters.search" type="search" class="input" placeholder="Buscar por nombre o código…" />

        <select v-model="list.filters.capability" class="input input--select">
            <option value="">Todas las capacidades</option>
            <option value="sellable">Vendibles</option>
            <option value="inventoriable">Inventariables</option>
            <option value="supply">Insumos</option>
            <option value="producible">Producibles</option>
        </select>

        <select v-model="list.filters.category" class="input input--select">
            <option value="">Todas las categorías</option>
            <option v-for="option in categoryOptions" :key="option.ulid" :value="option.ulid">
                {{ option.label }}
            </option>
        </select>

        <select v-model="list.filters.status" class="input input--select">
            <option value="active">Activos</option>
            <option value="archived">Archivados</option>
            <option value="">Todos</option>
        </select>

        <select v-if="branches.length > 1" v-model="list.filters.branch" class="input input--select">
            <option value="">Precio del negocio</option>
            <option v-for="branch in branches" :key="branch.ulid" :value="branch.ulid">
                Ver como {{ branch.name }}
            </option>
        </select>

        <ViewToggle v-model="view" persist-key="comandia:view:articles" class="toolbar__view" />
    </div>

    <p v-if="viewingBranch" class="notice">
        Viendo el precio y la disponibilidad efectivos en <strong>{{ viewingBranch.name }}</strong>. La
        marca <span class="badge badge--warn">propio</span> señala lo que esa sucursal decidió; el
        resto hereda del negocio y seguirá al precio maestro cuando cambie.
    </p>

    <p v-if="archive.generalError.value" class="alert">{{ archive.generalError.value }}</p>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay artículos que coincidan."
    >
        <template #cell:name="{ row }">
            <button class="row-link" type="button" @click="openArticle(row)">{{ row.name }}</button>
            <span v-if="row.short_name" class="row-sub">{{ row.short_name }}</span>
        </template>

        <template #cell:capabilities="{ row }">
            <span class="caps">
                <span
                    v-for="cap in capabilityBadges(row)"
                    :key="cap.letter"
                    class="cap"
                    :class="{ 'cap--on': cap.on }"
                    :title="cap.title"
                >
                    {{ cap.letter }}
                </span>
            </span>
        </template>

        <template #cell:category="{ row }">{{ row.category?.name ?? '—' }}</template>

        <template #cell:price="{ row }">
            <template v-if="row.capabilities?.sellable">
                <span class="money">${{ viewingBranch ? row.effective_price : row.base_price }}</span>
                <span v-if="viewingBranch && row.effective_price_is_overridden" class="badge badge--warn">propio</span>
            </template>
            <!-- Un insumo no tiene precio de venta, y «—» dice eso mejor que «$0.00». -->
            <span v-else class="muted">No se vende</span>
        </template>

        <template #cell:pos="{ row }">
            <template v-if="row.capabilities?.sellable">
                <span
                    class="badge"
                    :class="
                        (viewingBranch ? row.effective_is_available_in_pos : row.is_available_in_pos)
                            ? 'badge--ok'
                            : 'badge--off'
                    "
                >
                    {{
                        (viewingBranch ? row.effective_is_available_in_pos : row.is_available_in_pos)
                            ? 'Disponible'
                            : 'Oculto'
                    }}
                </span>
                <span v-if="viewingBranch && row.effective_availability_is_overridden" class="badge badge--warn">
                    propio
                </span>
            </template>
            <span v-else class="muted">—</span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button class="link-button" type="button" @click="openArticle(row)">Ver ficha</button>
                <button
                    v-if="row.status === 'active'"
                    v-can.write="'catalog.articles.archive'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="confirmArchive(row)"
                >
                    Archivar
                </button>
            </div>
        </template>
    </DataTable>

    <!-- Vista de cuadrícula: las fotos de publicación del artículo (ADR de imágenes) lucen aquí. -->
    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        min-card="15rem"
        empty-message="No hay artículos que coincidan."
    >
        <template #card="{ item }">
            <button class="art-card" type="button" @click="openArticle(item)">
                <span class="art-card__img">
                    <img v-if="item.image_url" :src="item.image_url" :alt="item.name" loading="lazy" />
                    <span v-else class="art-card__ph" aria-hidden="true">🍽️</span>
                </span>
                <span class="art-card__body">
                    <span class="art-card__code">{{ item.code }}</span>
                    <span class="art-card__name">{{ item.name }}</span>
                    <span class="caps">
                        <span
                            v-for="cap in capabilityBadges(item)"
                            :key="cap.letter"
                            class="cap"
                            :class="{ 'cap--on': cap.on }"
                            :title="cap.title"
                        >
                            {{ cap.letter }}
                        </span>
                    </span>
                    <span class="art-card__foot">
                        <span v-if="item.capabilities?.sellable" class="money">
                            ${{ viewingBranch ? item.effective_price : item.base_price }}
                        </span>
                        <span v-else class="muted">No se vende</span>
                        <span
                            v-if="item.capabilities?.sellable"
                            class="badge"
                            :class="(viewingBranch ? item.effective_is_available_in_pos : item.is_available_in_pos) ? 'badge--ok' : 'badge--off'"
                        >
                            {{ (viewingBranch ? item.effective_is_available_in_pos : item.is_available_in_pos) ? 'En POS' : 'Oculto' }}
                        </span>
                    </span>
                </span>
            </button>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="artículos" />

    <ArticleForm
        v-if="editing"
        :article="editing === 'new' ? null : editing"
        :categories="categoryOptions"
        :units="units"
        :tags="tags"
        @close="editing = null"
        @saved="afterSave"
    />
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.notice {
    margin: 0 0 0.75rem;
    padding: 0.6rem 0.85rem;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 0.375rem;
    font-size: 0.85rem;
}

.row-link {
    background: none;
    border: 0;
    padding: 0;
    font: inherit;
    font-weight: 500;
    color: #1c1917;
    text-align: left;
    cursor: pointer;
    text-decoration: underline;
    text-decoration-color: #d6d3d1;
}

.row-sub {
    display: block;
    font-size: 0.75rem;
    opacity: 0.5;
}

.caps {
    display: inline-flex;
    gap: 0.2rem;
}

/*
   Las cuatro capacidades siempre en el mismo sitio, encendidas o apagadas. Mostrar sólo las
   encendidas movería las letras de columna en columna y haría imposible comparar dos filas de un
   vistazo.
*/
.cap {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.2rem;
    height: 1.2rem;
    border-radius: 0.25rem;
    font-size: 0.65rem;
    font-weight: 700;
    background: #f5f5f4;
    color: #d6d3d1;
}

.cap--on {
    background: #1c1917;
    color: #fff;
}

.money {
    font-variant-numeric: tabular-nums;
}

.muted {
    opacity: 0.45;
}

/* El toggle lista/cuadrícula se empuja al extremo derecho del toolbar. */
.toolbar__view { margin-left: auto; }

/* Tarjeta de artículo (vista de cuadrícula): foto arriba, datos abajo. */
.art-card {
    display: flex;
    flex-direction: column;
    width: 100%;
    padding: 0;
    text-align: left;
    font: inherit;
    color: var(--color-contenido);
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.7rem;
    overflow: hidden;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.art-card:hover {
    border-color: var(--color-acento);
    box-shadow: 0 6px 16px -8px rgb(0 0 0 / 0.2);
    transform: translateY(-2px);
}
.art-card__img {
    display: grid;
    place-items: center;
    aspect-ratio: 4 / 3;
    background: var(--color-fondo);
    overflow: hidden;
}
.art-card__img img { width: 100%; height: 100%; object-fit: cover; }
.art-card__ph { font-size: 2rem; opacity: 0.5; }
.art-card__body { display: flex; flex-direction: column; gap: 0.3rem; padding: 0.7rem 0.8rem; }
.art-card__code { font-size: 0.72rem; color: var(--color-suave); font-variant-numeric: tabular-nums; }
.art-card__name { font-weight: 600; font-size: 0.92rem; line-height: 1.25; }
.art-card__foot { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.15rem; }
</style>
