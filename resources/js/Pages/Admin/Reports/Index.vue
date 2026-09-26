<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError, orEmptyWhenForbidden } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';
import ReportGoalsPanel from '../../../components/reporting/ReportGoalsPanel.vue';
import { formatMeasure } from '../../../components/reporting/format';

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
 * cola: aparece en «Descargas» y se baja cuando está listo. Y administra las **metas** del reporte, contra las que compara
 * el semáforo de los tableros (`ReportGoalsPanel`).
 *
 * ## Cada acción, sólo a quien la puede hacer
 *
 * Vistas, programados y metas tienen permiso fijo en su ruta (`reporting.saved_views.manage`, `reporting.schedules.manage`,
 * `dashboards.goals.manage`), y las escrituras van con `can.write`. Antes «Guardar vista» y «Programar» se ofrecían a
 * cualquiera que viera el reporte y el clic respondía 403.
 */
const { can, canWrite } = useAuthorization();
const canSaveViews = computed(() => canWrite('reporting.saved_views.manage'));
const canSchedule = computed(() => canWrite('reporting.schedules.manage'));
const canSeeGoals = computed(() => can('dashboards.goals.manage'));

// El subtítulo promete sólo lo que el rol activo puede hacer.
const subtitle = computed(() => {
    const actions = [
        canSaveViews.value && 'guarda la vista',
        'expórtala',
        canSchedule.value && 'programa su envío por correo',
        canWrite('dashboards.goals.manage') && 'fija sus metas',
    ].filter(Boolean);

    const list = actions.length > 1 ? `${actions.slice(0, -1).join(', ')} o ${actions.at(-1)}` : actions[0];

    return `Elige un reporte, agrúpalo y filtra por fechas. ${list.charAt(0).toUpperCase()}${list.slice(1)}.`;
});

const reports = ref([]);
const loading = ref(true);
// Falla al cargar la pantalla (catálogo, definición, descargas). La de la consulta del reporte vive aparte, en `loadError`.
const error = ref(null);
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
const exportsError = ref(null);

const schedules = ref([]);
const scheduleForm = ref({ format: 'pdf', frequency: 'daily', recipients: '' });

const rangeFilters = computed(() => (definition.value?.filters ?? []).filter((f) => f.operator === 'date_range'));

// Si la carga falla se dice: antes la pantalla quedaba con «No hay reportes disponibles para tu rol», que es mentira
// cuando lo que pasó es que no se pudo preguntar.
onMounted(async () => {
    try {
        reports.value = (await api.get('/reports')).data;
        await Promise.all([loadExports(), loadSchedules()]);
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
});

async function choose(key) {
    selected.value = key;
    definition.value = null;
    result.value = null;
    loadError.value = null;
    error.value = null;
    savedViews.value = [];

    try {
        const { data } = await api.get(`/reports/${key}/definition`);
        definition.value = data;
        grouping.value = [...data.default_grouping];
        dateFilters.value = Object.fromEntries(
            data.filters.filter((f) => f.operator === 'date_range').map((f) => [f.key, { from: '', to: '' }]),
        );

        await Promise.all([run(), loadSavedViews()]);
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    }
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
// Lista opcional: sin `reporting.saved_views.manage` no se pide (sería un 403 seguro), y si el permiso cambió desde que
// cargó el shell, el 403 es «no hay vistas», no una falla.
async function loadSavedViews() {
    if (! can('reporting.saved_views.manage')) {
        savedViews.value = [];
        return;
    }

    const { data } = await orEmptyWhenForbidden(api.get(`/reports/${selected.value}/views`));
    savedViews.value = data;
}

const saveView = useApiForm(async () => {
    await api.post(`/reports/${selected.value}/views`, { name: viewName.value, ...currentParams() });
    viewName.value = '';
    await loadSavedViews();
}, { success: { kind: 'create', entity: 'Vista', gender: 'f' } });

function applyView(view) {
    grouping.value = [...view.group_by];

    for (const key of Object.keys(dateFilters.value)) {
        dateFilters.value[key] = { from: view.filters[`${key}_from`] ?? '', to: view.filters[`${key}_to`] ?? '' };
    }

    run();
}

const removeView = useApiForm(async (view) => {
    await api.delete(`/report-views/${view.ulid}`);
    await loadSavedViews();
}, { success: { kind: 'delete', entity: 'Vista', gender: 'f' } });

async function confirmRemoveView(view) {
    // Se borra de verdad (no hay papelera), así que se pregunta; y se aclara que el reporte en sí no se toca.
    if (! window.confirm(`¿Eliminar la vista «${view.name}»? Sólo se borra la configuración guardada; el reporte y sus datos no cambian.`)) {
        return;
    }

    await removeView.submit(view);
}

// --- Exportación ---
async function loadExports() {
    const { data } = await api.get('/report-exports');
    exports.value = data;
}

/** «Actualizar» de Descargas: si falla, se dice junto al botón en vez de perderse en la consola. */
async function refreshExports() {
    exportsError.value = null;

    try {
        await loadExports();
    } catch (e) {
        if (e instanceof ApiError) exportsError.value = e.title; else throw e;
    }
}

// Corre en cola: el aviso dice dónde aparecerá el archivo, porque «Descargas» puede quedar fuera de la vista.
const exportReport = useApiForm(async (format) => {
    await api.post(`/reports/${selected.value}/exports`, { format, ...currentParams() });
    await loadExports();
}, { success: 'Exportación en proceso: aparecerá en «Descargas» cuando esté lista.' });

const ESTADO_EXPORT = { pending: 'En proceso', ready: 'Listo', failed: 'Falló' };

// --- Reportes programados ---
const FRECUENCIAS = { daily: 'Diario', weekly: 'Semanal (lunes)', monthly: 'Mensual (día 1)' };

// Opcional como las vistas: sin `reporting.schedules.manage` simplemente no hay programados que mostrar.
async function loadSchedules() {
    if (! can('reporting.schedules.manage')) return;

    const { data } = await orEmptyWhenForbidden(api.get('/scheduled-reports'));
    schedules.value = data;
}

/** Los correos capturados: separados por coma o salto de línea, sin espacios ni vacíos. */
function parsedRecipients() {
    return scheduleForm.value.recipients
        .split(/[\n,]/)
        .map((e) => e.trim())
        .filter(Boolean);
}

// La guarda de «sin destinatarios» vive en el formulario (botón deshabilitado y `@submit`), no aquí: dentro del envío, un
// `return` temprano contaría como éxito y lanzaría el aviso de «creado».
const createSchedule = useApiForm(async () => {
    await api.post('/scheduled-reports', {
        report_key: selected.value,
        format: scheduleForm.value.format,
        frequency: scheduleForm.value.frequency,
        group_by: grouping.value.join(','),
        recipients: parsedRecipients(),
    });

    scheduleForm.value.recipients = '';
    await loadSchedules();
}, { success: { kind: 'create', entity: 'Reporte programado', gender: 'm' } });

// «Enviar ahora» encola el mismo job del scheduler: genera el periodo cerrado, lo manda a los destinatarios y además lo
// deja en «Descargas». El botón lo dice así porque el efecto es un correo real a otras personas.
const runSchedule = useApiForm(async (schedule) => {
    await api.post(`/scheduled-reports/${schedule.ulid}/run`);
    await loadExports();
}, { success: 'Envío en proceso: llegará a los destinatarios y quedará en «Descargas».' });

const removeSchedule = useApiForm(async (schedule) => {
    await api.delete(`/scheduled-reports/${schedule.ulid}`);
    await loadSchedules();
}, { success: { kind: 'delete', entity: 'Reporte programado', gender: 'm' } });

async function confirmRemoveSchedule(schedule) {
    if (! window.confirm(`¿Eliminar el envío programado de «${schedule.label}»? Dejará de llegar por correo a ${schedule.recipients.join(', ')}.`)) {
        return;
    }

    await removeSchedule.submit(schedule);
}
</script>

<template>
    <Head title="Reportes" />

    <div class="reportes">
        <ListHeader title="Reportes" :subtitle="subtitle" />

        <p v-if="error" class="alert" role="alert">{{ error }}</p>

        <!-- El vacío sólo se afirma cuando la carga terminó bien: mientras carga o si falló, no se sabe. -->
        <p v-if="loading" class="nota">Cargando…</p>
        <p v-else-if="! reports.length && ! error" class="nota">No hay reportes disponibles para tu rol.</p>

        <div v-else-if="reports.length" class="selector">
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
                    <button
                        v-if="canSaveViews"
                        type="button"
                        class="link-button link-button--danger vista__eliminar"
                        :aria-label="`Eliminar la vista «${v.name}»`"
                        :title="`Eliminar la vista «${v.name}»`"
                        :disabled="removeView.processing.value"
                        @click="confirmRemoveView(v)"
                    ><Icon name="x" /></button>
                </li>
            </ul>
            <p v-if="removeView.generalError.value" class="alert" role="alert">{{ removeView.generalError.value }}</p>

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

                <button type="submit" class="button" :disabled="running || ! grouping.length">Ver</button>
            </form>

            <template v-if="canSaveViews">
                <div class="guardar">
                    <input v-model="viewName" type="text" maxlength="80" placeholder="Nombre de la vista" aria-label="Nombre de la vista" />
                    <button
                        type="button"
                        class="button button--ghost"
                        :disabled="saveView.processing.value || ! viewName.trim()"
                        @click="viewName.trim() && saveView.submit()"
                    >Guardar vista</button>
                </div>
                <p v-if="saveView.generalError.value" class="alert" role="alert">{{ saveView.generalError.value }}</p>
            </template>

            <p v-if="loadError" class="alert" role="alert">{{ loadError.title }}</p>

            <!-- Con muchas columnas la tabla desborda: se desplaza dentro de su marco, no arrastra la página entera. -->
            <div v-if="result && result.rows.length" class="tabla-marco">
                <table class="tabla">
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
                                {{ formatMeasure(row[m.key], m.format) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else-if="result" class="nota">Sin datos para los filtros elegidos.</p>

            <div v-if="result && result.rows.length" class="exportar">
                <span>Exportar:</span>
                <button type="button" class="link-button" :disabled="exportReport.processing.value" @click="exportReport.submit('csv')">CSV</button>
                <button type="button" class="link-button" :disabled="exportReport.processing.value" @click="exportReport.submit('xlsx')">Excel</button>
                <button type="button" class="link-button" :disabled="exportReport.processing.value" @click="exportReport.submit('pdf')">PDF</button>
            </div>
            <p v-if="exportReport.generalError.value" class="alert" role="alert">{{ exportReport.generalError.value }}</p>

            <form v-if="canSchedule" class="programar" @submit.prevent="parsedRecipients().length && createSchedule.submit()">
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
                <p v-if="createSchedule.generalError.value" class="alert" role="alert">{{ createSchedule.generalError.value }}</p>
                <button type="submit" class="button" :disabled="createSchedule.processing.value || ! parsedRecipients().length">Programar</button>
            </form>
        </section>

        <!-- Una por reporte: al cambiar de reporte se monta de nuevo y pide las metas del nuevo. -->
        <ReportGoalsPanel
            v-if="definition && canSeeGoals"
            :key="selected"
            :report-key="selected"
            :definition="definition"
        />

        <section v-if="schedules.length" class="panel">
            <h2>Reportes programados</h2>
            <p v-if="runSchedule.generalError.value" class="alert" role="alert">{{ runSchedule.generalError.value }}</p>
            <p v-if="removeSchedule.generalError.value" class="alert" role="alert">{{ removeSchedule.generalError.value }}</p>
            <ul class="descargas">
                <li v-for="s in schedules" :key="s.ulid">
                    <span>
                        {{ s.label }} ({{ s.format.toUpperCase() }}) — {{ FRECUENCIAS[s.frequency] ?? s.frequency }}
                        → {{ s.recipients.join(', ') }}
                        <span v-if="s.last_run_on" class="nota">· último: {{ s.last_run_on }}</span>
                    </span>
                    <span v-if="canSchedule" class="row-actions">
                        <button type="button" class="link-button" :disabled="runSchedule.processing.value" @click="runSchedule.submit(s)">
                            <Icon name="send" /> Enviar ahora
                        </button>
                        <button
                            type="button"
                            class="link-button link-button--danger"
                            :disabled="removeSchedule.processing.value"
                            @click="confirmRemoveSchedule(s)"
                        ><Icon name="trash" /> Eliminar</button>
                    </span>
                </li>
            </ul>
        </section>

        <section v-if="exports.length" class="panel">
            <div class="panel__cabecera">
                <h2>Descargas</h2>
                <button type="button" class="link-button" @click="refreshExports()"><Icon name="refresh" /> Actualizar</button>
            </div>
            <p v-if="exportsError" class="alert" role="alert">{{ exportsError }}</p>
            <ul class="descargas">
                <li v-for="e in exports" :key="e.ulid">
                    <span>
                        {{ e.label }} ({{ e.format.toUpperCase() }}) — {{ ESTADO_EXPORT[e.status] ?? e.status }}
                        <span v-if="e.status === 'failed'" class="error">{{ e.error }}</span>
                    </span>
                    <a v-if="e.status === 'ready'" class="link-button" :href="`/api/v1/report-exports/${e.ulid}/download`">
                        <Icon name="receive" /> Descargar
                    </a>
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.reportes { display: grid; gap: 1rem; }
/* En las rejillas el espacio lo pone el `gap`; el margen propio del aviso lo duplicaría. */
.reportes > .alert, .programar .alert { margin: 0; }
.selector label { display: grid; gap: 0.3rem; max-width: 22rem; font-size: 0.9rem; }
/* Igualado a la paleta del sistema (antes: borde #d6d6d6, chips y azules #06c/#eef3ff fuera de paleta). */
.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.1rem 1.25rem;
}
/* El reset de Tailwind deja los encabezados al tamaño del texto: sin esto, los títulos de panel no se distinguían. */
.panel h2 { margin: 0 0 0.75rem; font-size: 1rem; font-weight: 650; }
.panel__cabecera { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.75rem; }
.panel__cabecera h2 { margin: 0; }
.vistas { list-style: none; margin: 0 0 0.75rem; padding: 0; display: flex; flex-wrap: wrap; gap: 0.5rem; }
.vistas li { display: flex; align-items: center; gap: 0.2rem; }
/* Sólo icono: cuadrado y compacto junto a su chip. */
.vista__eliminar { padding: 0.2rem; }
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
.tabla-marco { overflow-x: auto; }
.tabla { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.tabla th, .tabla td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--color-borde); }
.tabla th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-suave); }
.tabla .der { text-align: right; font-variant-numeric: tabular-nums; }
.exportar { margin-top: 0.75rem; display: flex; gap: 0.75rem; align-items: center; font-size: 0.9rem; }
.programar { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--color-borde); display: grid; gap: 0.5rem; max-width: 32rem; }
.programar h3 { margin: 0; font-size: 0.95rem; font-weight: 600; }
.programar .fila { display: flex; gap: 1rem; flex-wrap: wrap; }
.programar textarea { padding: 0.35rem 0.5rem; font: inherit; resize: vertical; }
.programar button[type="submit"] { justify-self: start; }
.descargas { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; font-size: 0.9rem; }
/* Descripción a la izquierda, acciones a la derecha; en pantallas angostas las acciones bajan a su propia línea. */
.descargas li { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.error { color: var(--color-peligro); }
</style>
