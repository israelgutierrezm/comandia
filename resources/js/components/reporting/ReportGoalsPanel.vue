<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { api, ApiError } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';
import Icon from '../Icon.vue';
import ReportGoalForm from './ReportGoalForm.vue';
import { formatMeasure, goalDirectionLabel, goalPeriod, GOAL_PERIODS } from './format';

/**
 * Metas de UN reporte (D46): listarlas, fijarlas y eliminarlas.
 *
 * Viven en Reportes y no en un tablero porque una meta es de una MEDIDA de un reporte: la misma meta la usan todos los
 * semáforos que miran esa medida, en cualquier tablero. Ver exige `dashboards.goals.manage` (la pantalla no monta esto
 * sin él); fijar y eliminar, además, que el negocio admita escrituras.
 *
 * ## Lo que el semáforo hace hoy con una meta, dicho tal cual
 *
 * Compara lo que va del periodo en curso contra la meta CONSOLIDADA, con una tolerancia fija del 10 % para «Cerca»
 * (`EvaluateGoal`). Las metas por sucursal se guardan, pero el semáforo aún no las usa (`GoalStatusController` pasa
 * `branchId: null`): la lista las marca para que nadie espere verlas en un tablero.
 */
const props = defineProps({
    reportKey: { type: String, required: true },
    // La definición del reporte (`/reports/{key}/definition`): de ella salen las medidas y sus formatos.
    definition: { type: Object, required: true },
});

const { canWrite } = useAuthorization();
const canEdit = computed(() => canWrite('dashboards.goals.manage'));

const uid = useId();
const goals = ref([]);
const branches = ref([]);
const loading = ref(true);
const loadError = ref(null);
const creating = ref(false);

onMounted(load);

async function load() {
    loadError.value = null;

    try {
        // Las sucursales salen de `/context` —las que la membresía alcanza—, no de `/branches`, que es de administración y
        // pide otro permiso: son justo las que el servidor acepta para una meta, y con ellas se nombran las de la lista.
        const [metas, contexto] = await Promise.all([
            api.get('/report-goals', { report: props.reportKey }),
            api.get('/context'),
        ]);

        goals.value = metas.data;
        branches.value = contexto.data.branches ?? [];
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
}

const PERIOD_ORDER = Object.fromEntries(GOAL_PERIODS.map((p, i) => [p.value, i]));

function measureOf(key) {
    return props.definition.measures.find((m) => m.key === key) ?? null;
}

// El servidor sólo lista las metas consolidadas y las de las sucursales al alcance de quien mira. Si una no aparece entre
// las sucursales del contexto (p. ej. una sucursal desactivada), se dice en vez de dejar el renglón sin nombre.
function scopeLabel(goal) {
    if (! goal.branch_ulid) return 'Consolidada';

    return branches.value.find((b) => b.ulid === goal.branch_ulid)?.name ?? 'Sucursal no disponible';
}

function capitalize(text) {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Por medida, luego del periodo más corto al más largo, y la consolidada antes que las de sucursal. */
const rows = computed(() => goals.value
    .map((g) => ({ ...g, measure: measureOf(g.measure_key), scope: scopeLabel(g) }))
    .sort((a, b) => (a.measure?.label ?? a.measure_key).localeCompare(b.measure?.label ?? b.measure_key, 'es')
        || (PERIOD_ORDER[a.period] ?? 9) - (PERIOD_ORDER[b.period] ?? 9)
        || Number(Boolean(a.branch_ulid)) - Number(Boolean(b.branch_ulid))
        || a.scope.localeCompare(b.scope, 'es')));

async function onSaved() {
    creating.value = false;
    await load();
}

const remove = useApiForm(async (goal) => {
    await api.delete(`/report-goals/${goal.ulid}`);
    await load();
}, { success: { kind: 'delete', entity: 'Meta', gender: 'f' } });

async function confirmRemove(goal) {
    const medida = goal.measure?.label ?? goal.measure_key;
    const valor = formatMeasure(goal.target_value, goal.measure?.format);
    const periodo = goalPeriod(goal.period).adjective;

    // Se borra de verdad (no hay papelera). La consecuencia en los tableros depende del alcance: la consolidada es la que
    // leen los semáforos; la de sucursal todavía no la lee ninguno.
    const pregunta = goal.branch_ulid
        ? `¿Eliminar la meta ${periodo} de «${medida}» para «${goal.scope}» (${valor})? Se borra sin papelera; los semáforos de los tableros no cambian, porque todavía no usan las metas por sucursal.`
        : `¿Eliminar la meta ${periodo} consolidada de «${medida}» (${valor})? Se borra sin papelera, y los semáforos de los tableros que miran esa medida mostrarán «Sin meta» hasta que se fije otra.`;

    if (! window.confirm(pregunta)) return;

    await remove.submit(goal);
}
</script>

<template>
    <section class="metas" :aria-labelledby="`${uid}-titulo`">
        <div class="metas__cabecera">
            <h2 :id="`${uid}-titulo`">Metas</h2>
            <button
                v-if="canEdit && definition.measures.length"
                type="button"
                class="link-button"
                :aria-expanded="creating"
                @click="creating = ! creating"
            >
                <Icon name="plus" /> Nueva meta
            </button>
        </div>

        <p class="nota">
            El semáforo de los tableros compara lo que va del periodo en curso contra la meta consolidada; «Cerca» es quedar
            a menos del 10 % de ella.
        </p>

        <div v-if="creating" class="metas__form">
            <ReportGoalForm
                :report-key="reportKey"
                :measures="definition.measures"
                :branches="branches"
                :goals="goals"
                @saved="onSaved"
                @cancel="creating = false"
            />
        </div>

        <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
        <p v-if="remove.generalError.value" class="alert" role="alert">{{ remove.generalError.value }}</p>

        <!-- «Sin metas» sólo cuando la carga terminó bien: mientras carga o si falló, no se sabe si hay. -->
        <p v-if="loading" class="nota">Cargando metas…</p>

        <ul v-else-if="rows.length" class="metas__lista">
            <li v-for="g in rows" :key="g.ulid">
                <span class="metas__desc">
                    <strong>{{ g.measure?.label ?? g.measure_key }}</strong>
                    <span class="nota">{{ capitalize(goalPeriod(g.period).adjective) }} · {{ g.scope }}</span>
                    <span v-if="g.branch_ulid" class="badge badge--off">El semáforo aún no la usa</span>
                </span>
                <span class="metas__valor">
                    {{ formatMeasure(g.target_value, g.measure?.format) }}
                    <small>{{ goalDirectionLabel(g.direction) }}</small>
                </span>
                <button
                    v-if="canEdit"
                    type="button"
                    class="link-button link-button--danger"
                    :disabled="remove.processing.value"
                    @click="confirmRemove(g)"
                >
                    <Icon name="trash" /> Eliminar
                </button>
            </li>
        </ul>

        <p v-else-if="! loadError" class="nota">Este reporte no tiene metas todavía.</p>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.metas {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.1rem 1.25rem;
    display: grid;
    gap: 0.75rem;
}
/* En la rejilla el espacio lo pone el `gap`; el margen propio del aviso lo duplicaría. */
.metas > .alert { margin: 0; }
.metas__cabecera { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.metas__cabecera h2 { margin: 0; font-size: 1rem; font-weight: 650; }
.nota { margin: 0; color: var(--color-suave); font-size: 0.85rem; }
.metas__form { padding: 0.9rem 1rem; border: 1px dashed var(--color-borde); border-radius: var(--radio); }
.metas__lista { list-style: none; margin: 0; padding: 0; display: grid; }
/* Descripción a la izquierda; valor y acción a la derecha. En pantallas angostas bajan a su propia línea. */
.metas__lista li {
    display: flex;
    align-items: center;
    gap: 0.5rem 0.9rem;
    flex-wrap: wrap;
    padding: 0.6rem 0;
    border-top: 1px solid var(--color-borde);
}
.metas__lista li:first-child { border-top: 0; padding-top: 0; }
.metas__desc { flex: 1 1 14rem; min-width: 0; display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.2rem 0.6rem; }
.metas__valor { display: grid; justify-items: end; font-weight: 600; font-variant-numeric: tabular-nums; }
.metas__valor small { font-weight: 400; font-size: 0.75rem; color: var(--color-suave); }
</style>
