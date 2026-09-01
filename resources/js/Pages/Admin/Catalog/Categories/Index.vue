<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useApiForm } from '../../../../stores/useResourceList';
import { useReorder } from '../../../../composables/useReorder';

/**
 * Categorías del catálogo, dos niveles (D18).
 *
 * ## Por qué esta pantalla no usa el listado genérico
 *
 * El endpoint devuelve el árbol completo —raíces con sus hijas dentro— y **no pagina**: un selector
 * jerárquico necesita todas las categorías a la vez, y paginar un árbol obligaría a reconstruirlo
 * entre páginas. Usar aquí el listado con filtros y paginación sería pedirle a un endpoint algo que
 * deliberadamente no hace.
 *
 * ## Dos niveles y no un árbol arbitrario
 *
 * Es la decisión D18 y la impone el servidor: una subcategoría no puede tener hijas. La pantalla lo
 * refleja mostrando el botón «Subcategoría» sólo en las raíces, y el dato que lo decide —
 * `can_have_children`— viene del servidor. Si la UI aplicara la regla por su cuenta, tendría una
 * copia de la regla que se desactualiza el día que D18 cambie.
 */
const tree = ref([]);
const loading = ref(true);
const error = ref(null);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        tree.value = (await api.get('/article-categories')).data ?? [];
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        error.value = e;
        tree.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const total = computed(() =>
    tree.value.reduce((count, root) => count + 1 + (root.children?.length ?? 0), 0),
);

/**
 * Reordenar arrastrando dentro de un mismo nivel (D18: dos niveles y punto). No se cruza entre niveles —una raíz no se
 * suelta entre subcategorías ni al revés—, así que `dragScope` marca el ámbito del arrastre en curso: `'root'` para las
 * raíces, o el ULID de la raíz cuyas hijas se están reacomodando. Al soltar fuera del mismo ámbito no pasa nada.
 */
const dragCat = useReorder();
const dragScope = ref(null);
const reorderError = ref(null);

function iniciarRaiz(i) {
    dragScope.value = 'root';
    dragCat.start(i);
}

function iniciarHija(root, i) {
    dragScope.value = root.ulid;
    dragCat.start(i);
}

async function soltarRaiz(i) {
    if (dragScope.value !== 'root') {
        dragCat.end();
        return;
    }

    const nuevas = dragCat.reorder(i, tree.value);
    if (nuevas) await persistirOrden(nuevas);
}

async function soltarHija(root, i) {
    if (dragScope.value !== root.ulid) {
        dragCat.end();
        return;
    }

    const nuevas = dragCat.reorder(i, root.children ?? []);
    if (nuevas) await persistirOrden(nuevas);
}

/**
 * Renumera (10, 20, 30… deja hueco para insertar a mano) y persiste sólo las que cambiaron. El update de categoría exige
 * `name`, así que va junto al `sort_order`; no se toca `parent_ulid`, el reorden nunca cambia de padre.
 */
async function persistirOrden(nuevas) {
    reorderError.value = null;

    try {
        const cambios = nuevas
            .map((c, i) => ({ ulid: c.ulid, name: c.name, sort_order: (i + 1) * 10, antes: Number(c.sort_order ?? 0) }))
            .filter((c) => c.sort_order !== c.antes);

        await Promise.all(cambios.map((c) => api.patch(`/article-categories/${c.ulid}`, { name: c.name, sort_order: c.sort_order })));
        await load();
    } catch (e) {
        if (e instanceof ApiError) {
            reorderError.value = e.title;
        } else {
            throw e;
        }
    }
}

/** `null` = cerrado; `{ parent }` = nueva; una categoría = edición. */
const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    if (editing.value.isNew) {
        await api.post('/article-categories', {
            name: form.value.name,
            parent_ulid: editing.value.parent?.ulid,
            sort_order: form.value.sort_order === '' ? undefined : Number(form.value.sort_order),
        });
    } else {
        await api.patch(`/article-categories/${editing.value.ulid}`, {
            name: form.value.name,
            sort_order: Number(form.value.sort_order || 0),
            status: form.value.status,
        });
    }
});

const archive = useApiForm(async (category) => {
    await api.post(`/article-categories/${category.ulid}/archive`);
});

function startCreate(parent = null) {
    editing.value = { isNew: true, parent };
    form.value = { name: '', sort_order: '' };
}

function startEdit(category) {
    editing.value = category;
    form.value = {
        name: category.name,
        sort_order: String(category.sort_order ?? 0),
        status: category.status,
    };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await load();
    }
}

async function confirmArchive(category) {
    if (!window.confirm(`¿Dar de baja la categoría «${category.name}»?`)) {
        return;
    }

    // Puede devolver 409: el servidor se niega si tiene subcategorías activas o artículos dentro, y
    // el mensaje explica qué mover primero. Se muestra tal cual.
    if (await archive.submit(category)) {
        await load();
    }
}
</script>

<template>
    <Head title="Categorías" />

    <header class="page-header">
        <div>
            <h1>Categorías</h1>
            <p class="page-header__hint">
                Dos niveles: categoría y subcategoría. Es la estructura del menú —lo que agrupa los
                botones del punto de venta—, así que el orden importa: es el que verá quien tome la
                orden.
            </p>
        </div>

        <button v-can.write="'catalog.categories.manage'" class="button" type="button" @click="startCreate()">
            Nueva categoría
        </button>
    </header>

    <p v-if="archive.generalError.value" class="alert">{{ archive.generalError.value }}</p>
    <p v-if="reorderError" class="alert">{{ reorderError }}</p>

    <div v-if="error" class="panel panel--error">
        <p v-if="error.isForbidden">No tienes permiso para ver las categorías.</p>
        <p v-else>{{ error.message }}</p>
    </div>

    <p v-else-if="loading" class="panel panel--quiet">Cargando…</p>

    <p v-else-if="tree.length === 0" class="panel panel--quiet">
        Todavía no hay categorías. Crea la primera para poder dar de alta artículos vendibles: un
        artículo que se vende necesita categoría.
    </p>

    <template v-else>
        <p class="count">{{ total }} categorías en total · arrastra ⠿ para reordenar dentro de cada nivel</p>

        <ul class="tree">
            <li v-for="(root, ri) in tree" :key="root.ulid" class="tree__root">
                <div
                    class="node"
                    :class="{ 'node--over': dragScope === 'root' && dragCat.over.value === ri && dragCat.from.value !== ri }"
                    @dragover.prevent="dragScope === 'root' && dragCat.enter(ri)"
                    @drop="soltarRaiz(ri)"
                    @dragend="dragCat.end()"
                >
                    <span
                        v-can.write="'catalog.categories.manage'"
                        class="node__handle"
                        draggable="true"
                        title="Arrastra para reordenar"
                        aria-label="Arrastra para reordenar"
                        @dragstart="iniciarRaiz(ri)"
                    >
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor">
                            <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
                            <circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" />
                            <circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" />
                        </svg>
                    </span>
                    <span class="node__name">{{ root.name }}</span>

                    <span v-if="root.status !== 'active'" class="badge badge--off">Baja</span>
                    <span class="node__order">orden {{ root.sort_order }}</span>

                    <span class="node__actions">
                        <button
                            v-can.write="'catalog.categories.manage'"
                            class="link-button"
                            type="button"
                            @click="startCreate(root)"
                        >
                            + Subcategoría
                        </button>
                        <button
                            v-can.write="'catalog.categories.manage'"
                            class="link-button"
                            type="button"
                            @click="startEdit(root)"
                        >
                            Editar
                        </button>
                        <button
                            v-if="root.status === 'active'"
                            v-can.write="'catalog.categories.manage'"
                            class="link-button link-button--danger"
                            type="button"
                            @click="confirmArchive(root)"
                        >
                            Dar de baja
                        </button>
                    </span>
                </div>

                <ul v-if="root.children?.length" class="tree__children">
                    <li v-for="(child, ci) in root.children" :key="child.ulid">
                        <div
                            class="node node--child"
                            :class="{ 'node--over': dragScope === root.ulid && dragCat.over.value === ci && dragCat.from.value !== ci }"
                            @dragover.prevent="dragScope === root.ulid && dragCat.enter(ci)"
                            @drop="soltarHija(root, ci)"
                            @dragend="dragCat.end()"
                        >
                            <span
                                v-can.write="'catalog.categories.manage'"
                                class="node__handle"
                                draggable="true"
                                title="Arrastra para reordenar"
                                aria-label="Arrastra para reordenar"
                                @dragstart="iniciarHija(root, ci)"
                            >
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                                    <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
                                    <circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" />
                                    <circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" />
                                </svg>
                            </span>
                            <span class="node__name">{{ child.name }}</span>

                            <span v-if="child.status !== 'active'" class="badge badge--off">Baja</span>
                            <span class="node__order">orden {{ child.sort_order }}</span>

                            <span class="node__actions">
                                <button
                                    v-can.write="'catalog.categories.manage'"
                                    class="link-button"
                                    type="button"
                                    @click="startEdit(child)"
                                >
                                    Editar
                                </button>
                                <button
                                    v-if="child.status === 'active'"
                                    v-can.write="'catalog.categories.manage'"
                                    class="link-button link-button--danger"
                                    type="button"
                                    @click="confirmArchive(child)"
                                >
                                    Dar de baja
                                </button>
                            </span>
                        </div>
                    </li>
                </ul>

                <!--
                    Una raíz sin hijas no es un error: muchos negocios tienen «Bebidas» sin
                    subdividir. Se dice para que el hueco no parezca una carga a medias.
                -->
                <p v-else class="tree__empty">Sin subcategorías</p>
            </li>
        </ul>
    </template>

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <h2>
                <template v-if="editing.isNew && editing.parent">
                    Subcategoría de {{ editing.parent.name }}
                </template>
                <template v-else-if="editing.isNew">Nueva categoría</template>
                <template v-else>Editar {{ editing.name }}</template>
            </h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="80" required />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <label class="field">
                <span class="field__label">Orden</span>
                <input v-model="form.sort_order" class="input" inputmode="numeric" placeholder="0" />
                <span class="field__hint">
                    Menor primero. Es el orden en que se verán los botones al tomar la orden.
                </span>
                <span v-if="save.fieldErrors.value.sort_order" class="field__error">
                    {{ save.fieldErrors.value.sort_order }}
                </span>
            </label>

            <label v-if="!editing.isNew" class="field">
                <span class="field__label">Estado</span>
                <select v-model="form.status" class="input">
                    <option value="active">Activa</option>
                    <option value="inactive">Dada de baja</option>
                </select>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.count {
    margin: 0 0 0.75rem;
    font-size: 0.8rem;
    opacity: 0.6;
}

.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    padding: 1.25rem;
    margin: 0;
    color: var(--color-suave);
}

.panel--quiet {
    opacity: 0.85;
}

.panel--error {
    border-color: color-mix(in srgb, var(--color-peligro) 35%, transparent);
    color: var(--color-peligro);
}

.tree {
    list-style: none;
    margin: 0;
    padding: 0;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
}

.tree__root + .tree__root {
    border-top: 1px solid var(--color-borde);
}

.tree__children {
    list-style: none;
    margin: 0;
    padding: 0 0 0.4rem 1.75rem;
}

.tree__empty {
    margin: 0;
    padding: 0 0 0.6rem 1.75rem;
    font-size: 0.8rem;
    opacity: 0.45;
}

.node {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.6rem 0.85rem;
}

.node--child {
    padding-block: 0.35rem;
    font-size: 0.9rem;
}

.node__name {
    font-weight: 500;
}

.node--child .node__name {
    font-weight: 400;
}

.node__order {
    font-size: 0.75rem;
    opacity: 0.45;
}

.node__actions {
    margin-left: auto;
    display: flex;
    gap: 0.75rem;
}

/* Reordenar arrastrando dentro de cada nivel. */
.node__handle {
    display: inline-grid;
    place-items: center;
    color: var(--color-suave);
    cursor: grab;
    border-radius: 0.3rem;
    padding: 0.1rem;
    margin-left: -0.2rem;
}
.node__handle:hover { color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
.node__handle:active { cursor: grabbing; }
.node--over { box-shadow: inset 0 2px 0 var(--color-acento); }
</style>
