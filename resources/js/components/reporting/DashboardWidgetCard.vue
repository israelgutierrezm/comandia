<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';

/**
 * Un widget de tablero: corre su reporte por el motor y lo pinta según su tipo (número, semáforo, barras, top-N).
 *
 * Las gráficas son **SVG + Vue puro** (ADR-003): sin librería de charting. Cada widget hereda el permiso de su reporte —
 * si el backend responde 403, el widget lo dice en vez de romper el tablero—. El servidor ya devolvió las cifras sumadas
 * y redondeadas (D134): aquí sólo se pintan.
 */
const props = defineProps({ widget: { type: Object, required: true } });

const loading = ref(true);
const error = ref(null);
const rows = ref([]);
const measures = ref([]);
const dimensions = ref([]);
const semaforo = ref(null);

const w = props.widget;

onMounted(load);

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

function fmt(value, format) {
    if (value === null || value === undefined) return '—';
    if (format === 'money') return `$${value}`;
    if (format === 'percent') return `${value}%`;
    return String(value);
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
    on_track: '#137333', warning: '#b06000', off_track: '#a11', no_goal: '#888',
}[semaforo.value?.status] ?? '#888'));

const semaforoLabel = computed(() => ({
    on_track: 'En meta', warning: 'Cerca', off_track: 'Fuera de meta', no_goal: 'Sin meta',
}[semaforo.value?.status] ?? '—'));
</script>

<template>
    <div class="widget">
        <h3>{{ w.title }}</h3>

        <p v-if="loading" class="muted">Cargando…</p>
        <p v-else-if="error" class="err">{{ error }}</p>

        <template v-else>
            <!-- número -->
            <p v-if="w.visualization === 'numero'" class="grande">{{ fmt(numero, measureFormat(w.measure_key)) }}</p>

            <!-- semáforo -->
            <div v-else-if="w.visualization === 'semaforo'" class="semaforo">
                <span class="punto" :style="{ background: semaforoColor }"></span>
                <div>
                    <p class="grande">{{ fmt(semaforo?.value, measureFormat(w.measure_key)) }}</p>
                    <p class="muted">
                        {{ semaforoLabel }}<template v-if="semaforo?.target"> · meta {{ fmt(semaforo.target, measureFormat(w.measure_key)) }}</template>
                    </p>
                </div>
            </div>

            <!-- barras (SVG puro) -->
            <svg v-else-if="w.visualization === 'barras'" class="barras" :viewBox="`0 0 100 ${topRows.length * 14}`" preserveAspectRatio="none" v-show="topRows.length">
                <g v-for="(r, i) in topRows" :key="i">
                    <rect x="0" :y="i * 14 + 2" :width="barWidth(r[w.measure_key])" height="10" rx="1" fill="#4a7dc4" />
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
.widget { border: 1px solid #e2e2e2; border-radius: 8px; padding: 0.9rem 1rem; background: #fff; min-height: 8rem; }
.widget h3 { margin: 0 0 0.5rem; font-size: 0.95rem; }
.grande { font-size: 1.9rem; font-weight: 600; margin: 0.2rem 0; }
.muted { color: #666; font-size: 0.85rem; margin: 0.1rem 0; }
.err { color: #a11; font-size: 0.85rem; }
.semaforo { display: flex; gap: 0.75rem; align-items: center; }
.punto { width: 1.1rem; height: 1.1rem; border-radius: 999px; flex: none; }
.barras { width: 100%; height: auto; display: block; }
.leyenda, .topn { list-style: none; margin: 0.4rem 0 0; padding: 0; display: grid; gap: 0.25rem; font-size: 0.85rem; }
.topn { list-style: decimal; padding-left: 1.2rem; }
.leyenda li, .topn li { display: flex; justify-content: space-between; gap: 1rem; }
</style>
