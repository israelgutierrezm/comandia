<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { api, ApiError } from '../../api/client';
import { useAuthorization } from '../../composables/useAuthorization';
import Icon from '../Icon.vue';
import ReportGoalForm from './ReportGoalForm.vue';
import { formatMeasure, goalPeriod } from './format';

/**
 * Un widget de tablero: corre su reporte por el motor y lo pinta según su tipo (número, semáforo, barras, top-N).
 *
 * Las gráficas son **SVG + Vue puro** (ADR-003): sin librería de charting. Cada widget hereda el permiso de su reporte —
 * si el backend responde 403, el widget lo dice en vez de romper el tablero—. El servidor ya devolvió las cifras sumadas
 * y redondeadas (D134): aquí sólo se pintan.
 *
 * ## Semáforo sin meta
 *
 * Si la medida no tiene meta consolidada para el periodo del indicador (`status: no_goal`), el semáforo lo dice —antes
 * sólo quedaba un punto gris y «Sin meta»— y a quien puede fijar metas (`dashboards.goals.manage` escribible) le ofrece
 * fijarla ahí mismo, con la medida y el periodo del indicador ya puestos. Al guardarla avisa al tablero (`goal-saved`),
 * que sube `goalsVersion` y así se vuelven a pintar TODOS sus semáforos: la misma meta puede alimentar a varios.
 */
const props = defineProps({
    widget: { type: Object, required: true },
    // Lo sube el tablero cada vez que se fija una meta: los semáforos vuelven a pedir su estado.
    goalsVersion: { type: Number, default: 0 },
});

const emit = defineEmits(['goal-saved']);

const { canWrite } = useAuthorization();

const loading = ref(true);
const error = ref(null);
const rows = ref([]);
const measures = ref([]);
const dimensions = ref([]);
const semaforo = ref(null);
const settingGoal = ref(false);

const w = props.widget;

onMounted(load);

watch(() => props.goalsVersion, () => {
    if (w.visualization === 'semaforo') load();
});

async function load() {
    loading.value = true;
    error.value = null;

    try {
        // La definición da los formatos y etiquetas de las columnas.
        const def = (await api.get(`/reports/${w.report_key}/definition`)).data;
        measures.value = def.measures;
        dimensions.value = def.dimensions;

        if (w.visualization === 'semaforo') {
            semaforo.value = (await api.get(`/reports/${w.report_key}/goal-status`, {
                measure: w.measure_key,
                period: w.period ?? 'month',
            })).data;
            return;
        }

        // El número es un gran total (sin dimensión): centinela `__total__`, porque el cliente omite `group_by=`.
        const groupBy = w.visualization === 'numero' ? '__total__' : (w.dimension_key ?? '');
        const data = (await api.get(`/reports/${w.report_key}`, { group_by: groupBy })).data;
        rows.value = data.rows;
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
}

function measureFormat(key) {
    return measures.value.find((m) => m.key === key)?.format ?? 'number';
}

// Dinero con `formatMoney` («$15,000.00»): antes se pegaba un «$» a la cadena cruda y la meta, DECIMAL(14,4), se leía
// «$15000.0000».
function fmt(value, format) {
    return formatMeasure(value, format);
}

// --- número ---
const numero = computed(() => (rows.value[0] ? rows.value[0][w.measure_key] : null));

// --- barras / top-N ---
const topRows = computed(() => {
    const limit = w.visualization === 'topn' ? (w.top_n ?? 5) : 8;
    return rows.value.slice(0, limit);
});

const maxValue = computed(() => Math.max(1, ...topRows.value.map((r) => Number(r[w.measure_key]) || 0)));

function barWidth(value) {
    return `${Math.max(1, (Number(value) / maxValue.value) * 100)}%`;
}

const semaforoColor = computed(() => ({
    on_track: 'var(--color-exito)', warning: 'var(--color-aviso)', off_track: 'var(--color-peligro)', no_goal: 'var(--color-suave)',
}[semaforo.value?.status] ?? 'var(--color-suave)'));

const semaforoLabel = computed(() => ({
    on_track: 'En meta', warning: 'Cerca', off_track: 'Fuera de meta', no_goal: 'Sin meta',
}[semaforo.value?.status] ?? '—'));

// --- semáforo sin meta ---
const canSetGoal = computed(() => canWrite('dashboards.goals.manage'));
// El mismo periodo que pide el estado del semáforo (arriba, en `load`): la meta que se fije es la que éste va a leer.
const widgetPeriod = computed(() => goalPeriod(w.period ?? 'month'));
const measureLabel = computed(() => measures.value.find((m) => m.key === w.measure_key)?.label ?? w.measure_key);

function onGoalSaved() {
    settingGoal.value = false;
    emit('goal-saved');
}
</script>

<template>
    <div class="widget">
        <div class="widget__cabecera">
            <h3>{{ w.title }}</h3>
            <!-- Acciones del autor (eliminar): las pone el tablero; aquí sólo tienen su lugar junto al título. Antes flotaban
                 en posición absoluta y se encimaban con los títulos largos. -->
            <slot name="acciones" />
        </div>

        <template v-if="loading"></template>
        <p v-else-if="error" class="err">{{ error }}</p>

        <template v-else>
            <!-- número -->
            <p v-if="w.visualization === 'numero'" class="grande">{{ fmt(numero, measureFormat(w.measure_key)) }}</p>

            <!-- semáforo -->
            <div v-else-if="w.visualization === 'semaforo'">
                <div class="semaforo">
                    <!-- El color repite lo que ya dice el texto de abajo: para un lector de pantalla sobra. -->
                    <span class="punto" :style="{ background: semaforoColor }" aria-hidden="true"></span>
                    <div>
                        <p class="grande">{{ fmt(semaforo?.value, measureFormat(w.measure_key)) }}</p>
                        <p class="muted">
                            {{ semaforoLabel }}<template v-if="semaforo?.target"> · meta {{ fmt(semaforo.target, measureFormat(w.measure_key)) }}</template>
                        </p>
                    </div>
                </div>

                <div v-if="semaforo?.status === 'no_goal'" class="sin-meta">
                    <p class="muted">
                        No hay meta {{ widgetPeriod.adjective }} consolidada para «{{ measureLabel }}»: el semáforo no tiene
                        contra qué comparar.
                    </p>

                    <template v-if="canSetGoal">
                        <ReportGoalForm
                            v-if="settingGoal"
                            fixed-scope
                            :report-key="w.report_key"
                            :measures="measures"
                            :initial="{ measure_key: w.measure_key, period: w.period ?? 'month' }"
                            @saved="onGoalSaved"
                            @cancel="settingGoal = false"
                        />
                        <button v-else type="button" class="link-button" @click="settingGoal = true">
                            <Icon name="plus" /> Fijar meta
                        </button>
                    </template>

                    <p v-else class="muted">Pídele a quien define las metas del negocio que la fije.</p>
                </div>
            </div>

            <!-- barras (SVG puro) -->
            <svg v-else-if="w.visualization === 'barras'" class="barras" :viewBox="`0 0 100 ${topRows.length * 14}`" preserveAspectRatio="none" v-show="topRows.length">
                <g v-for="(r, i) in topRows" :key="i">
                    <rect x="0" :y="i * 14 + 2" :width="barWidth(r[w.measure_key])" height="10" rx="1" fill="var(--color-acento)" />
                </g>
            </svg>

            <ul v-if="w.visualization === 'barras'" class="leyenda">
                <li v-for="(r, i) in topRows" :key="i">
                    <span>{{ r[w.dimension_key] }}</span><strong>{{ fmt(r[w.measure_key], measureFormat(w.measure_key)) }}</strong>
                </li>
            </ul>

            <!-- top-N -->
            <ol v-else-if="w.visualization === 'topn'" class="topn">
                <li v-for="(r, i) in topRows" :key="i">
                    <span>{{ r[w.dimension_key] }}</span><strong>{{ fmt(r[w.measure_key], measureFormat(w.measure_key)) }}</strong>
                </li>
            </ol>

            <p v-if="['barras', 'topn'].includes(w.visualization) && ! topRows.length" class="muted">Sin datos.</p>
        </template>
    </div>
</template>

<style scoped>
/* Botones y campos del «Fijar meta» (el formulario trae los suyos; aquí, el botón que lo abre). */
@import '../../../css/admin-page.css';

/* Superficie por token, no `#fff`: con el tema oscuro la tarjeta quedaba blanca sobre fondo oscuro. */
.widget { border: 1px solid var(--color-borde); border-radius: 8px; padding: 0.9rem 1rem; background: var(--color-superficie); min-height: 8rem; }
.widget__cabecera { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; margin: 0 0 0.5rem; }
.widget h3 { margin: 0; font-size: 0.95rem; }
.grande { font-size: 1.9rem; font-weight: 600; margin: 0.2rem 0; }
.muted { color: var(--color-suave); font-size: 0.85rem; margin: 0.1rem 0; }
.err { color: var(--color-peligro); font-size: 0.85rem; }
.semaforo { display: flex; gap: 0.75rem; align-items: center; }
.sin-meta { display: grid; gap: 0.5rem; justify-items: start; margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px solid var(--color-borde); }
.sin-meta > form { justify-self: stretch; }
.punto { width: 1.1rem; height: 1.1rem; border-radius: 999px; flex: none; }
.barras { width: 100%; height: auto; display: block; }
.leyenda, .topn { list-style: none; margin: 0.4rem 0 0; padding: 0; display: grid; gap: 0.25rem; font-size: 0.85rem; }
.topn { list-style: decimal; padding-left: 1.2rem; }
.leyenda li, .topn li { display: flex; justify-content: space-between; gap: 1rem; }
</style>
