<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';
import CostBreakdownLines from './CostBreakdownLines.vue';
import Icon from '../../components/Icon.vue';

/**
 * Costo del artículo: el vigente, el desglose, el historial y el impacto de cambiarlo.
 *
 * ## El impacto se consulta ANTES de guardar
 *
 * Es la parte que no es cosmética. Subir el jitomate de $20 a $60 el kilo cambia el costo de catorce
 * platillos, y quien lo captura tiene derecho a saberlo antes de guardar en lugar de descubrirlo al
 * día siguiente en un reporte de márgenes. El panel lo pide al abrir el formulario, no al terminarlo.
 *
 * ## «Sin costo» no es «cuesta cero»
 *
 * El servidor devuelve `null` cuando no puede calcular, y la pantalla lo dice con palabras. Cero diría
 * que producirlo es gratis, y de ahí saldría un margen del 100 % que el dueño creería.
 *
 * ## El promedio del periodo es referencia, no verdad
 *
 * Se calcula sólo sobre ADQUISICIONES —compras y capturas manuales—, nunca sobre costos calculados en
 * cascada: mezclarlos daría un promedio de números que no son comparables. El costo con el que se
 * valúa es el vigente, no el promedio, y la pantalla lo dice para que nadie los confunda.
 */
const props = defineProps({
    article: { type: Object, required: true },
});

const emit = defineEmits(['changed']);

const { can, canWrite } = useAuthorization();

const current = ref(null);
const breakdown = ref(null);
const history = ref([]);
const error = ref(null);
const loading = ref(true);

async function load() {
    loading.value = true;
    error.value = null;

    const [cost, bd, hist] = await Promise.all([
        api.get(`/articles/${props.article.ulid}/cost`).catch((e) => e),
        api.get(`/articles/${props.article.ulid}/cost-breakdown`).catch((e) => e),
        can('costing.costs.history.view')
            ? api.get(`/articles/${props.article.ulid}/costs`, { per_page: 20 }).catch((e) => e)
            : Promise.resolve(null),
    ]);

    if (cost instanceof ApiError) {
        error.value = cost;
    } else {
        current.value = cost.data;
    }

    breakdown.value = bd instanceof ApiError ? null : bd.data;
    history.value = hist === null || hist instanceof ApiError ? [] : (hist.data ?? []);

    loading.value = false;
}

onMounted(load);

// ---- Captura de costo ----

const capturing = ref(false);
const form = ref({ mode: 'unit', unit_cost: '', presentation_ulid: '', total_cost: '', notes: '' });
const presentations = ref([]);
const impact = ref(null);

const save = useApiForm(async () => {
    // Dos formas de capturar y se manda UNA. El servidor las valida como excluyentes: mandar las dos
    // sería pedirle que adivine cuál gana.
    const body =
        form.value.mode === 'unit'
            ? { unit_cost: form.value.unit_cost }
            : { presentation_ulid: form.value.presentation_ulid, total_cost: form.value.total_cost };

    await api.post(`/articles/${props.article.ulid}/costs`, {
        ...body,
        notes: form.value.notes === '' ? null : form.value.notes,
    });
});

async function startCapture() {
    capturing.value = true;
    form.value = { mode: 'unit', unit_cost: '', presentation_ulid: '', total_cost: '', notes: '' };

    // Las presentaciones para poder capturar «pagué $180 por la caja» en lugar de dividir a mano; y el
    // impacto, para saber a qué le va a mover esto antes de guardar.
    const [pres, imp] = await Promise.all([
        api.get(`/articles/${props.article.ulid}/presentations`).catch(() => ({ data: [] })),
        can('costing.recipes.view')
            ? api.get(`/articles/${props.article.ulid}/impact`).catch(() => null)
            : Promise.resolve(null),
    ]);

    presentations.value = (pres.data ?? []).filter((p) => p.status === 'active');
    impact.value = imp === null ? null : imp.data;
}

async function submit() {
    if (await save.submit()) {
        capturing.value = false;
        await load();
        emit('changed');
    }
}

/** El costo unitario que resultaría de «pagué $X por la presentación Y», calculado para verlo antes. */
const previewUnitCost = computed(() => {
    if (form.value.mode !== 'presentation' || form.value.total_cost === '') {
        return null;
    }

    const presentation = presentations.value.find((p) => p.ulid === form.value.presentation_ulid);
    const quantity = Number(presentation?.quantity_in_base_unit ?? 0);
    const total = Number(form.value.total_cost);

    if (!quantity || !Number.isFinite(total)) {
        return null;
    }

    // Sólo para VER. El cálculo que cuenta lo hace el servidor con aritmética decimal: este número
    // usa punto flotante y podría diferir en el último decimal, así que no se manda a ninguna parte.
    return (total / quantity).toFixed(4);
});
</script>

<template>
    <section class="panel">
        <p v-if="loading" class="muted">Cargando…</p>

        <div v-else-if="error" class="alert">
            <template v-if="error.isForbidden">
                Tu rol no tiene permiso para ver costos. El costo es información del negocio: quien
                cobra no necesita saber cuánto se gana.
            </template>
            <template v-else>{{ error.message }}</template>
        </div>

        <template v-else>
            <div class="grid">
                <div class="figure">
                    <span class="figure__label">Costo unitario vigente</span>
                    <strong class="figure__value">
                        <template v-if="current?.unit_cost !== null && current?.unit_cost !== undefined">
                            ${{ current.unit_cost }}
                        </template>
                        <span v-else class="muted">Sin costo</span>
                    </strong>
                    <span class="figure__hint">
                        por {{ props.article.base_unit?.code ?? 'unidad base' }}
                        <template v-if="current?.effective_at">
                            · desde {{ new Date(current.effective_at).toLocaleDateString('es-MX') }}
                        </template>
                    </span>
                </div>

                <div class="figure">
                    <span class="figure__label">Promedio de {{ current?.period_days ?? 0 }} días</span>
                    <strong class="figure__value">
                        <template v-if="current?.period_average">${{ current.period_average }}</template>
                        <span v-else class="muted">—</span>
                    </strong>
                    <span class="figure__hint">
                        Sólo compras y capturas. <strong>Es referencia</strong>: lo que valúa es el
                        vigente.
                    </span>
                </div>
            </div>

            <button
                v-if="canWrite('costing.costs.update')"
                class="button"
                type="button"
                @click="startCapture"
            ><Icon name="plus" /> Capturar costo</button>

            <!-- ---- Desglose ---- -->
            <template v-if="breakdown">
                <h3 class="subtitle">Desglose del cálculo</h3>

                <p v-if="!breakdown.is_computable" class="warn">
                    <strong>No se puede calcular.</strong>
                    <template v-if="breakdown.missing_costs?.length">
                        Falta el costo de: {{ breakdown.missing_costs.join(', ') }}. Captúralos y el costo
                        de este artículo se recalcula solo.
                    </template>
                    <template v-else>
                        Este artículo no tiene receta, así que su costo se captura a mano en lugar de
                        calcularse.
                    </template>
                </p>

                <template v-if="breakdown.lines?.length">
                    <table class="breakdown">
                        <thead>
                            <tr>
                                <th>Componente</th>
                                <th class="num">Cantidad</th>
                                <th class="num">En unidad base</th>
                                <th class="num">Costo unitario</th>
                                <th class="num">Rendimiento</th>
                                <th class="num">Costo de línea</th>
                            </tr>
                        </thead>
                        <tbody>
                            <CostBreakdownLines :lines="breakdown.lines" />
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5">
                                    Costo de la tanda completa, que rinde
                                    {{ breakdown.batch_yield_in_base_unit }}
                                    {{ props.article.base_unit?.code ?? '' }}
                                </td>
                                <td class="num">
                                    <strong v-if="breakdown.batch_cost !== null">${{ breakdown.batch_cost }}</strong>
                                    <span v-else class="muted">—</span>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="5">
                                    Costo por {{ props.article.base_unit?.code ?? 'unidad' }}
                                    <span class="muted">(tanda ÷ rendimiento)</span>
                                </td>
                                <td class="num">
                                    <strong v-if="breakdown.unit_cost !== null">${{ breakdown.unit_cost }}</strong>
                                    <span v-else class="muted">—</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </template>
            </template>

            <!-- ---- Historial inmutable ---- -->
            <template v-if="can('costing.costs.history.view')">
                <h3 class="subtitle">Historial de costos</h3>

                <p v-if="history.length === 0" class="muted small">Todavía no hay costos registrados.</p>

                <table v-else class="breakdown">
                    <thead>
                        <tr>
                            <th>Vigente desde</th>
                            <th class="num">Costo</th>
                            <th>Origen</th>
                            <th>Quién</th>
                            <th>Notas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in history" :key="entry.ulid">
                            <td class="nowrap">{{ new Date(entry.effective_at).toLocaleString('es-MX') }}</td>
                            <td class="num">${{ entry.unit_cost }}</td>
                            <td>
                                <span class="badge" :class="entry.is_acquisition ? 'badge--warn' : 'badge--off'">
                                    {{ entry.origin_label }}
                                </span>
                            </td>
                            <!-- Sin actor = lo calculó un job. No se inventa uno: un actor falso en un
                                 registro de evidencia es indistinguible de uno real. -->
                            <td>{{ entry.actor?.name ?? 'Sistema' }}</td>
                            <td>{{ entry.notes ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </template>

        <!-- ---- Captura ---- -->
        <div v-if="capturing" class="drawer-backdrop" @click.self="capturing = false">
            <form class="drawer" @submit.prevent="submit">
                <h2>Capturar costo de {{ props.article.name }}</h2>

                <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

                <div class="modes">
                    <label class="mode">
                        <input v-model="form.mode" type="radio" value="unit" />
                        <span>Por unidad base ({{ props.article.base_unit?.code ?? '' }})</span>
                    </label>
                    <label class="mode">
                        <input v-model="form.mode" type="radio" value="presentation" :disabled="presentations.length === 0" />
                        <span>
                            Por presentación de compra
                            <span v-if="presentations.length === 0" class="muted">(no hay ninguna)</span>
                        </span>
                    </label>
                </div>

                <label v-if="form.mode === 'unit'" class="field">
                    <span class="field__label">Costo por {{ props.article.base_unit?.code ?? 'unidad' }}</span>
                    <input v-model="form.unit_cost" class="input" inputmode="decimal" required />
                    <span v-if="save.fieldErrors.value.unit_cost" class="field__error">
                        {{ save.fieldErrors.value.unit_cost }}
                    </span>
                </label>

                <template v-else>
                    <label class="field">
                        <span class="field__label">Presentación</span>
                        <select v-model="form.presentation_ulid" class="input" required>
                            <option value="">Elige una…</option>
                            <option v-for="p in presentations" :key="p.ulid" :value="p.ulid">
                                {{ p.name }} — {{ p.quantity_in_base_unit }}
                                {{ props.article.base_unit?.code ?? '' }}
                            </option>
                        </select>
                        <span v-if="save.fieldErrors.value.presentation_ulid" class="field__error">
                            {{ save.fieldErrors.value.presentation_ulid }}
                        </span>
                    </label>

                    <label class="field">
                        <span class="field__label">Lo que se pagó por ella</span>
                        <input v-model="form.total_cost" class="input" inputmode="decimal" required />
                        <span v-if="previewUnitCost" class="field__hint">
                            Quedaría en <strong>${{ previewUnitCost }}</strong> por
                            {{ props.article.base_unit?.code ?? 'unidad' }} — el cálculo exacto lo hace el
                            servidor.
                        </span>
                        <span v-if="save.fieldErrors.value.total_cost" class="field__error">
                            {{ save.fieldErrors.value.total_cost }}
                        </span>
                    </label>
                </template>

                <label class="field">
                    <span class="field__label">Notas</span>
                    <input v-model="form.notes" class="input" maxlength="200" placeholder="Factura 4471, proveedor Central" />
                    <span v-if="save.fieldErrors.value.notes" class="field__error">
                        {{ save.fieldErrors.value.notes }}
                    </span>
                </label>

                <!-- Lo que se va a mover, ANTES de guardar. -->
                <div v-if="impact && impact.total > 0" class="impact">
                    <strong>Esto va a recalcular {{ impact.total }} artículo(s):</strong>
                    <ul>
                        <li v-for="dependent in impact.dependents.slice(0, 8)" :key="dependent.ulid">
                            {{ dependent.name }}
                            <span v-if="dependent.is_direct" class="muted">· directo</span>
                            <span v-else class="muted">· en cascada</span>
                        </li>
                    </ul>
                    <p v-if="impact.total > 8" class="muted small">y {{ impact.total - 8 }} más.</p>
                    <p class="small">
                        El recálculo ocurre en segundo plano y no bloquea nada. Los precios
                        <strong>no</strong> cambian solos: el sistema sugiere y la persona decide.
                    </p>
                </div>

                <p v-else-if="impact" class="muted small">
                    Ningún otro artículo depende de este costo.
                </p>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="capturing = false"><Icon name="x" /> Cancelar</button>
                    <button type="submit" class="button" :disabled="save.processing.value"><Icon name="check" /> Guardar costo</button>
                </div>
            </form>
        </div>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.panel {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    align-items: flex-start;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: 0.75rem;
    width: 100%;
}

.figure {
    background: #fafaf9;
    border: 1px solid #e7e5e4;
    border-radius: 0.375rem;
    padding: 0.6rem 0.75rem;
}

.figure__label {
    display: block;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.55;
}

.figure__value {
    display: block;
    font-size: 1.25rem;
    font-variant-numeric: tabular-nums;
    margin-top: 0.15rem;
}

.figure__hint {
    display: block;
    font-size: 0.72rem;
    opacity: 0.6;
    margin-top: 0.15rem;
}

.subtitle {
    margin: 0.6rem 0 0;
    font-size: 0.95rem;
}

.breakdown {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}

.breakdown th {
    text-align: left;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.5;
    padding: 0.3rem 0.5rem;
    border-bottom: 1px solid #e7e5e4;
}

.breakdown th.num {
    text-align: right;
}

.breakdown td {
    padding: 0.4rem 0.5rem;
    border-bottom: 1px solid #f5f5f4;
}

.breakdown tfoot td {
    border-top: 2px solid #e7e5e4;
    border-bottom: 0;
    font-size: 0.85rem;
}

.num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.nowrap {
    white-space: nowrap;
}

.muted {
    opacity: 0.55;
}

.small {
    font-size: 0.8rem;
    margin: 0.25rem 0 0;
}

.warn {
    width: 100%;
    margin: 0;
    padding: 0.7rem 0.85rem;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 0.375rem;
    font-size: 0.85rem;
    color: #92400e;
}

.modes {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin-bottom: 0.9rem;
}

.mode {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.88rem;
}

.impact {
    margin: 0 0 0.9rem;
    padding: 0.7rem 0.85rem;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 0.375rem;
    font-size: 0.82rem;
    color: #78350f;
}

.impact ul {
    margin: 0.35rem 0 0;
    padding-left: 1.1rem;
}
</style>
