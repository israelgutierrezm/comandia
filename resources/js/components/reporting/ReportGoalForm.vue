<script setup>
import { computed, ref, useId } from 'vue';
import { api } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import Icon from '../Icon.vue';
import { formatMeasure, goalDirectionLabel, goalPeriod, GOAL_DIRECTIONS, GOAL_PERIODS } from './format';

/**
 * Fija la meta de UNA medida de un reporte (D46): `POST /report-goals`, con `dashboards.goals.manage` escribible.
 *
 * ## Guardar una meta que ya existe la reemplaza
 *
 * El servidor hace `updateOrCreate` por el alcance —reporte, medida, sucursal y periodo—: una segunda meta con el mismo
 * alcance AJUSTA la vigente, no la duplica. Cuando la pantalla conoce las metas del reporte (`goals`), el formulario lo
 * avisa antes de guardar, con el valor que se va a sustituir, y el botón dice «Reemplazar meta».
 *
 * ## Alcance fijo (`fixedScope`)
 *
 * Lo usa el semáforo de un tablero: la medida y el periodo son los del indicador y la meta es la CONSOLIDADA, la única
 * que el semáforo compara hoy (`GoalStatusController`). Ahí sólo se capturan el valor y la dirección.
 *
 * La comparación contra la meta (en meta / cerca / fuera) la decide el servidor; aquí no se calcula nada.
 */
const props = defineProps({
    reportKey: { type: String, required: true },
    // Las medidas de la definición del reporte: `{ key, label, format }`.
    measures: { type: Array, required: true },
    // Las sucursales al alcance de quien fija la meta (de `/context`): el servidor rechaza las demás con 403.
    branches: { type: Array, default: () => [] },
    // Las metas vigentes del reporte, para avisar que guardar reemplaza una.
    goals: { type: Array, default: () => [] },
    // Valores iniciales: `{ measure_key, period, branch_ulid, target_value, direction }`.
    initial: { type: Object, default: () => ({}) },
    fixedScope: { type: Boolean, default: false },
});

const emit = defineEmits(['saved', 'cancel']);

const uid = useId();

const form = ref({
    measure_key: props.initial.measure_key ?? (props.measures.length === 1 ? props.measures[0].key : ''),
    period: props.initial.period ?? 'month',
    // '' = consolidada: un `<select>` no distingue `null`; el envío lo vuelve a traducir.
    branch_ulid: props.initial.branch_ulid ?? '',
    target_value: props.initial.target_value ?? '',
    direction: props.initial.direction ?? 'higher_better',
});

const measure = computed(() => props.measures.find((m) => m.key === form.value.measure_key) ?? null);
const period = computed(() => goalPeriod(form.value.period));

const unit = computed(() => ({ money: ' (en pesos)', percent: ' (en %)' }[measure.value?.format] ?? ''));

/** La meta vigente con el MISMO alcance que la que se está capturando: guardar la reemplaza. */
const existing = computed(() => props.goals.find((g) => g.measure_key === form.value.measure_key
    && g.period === form.value.period
    && (g.branch_ulid ?? '') === form.value.branch_ulid) ?? null);

// Se decide al ENVIAR, no al pintar el aviso: al terminar, la pantalla recarga las metas y `existing` ya apuntaría a la
// recién guardada, así que todo envío parecería un reemplazo.
let replaces = false;

const save = useApiForm(async () => {
    replaces = existing.value !== null;

    const { data } = await api.post('/report-goals', {
        report_key: props.reportKey,
        measure_key: form.value.measure_key,
        branch_ulid: form.value.branch_ulid || null,
        period: form.value.period,
        target_value: form.value.target_value,
        direction: form.value.direction,
    });

    emit('saved', data);
}, { success: () => ({ kind: replaces ? 'update' : 'create', entity: 'Meta', gender: 'f' }) });

const errors = computed(() => save.fieldErrors.value);
</script>

<template>
    <form class="meta-form" @submit.prevent="save.submit()">
        <p v-if="fixedScope" class="meta-form__alcance">
            Meta {{ period.adjective }} consolidada de «{{ measure?.label ?? form.measure_key }}»: el semáforo compara
            {{ period.progress }} contra ella.
        </p>

        <div class="meta-form__campos" :class="{ 'meta-form__campos--completo': ! fixedScope }">
            <template v-if="! fixedScope">
                <div class="field">
                    <label :for="`${uid}-medida`" class="field__label">Medida</label>
                    <select :id="`${uid}-medida`" v-model="form.measure_key" class="input" required>
                        <option value="" disabled>Elige una medida…</option>
                        <option v-for="m in measures" :key="m.key" :value="m.key">{{ m.label }}</option>
                    </select>
                    <span v-if="errors.measure_key" class="field__error">{{ errors.measure_key }}</span>
                </div>

                <div class="field">
                    <label :for="`${uid}-periodo`" class="field__label">Periodo</label>
                    <select :id="`${uid}-periodo`" v-model="form.period" class="input" required>
                        <option v-for="p in GOAL_PERIODS" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </select>
                    <span class="field__hint">El semáforo compara {{ period.progress }}.</span>
                    <span v-if="errors.period" class="field__error">{{ errors.period }}</span>
                </div>

                <div class="field">
                    <label :for="`${uid}-sucursal`" class="field__label">Sucursal</label>
                    <select :id="`${uid}-sucursal`" v-model="form.branch_ulid" class="input">
                        <option value="">Consolidada</option>
                        <option v-for="b in branches" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                    </select>
                    <!-- Dicho tal cual: hoy el semáforo sólo lee la consolidada (GoalStatusController usa branchId null). -->
                    <span v-if="form.branch_ulid" class="field__hint">
                        Se guarda, pero el semáforo de los tableros todavía compara sólo contra la meta consolidada.
                    </span>
                    <span v-else class="field__hint">Se compara con el total de las sucursales que alcanza quien mira.</span>
                    <span v-if="errors.branch_ulid" class="field__error">{{ errors.branch_ulid }}</span>
                </div>
            </template>

            <div class="field">
                <label :for="`${uid}-valor`" class="field__label">Valor objetivo{{ unit }}</label>
                <input
                    :id="`${uid}-valor`"
                    v-model="form.target_value"
                    class="input"
                    type="number"
                    min="0"
                    step="any"
                    inputmode="decimal"
                    required
                />
                <span v-if="errors.target_value" class="field__error">{{ errors.target_value }}</span>
            </div>

            <div class="field">
                <label :for="`${uid}-direccion`" class="field__label">Dirección</label>
                <select :id="`${uid}-direccion`" v-model="form.direction" class="input" required>
                    <option v-for="d in GOAL_DIRECTIONS" :key="d.value" :value="d.value">{{ d.label }} ({{ d.example }})</option>
                </select>
                <span v-if="errors.direction" class="field__error">{{ errors.direction }}</span>
            </div>
        </div>

        <p v-if="existing" class="alert alert--notice">
            Ya hay una meta de {{ formatMeasure(existing.target_value, measure?.format) }}
            ({{ goalDirectionLabel(existing.direction).toLowerCase() }}) con esta medida, periodo y sucursal: al guardar, la
            nueva la reemplaza.
        </p>

        <p v-if="save.generalError.value" class="alert" role="alert">{{ save.generalError.value }}</p>

        <div class="meta-form__acciones">
            <button type="submit" class="button" :disabled="save.processing.value">
                {{ save.processing.value ? 'Guardando…' : existing ? 'Reemplazar meta' : 'Guardar meta' }}
            </button>
            <button type="button" class="link-button" :disabled="save.processing.value" @click="emit('cancel')">
                <Icon name="x" /> Cancelar
            </button>
        </div>
    </form>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.meta-form { display: grid; gap: 0.7rem; }
/* En la rejilla el espacio lo pone el `gap`; los márgenes propios de campos y avisos lo duplicarían. */
.meta-form .field, .meta-form .alert { margin: 0; }
.meta-form__alcance { margin: 0; font-size: 0.85rem; color: var(--color-suave); }
.meta-form__campos { display: grid; gap: 0.7rem; }
/* Con todos los campos (pantalla de Reportes) se acomodan en columnas; en un teléfono queda una sola. */
.meta-form__campos--completo { grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); align-items: start; }
.meta-form__acciones { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
</style>
