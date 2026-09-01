<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';
import ArticlePicker from './ArticlePicker.vue';
import Icon from '../../components/Icon.vue';

/**
 * Receta del artículo (D16, D21).
 *
 * ## Se guarda entera, siempre
 *
 * `PUT` reemplaza la receta completa, y la pantalla trabaja igual: se edita una copia local y se manda
 * de una vez. Es lo correcto y no una comodidad — la detección de ciclos se evalúa sobre el estado
 * FINAL de la receta, así que guardar línea por línea permitiría estados intermedios que el sistema
 * tendría que aceptar y que después no cerrarían.
 *
 * ## El ciclo se explica, no se anuncia
 *
 * Si la receta se cierra sobre sí misma —la salsa lleva mole y el mole lleva salsa— el servidor
 * responde 409 con la RUTA del ciclo. La pantalla la muestra tal cual: sin ella, «receta circular» deja
 * al usuario buscando a mano en veinte líneas cuál de ellas cierra el círculo.
 *
 * ## El rendimiento de la receta no es el rendimiento del insumo
 *
 * Son dos números distintos con nombres parecidos, y confundirlos deja costos mal:
 *
 *   - **El rendimiento de la receta** es cuánto sale de una tanda: 4 litros de salsa. Divide el costo
 *     total, y es lo que hace que el costo sea *por litro* y no *por tanda*.
 *   - **El rendimiento del insumo** (D21) es cuánto de lo que se compra llega al plato: de 1 kg de
 *     jitomate, el 85 % — el resto es cáscara y semilla. Encarece la línea, porque hay que comprar más
 *     para tener lo mismo.
 */
const props = defineProps({
    article: { type: Object, required: true },
    units: { type: Array, required: true },
});

const emit = defineEmits(['changed']);

const { canWrite } = useAuthorization();

const recipe = ref(null);
const loading = ref(true);
const error = ref(null);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        recipe.value = (await api.get(`/articles/${props.article.ulid}/recipe`)).data;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        // 404 es «no tiene receta», que es un estado normal y no un error: casi ningún artículo tiene
        // receta. Se distingue de un 403 o un 500, que sí hay que mostrar.
        if (e.status === 404) {
            recipe.value = null;
        } else {
            error.value = e;
        }
    } finally {
        loading.value = false;
    }
}

onMounted(load);

// ---- Edición ----

const editing = ref(false);
const draft = ref({ output_quantity: '1', output_unit_ulid: '', notes: '', lines: [] });

function startEdit() {
    editing.value = true;

    draft.value = recipe.value
        ? {
              output_quantity: recipe.value.output_quantity,
              output_unit_ulid: recipe.value.output_unit?.ulid ?? '',
              notes: recipe.value.notes ?? '',
              lines: recipe.value.lines.map((line) => ({
                  component_ulid: line.component.ulid,
                  component_name: line.component.name,
                  quantity: line.quantity,
                  unit_ulid: line.unit.ulid,
                  yield_percent: line.yield_percent,
              })),
          }
        : {
              output_quantity: '1',
              // Por omisión rinde en la unidad base del propio artículo, que es el caso normal: una
              // salsa que se mide en litros rinde litros.
              output_unit_ulid: props.article.base_unit?.ulid ?? '',
              notes: '',
              lines: [],
          };
}

function addLine(article) {
    draft.value.lines.push({
        component_ulid: article.ulid,
        component_name: article.name,
        quantity: '1',
        unit_ulid: article.base_unit?.ulid ?? '',
        yield_percent: '100.00',
    });
}

function removeLine(index) {
    draft.value.lines.splice(index, 1);
}

const save = useApiForm(async () => {
    await api.put(`/articles/${props.article.ulid}/recipe`, {
        output_quantity: draft.value.output_quantity,
        output_unit_ulid: draft.value.output_unit_ulid === '' ? null : draft.value.output_unit_ulid,
        notes: draft.value.notes === '' ? null : draft.value.notes,
        lines: draft.value.lines.map((line, index) => ({
            component_ulid: line.component_ulid,
            quantity: line.quantity,
            unit_ulid: line.unit_ulid,
            yield_percent: line.yield_percent === '' ? null : line.yield_percent,
            sort_order: index,
        })),
    });
});

const remove = useApiForm(async () => {
    await api.delete(`/articles/${props.article.ulid}/recipe`);
});

async function submit() {
    if (await save.submit()) {
        editing.value = false;
        await load();
        emit('changed');
    }
}

async function confirmRemove() {
    if (
        !window.confirm(
            `¿Quitar la receta de «${props.article.name}»? Su costo dejará de calcularse y habrá que ` +
                'capturarlo a mano.',
        )
    ) {
        return;
    }

    if (await remove.submit()) {
        await load();
        emit('changed');
    }
}

/** Errores por línea: el servidor los devuelve como `lines.0.quantity`, y así se pintan donde toca. */
function lineError(index, field) {
    return save.fieldErrors.value[`lines.${index}.${field}`];
}

const unitsByDimension = computed(() => {
    const groups = {};

    for (const unit of props.units) {
        groups[unit.dimension_label] ??= [];
        groups[unit.dimension_label].push(unit);
    }

    return groups;
});
</script>

<template>
    <section class="panel">
        <template v-if="loading"></template>

        <div v-else-if="error" class="alert">
            <template v-if="error.isForbidden">Tu rol no tiene permiso para ver recetas.</template>
            <template v-else>{{ error.message }}</template>
        </div>

        <template v-else-if="!editing">
            <template v-if="recipe">
                <p class="yieldline">
                    Una tanda rinde
                    <strong>{{ recipe.output_quantity }} {{ recipe.output_unit?.code ?? '' }}</strong>
                    <span class="muted"> · {{ recipe.lines.length }} ingrediente(s)</span>
                </p>

                <table class="lines">
                    <thead>
                        <tr>
                            <th>Ingrediente</th>
                            <th class="num">Cantidad</th>
                            <th class="num">Rendimiento del insumo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in recipe.lines" :key="`${line.component.ulid}-${line.sort_order}`">
                            <td>{{ line.component.name }}</td>
                            <td class="num">{{ line.quantity }} {{ line.unit.code }}</td>
                            <td class="num">
                                <span v-if="line.yield_percent !== '100.00'" class="yield">
                                    {{ line.yield_percent }} %
                                </span>
                                <span v-else class="muted">Íntegro</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-if="recipe.notes" class="notes">{{ recipe.notes }}</p>

                <p class="muted small">
                    El costo se calcula a partir de esta receta y se actualiza solo cuando cambia el costo
                    de cualquier ingrediente, por profundo que esté. El desglose está en la pestaña
                    <strong>Costo</strong>.
                </p>

                <div class="actions">
                    <button v-if="canWrite('costing.recipes.manage')" class="button button--warning" type="button" @click="startEdit"><Icon name="edit" /> Editar receta</button>
                    <button
                        v-if="canWrite('costing.recipes.manage')"
                        class="link-button link-button--danger"
                        type="button"
                        @click="confirmRemove"
                    ><Icon name="trash" /> Quitar receta</button>
                </div>

                <p v-if="remove.generalError.value" class="alert">{{ remove.generalError.value }}</p>
            </template>

            <template v-else>
                <p class="muted">
                    Este artículo no tiene receta, así que su costo se captura a mano. Con receta, el costo
                    se calcula a partir de lo que consume y se recalcula solo cuando cambia el costo de
                    cualquier ingrediente.
                </p>

                <button v-if="canWrite('costing.recipes.manage')" class="button" type="button" @click="startEdit"><Icon name="plus" /> Crear receta</button>
            </template>
        </template>

        <!-- ---- Editor ---- -->
        <form v-else class="editor" @submit.prevent="submit">
            <p v-if="save.generalError.value" class="alert">
                {{ save.generalError.value }}
            </p>

            <div class="yieldfields">
                <label class="field">
                    <span class="field__label">Una tanda rinde</span>
                    <input v-model="draft.output_quantity" class="input" inputmode="decimal" required />
                    <span v-if="save.fieldErrors.value.output_quantity" class="field__error">
                        {{ save.fieldErrors.value.output_quantity }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">en</span>
                    <select v-model="draft.output_unit_ulid" class="input">
                        <option value="">Unidad base del artículo</option>
                        <optgroup v-for="(group, label) in unitsByDimension" :key="label" :label="label">
                            <option v-for="unit in group" :key="unit.ulid" :value="unit.ulid">
                                {{ unit.name }} ({{ unit.code }})
                            </option>
                        </optgroup>
                    </select>
                    <span v-if="save.fieldErrors.value.output_unit_ulid" class="field__error">
                        {{ save.fieldErrors.value.output_unit_ulid }}
                    </span>
                </label>
            </div>

            <p class="hint">
                Es cuánto sale de preparar la receta una vez. Divide el costo de la tanda, y es lo que
                hace que el costo salga <strong>por unidad</strong> y no por tanda.
            </p>

            <table class="lines">
                <thead>
                    <tr>
                        <th>Ingrediente</th>
                        <th style="width: 7rem">Cantidad</th>
                        <th style="width: 9rem">Unidad</th>
                        <th style="width: 7rem">Rendimiento</th>
                        <th style="width: 3rem"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in draft.lines" :key="`${line.component_ulid}-${index}`">
                        <td>
                            {{ line.component_name }}
                            <span v-if="lineError(index, 'component_ulid')" class="field__error">
                                {{ lineError(index, 'component_ulid') }}
                            </span>
                        </td>
                        <td>
                            <input v-model="line.quantity" class="input input--tight" inputmode="decimal" required />
                            <span v-if="lineError(index, 'quantity')" class="field__error">
                                {{ lineError(index, 'quantity') }}
                            </span>
                        </td>
                        <td>
                            <select v-model="line.unit_ulid" class="input input--tight" required>
                                <option v-for="unit in props.units" :key="unit.ulid" :value="unit.ulid">
                                    {{ unit.code }}
                                </option>
                            </select>
                            <!--
                                La unidad tiene que ser de la misma magnitud que la unidad base del
                                ingrediente: los gramos no se vuelven mililitros. El servidor lo rechaza
                                nombrando las dos unidades, y ese mensaje aparece aquí.
                            -->
                            <span v-if="lineError(index, 'unit_ulid')" class="field__error">
                                {{ lineError(index, 'unit_ulid') }}
                            </span>
                        </td>
                        <td>
                            <input v-model="line.yield_percent" class="input input--tight" inputmode="decimal" />
                            <span v-if="lineError(index, 'yield_percent')" class="field__error">
                                {{ lineError(index, 'yield_percent') }}
                            </span>
                        </td>
                        <td>
                            <button class="link-button link-button--danger" type="button" @click="removeLine(index)">
                                ✕
                            </button>
                        </td>
                    </tr>

                    <tr v-if="draft.lines.length === 0">
                        <td colspan="5" class="muted">
                            Una receta necesita al menos un ingrediente.
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-if="save.fieldErrors.value.lines" class="field__error">{{ save.fieldErrors.value.lines }}</p>

            <p class="hint">
                <strong>Rendimiento del insumo:</strong> qué porcentaje de lo que se compra llega al plato.
                De 1 kg de jitomate llega el 85 %; el resto es cáscara y semilla. Encarece la línea, porque
                hay que comprar más para tener lo mismo. Déjalo en 100 si se usa íntegro.
            </p>

            <div class="adder">
                <span class="field__label">Agregar ingrediente</span>
                <ArticlePicker :exclude-ulid="props.article.ulid" @picked="addLine" />
            </div>

            <label class="field">
                <span class="field__label">Notas de preparación</span>
                <textarea v-model="draft.notes" class="input" rows="2" maxlength="500"></textarea>
                <span v-if="save.fieldErrors.value.notes" class="field__error">
                    {{ save.fieldErrors.value.notes }}
                </span>
            </label>

            <div class="actions">
                <button type="button" class="link-button" @click="editing = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value || draft.lines.length === 0"><Icon name="check" /> Guardar receta</button>
            </div>
        </form>
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

.yieldline {
    margin: 0;
    font-size: 0.95rem;
}

.yieldfields {
    display: grid;
    grid-template-columns: 8rem 1fr;
    gap: 0.75rem;
    max-width: 26rem;
    width: 100%;
}

.lines {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.lines th {
    text-align: left;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.5;
    padding: 0.3rem 0.5rem;
    border-bottom: 1px solid #e7e5e4;
}

.lines th.num,
.lines td.num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.lines td {
    padding: 0.35rem 0.5rem;
    border-bottom: 1px solid #f5f5f4;
    vertical-align: top;
}

.input--tight {
    padding: 0.3rem 0.4rem;
    font-size: 0.85rem;
}

.adder {
    max-width: 26rem;
    width: 100%;
}

.hint {
    margin: 0;
    font-size: 0.8rem;
    opacity: 0.65;
    max-width: 46rem;
}

.notes {
    margin: 0;
    padding: 0.5rem 0.7rem;
    background: #fafaf9;
    border-left: 3px solid #e7e5e4;
    font-size: 0.85rem;
}

.actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.muted {
    opacity: 0.55;
}

.small {
    font-size: 0.8rem;
}

.yield {
    color: #92400e;
}
</style>
