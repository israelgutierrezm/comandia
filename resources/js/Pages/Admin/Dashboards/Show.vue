<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import DashboardWidgetCard from '../../../components/reporting/DashboardWidgetCard.vue';

/**
 * Un tablero: su grid de widgets, cada uno pintado por el motor con el scope de quien mira. El autor puede agregar y
 * quitar widgets. La configuración del widget (tipo, medida, dimensión) se arma desde la definición del reporte —el
 * frontend se autoconfigura, como el motor—.
 */
const props = defineProps({ dashboardUlid: { type: String, required: true } });

const dashboard = ref(null);
const loadError = ref(null);
const reports = ref([]);

const adding = ref(false);
const form = ref({ report_key: '', visualization: 'numero', title: '', measure_key: '', dimension_key: '', period: 'month', top_n: 5 });
const definition = ref(null);

onMounted(async () => {
    reports.value = (await api.get('/reports')).data;
    await load();
});

async function load() {
    try {
        dashboard.value = (await api.get(`/dashboards/${props.dashboardUlid}`)).data;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e; else throw e;
    }
}

const needsMeasure = computed(() => ['numero', 'semaforo', 'barras', 'topn'].includes(form.value.visualization));
const needsDimension = computed(() => ['barras', 'topn'].includes(form.value.visualization));

async function onReportChange() {
    form.value.measure_key = '';
    form.value.dimension_key = '';
    definition.value = form.value.report_key
        ? (await api.get(`/reports/${form.value.report_key}/definition`)).data
        : null;
}

const addWidget = useApiForm(async () => {
    const body = {
        report_key: form.value.report_key,
        visualization: form.value.visualization,
        title: form.value.title,
    };
    if (needsMeasure.value) body.measure_key = form.value.measure_key;
    if (needsDimension.value) body.dimension_key = form.value.dimension_key;
    if (form.value.visualization === 'semaforo') body.period = form.value.period;
    if (form.value.visualization === 'topn') body.top_n = Number(form.value.top_n);

    await api.post(`/dashboards/${props.dashboardUlid}/widgets`, body);
    adding.value = false;
    form.value = { report_key: '', visualization: 'numero', title: '', measure_key: '', dimension_key: '', period: 'month', top_n: 5 };
    definition.value = null;
    await load();
});

const removeWidget = useApiForm(async (ulid) => {
    await api.delete(`/dashboard-widgets/${ulid}`);
    await load();
});
</script>

<template>
    <Head :title="dashboard ? dashboard.name : 'Tablero'" />

    <div class="tablero">
        <p><a href="/admin/tableros">← Tableros</a></p>
        <p v-if="loadError" class="error">{{ loadError.title }}</p>

        <template v-if="dashboard">
            <header>
                <h1>{{ dashboard.name }}</h1>
                <button v-if="dashboard.is_mine" type="button" @click="adding = ! adding">Agregar widget</button>
            </header>

            <section v-if="adding" class="panel">
                <form @submit.prevent="addWidget.submit()">
                    <label>Reporte
                        <select v-model="form.report_key" required @change="onReportChange">
                            <option value="">Elige…</option>
                            <option v-for="r in reports" :key="r.key" :value="r.key">{{ r.label }}</option>
                        </select>
                    </label>
                    <label>Tipo
                        <select v-model="form.visualization">
                            <option value="numero">Número</option>
                            <option value="semaforo">Semáforo (vs meta)</option>
                            <option value="barras">Barras</option>
                            <option value="topn">Top-N</option>
                        </select>
                    </label>
                    <label>Título <input v-model="form.title" type="text" required maxlength="80" /></label>

                    <label v-if="needsMeasure && definition">Medida
                        <select v-model="form.measure_key" required>
                            <option value="">Elige…</option>
                            <option v-for="m in definition.measures" :key="m.key" :value="m.key">{{ m.label }}</option>
                        </select>
                    </label>
                    <label v-if="needsDimension && definition">Dimensión
                        <select v-model="form.dimension_key" required>
                            <option value="">Elige…</option>
                            <option v-for="d in definition.dimensions" :key="d.key" :value="d.key">{{ d.label }}</option>
                        </select>
                    </label>
                    <label v-if="form.visualization === 'semaforo'">Periodo
                        <select v-model="form.period">
                            <option value="day">Día</option>
                            <option value="week">Semana</option>
                            <option value="month">Mes</option>
                            <option value="year">Año</option>
                        </select>
                    </label>
                    <label v-if="form.visualization === 'topn'">Cuántos <input v-model="form.top_n" type="number" min="1" max="50" /></label>

                    <p v-if="addWidget.generalError.value" class="error">{{ addWidget.generalError.value }}</p>
                    <div class="acciones">
                        <button type="submit" :disabled="addWidget.processing.value">Agregar</button>
                        <button type="button" class="enlace" @click="adding = false">Cancelar</button>
                    </div>
                </form>
            </section>

            <div v-if="dashboard.widgets.length" class="grid">
                <div v-for="wg in dashboard.widgets" :key="wg.ulid" class="celda">
                    <DashboardWidgetCard :widget="wg" />
                    <button v-if="dashboard.is_mine" type="button" class="quitar" @click="removeWidget.submit(wg.ulid)">quitar</button>
                </div>
            </div>

            <p v-else class="nota">Este tablero no tiene widgets todavía.</p>
        </template>
    </div>
</template>

<style scoped>
.tablero { display: grid; gap: 1rem; max-width: 68rem; }
header { display: flex; justify-content: space-between; align-items: baseline; }
header h1 { margin: 0; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
form { display: grid; gap: 0.5rem; max-width: 24rem; }
label { display: grid; gap: 0.2rem; font-size: 0.9rem; }
.acciones { display: flex; gap: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); gap: 1rem; }
.celda { position: relative; }
.quitar { position: absolute; top: 0.5rem; right: 0.6rem; background: none; border: 0; color: #a11; cursor: pointer; font-size: 0.8rem; }
.nota { color: #555; font-size: 0.9rem; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; }
.error { color: #a11; }
</style>
