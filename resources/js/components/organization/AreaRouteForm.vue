<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { api } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import ArticlePicker from '../catalog/ArticlePicker.vue';
import FormHeader from '../FormHeader.vue';
import Icon from '../Icon.vue';
import { categoryLabel, categoryRuleFor, indexCategories, splitRules } from './areaRouting';

/**
 * Alta de una regla de ruteo a un área de preparación (D240), en UNA sucursal.
 *
 * ## Una regla apunta a una categoría o a un artículo, nunca a los dos
 *
 * Es lo que exige el servidor (y un CHECK en la base): con los dos, la misma pregunta tendría dos respuestas. Por eso
 * el formulario pregunta primero QUÉ se manda y sólo muestra el campo que corresponde.
 *
 * ## Lo que ya tiene regla no se ofrece
 *
 * Hay una regla por categoría y una por artículo en cada sucursal (índices únicos). No hay edición: cambiar a dónde va
 * «Bebidas» es quitar su regla y crear otra. Así que la categoría o el artículo que ya tiene regla se muestra
 * deshabilitado, diciendo a dónde va hoy, en lugar de dejar que el servidor rechace el alta.
 *
 * ## El área la valida el servidor
 *
 * Tiene que ser de la MISMA sucursal y estar activa; si no, responde 422. Aquí sólo se ofrecen ésas, pero la palabra
 * final es suya.
 */
const props = defineProps({
    branch: { type: Object, required: true },

    // Áreas ACTIVAS de esta sucursal, en su orden.
    areas: { type: Array, required: true },

    // Árbol de categorías (raíces con `children`) y si el rol pudo consultarlo.
    categories: { type: Array, default: () => [] },
    catalogAvailable: { type: Boolean, default: true },
    catalogError: { type: String, default: null },

    // Reglas vigentes de ESTA sucursal.
    rules: { type: Array, default: () => [] },
});

const emit = defineEmits(['saved', 'cancel']);

const ids = { category: useId(), article: useId(), area: useId() };
const formRef = ref(null);

const index = computed(() => indexCategories(props.categories));
const split = computed(() => splitRules(props.rules));

/** Categorías activas, en el orden del árbol, marcando las que ya tienen regla en esta sucursal. */
const categoryOptions = computed(() => {
    const options = [];

    for (const root of props.categories) {
        if (root.status !== 'active') {
            continue;
        }

        for (const node of [root, ...(root.children ?? []).filter((child) => child.status === 'active')]) {
            options.push({
                ulid: node.ulid,
                label: categoryLabel(node, index.value),
                takenBy: split.value.byCategory.get(node.ulid)?.preparation_area?.name ?? null,
            });
        }
    }

    return options;
});

const kind = ref(categoryOptions.value.length > 0 ? 'category' : 'article');
const categoryUlid = ref('');
const article = ref(null);
const areaUlid = ref('');

const chosenCategory = computed(() => categoryOptions.value.find((option) => option.ulid === categoryUlid.value) ?? null);
const chosenArea = computed(() => props.areas.find((area) => area.ulid === areaUlid.value) ?? null);

/** Si el artículo elegido ya tiene regla propia en esta sucursal, a qué área va. */
const articleTakenBy = computed(() => {
    if (!article.value) {
        return null;
    }

    return split.value.byArticle.get(article.value.ulid)?.preparation_area?.name ?? null;
});

/**
 * Qué pasa HOY con lo elegido, según la precedencia (previsualización, v. `areaRouting.js`): es lo que hace entender
 * para qué sirve la regla — «Cervezas hoy va a Cocina por la regla de Bebidas; con ésta irá a la que elijas».
 */
function currentDestination(categoryUlidToCheck) {
    const rule = categoryRuleFor(categoryUlidToCheck, index.value, split.value.byCategory);

    if (!rule) {
        return null;
    }

    return { area: rule.preparation_area?.name ?? '—', via: rule.category?.name ?? '' };
}

const categoryPreview = computed(() => {
    if (!chosenCategory.value || chosenCategory.value.takenBy) {
        return null;
    }

    const today = currentDestination(chosenCategory.value.ulid);

    if (today) {
        return `Hoy va a ${today.area} por la regla de «${today.via}». Con esta regla irá al área que elijas.`;
    }

    const parent = index.value.get(chosenCategory.value.ulid)?.parent;

    return parent
        ? `Hoy no genera comanda en esta sucursal: ni ella ni «${parent.name}» tienen regla.`
        : 'Hoy no genera comanda en esta sucursal: no tiene regla.';
});

const articlePreview = computed(() => {
    if (!article.value || articleTakenBy.value) {
        return null;
    }

    if (!article.value.category) {
        return 'No tiene categoría, así que hoy no genera comanda en esta sucursal. Con esta regla irá al área que elijas.';
    }

    const today = currentDestination(article.value.category.ulid);

    return today
        ? `Hoy va a ${today.area} por la regla de «${today.via}». Con esta regla irá al área que elijas, sin importar su categoría.`
        : `Hoy no genera comanda en esta sucursal: su categoría («${article.value.category.name}») no tiene regla.`;
});

const canSubmit = computed(() => {
    if (!props.catalogAvailable || !areaUlid.value) {
        return false;
    }

    if (kind.value === 'category') {
        return chosenCategory.value !== null && !chosenCategory.value.takenBy;
    }

    return article.value !== null && !articleTakenBy.value;
});

const save = useApiForm(
    async () => {
        await api.post('/pos-area-routes', {
            branch_ulid: props.branch.ulid,
            preparation_area_ulid: areaUlid.value,
            ...(kind.value === 'category'
                ? { article_category_ulid: categoryUlid.value }
                : { article_ulid: article.value?.ulid }),
        });
    },
    { success: { kind: 'create', entity: 'Regla de ruteo' } },
);

async function submit() {
    if (!canSubmit.value) {
        return;
    }

    if (await save.submit()) {
        emit('saved');
    }
}

function cancel() {
    if (!save.processing.value) {
        emit('cancel');
    }
}

// El foco entra al formulario: en la opción marcada de «¿Qué quieres mandar?». Dos consultas y no una lista de
// selectores: `querySelector('a, b')` devuelve el primero en el documento, no el primero de la lista.
onMounted(() => (formRef.value?.querySelector('input:checked') ?? formRef.value?.querySelector('select, input'))?.focus());
</script>

<template>
    <div class="drawer-backdrop" @click.self="cancel">
        <form ref="formRef" class="drawer" role="dialog" aria-modal="true" aria-label="Nueva regla de ruteo" @submit.prevent="submit">
            <FormHeader title="Nueva regla de ruteo" :subtitle="`Sucursal: ${props.branch.name}`" />

            <p v-if="save.generalError.value" class="alert" role="alert">{{ save.generalError.value }}</p>

            <p v-if="props.catalogError" class="alert" role="alert">
                No se pudo cargar el catálogo, así que no se puede elegir qué mandar. Detalle: {{ props.catalogError }}
            </p>
            <p v-else-if="!props.catalogAvailable" class="alert alert--notice" role="alert">
                Tu rol no puede consultar el catálogo (categorías y artículos), así que desde aquí no se puede elegir qué
                mandar. Pide a alguien con ese permiso que cree la regla.
            </p>

            <fieldset class="field kind">
                <legend class="field__label">¿Qué quieres mandar?</legend>

                <label class="kind__option">
                    <input v-model="kind" type="radio" name="route-kind" value="category" />
                    <span>
                        Una categoría completa
                        <span class="field__hint">Lo normal: «Bebidas → Barra» cubre sus artículos y sus subcategorías sin regla propia.</span>
                    </span>
                </label>

                <label class="kind__option">
                    <input v-model="kind" type="radio" name="route-kind" value="article" />
                    <span>
                        Un artículo en particular
                        <span class="field__hint">La excepción: gana sobre la regla de su categoría.</span>
                    </span>
                </label>
            </fieldset>

            <div v-if="kind === 'category'" class="field">
                <label class="field__label" :for="ids.category">Categoría</label>
                <select :id="ids.category" v-model="categoryUlid" class="input" required :disabled="!props.catalogAvailable">
                    <option value="" disabled>Elige una categoría…</option>
                    <option v-for="option in categoryOptions" :key="option.ulid" :value="option.ulid" :disabled="option.takenBy !== null">
                        {{ option.label }}{{ option.takenBy !== null ? ` — ya va a ${option.takenBy}` : '' }}
                    </option>
                </select>
                <span v-if="props.catalogAvailable && categoryOptions.length === 0" class="field__hint">
                    Todavía no hay categorías activas en el catálogo: rutea por artículo o crea las categorías primero.
                </span>
                <span v-else class="field__hint">
                    Las que ya tienen regla aparecen deshabilitadas: para cambiarles el área, quita su regla y crea otra.
                </span>
                <span v-if="categoryPreview" class="field__hint field__hint--preview">{{ categoryPreview }}</span>
                <span v-if="save.fieldErrors.value.article_category_ulid" class="field__error">
                    {{ save.fieldErrors.value.article_category_ulid }}
                </span>
            </div>

            <div v-else class="field">
                <label class="field__label" :for="ids.article">Artículo</label>

                <div v-if="article" class="chosen">
                    <span class="chosen__name">{{ article.name }}</span>
                    <button type="button" class="link-button" @click="article = null">Cambiar</button>
                </div>
                <ArticlePicker
                    v-else-if="props.catalogAvailable"
                    :input-id="ids.article"
                    capability="sellable"
                    :supply-hint="false"
                    placeholder="Buscar artículo vendible…"
                    @picked="(picked) => (article = picked)"
                />
                <input v-else :id="ids.article" class="input" disabled placeholder="Sin acceso al catálogo" />

                <span v-if="articleTakenBy" class="field__error">
                    Este artículo ya tiene regla en esta sucursal: va a {{ articleTakenBy }}. Para cambiarla, quítala y crea otra.
                </span>
                <span v-else-if="articlePreview" class="field__hint field__hint--preview">{{ articlePreview }}</span>
                <span v-if="save.fieldErrors.value.article_ulid" class="field__error">{{ save.fieldErrors.value.article_ulid }}</span>
            </div>

            <div class="field">
                <label class="field__label" :for="ids.area">Área de preparación</label>
                <select :id="ids.area" v-model="areaUlid" class="input" required>
                    <option value="" disabled>Elige un área…</option>
                    <option v-for="area in props.areas" :key="area.ulid" :value="area.ulid">
                        {{ area.name }}{{ area.printer ? '' : ' (sin impresora)' }}
                    </option>
                </select>
                <span class="field__hint">Sólo las áreas activas de {{ props.branch.name }}: una comanda no cruza de sucursal.</span>
                <span v-if="chosenArea && !chosenArea.printer" class="field__hint field__hint--preview">
                    «{{ chosenArea.name }}» no tiene impresora:
                    {{ chosenArea.uses_kds
                        ? 'sus comandas no saldrán en papel, sólo en el tablero de cocina.'
                        : 'sus comandas no saldrán en papel ni en el tablero de cocina; nadie las verá.' }}
                </span>
                <span v-if="save.fieldErrors.value.preparation_area_ulid" class="field__error">
                    {{ save.fieldErrors.value.preparation_area_ulid }}
                </span>
                <span v-if="save.fieldErrors.value.branch_ulid" class="field__error">{{ save.fieldErrors.value.branch_ulid }}</span>
            </div>

            <div class="drawer__actions">
                <button type="button" class="link-button" :disabled="save.processing.value" @click="cancel">
                    <Icon name="x" /> Cancelar
                </button>
                <button type="submit" class="button" :disabled="!canSubmit || save.processing.value">
                    <Icon name="check" /> {{ save.processing.value ? 'Guardando…' : 'Crear regla' }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.kind {
    border: 0;
    padding: 0;
    min-width: 0;
}

.kind__option {
    display: flex;
    align-items: flex-start;
    gap: 0.55rem;
    padding: 0.45rem 0;
    font-size: 0.9rem;
    cursor: pointer;
}

.kind__option input {
    margin-top: 0.15rem;
}

/* Lo que pasa HOY con lo elegido: se distingue de la ayuda fija porque cambia con la selección. */
.field__hint--preview {
    padding: 0.4rem 0.55rem;
    border-left: 3px solid var(--color-acento);
    background: color-mix(in srgb, var(--color-acento) 7%, transparent);
    color: var(--color-contenido);
}

.chosen {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    padding: 0.45rem 0.65rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-sm);
    background: var(--color-fondo);
}

.chosen__name {
    min-width: 0;
    overflow-wrap: anywhere;
    font-weight: 500;
}
</style>
