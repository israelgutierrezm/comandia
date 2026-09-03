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
 * se puede pedir; el frontend sólo lo pinta. Un reporte nuevo aparece aquí sin tocar el frontend.
 *
 * Además deja **guardar** la configuración actual como una vista y **exportar** el resultado (PDF/Excel/CSV), que corre en
 * cola: aparece en «Descargas» y se baja cuando está listo.
 */
const reports = ref([]);
const selected = ref('');
const definition = ref(null);
const grouping = ref([]);
const dateFilters = ref({});
const result = ref(null);
const loadError = ref(null);
const running = ref(false);

const savedViews = ref([]);
const viewName = ref('');
const exports = ref([]);

const schedules = ref([]);
const scheduleForm = ref({ format: 'pdf', frequency: 'daily', recipients: '' });

const rangeFilters = computed(() => (definition.value?.filters ?? []).filter((f) => f.operator === 'date_range'));

onMounted(async () => {
    const { data } = await api.get('/reports');
    reports.value = data;
    await Promise.all([loadExports(), loadSchedules()]);
});

async function choose(key) {
    selected.value = key;
    definition.value = null;
    result.value = null;
    loadError.value = null;
    savedViews.value = [];

    const { data } = await api.get(`/reports/${key}/definition`);
    definition.value = data;
    grouping.value = [...data.default_grouping];
    dateFilters.value = Object.fromEntries(
        data.filters.filter((f) => f.operator === 'date_range').map((f) => [f.key, { from: '', to: '' }]),
    );

    await Promise.all([run(), loadSavedViews()]);
}

/** Los parámetros de la consulta actual: agrupación + rangos de fecha con valor. */
function currentParams() {
    const params = { group_by: grouping.value.join(',') };

    for (const [key, range] of Object.entries(dateFilters.value)) {
        if (range.from) params[`${key}_from`] = range.from;
        if (range.to) params[`${key}_to`] = range.to;
    }

    return params;
}

async function run() {
    if (! selected.value) return;

    running.value = true;
    loadError.value = null;

    try {
        const { data } = await api.get(`/reports/${selected.value}`, currentParams());
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

// --- Vistas guardadas ---
async function loadSavedViews() {
    const { data } = await api.get(`/reports/${selected.value}/views`);
    savedViews.value = data;
}

const saveView = async () => {
    if (! viewName.value.trim()) return;

    await api.post(`/reports/${selected.value}/views`, { name: viewName.value, ...currentParams() });
    viewName.value = '';
    await loadSavedViews();
};

function applyView(view) {
    grouping.value = [...view.group_by];

    for (const key of Object.keys(dateFilters.value)) {
        dateFilters.value[key] = { from: view.filters[`${key}_from`] ?? '', to: view.filters[`${key}_to`] ?? '' };
    }

    run();
}

async function deleteView(ulid) {
    await api.delete(`/report-views/${ulid}`);
    await loadSavedViews();
}

// --- Exportación ---
async function loadExports() {
    const { data } = await api.get('/report-exports');
    exports.value = data;
}

async function requestExport(format) {
    await api.post(`/reports/${selected.value}/exports`, { format, ...currentParams() });
    await loadExports();
}

const ESTADO_EXPORT = { pending: 'En proceso', ready: 'Listo', failed: 'Falló' };

// --- Reportes programados ---
const FRECUENCIAS = { daily: 'Diario', weekly: 'Semanal (lunes)', monthly: 'Mensual (día 1)' };

async function loadSchedules() {
    const { data } = await api.get('/scheduled-reports');
    schedules.value = data;
}

/** Los correos capturados: separados por coma o salto de línea, sin espacios ni vacíos. */
function parsedRecipients() {
    return scheduleForm.value.recipients
        .split(/[\n,]/)
        .map((e) => e.trim())
        .filter(Boolean);
}

async function createSchedule() {
    const recipients = parsedRecipients();
    if (! selected.value || ! recipients.length) return;

    await api.post('/scheduled-reports', {
        report_key: selected.value,
        format: scheduleForm.value.format,
        frequency: scheduleForm.value.frequency,
        group_by: grouping.value.join(','),
        recipients,
    });

    scheduleForm.value.recipients = '';
    await loadSchedules();
}

async function runSchedule(ulid) {
    await api.post(`/scheduled-reports/${ulid}/run`);
    await loadExports();
}

async function deleteSchedule(ulid) {
    await api.delete(`/scheduled-reports/${ulid}`);
    await loadSchedules();
}
</script>

<template>
    <Head title="Reportes" />

    <h1 class="titulo-pantalla">Reportes</h1>

    <div class="reportes">
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

            <ul v-if="savedViews.length" class="vistas">
                <li v-for="v in savedViews" :key="v.ulid">
                    <button type="button" class="chip" @click="applyView(v)">{{ v.name }}</button>
                    <button type="button" class="enlace" @click="deleteView(v.ulid)">×</button>
                </li>
            </ul>

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

            <div class="guardar">
                <input v-model="viewName" type="text" maxlength="80" placeholder="Nombre de la vista" />
                <button type="button" class="enlace" :disabled="! viewName.trim()" @click="saveView()">Guardar vista</button>
            </div>

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
                        <td v-for="m in result.columns.measures" :key="m.key" class="der">
                            {{ m.format === 'money' ? `$${row[m.key]}` : m.format === 'percent' ? `${row[m.key]}%` : row[m.key] }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else-if="result" class="nota">Sin datos para los filtros elegidos.</p>

            <div v-if="result && result.rows.length" class="exportar">
                <span>Exportar:</span>
                <button type="button" class="enlace" @click="requestExport('csv')">CSV</button>
                <button type="button" class="enlace" @click="requestExport('xlsx')">Excel</button>
                <button type="button" class="enlace" @click="requestExport('pdf')">PDF</button>
            </div>

            <form class="programar" @submit.prevent="createSchedule()">
                <h3>Programar envío por correo</h3>
                <p class="nota">Se enviará el periodo cerrado (ayer / la semana pasada / el mes pasado) con la agrupación de arriba.</p>
                <div class="fila">
                    <label>Frecuencia
                        <select v-model="scheduleForm.frequency">
                            <option v-for="(txt, key) in FRECUENCIAS" :key="key" :value="key">{{ txt }}</option>
                        </select>
                    </label>
                    <label>Formato
                        <select v-model="scheduleForm.format">
                            <option value="pdf">PDF</option>
                            <option value="xlsx">Excel</option>
                            <option value="csv">CSV</option>
                        </select>
                    </label>
                </div>
                <label>Destinatarios (uno por línea o separados por coma)
                    <textarea v-model="scheduleForm.recipients" rows="2" placeholder="jefe@negocio.mx, contador@negocio.mx"></textarea>
                </label>
                <button type="submit" :disabled="! parsedRecipients().length">Programar</button>
            </form>
        </section>

        <section v-if="schedules.length" class="panel">
            <h2>Reportes programados</h2>
            <ul class="descargas">
                <li v-for="s in schedules" :key="s.ulid">
                    {{ s.label }} ({{ s.format.toUpperCase() }}) — {{ FRECUENCIAS[s.frequency] ?? s.frequency }}
                    → {{ s.recipients.join(', ') }}
                    <span v-if="s.last_run_on" class="nota">· último: {{ s.last_run_on }}</span>
                    <button type="button" class="enlace" @click="runSchedule(s.ulid)">correr ahora</button>
                    <button type="button" class="enlace" @click="deleteSchedule(s.ulid)">borrar</button>
                </li>
            </ul>
        </section>

        <section v-if="exports.length" class="panel">
            <h2>Descargas <button type="button" class="enlace" @click="loadExports()">actualizar</button></h2>
            <ul class="descargas">
                <li v-for="e in exports" :key="e.ulid">
                    {{ e.label }} ({{ e.format.toUpperCase() }}) — {{ ESTADO_EXPORT[e.status] ?? e.status }}
                    <a v-if="e.status === 'ready'" :href="`/api/v1/report-exports/${e.ulid}/download`">descargar</a>
                    <span v-if="e.status === 'failed'" class="error">{{ e.error }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.titulo-pantalla { margin: 0 0 0.25rem; font-size: 1.6rem; font-weight: 600; letter-spacing: -0.02em; line-height: 1.15; }
.reportes { display: grid; gap: 1rem; }
.selector label { display: grid; gap: 0.3rem; max-width: 22rem; font-size: 0.9rem; }
/* Igualado a la paleta del sistema (antes: borde #d6d6d6, chips y azules #06c/#eef3ff fuera de paleta). */
.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.1rem 1.25rem;
}
.panel h2 { margin-top: 0; display: flex; gap: 0.75rem; align-items: baseline; }
.vistas { list-style: none; margin: 0 0 0.75rem; padding: 0; display: flex; flex-wrap: wrap; gap: 0.5rem; }
.vistas li { display: flex; align-items: center; gap: 0.2rem; }
.chip {
    background: color-mix(in srgb, var(--color-acento) 12%, transparent);
    border: 1px solid color-mix(in srgb, var(--color-acento) 30%, transparent);
    color: var(--color-acento);
    border-radius: 999px; padding: 0.15rem 0.7rem; font-size: 0.85rem; font-weight: 600; cursor: pointer;
}
.controles { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 0.75rem; }
fieldset { border: 1px solid var(--color-borde); border-radius: var(--radio); display: flex; gap: 0.75rem; flex-wrap: wrap; }
legend { font-size: 0.85rem; color: var(--color-contenido); padding: 0 0.4rem; }
.chk { display: flex; gap: 0.3rem; align-items: center; font-size: 0.9rem; }
label { font-size: 0.85rem; display: grid; gap: 0.2rem; }
.guardar { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1rem; }
.guardar input { padding: 0.3rem 0.5rem; }
.tabla { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.tabla th, .tabla td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--color-borde); }
.tabla th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-suave); }
.tabla .der { text-align: right; font-variant-numeric: tabular-nums; }
.exportar { margin-top: 0.75rem; display: flex; gap: 0.75rem; align-items: center; font-size: 0.9rem; }
.programar { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--color-borde); display: grid; gap: 0.5rem; max-width: 32rem; }
.programar h3 { margin: 0; font-size: 1rem; }
.programar .fila { display: flex; gap: 1rem; flex-wrap: wrap; }
.programar textarea { padding: 0.35rem 0.5rem; font: inherit; resize: vertical; }
.programar button[type="submit"] { justify-self: start; }
.descargas { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.35rem; font-size: 0.9rem; }
.descargas a { margin-left: 0.4rem; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.error { color: var(--color-peligro); }
.enlace { background: none; border: 0; color: var(--color-acento); cursor: pointer; padding: 0; font: inherit; }
.enlace:disabled { color: var(--color-suave); cursor: default; }
</style>
