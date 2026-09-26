<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import DashboardWidgetCard from '../../../components/reporting/DashboardWidgetCard.vue';
import DashboardSettings from '../../../components/reporting/DashboardSettings.vue';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Un tablero: su grid de widgets, cada uno pintado por el motor con el scope de quien mira. El autor puede agregar y
 * quitar widgets, y renombrar, publicar o eliminar el tablero (`DashboardSettings`). La configuración del widget (tipo,
 * medida, dimensión) se arma desde la definición del reporte —el frontend se autoconfigura, como el motor—.
 *
 * En la interfaz el widget se llama «indicador»: es la palabra del negocio, «widget» es la del código.
 */
const props = defineProps({ dashboardUlid: { type: String, required: true } });

const { canWrite } = useAuthorization();

const dashboard = ref(null);
const loading = ref(true);
const loadError = ref(null);
const reports = ref([]);

// Construir (indicadores, nombre, publicación, eliminar) exige que el tablero sea TUYO y `dashboards.dashboards.manage`
// escribible: el servidor pide las dos cosas. Antes bastaba `is_mine`, y el autor cuyo rol activo no construye tableros
// —o un negocio en sólo lectura— veía botones que respondían 403.
const canBuild = computed(() => dashboard.value?.is_mine === true && canWrite('dashboards.dashboards.manage'));

const editing = ref(false);
// Sube cada vez que se fija una meta desde un semáforo: todos los semáforos del tablero vuelven a pedir su estado.
const goalsVersion = ref(0);

const adding = ref(false);
const form = ref({ report_key: '', visualization: 'numero', title: '', measure_key: '', dimension_key: '', period: 'month', top_n: 5 });
const definition = ref(null);
const definitionError = ref(null);

// El tablero y el catálogo de reportes se piden a la vez y cada uno avisa su propia falla. Antes, si fallaba el
// catálogo (que sólo usa el formulario de alta), el tablero ni se pedía y la pantalla quedaba en blanco.
onMounted(() => Promise.all([load(), loadReports()]));

async function loadReports() {
    try {
        reports.value = (await api.get('/reports')).data;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    }
}

async function load() {
    try {
        dashboard.value = (await api.get(`/dashboards/${props.dashboardUlid}`)).data;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
}

const subtitle = computed(() => {
    if (! dashboard.value) return '';

    if (! dashboard.value.is_mine) {
        return 'Compartido contigo: sólo su autor puede cambiarlo. Las cifras se calculan con tus permisos.';
    }

    return dashboard.value.published_role_ulid
        ? 'Tu tablero, publicado a un rol. Cada indicador se calcula con los permisos de quien lo mira.'
        : 'Tu tablero, sin publicar. Cada indicador se calcula con los permisos de quien lo mira.';
});

/** El tablero que devolvió el servidor al renombrarlo o publicarlo: ya trae sus indicadores. */
function replaceDashboard(updated) {
    dashboard.value = updated;
}

const needsMeasure = computed(() => ['numero', 'semaforo', 'barras', 'topn'].includes(form.value.visualization));
const needsDimension = computed(() => ['barras', 'topn'].includes(form.value.visualization));

async function onReportChange() {
    form.value.measure_key = '';
    form.value.dimension_key = '';
    definitionError.value = null;

    try {
        definition.value = form.value.report_key
            ? (await api.get(`/reports/${form.value.report_key}/definition`)).data
            : null;
    } catch (e) {
        definition.value = null;
        if (e instanceof ApiError) definitionError.value = e.title; else throw e;
    }
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
}, { success: { kind: 'create', entity: 'Indicador', gender: 'm' } });

const removeWidget = useApiForm(async (widget) => {
    await api.delete(`/dashboard-widgets/${widget.ulid}`);
    await load();
}, { success: { kind: 'delete', entity: 'Indicador', gender: 'm' } });

async function confirmRemoveWidget(widget) {
    // Se borra de verdad; si el tablero está publicado, también lo pierde el rol que lo ve, y eso se dice.
    const alcance = dashboard.value?.published_role_ulid ? ' también para el rol al que lo publicaste' : '';

    if (! window.confirm(`¿Eliminar el indicador «${widget.title}»? Desaparece de este tablero${alcance}; el reporte y sus datos no cambian.`)) {
        return;
    }

    await removeWidget.submit(widget);
}
</script>

<template>
    <Head :title="dashboard ? dashboard.name : 'Tablero'" />

    <div class="tablero">
        <p><Link href="/admin/tableros" class="link-button">← Tableros</Link></p>

        <ListHeader :title="dashboard?.name ?? 'Tablero'" :subtitle="subtitle">
            <template v-if="canBuild" #action>
                <div class="cabecera-acciones">
                    <button type="button" class="button button--warning" :aria-expanded="editing" @click="editing = ! editing">
                        <Icon name="edit" /> Editar tablero
                    </button>
                    <button type="button" class="button" :aria-expanded="adding" @click="adding = ! adding">
                        <Icon name="plus" /> Agregar indicador
                    </button>
                </div>
            </template>
        </ListHeader>

        <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
        <p v-if="removeWidget.generalError.value" class="alert" role="alert">{{ removeWidget.generalError.value }}</p>

        <p v-if="loading" class="nota">Cargando…</p>

        <template v-if="dashboard">
            <DashboardSettings
                v-if="editing && canBuild"
                :dashboard="dashboard"
                @updated="replaceDashboard"
                @close="editing = false"
            />

            <section v-if="adding && canBuild" class="panel">
                <form @submit.prevent="addWidget.submit()">
                    <label>Reporte
                        <select v-model="form.report_key" required @change="onReportChange">
                            <option value="">Elige…</option>
                            <option v-for="r in reports" :key="r.key" :value="r.key">{{ r.label }}</option>
                        </select>
                    </label>
                    <p v-if="definitionError" class="alert" role="alert">{{ definitionError }}</p>
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

                    <p v-if="addWidget.generalError.value" class="alert" role="alert">{{ addWidget.generalError.value }}</p>
                    <div class="acciones">
                        <button type="submit" class="button" :disabled="addWidget.processing.value">Agregar</button>
                        <button type="button" class="link-button" @click="adding = false"><Icon name="x" /> Cancelar</button>
                    </div>
                </form>
            </section>

            <div v-if="dashboard.widgets.length" class="grid">
                <DashboardWidgetCard
                    v-for="wg in dashboard.widgets"
                    :key="wg.ulid"
                    :widget="wg"
                    :goals-version="goalsVersion"
                    @goal-saved="goalsVersion++"
                >
                    <template v-if="canBuild" #acciones>
                        <button
                            type="button"
                            class="link-button link-button--danger quitar"
                            :aria-label="`Eliminar el indicador «${wg.title}»`"
                            :title="`Eliminar el indicador «${wg.title}»`"
                            :disabled="removeWidget.processing.value"
                            @click="confirmRemoveWidget(wg)"
                        ><Icon name="trash" /></button>
                    </template>
                </DashboardWidgetCard>
            </div>

            <p v-else class="nota">Este tablero no tiene indicadores todavía.</p>
        </template>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.tablero { display: grid; gap: 1rem; max-width: 68rem; }
/* Las dos acciones del autor; en un teléfono, si no caben en una línea, la segunda baja. */
.cabecera-acciones { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: flex-end; }
/* En las rejillas el espacio lo pone el `gap`; el margen propio del aviso lo duplicaría. */
.tablero > .alert, form .alert { margin: 0; }
.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.15rem 1.25rem;
}
form { display: grid; gap: 0.6rem; max-width: 24rem; }
label { display: grid; gap: 0.2rem; font-size: 0.9rem; }
.acciones { display: flex; gap: 1rem; align-items: center; margin-top: 0.4rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); gap: 1rem; }
/* Sólo icono en la cabecera de la tarjeta: compacto, para no competir con el título del indicador. */
.quitar { padding: 0.2rem; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
</style>
