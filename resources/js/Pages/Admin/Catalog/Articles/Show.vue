<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useAuthorization } from '../../../../composables/useAuthorization';
import ArticleForm from '../../../../components/catalog/ArticleForm.vue';
import ArticlePricePanel from '../../../../components/catalog/ArticlePricePanel.vue';
import ArticleCostPanel from '../../../../components/catalog/ArticleCostPanel.vue';
import ArticleRecipePanel from '../../../../components/catalog/ArticleRecipePanel.vue';
import ArticleBranchesPanel from '../../../../components/catalog/ArticleBranchesPanel.vue';
import ArticlePresentationsPanel from '../../../../components/catalog/ArticlePresentationsPanel.vue';
import ArticleModifiersPanel from '../../../../components/catalog/ArticleModifiersPanel.vue';
import SupplierPricePanel from '../../../../components/purchasing/SupplierPricePanel.vue';

/**
 * Ficha del artículo: el sitio donde el catálogo y el costeo se ven juntos.
 *
 * ## Por qué la receta y el costo no tienen pantalla propia
 *
 * Porque no tienen sentido solas. Una receta no existe sin su artículo (invariante I1: una receta activa
 * por artículo) y un costo se lee siempre preguntando «¿cuánto cuesta ESTO?». Darles URL propia habría
 * creado dos pantallas huérfanas y un listado de recetas que nadie consultaría.
 *
 * ## Las pestañas dependen de las CAPACIDADES, no de un tipo
 *
 * Es la consecuencia visible de D17. Un insumo no tiene pestaña de precio porque no se vende; un artículo
 * que no es producible no tiene receta. Mostrarlas todas siempre enseñaría que a un jitomate «le falta»
 * poner precio de venta.
 *
 * Y dependen también de los PERMISOS: quien no ve costos no ve la pestaña, en lugar de encontrarse un 403
 * al abrirla. La autorización real la aplica cada endpoint — esto es presentación (§4.2).
 */
const props = defineProps({
    /**
     * El ULID de la ruta. Es lo único que el shell entrega: los datos vienen de la API, como en el resto
     * de la administración (D59).
     */
    articleUlid: { type: String, required: true },
});

const { can } = useAuthorization();

const article = ref(null);
const loading = ref(true);
const error = ref(null);

/** Ya llegó todo: el artículo y los datos de referencia de los que dependen las pestañas. */
const ready = ref(false);

const categories = ref([]);
const units = ref([]);
const tags = ref([]);
const branches = ref([]);

async function loadArticle() {
    loading.value = true;
    error.value = null;

    try {
        article.value = (await api.get(`/articles/${props.articleUlid}`)).data;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        error.value = e;
        article.value = null;
    } finally {
        loading.value = false;
    }
}

/**
 * Todo en UNA tanda de peticiones, y la pantalla espera a que termine.
 *
 * La primera versión pedía el artículo y después los datos de referencia, pintando en cuanto llegaba el
 * artículo. El resultado en el navegador: las pestañas aparecían de a una conforme llegaban los datos
 * —«Sucursales» salía un segundo tarde, porque depende de cuántas sucursales hay— y la barra se movía
 * bajo el cursor. Quien iba a pulsar «Costo» acababa en otra pestaña.
 *
 * Ninguna de estas peticiones depende del resultado de otra: todas se resuelven con el ULID de la ruta.
 * Encadenarlas no daba nada y costaba un salto de ida y vuelta más.
 */
onMounted(async () => {
    const [cats, uns, tgs, brs] = await Promise.all([
        loadArticle(),
        api.get('/article-categories').catch(() => ({ data: [] })),
        api.get('/units', { status: 'active', per_page: 100 }).catch(() => ({ data: [] })),
        api.get('/tags').catch(() => ({ data: [] })),
        api.get('/branches', { status: 'active', per_page: 100 }).catch(() => ({ data: [] })),
        probeRecipe(),
    ]).then(([, ...rest]) => rest);

    categories.value = (cats.data ?? []).flatMap((root) => [
        { ulid: root.ulid, label: root.name },
        ...(root.children ?? []).map((child) => ({ ulid: child.ulid, label: `   ${child.name}` })),
    ]);
    units.value = uns.data ?? [];
    tags.value = tgs.data ?? [];
    branches.value = brs.data ?? [];

    ready.value = true;
});

const editing = ref(false);

/**
 * ¿Este artículo tiene receta, aunque ya no esté marcado como producible?
 *
 * Se pregunta porque el caso existe y es peligroso: si alguien le quita la capacidad a un artículo que
 * ya tenía receta, la receta sigue en la base y sigue costeando. Mostrar la pestaña sólo por la
 * capacidad la volvería invisible — y un costo calculado por una receta que nadie puede ver es la clase
 * de dato que nadie logra explicar meses después.
 *
 * El 404 es la respuesta normal para la gran mayoría de artículos, así que no se trata como error.
 */
const hasRecipe = ref(false);

async function probeRecipe() {
    if (!can('costing.recipes.view')) {
        return;
    }

    try {
        await api.get(`/articles/${props.articleUlid}/recipe`);
        hasRecipe.value = true;
    } catch {
        hasRecipe.value = false;
    }
}

async function afterSave() {
    editing.value = false;
    await loadArticle();
    await probeRecipe();
}

const tabs = computed(() => {
    if (article.value === null) {
        return [];
    }

    const caps = article.value.capabilities ?? {};

    return [
        { key: 'general', label: 'General', show: true },
        { key: 'price', label: 'Precio', show: caps.sellable && can('catalog.prices.view') },
        { key: 'cost', label: 'Costo', show: can('costing.costs.view') },
        {
            key: 'recipe',
            label: 'Receta',
            // Producible, o con receta ya guardada: un artículo al que le quitaron la capacidad no debe
            // quedarse con una receta invisible que sigue costeando.
            show: (caps.producible || hasRecipe.value) && can('costing.recipes.view'),
        },
        {
            key: 'branches',
            label: 'Sucursales',
            // Con una sola sucursal no hay nada que decidir por sucursal, y la pestaña sólo sería ruido.
            show: caps.sellable && branches.value.length > 1 && can('catalog.prices.view'),
        },
        {
            key: 'presentations',
            label: 'Presentaciones',
            show: caps.inventoriable || caps.supply,
        },
        { key: 'modifiers', label: 'Modificadores', show: caps.sellable },
        {
            key: 'supplier_prices',
            label: 'Proveedores',
            // Sólo lo que se compra: un platillo no tiene precio de proveedor, y el propio endpoint lo rechaza. La
            // condición es la misma que valida `StoreSupplierPriceRequest`, para que la pestaña no prometa algo que el
            // servidor niega.
            show: (caps.inventoriable || caps.supply) && can('purchasing.supplier_prices.view'),
        },
    ].filter((tab) => tab.show);
});

const activeTab = ref('general');

/** Si la pestaña activa deja de existir —al quitarle una capacidad al artículo— se vuelve a General. */
const currentTab = computed(() =>
    tabs.value.some((tab) => tab.key === activeTab.value) ? activeTab.value : 'general',
);

const capabilityLabels = computed(() => {
    const caps = article.value?.capabilities ?? {};

    return [
        { on: caps.sellable, label: 'Vendible' },
        { on: caps.inventoriable, label: 'Inventariable' },
        { on: caps.supply, label: 'Insumo' },
        { on: caps.producible, label: 'Producible' },
    ].filter((cap) => cap.on);
});
</script>

<template>
    <Head :title="article?.name ?? 'Artículo'" />

    <p class="breadcrumb">
        <Link href="/admin/articulos">← Artículos</Link>
    </p>

    <p v-if="loading || (!ready && error === null)" class="card card--quiet">Cargando…</p>

    <div v-else-if="error" class="card card--error">
        <p v-if="error.status === 404">
            Este artículo no existe, o pertenece a otro negocio.
        </p>
        <p v-else-if="error.isForbidden">No tienes permiso para ver el catálogo.</p>
        <p v-else>{{ error.message }}</p>
    </div>

    <template v-else-if="article">
        <header class="page-header">
            <div>
                <h1>{{ article.name }}</h1>
                <p class="meta">
                    <span v-if="article.code" class="code">{{ article.code }}</span>
                    <span>{{ article.category?.name ?? 'Sin categoría' }}</span>
                    <span>Unidad base: {{ article.base_unit?.code ?? '—' }}</span>
                    <span v-if="article.status !== 'active'" class="badge badge--off">Archivado</span>
                </p>
                <p class="caps">
                    <span v-for="cap in capabilityLabels" :key="cap.label" class="badge badge--warn">
                        {{ cap.label }}
                    </span>
                    <span v-for="tag in article.tags ?? []" :key="tag.ulid" class="badge badge--off">
                        {{ tag.name }}
                    </span>
                </p>
            </div>

            <button v-can.write="'catalog.articles.manage'" class="button" type="button" @click="editing = true">
                Editar
            </button>
        </header>

        <nav class="tabs">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                class="tab"
                :class="{ 'tab--current': currentTab === tab.key }"
                type="button"
                @click="activeTab = tab.key"
            >
                {{ tab.label }}
            </button>
        </nav>

        <div class="card">
            <template v-if="currentTab === 'general'">
                <dl class="facts">
                    <dt>Nombre para comanda y POS</dt>
                    <dd>{{ article.display_name }}</dd>

                    <dt>Precio de venta</dt>
                    <dd>
                        <template v-if="article.capabilities?.sellable">
                            ${{ article.base_price }} <span class="muted">IVA incluido</span>
                        </template>
                        <span v-else class="muted">No se vende</span>
                    </dd>

                    <dt>Markup</dt>
                    <dd>
                        <template v-if="article.markup_percent">
                            {{ article.markup_percent }} % <span class="muted">propio del artículo</span>
                        </template>
                        <span v-else class="muted">Hereda el del negocio</span>
                    </dd>

                    <dt>En el punto de venta</dt>
                    <dd>
                        <template v-if="article.capabilities?.sellable">
                            {{ article.is_available_in_pos ? 'Disponible' : 'Oculto' }}
                        </template>
                        <span v-else class="muted">—</span>
                    </dd>

                    <dt>Dado de alta</dt>
                    <dd>{{ new Date(article.created_at).toLocaleString('es-MX') }}</dd>
                </dl>

                <p v-if="!can('costing.costs.view')" class="muted small">
                    Tu rol no ve costos: el costo es información del negocio y quien cobra no necesita
                    saber cuánto se gana.
                </p>
            </template>

            <ArticlePricePanel
                v-else-if="currentTab === 'price'"
                :article="article"
                @changed="loadArticle"
            />

            <ArticleCostPanel v-else-if="currentTab === 'cost'" :article="article" />

            <ArticleRecipePanel
                v-else-if="currentTab === 'recipe'"
                :article="article"
                :units="units"
            />

            <ArticleBranchesPanel
                v-else-if="currentTab === 'branches'"
                :article="article"
                :branches="branches"
            />

            <ArticlePresentationsPanel v-else-if="currentTab === 'presentations'" :article="article" />

            <SupplierPricePanel v-else-if="currentTab === 'supplier_prices'" :article="article" />

            <ArticleModifiersPanel
                v-else-if="currentTab === 'modifiers'"
                :article="article"
                :units="units"
            />
        </div>

        <ArticleForm
            v-if="editing"
            :article="article"
            :categories="categories"
            :units="units"
            :tags="tags"
            @close="editing = false"
            @saved="afterSave"
        />
    </template>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.breadcrumb {
    margin: 0 0 0.6rem;
    font-size: 0.85rem;
}

.breadcrumb a {
    color: #78716c;
    text-decoration: none;
}

.meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.85rem;
    margin: 0.15rem 0 0;
    font-size: 0.8rem;
    opacity: 0.65;
}

.code {
    font-family: ui-monospace, monospace;
}

.caps {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    margin: 0.45rem 0 0;
}

.tabs {
    display: flex;
    gap: 0.15rem;
    margin-bottom: -1px;
    overflow-x: auto;
}

.tab {
    padding: 0.45rem 0.85rem;
    background: transparent;
    border: 1px solid transparent;
    border-bottom: 0;
    border-radius: 0.375rem 0.375rem 0 0;
    font: inherit;
    font-size: 0.87rem;
    color: #78716c;
    cursor: pointer;
    white-space: nowrap;
}

.tab--current {
    background: #fff;
    border-color: #e7e5e4;
    color: #1c1917;
    font-weight: 600;
}

.card {
    background: #fff;
    border: 1px solid #e7e5e4;
    border-radius: 0 0.5rem 0.5rem 0.5rem;
    padding: 1.1rem;
}

.card--quiet {
    opacity: 0.7;
    border-radius: 0.5rem;
}

.card--error {
    border-color: #fecaca;
    color: #b91c1c;
    border-radius: 0.5rem;
}

.facts {
    display: grid;
    grid-template-columns: minmax(10rem, max-content) 1fr;
    gap: 0.4rem 1.25rem;
    margin: 0;
    font-size: 0.88rem;
}

.facts dt {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.55;
    align-self: center;
}

.facts dd {
    margin: 0;
}

.muted {
    opacity: 0.55;
}

.small {
    font-size: 0.8rem;
    margin: 1rem 0 0;
}
</style>
