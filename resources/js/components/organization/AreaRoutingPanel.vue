<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { api, ApiError, getAllPages } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';
import Icon from '../Icon.vue';
import AreaRouteForm from './AreaRouteForm.vue';
import ConfirmDialog from './ConfirmDialog.vue';
import { categoryLabel, indexCategories, removalOutcome, splitRules, uncoveredCategories } from './areaRouting';

/**
 * «¿Qué va a cada área?»: las reglas de ruteo a áreas de preparación, sucursal por sucursal (D240).
 *
 * ## Por qué no es un ajuste opcional
 *
 * El servidor sólo manda a un área lo que coincide con una regla; lo demás no genera comanda. Un negocio sin reglas
 * captura y cobra con normalidad… y la cocina no recibe ni un papel ni una comanda en el tablero, sin que nada falle.
 * Por eso esta sección dice con todas sus letras cuándo una sucursal no tiene reglas, y qué categorías quedan fuera.
 *
 * ## Lo que se lista es lo que hay
 *
 * Las reglas tal como las devuelve `/pos-area-routes`. Lo único calculado aquí es la EXPLICACIÓN —qué queda sin área, a
 * dónde iría algo si se quita una regla—, con la misma precedencia que el servidor (`areaRouting.js`).
 *
 * ## Alta y baja, sin edición
 *
 * La API no edita reglas: cambiar el área de «Bebidas» es quitar su regla y crear otra.
 *
 * ## Un área dada de baja sigue recibiendo
 *
 * Dar de baja un área NO quita sus reglas, y el servidor sigue ruteando a ella: sus comandas salen por su impresora, si
 * tiene, pero ya no aparecen en ningún tablero. Esas reglas se marcan para que se vean y se corrijan.
 */
const props = defineProps({
    // Sucursales activas que cargó la página. Pueden llegar después del montaje, o vacías si el rol no puede verlas.
    branches: { type: Array, default: () => [] },
});

const emit = defineEmits(['summary']);

const page = usePage();
const { canWrite } = useAuthorization();
const canManage = computed(() => canWrite('organization.preparation_areas.manage'));

const baseId = useId();

const areas = ref([]);
const rules = ref([]);
const loaded = ref(false);
const loadError = ref(null);

const categories = ref([]);
const categoriesReady = ref(false);
const catalogAvailable = ref(true);
const catalogError = ref(null);

let loadSequence = 0;

/**
 * Todas las áreas —también las dadas de baja, para marcar las reglas que apuntan a ellas— y todas las reglas del
 * negocio. Son pocas filas; se recorren las páginas porque el servidor corta en 100.
 */
async function load() {
    const mine = ++loadSequence;

    loadError.value = null;

    try {
        const [allAreas, allRules] = await Promise.all([
            getAllPages('/preparation-areas'),
            getAllPages('/pos-area-routes'),
        ]);

        // Una recarga posterior ya está en camino: su respuesta es la que vale.
        if (mine !== loadSequence) {
            return;
        }

        areas.value = allAreas;
        rules.value = allRules;
        loaded.value = true;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (mine === loadSequence) {
            loadError.value = e.title;
        }
    }
}

/**
 * El árbol de categorías: para nombrar las subcategorías («Bebidas › Cervezas»), ofrecerlas en el alta y decir qué
 * queda sin área. Es de otro permiso (`catalog.articles.view`): sin él las reglas se ven igual y sólo se pierde eso.
 */
async function loadCategories() {
    catalogError.value = null;

    try {
        categories.value = (await api.get('/article-categories')).data ?? [];
        catalogAvailable.value = true;
        categoriesReady.value = true;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        categories.value = [];
        catalogAvailable.value = false;
        categoriesReady.value = false;

        if (e.status !== 403) {
            catalogError.value = e.title;
        }
    }
}

/** La página la llama cuando cambian las áreas (alta, edición, baja): el ruteo depende de ellas. */
async function reload() {
    await Promise.all([load(), loadCategories()]);
}

defineExpose({ reload });

onMounted(reload);

// ---- Sucursales ----

const branchList = computed(() => {
    if (props.branches.length > 0) {
        return props.branches.map((branch) => ({ ulid: branch.ulid, name: branch.name }));
    }

    // Sin la lista de sucursales (el rol no puede verlas): las que aparecen en las áreas y en las reglas.
    const seen = new Map();

    for (const item of [...areas.value, ...rules.value]) {
        if (item.branch) {
            seen.set(item.branch.ulid, item.branch);
        }
    }

    return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name, 'es'));
});

const chosenBranchUlid = ref(null);

// La que se eligió; si no (o ya no está), la sucursal en la que se está trabajando; y si no, la primera.
const currentBranch = computed(() => {
    const list = branchList.value;

    return list.find((branch) => branch.ulid === chosenBranchUlid.value)
        ?? list.find((branch) => branch.ulid === page.props.context?.branch_ulid)
        ?? list[0]
        ?? null;
});

function rulesOf(branchUlid) {
    return rules.value.filter((rule) => rule.branch?.ulid === branchUlid);
}

function onBranchKeydown(event, position) {
    const total = branchList.value.length;
    const target = { ArrowRight: position + 1, ArrowLeft: position - 1, Home: 0, End: total - 1 }[event.key];

    if (target === undefined) {
        return;
    }

    event.preventDefault();

    const next = (target + total) % total;

    chosenBranchUlid.value = branchList.value[next].ulid;
    event.currentTarget.parentElement?.querySelectorAll('[role="tab"]')[next]?.focus();
}

// ---- Reglas de la sucursal elegida ----

const areaByUlid = computed(() => new Map(areas.value.map((area) => [area.ulid, area])));

function pointsToArchivedArea(rule) {
    const area = areaByUlid.value.get(rule.preparation_area?.ulid);

    return area !== undefined && area.status !== 'active';
}

const categoryIndex = computed(() => indexCategories(categories.value));
const branchRules = computed(() => (currentBranch.value ? rulesOf(currentBranch.value.ulid) : []));
const split = computed(() => splitRules(branchRules.value));

const activeAreas = computed(() =>
    areas.value
        .filter((area) => area.branch?.ulid === currentBranch.value?.ulid && area.status === 'active')
        .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0)),
);

/** Las reglas por categoría, en el orden del árbol del catálogo (el de las pestañas del POS). */
const categoryRows = computed(() => {
    const order = new Map();
    let position = 0;

    for (const root of categories.value) {
        order.set(root.ulid, position++);

        for (const child of root.children ?? []) {
            order.set(child.ulid, position++);
        }
    }

    return branchRules.value
        .filter((rule) => !rule.is_article_override)
        .map((rule) => ({
            rule,
            kindLabel: categoryIndex.value.get(rule.category?.ulid)?.parent ? 'Subcategoría' : 'Categoría',
            label: rule.category ? categoryLabel(rule.category, categoryIndex.value) : '—',
            archived: pointsToArchivedArea(rule),
        }))
        .sort(
            (a, b) =>
                (order.get(a.rule.category?.ulid) ?? Infinity) - (order.get(b.rule.category?.ulid) ?? Infinity)
                || a.label.localeCompare(b.label, 'es'),
        );
});

const articleRows = computed(() =>
    branchRules.value
        .filter((rule) => rule.is_article_override)
        .map((rule) => ({ rule, kindLabel: 'Artículo', label: rule.article?.name ?? '—', archived: pointsToArchivedArea(rule) }))
        .sort((a, b) => a.label.localeCompare(b.label, 'es')),
);

const rulesToArchived = computed(() => branchRules.value.filter(pointsToArchivedArea));

const hasActiveCategories = computed(() => categories.value.some((root) => root.status === 'active'));

// «Todo lo demás». Sin el árbol no se puede decir QUÉ queda fuera; sólo que lo que no coincide queda fuera.
const uncovered = computed(() =>
    categoriesReady.value ? uncoveredCategories(categories.value, split.value.byCategory) : null,
);

// Lo que la página necesita para avisar en la pestaña de áreas, sin tener que abrir ésta.
const summary = computed(() => ({
    ready: loaded.value,
    branchesWithoutRules: branchList.value.filter((branch) => rulesOf(branch.ulid).length === 0).map((branch) => branch.name),
    rulesToArchivedAreas: rules.value.filter(pointsToArchivedArea).length,
}));

watch(summary, (value) => emit('summary', value), { immediate: true });

// ---- Alta ----

const creating = ref(false);

async function onCreated() {
    creating.value = false;
    await load();
}

// ---- Baja ----

const pendingRemoval = ref(null);

const remove = useApiForm(
    async (rule) => {
        await api.delete(`/pos-area-routes/${rule.ulid}`);
    },
    { success: { kind: 'delete', entity: 'Regla de ruteo' } },
);

// A dónde irá lo que decidía la regla, con la precedencia del servidor: es la consecuencia que se confirma.
const removal = computed(() =>
    pendingRemoval.value ? removalOutcome(pendingRemoval.value.rule, categoryIndex.value, split.value.byCategory) : null,
);

function askRemove(row) {
    remove.generalError.value = null;
    pendingRemoval.value = row;
}

async function confirmRemove() {
    if (await remove.submit(pendingRemoval.value.rule)) {
        pendingRemoval.value = null;
        await load();
    }
}
</script>

<template>
    <section class="routing">
        <div class="tarjeta routing__intro">
            <h2 class="routing__title">¿Qué va a cada área?</h2>
            <p class="routing__lead">
                Las reglas dicen a qué área de preparación va cada artículo que se vende en una sucursal, en el punto de
                venta o en línea. Cada sucursal tiene las suyas, y gana lo primero que exista:
            </p>
            <ol class="routing__steps">
                <li><strong>La regla del artículo</strong>, si tiene. Es la excepción: gana sobre la de su categoría.</li>
                <li>
                    Si no, <strong>la regla de su categoría</strong>; y si su categoría no tiene, la de
                    <strong>la categoría de arriba</strong> (de «Bebidas › Cervezas», la de «Bebidas»).
                </li>
                <li>
                    Si nada coincide, <strong>no genera comanda</strong>: no sale en ninguna impresora ni tablero. Está bien
                    para lo que se sirve sin preparar —un refresco de la nevera—, y es un error si nadie lo decidió.
                </li>
            </ol>
            <p class="routing__note">
                No hay una regla «para todo lo demás». El área se decide al capturar (o al recibir el pedido en línea) y queda
                fija en esa línea: crear o quitar una regla no mueve lo ya capturado ni lo que ya está en preparación.
            </p>
        </div>

        <p v-if="loadError" class="alert" role="alert">
            No se pudieron cargar las reglas de ruteo. Detalle: {{ loadError }}
            <button type="button" class="link-button" @click="reload"><Icon name="refresh" /> Reintentar</button>
        </p>

        <p v-if="catalogError" class="alert" role="alert">
            No se pudo cargar el catálogo de categorías: las reglas se listan, pero no se puede decir qué queda sin área.
            Detalle: {{ catalogError }}
        </p>

        <template v-if="loaded">
            <p v-if="branchList.length === 0" class="routing__empty">No hay sucursales activas que mostrar.</p>

            <template v-else>
                <div v-if="branchList.length > 1" class="filtros" role="tablist" aria-label="Sucursal">
                    <button
                        v-for="(branch, position) in branchList"
                        :id="`${baseId}-tab-${branch.ulid}`"
                        :key="branch.ulid"
                        type="button"
                        role="tab"
                        class="filtro"
                        :class="{ 'filtro--activo': branch.ulid === currentBranch?.ulid }"
                        :aria-selected="branch.ulid === currentBranch?.ulid"
                        :aria-controls="`${baseId}-branch`"
                        :tabindex="branch.ulid === currentBranch?.ulid ? 0 : -1"
                        @click="chosenBranchUlid = branch.ulid"
                        @keydown="onBranchKeydown($event, position)"
                    >
                        {{ branch.name }}
                        <span v-if="rulesOf(branch.ulid).length === 0" class="badge badge--warn">sin reglas</span>
                    </button>
                </div>

                <div
                    v-if="currentBranch"
                    :id="`${baseId}-branch`"
                    class="tarjeta routing__branch"
                    :role="branchList.length > 1 ? 'tabpanel' : undefined"
                    :aria-labelledby="branchList.length > 1 ? `${baseId}-tab-${currentBranch.ulid}` : undefined"
                >
                    <div class="routing__bar">
                        <h3 class="routing__branch-name">
                            {{ currentBranch.name }}
                            <span class="routing__count">
                                {{ branchRules.length === 1 ? '1 regla' : `${branchRules.length} reglas` }}
                            </span>
                        </h3>

                        <button
                            v-if="canManage"
                            type="button"
                            class="button"
                            :disabled="activeAreas.length === 0"
                            @click="creating = true"
                        >
                            <Icon name="plus" /> Nueva regla
                        </button>
                    </div>

                    <p v-if="branchRules.length === 0" class="alert alert--notice">
                        <strong>Sin reglas: las órdenes de esta sucursal no se comandan a ningún área.</strong>
                        Nada de lo que se venda aquí —en el punto de venta o en línea— sale en la cocina, la barra ni el
                        tablero hasta que agregues al menos una regla.
                    </p>

                    <p v-if="activeAreas.length === 0" class="routing__hint">
                        Esta sucursal no tiene áreas activas a las cuales mandar: crea una con «Nueva área».
                    </p>

                    <p v-if="rulesToArchived.length > 0" class="alert alert--notice">
                        {{ rulesToArchived.length === 1 ? 'Una regla manda' : `${rulesToArchived.length} reglas mandan` }}
                        artículos a un área dada de baja: el servidor sigue ruteando a ella, así que sus comandas no aparecen
                        en ningún tablero y sólo salen en papel si esa área tiene impresora. Quítalas y, si hace falta,
                        créalas de nuevo hacia un área activa.
                    </p>

                    <template v-if="categoryRows.length > 0">
                        <h4 class="routing__group">Por categoría</h4>
                        <ul class="rules">
                            <li v-for="row in categoryRows" :key="row.rule.ulid" class="rule">
                                <span class="rule__what">
                                    <span class="rule__kind">{{ row.kindLabel }}</span>
                                    {{ row.label }}
                                </span>
                                <span class="rule__arrow" aria-hidden="true">→</span>
                                <span class="sr-only">va a</span>
                                <span class="rule__area">
                                    {{ row.rule.preparation_area?.name ?? '—' }}
                                    <span v-if="row.archived" class="badge badge--warn">dada de baja</span>
                                </span>
                                <button
                                    v-if="canManage"
                                    type="button"
                                    class="link-button link-button--danger rule__action"
                                    @click="askRemove(row)"
                                >
                                    <Icon name="trash" /> Quitar
                                </button>
                            </li>
                        </ul>
                    </template>

                    <template v-if="articleRows.length > 0">
                        <h4 class="routing__group">
                            Excepciones por artículo
                            <span class="routing__group-hint">ganan sobre la regla de su categoría</span>
                        </h4>
                        <ul class="rules">
                            <li v-for="row in articleRows" :key="row.rule.ulid" class="rule">
                                <span class="rule__what">
                                    <span class="rule__kind">{{ row.kindLabel }}</span>
                                    {{ row.label }}
                                </span>
                                <span class="rule__arrow" aria-hidden="true">→</span>
                                <span class="sr-only">va a</span>
                                <span class="rule__area">
                                    {{ row.rule.preparation_area?.name ?? '—' }}
                                    <span v-if="row.archived" class="badge badge--warn">dada de baja</span>
                                </span>
                                <button
                                    v-if="canManage"
                                    type="button"
                                    class="link-button link-button--danger rule__action"
                                    @click="askRemove(row)"
                                >
                                    <Icon name="trash" /> Quitar
                                </button>
                            </li>
                        </ul>
                    </template>

                    <h4 class="routing__group">Todo lo demás</h4>
                    <div class="routing__rest">
                        <p class="routing__rest-lead">
                            Lo que no coincide con ninguna regla <strong>no genera comanda</strong> en esta sucursal.
                        </p>

                        <p v-if="!catalogAvailable && !catalogError" class="routing__hint">
                            Tu rol no puede ver el catálogo, así que aquí no se lista qué categorías quedan fuera.
                        </p>
                        <template v-else-if="uncovered !== null">
                            <p v-if="!hasActiveCategories" class="routing__hint">
                                No hay categorías activas en el catálogo: sólo genera comanda lo que tenga regla por artículo.
                            </p>
                            <template v-else-if="uncovered.length > 0">
                                <p class="routing__hint">Hoy quedan fuera estas categorías (y sus subcategorías):</p>
                                <ul class="rest">
                                    <li v-for="category in uncovered" :key="category.ulid" class="badge badge--off">
                                        {{ category.name }}
                                        <template v-if="category.except.length > 0">(menos {{ category.except.join(', ') }})</template>
                                    </li>
                                </ul>
                                <p class="routing__hint">Y cualquier artículo sin categoría que no tenga regla propia.</p>
                            </template>
                            <p v-else class="routing__hint">
                                Todas las categorías activas tienen área. Sólo quedaría fuera un artículo sin categoría y sin
                                regla propia.
                            </p>
                        </template>
                    </div>
                </div>
            </template>
        </template>

        <AreaRouteForm
            v-if="creating && currentBranch"
            :branch="currentBranch"
            :areas="activeAreas"
            :categories="categories"
            :catalog-available="catalogAvailable"
            :catalog-error="catalogError"
            :rules="branchRules"
            @saved="onCreated"
            @cancel="creating = false"
        />

        <ConfirmDialog
            v-if="pendingRemoval && removal && currentBranch"
            :title="`¿Quitar la regla «${pendingRemoval.label} → ${pendingRemoval.rule.preparation_area?.name ?? '—'}»?`"
            confirm-label="Quitar regla"
            processing-label="Quitando…"
            :processing="remove.processing.value"
            :error="remove.generalError.value"
            @confirm="confirmRemove"
            @cancel="pendingRemoval = null"
        >
            <p v-if="removal.kind === 'article'">
                De ahora en adelante, «{{ pendingRemoval.label }}» volverá a seguir en {{ currentBranch.name }} la regla de su
                categoría (o la de la categoría de arriba). Si ninguna tiene, <strong>dejará de generar comanda</strong>.
            </p>
            <p v-else-if="removal.kind === 'child' && removal.fallback">
                De ahora en adelante, lo que se capture de «{{ pendingRemoval.label }}» en {{ currentBranch.name }} irá a
                <strong>{{ removal.fallback.preparation_area?.name ?? '—' }}</strong>, por la regla de «{{ removal.parent.name }}».
            </p>
            <p v-else-if="removal.kind === 'child'">
                De ahora en adelante, lo que se capture de «{{ pendingRemoval.label }}» en {{ currentBranch.name }}
                <strong>dejará de generar comanda</strong>: «{{ removal.parent.name }}» tampoco tiene regla, así que no irá a
                ningún área.
            </p>
            <p v-else-if="removal.kind === 'root'">
                De ahora en adelante, lo que se capture de «{{ pendingRemoval.label }}»
                <template v-if="removal.keep.length > 0">(menos {{ removal.keep.join(', ') }}, que tienen regla propia)</template>
                en {{ currentBranch.name }} <strong>dejará de generar comanda</strong>: no irá a ningún área.
            </p>
            <p v-else>
                De ahora en adelante, lo que se capture de «{{ pendingRemoval.label }}» en {{ currentBranch.name }} seguirá la
                regla de la categoría de arriba, si la tiene; si no, <strong>dejará de generar comanda</strong>.
            </p>

            <p v-if="!pendingRemoval.rule.is_article_override">Los artículos con regla propia no cambian.</p>

            <p>
                Lo ya capturado conserva su área: quitar la regla no cambia comandas ya emitidas ni lo que está en
                preparación. Queda registrado en la bitácora.
            </p>
        </ConfirmDialog>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.routing {
    display: grid;
    gap: 1rem;
    min-width: 0;
}

.routing__intro,
.routing__branch {
    padding: 1rem 1.15rem;
    min-width: 0;
}

.routing__title {
    margin: 0 0 0.35rem;
    font-size: 1.05rem;
    font-weight: 650;
}

.routing__lead,
.routing__note {
    margin: 0;
    font-size: 0.88rem;
    line-height: 1.5;
    color: var(--color-suave);
}

.routing__steps {
    margin: 0.5rem 0 0.6rem;
    padding-left: 1.3rem;
    font-size: 0.9rem;
    line-height: 1.5;
}

.routing__steps li + li {
    margin-top: 0.3rem;
}

.routing__empty,
.routing__hint {
    margin: 0 0 0.75rem;
    font-size: 0.85rem;
    color: var(--color-suave);
}

.routing__bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    margin-bottom: 0.9rem;
}

.routing__branch-name {
    margin: 0;
    font-size: 1rem;
    font-weight: 650;
    overflow-wrap: anywhere;
}

.routing__count {
    margin-left: 0.35rem;
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--color-suave);
}

.routing__group {
    margin: 1rem 0 0.35rem;
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-suave);
}

.routing__group-hint {
    margin-left: 0.3rem;
    font-weight: 500;
    letter-spacing: normal;
    text-transform: none;
}

.routing__rest-lead {
    margin: 0 0 0.5rem;
    font-size: 0.9rem;
}

.rules {
    margin: 0;
    padding: 0;
    list-style: none;
}

/* Una fila por regla: «Categoría Bebidas → Barra». En un teléfono la acción baja a su propia línea. */
.rule {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.6rem;
    padding: 0.55rem 0;
    border-bottom: 1px solid var(--color-borde);
    font-size: 0.9rem;
}

.rule:last-child {
    border-bottom: 0;
}

.rule__what,
.rule__area {
    min-width: 0;
    overflow-wrap: anywhere;
}

.rule__kind {
    margin-right: 0.25rem;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--color-suave);
}

.rule__arrow {
    color: var(--color-suave);
}

.rule__area {
    font-weight: 600;
}

.rule__action {
    margin-left: auto;
}

.rest {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin: 0 0 0.6rem;
    padding: 0;
    list-style: none;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}
</style>
