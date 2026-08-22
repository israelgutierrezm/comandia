<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

/**
 * Reportes (§6.7, ADR-006).
 *
 * ## Una sola pantalla para TODOS los reportes
 *
 * No hay una pantalla por reporte: hay un motor. Esta pantalla lista los reportes que el rol activo puede ver, y al elegir
 * uno se **autoconfigura** desde su definición (`/definition`) —filtros, agrupaciones y columnas—. El backend decide qué
 * se puede pedir; el frontend sólo lo pinta. Un reporte nuevo aparece aquí sin tocar el frontend (ese es el punto de
 * ADR-006).
 */
const reports = ref([]);
const selected = ref('');
const definition = ref(null);
const grouping = ref([]);
const dateFilters = ref({}); // { filterKey: { from, to } }
const result = ref(null);
const loadError = ref(null);
const running = ref(false);

onMounted(async () => {
    const { data } = await api.get('/reports');
    reports.value = data;
});

/** Los filtros de rango de fecha declarados por el reporte (los únicos que la UI pinta en v1). */
const rangeFilters = computed(() => (definition.value?.filters ?? []).filter((f) => f.operator === 'date_range'));

async function choose(key) {
    selected.value = key;
    definition.value = null;
    result.value = null;
    loadError.value = null;

    const { data } = await api.get(`/reports/${key}/definition`);
    definition.value = data;
    grouping.value = [...data.default_grouping];
    dateFilters.value = Object.fromEntries(
        data.filters.filter((f) => f.operator === 'date_range').map((f) => [f.key, { from: '', to: '' }]),
    );

    await run();
}

async function run() {
    if (! selected.value) {
        return;
    }

    running.value = true;
    loadError.value = null;

    try {
        const query = { group_by: grouping.value.join(',') };

        for (const [key, range] of Object.entries(dateFilters.value)) {
            if (range.from) query[`${key}_from`] = range.from;
            if (range.to) query[`${key}_to`] = range.to;
        }

        const { data } = await api.get(`/reports/${selected.value}`, query);
        result.value = data;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e; else throw e;
        result.value = null;
    } finally {
        running.value = false;
    }
}

function toggleGroup(key) {
    const i = grouping.value.indexOf(key);
    i === -1 ? grouping.value.push(key) : grouping.value.splice(i, 1);
}

/** Cada medida se presenta según su formato; el dinero ya viene sumado y redondeado del servidor (D134). */
function formatCell(value, format) {
    if (value === null || value === undefined) return '—';
    if (format === 'money') return `$${value}`;
    if (format === 'percent') return `${value}%`;
    return String(value);
}
</script>

<template>
    <Head title="Reportes" />

    <div class="reportes">
        <h1>Reportes</h1>

        <p v-if="! reports.length" class="nota">No hay reportes disponibles para tu rol.</p>

        <div v-else class="selector">
            <label>
                Reporte
                <select :value="selected" @change="choose($event.target.value)">
                    <option value="" disabled>Elige un reporte…</option>
                    <option v-for="r in reports" :key="r.key" :value="r.key">{{ r.label }}</option>
                </select>
            </label>
        </div>

        <section v-if="definition" class="panel">
            <h2>{{ definition.label }}</h2>

            <form class="controles" @submit.prevent="run()">
                <fieldset v-if="definition.groupings.length">
                    <legend>Agrupar por</legend>
                    <label v-for="d in definition.dimensions" :key="d.key" class="chk">
                        <input type="checkbox" :checked="grouping.includes(d.key)" @change="toggleGroup(d.key)" />
                        {{ d.label }}
                    </label>
                </fieldset>

                <fieldset v-for="f in rangeFilters" :key="f.key">
                    <legend>Rango de fechas</legend>
                    <label>Desde <input v-model="dateFilters[f.key].from" type="date" /></label>
                    <label>Hasta <input v-model="dateFilters[f.key].to" type="date" /></label>
                </fieldset>

                <button type="submit" :disabled="running || ! grouping.length">Ver</button>
            </form>

            <p v-if="loadError" class="error">{{ loadError.title }}</p>

            <table v-if="result && result.rows.length" class="tabla">
                <thead>
                    <tr>
                        <th v-for="d in result.columns.dimensions" :key="d.key">{{ d.label }}</th>
                        <th v-for="m in result.columns.measures" :key="m.key" class="der">{{ m.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in result.rows" :key="i">
                        <td v-for="d in result.columns.dimensions" :key="d.key">{{ row[d.key] }}</td>
                        <td v-for="m in result.columns.measures" :key="m.key" class="der">{{ formatCell(row[m.key], m.format) }}</td>
                    </tr>
                </tbody>
            </table>

            <p v-else-if="result" class="nota">Sin datos para los filtros elegidos.</p>
        </section>
    </div>
</template>

<style scoped>
.reportes { display: grid; gap: 1rem; max-width: 60rem; }
.reportes h1 { margin: 0; }
.selector label { display: grid; gap: 0.3rem; max-width: 22rem; font-size: 0.9rem; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
.panel h2 { margin-top: 0; }
.controles { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1rem; }
fieldset { border: 1px solid #e2e2e2; border-radius: 6px; display: flex; gap: 0.75rem; flex-wrap: wrap; }
legend { font-size: 0.85rem; color: #444; padding: 0 0.4rem; }
.chk { display: flex; gap: 0.3rem; align-items: center; font-size: 0.9rem; }
label { font-size: 0.85rem; display: grid; gap: 0.2rem; }
.tabla { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.tabla th, .tabla td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #eee; }
.tabla .der { text-align: right; }
.nota { color: #555; font-size: 0.9rem; }
.error { color: #a11; }
</style>
