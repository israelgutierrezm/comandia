<script setup>
import { onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';
import ArticlePicker from './ArticlePicker.vue';
import Icon from '../../components/Icon.vue';

/**
 * Grupos de modificadores asignados al artículo, y la receta de cada opción.
 *
 * ## Asignar, no definir
 *
 * Los grupos se definen en su propia pantalla porque se comparten entre artículos. Aquí se eligen los que
 * este artículo ofrece y en qué orden — y el orden es el que verá quien tome la orden, así que es parte de
 * la asignación y no un adorno.
 *
 * Se guarda la lista COMPLETA de una vez, igual que la receta: con operaciones de agregar y quitar habría
 * que recalcular el orden en cada llamada, o dejarlo inconsistente entre ellas.
 *
 * ## La receta de un modificador
 *
 * «Extra queso» consume 30 g de queso, y sin capturarlo el platillo con extras costaría lo mismo que sin
 * ellos: el margen del extra saldría del 100 %. El error apunta siempre en la dirección optimista, que es
 * la peor para un negocio de alimentos.
 *
 * Un modificador **sin receta cuesta cero**, no «desconocido»: «término medio» no gasta insumos. Es
 * distinto de un artículo sin costo capturado, y confundirlos volvería incalculable un platillo entero por
 * llevar un modificador que no consume nada.
 */
const props = defineProps({
    article: { type: Object, required: true },
    units: { type: Array, required: true },
});

const { canWrite } = useAuthorization();

const assigned = ref([]);
const available = ref([]);
const loading = ref(true);
const error = ref(null);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const [mine, all] = await Promise.all([
            api.get(`/articles/${props.article.ulid}/modifier-groups`),
            api.get('/modifier-groups', { status: 'active', per_page: 100 }),
        ]);

        assigned.value = mine.data ?? [];
        available.value = all.data ?? [];
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        error.value = e;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

// ---- Asignación ----

const editing = ref(false);
const draft = ref([]);

function startEdit() {
    editing.value = true;
    draft.value = assigned.value.map((group) => group.ulid);
}

function toggle(ulid) {
    const index = draft.value.indexOf(ulid);

    if (index === -1) {
        draft.value.push(ulid);
    } else {
        draft.value.splice(index, 1);
    }
}

function move(index, delta) {
    const target = index + delta;

    if (target < 0 || target >= draft.value.length) {
        return;
    }

    const [item] = draft.value.splice(index, 1);
    draft.value.splice(target, 0, item);
}

function groupOf(ulid) {
    return available.value.find((group) => group.ulid === ulid);
}

const save = useApiForm(async () => {
    // El ORDEN del arreglo es el orden de presentación: se manda tal como está pintado.
    await api.put(`/articles/${props.article.ulid}/modifier-groups`, {
        modifier_group_ulids: draft.value,
    });
});

async function submit() {
    if (await save.submit()) {
        editing.value = false;
        await load();
    }
}

// ---- Receta de un modificador ----

/** `{ modifier, recipe, lines }`; `recipe === null` = todavía no tiene. */
const editingRecipe = ref(null);
const recipeDraft = ref([]);

async function openRecipe(modifier) {
    editingRecipe.value = { modifier, loading: true, recipe: null };
    recipeDraft.value = [];

    try {
        const response = await api.get(`/modifiers/${modifier.ulid}/recipe`);

        editingRecipe.value.recipe = response.data;
        recipeDraft.value = response.data.lines.map((line) => ({
            component_ulid: line.component.ulid,
            component_name: line.component.name,
            quantity: line.quantity,
            unit_ulid: line.unit.ulid,
            yield_percent: line.yield_percent,
        }));
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        // 404 = no tiene receta, que es el caso normal en la mayoría de modificadores.
        if (e.status !== 404) {
            editingRecipe.value.error = e.message;
        }
    } finally {
        editingRecipe.value.loading = false;
    }
}

function addRecipeLine(article) {
    recipeDraft.value.push({
        component_ulid: article.ulid,
        component_name: article.name,
        quantity: '1',
        unit_ulid: article.base_unit?.ulid ?? '',
        yield_percent: '100.00',
    });
}

const saveRecipe = useApiForm(async () => {
    await api.put(`/modifiers/${editingRecipe.value.modifier.ulid}/recipe`, {
        // El rendimiento de la receta lo fija el servidor en 1: un modificador rinde UNA aplicación. Se
        // manda porque el Form Request lo exige —es el mismo de la receta de artículo— y se ignora.
        output_quantity: '1',
        lines: recipeDraft.value.map((line, index) => ({
            component_ulid: line.component_ulid,
            quantity: line.quantity,
            unit_ulid: line.unit_ulid,
            yield_percent: line.yield_percent === '' ? null : line.yield_percent,
            sort_order: index,
        })),
    });
});

const removeRecipe = useApiForm(async () => {
    await api.delete(`/modifiers/${editingRecipe.value.modifier.ulid}/recipe`);
});

async function submitRecipe() {
    if (await saveRecipe.submit()) {
        editingRecipe.value = null;
        await load();
    }
}

async function confirmRemoveRecipe() {
    if (!window.confirm(`¿Quitar la receta de «${editingRecipe.value.modifier.name}»? Volverá a costar cero.`)) {
        return;
    }

    if (await removeRecipe.submit()) {
        editingRecipe.value = null;
        await load();
    }
}

function recipeLineError(index, field) {
    return saveRecipe.fieldErrors.value[`lines.${index}.${field}`];
}
</script>

<template>
    <section class="panel">
        <p v-if="loading" class="muted">Cargando…</p>

        <div v-else-if="error" class="alert">{{ error.message }}</div>

        <template v-else-if="!editing">
            <template v-if="assigned.length">
                <ol class="assigned">
                    <li v-for="group in assigned" :key="group.ulid">
                        <div class="assigned__head">
                            <strong>{{ group.name }}</strong>
                            <span class="badge" :class="group.is_required ? 'badge--warn' : 'badge--off'">
                                {{ group.is_required ? 'Obligatorio' : 'Opcional' }}
                            </span>
                        </div>

                        <ul class="options">
                            <li v-for="modifier in group.modifiers ?? []" :key="modifier.ulid">
                                <span>{{ modifier.name }}</span>
                                <span v-if="modifier.is_paid" class="money">+${{ modifier.extra_price }}</span>
                                <span v-else class="muted">sin costo extra</span>

                                <button
                                    v-if="canWrite('costing.recipes.manage')"
                                    class="link-button"
                                    type="button"
                                    @click="openRecipe(modifier)"
                                ><Icon name="eye" /> Receta</button>
                            </li>
                        </ul>
                    </li>
                </ol>
            </template>

            <p v-else class="muted">
                Este artículo no ofrece modificadores. Los grupos se definen en
                <strong>Catálogo → Modificadores</strong> y se comparten entre artículos.
            </p>

            <button v-if="canWrite('catalog.modifiers.manage')" class="button button--warning" type="button" @click="startEdit"><Icon name="edit" /> Cambiar los grupos que ofrece</button>
        </template>

        <!-- ---- Asignación ---- -->
        <form v-else class="editor" @submit.prevent="submit">
            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <p class="muted small">
                El orden es el que verá quien tome la orden.
            </p>

            <ol v-if="draft.length" class="ordering">
                <li v-for="(ulid, index) in draft" :key="ulid">
                    <span>{{ groupOf(ulid)?.name ?? ulid }}</span>
                    <span class="ordering__actions">
                        <button class="link-button" type="button" :disabled="index === 0" @click="move(index, -1)">
                            ↑
                        </button>
                        <button
                            class="link-button"
                            type="button"
                            :disabled="index === draft.length - 1"
                            @click="move(index, 1)"
                        >
                            ↓
                        </button>
                        <button class="link-button link-button--danger" type="button" @click="toggle(ulid)"><Icon name="trash" /> Quitar</button>
                    </span>
                </li>
            </ol>

            <p v-else class="muted small">Ningún grupo asignado.</p>

            <fieldset class="available">
                <legend class="field__label">Grupos disponibles</legend>

                <p v-if="available.length === 0" class="muted small">
                    No hay grupos activos. Crea el primero en <strong>Catálogo → Modificadores</strong>.
                </p>

                <label v-for="group in available" :key="group.ulid" class="choice">
                    <input type="checkbox" :checked="draft.includes(group.ulid)" @change="toggle(group.ulid)" />
                    <span>
                        {{ group.name }}
                        <span class="muted">· {{ group.modifiers?.length ?? 0 }} opciones</span>
                    </span>
                </label>
            </fieldset>

            <span v-if="save.fieldErrors.value.modifier_group_ulids" class="field__error">
                {{ save.fieldErrors.value.modifier_group_ulids }}
            </span>

            <div class="actions">
                <button type="button" class="link-button" @click="editing = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value"><Icon name="check" /> Guardar</button>
            </div>
        </form>

        <!-- ---- Receta del modificador ---- -->
        <div v-if="editingRecipe" class="drawer-backdrop" @click.self="editingRecipe = null">
            <form class="drawer" @submit.prevent="submitRecipe">
                <h2>Receta de «{{ editingRecipe.modifier.name }}»</h2>
                <p class="drawer__sub">
                    Lo que consume aplicarlo una vez. Sin receta, cuesta cero — que es lo correcto para
                    «término medio» y falso para «extra queso».
                </p>

                <p v-if="editingRecipe.loading" class="muted">Cargando…</p>
                <p v-if="editingRecipe.error" class="alert">{{ editingRecipe.error }}</p>
                <p v-if="saveRecipe.generalError.value" class="alert">{{ saveRecipe.generalError.value }}</p>

                <table v-if="recipeDraft.length" class="lines">
                    <thead>
                        <tr>
                            <th>Insumo</th>
                            <th style="width: 5.5rem">Cantidad</th>
                            <th style="width: 6rem">Unidad</th>
                            <th style="width: 3rem"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in recipeDraft" :key="`${line.component_ulid}-${index}`">
                            <td>
                                {{ line.component_name }}
                                <span v-if="recipeLineError(index, 'component_ulid')" class="field__error">
                                    {{ recipeLineError(index, 'component_ulid') }}
                                </span>
                            </td>
                            <td>
                                <input v-model="line.quantity" class="input input--tight" inputmode="decimal" required />
                                <span v-if="recipeLineError(index, 'quantity')" class="field__error">
                                    {{ recipeLineError(index, 'quantity') }}
                                </span>
                            </td>
                            <td>
                                <select v-model="line.unit_ulid" class="input input--tight" required>
                                    <option v-for="unit in props.units" :key="unit.ulid" :value="unit.ulid">
                                        {{ unit.code }}
                                    </option>
                                </select>
                                <span v-if="recipeLineError(index, 'unit_ulid')" class="field__error">
                                    {{ recipeLineError(index, 'unit_ulid') }}
                                </span>
                            </td>
                            <td>
                                <button
                                    class="link-button link-button--danger"
                                    type="button"
                                    @click="recipeDraft.splice(index, 1)"
                                >
                                    ✕
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="adder">
                    <span class="field__label">Agregar insumo</span>
                    <ArticlePicker :exclude-ulid="props.article.ulid" @picked="addRecipeLine" />
                </div>

                <span v-if="saveRecipe.fieldErrors.value.lines" class="field__error">
                    {{ saveRecipe.fieldErrors.value.lines }}
                </span>

                <div class="drawer__actions">
                    <button
                        v-if="editingRecipe.recipe"
                        type="button"
                        class="link-button link-button--danger"
                        @click="confirmRemoveRecipe"
                    ><Icon name="trash" /> Quitar receta</button>
                    <button type="button" class="link-button" @click="editingRecipe = null"><Icon name="x" /> Cancelar</button>
                    <button
                        type="submit"
                        class="button"
                        :disabled="saveRecipe.processing.value || recipeDraft.length === 0"
                    ><Icon name="check" /> Guardar</button>
                </div>
            </form>
        </div>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.panel,
.editor {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    align-items: flex-start;
    width: 100%;
}

.assigned,
.ordering {
    margin: 0;
    padding-left: 1.2rem;
    width: 100%;
    font-size: 0.88rem;
}

.assigned li + li {
    margin-top: 0.6rem;
}

.assigned__head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.options {
    list-style: none;
    margin: 0.2rem 0 0;
    padding: 0;
}

.options li {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.85rem;
    padding: 0.12rem 0;
}

.ordering li {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.2rem 0;
}

.ordering__actions {
    margin-left: auto;
    display: flex;
    gap: 0.6rem;
}

.available {
    border: 1px solid #e7e5e4;
    border-radius: 0.375rem;
    padding: 0.7rem;
    width: 100%;
    max-width: 28rem;
}

.choice {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.87rem;
    padding: 0.12rem 0;
}

.lines {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    margin-bottom: 0.6rem;
}

.lines th {
    text-align: left;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.5;
    padding: 0.25rem 0.4rem;
    border-bottom: 1px solid #e7e5e4;
}

.lines td {
    padding: 0.3rem 0.4rem;
    border-bottom: 1px solid #f5f5f4;
    vertical-align: top;
}

.input--tight {
    padding: 0.3rem 0.4rem;
    font-size: 0.85rem;
}

.adder {
    width: 100%;
    margin-bottom: 0.9rem;
}

.actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.money {
    font-variant-numeric: tabular-nums;
}

.muted {
    opacity: 0.55;
}

.small {
    font-size: 0.8rem;
    margin: 0;
}

.drawer__sub {
    margin: -0.5rem 0 0.9rem;
    font-size: 0.8rem;
    opacity: 0.65;
}
</style>
