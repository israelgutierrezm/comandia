<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';

/**
 * Precio de venta: el sugerido, el semáforo y el historial (D15, §6.7).
 *
 * ## El sistema sugiere; el humano decide
 *
 * Es regla de negocio, no un detalle de interfaz: el botón que aplica el sugerido **no guarda** — llena
 * el campo y deja que la persona lo cambie o lo confirme. Un botón que guardara el sugerido de golpe
 * convertiría la sugerencia en una decisión automática, y el precio es la variable comercial más
 * sensible que tiene un negocio.
 *
 * ## El semáforo dice cuándo el precio se quedó atrás
 *
 * Compara el precio vigente con el sugerido de HOY. Existe porque el caso que arruina márgenes no es
 * poner mal un precio: es poner uno bien y no volver a mirarlo mientras el costo sube compra a compra.
 * La tolerancia es configurable por el negocio, y el que decide si está desviado es el servidor: aquí
 * sólo se pinta `is_stale`.
 *
 * ## Markup y margen no son lo mismo (D13)
 *
 * Markup = utilidad ÷ costo. Margen = utilidad ÷ precio. La pantalla los muestra juntos y etiquetados,
 * porque confundirlos hace creer que un platillo con 100 % de markup deja el 100 % de utilidad — deja
 * el 50 %.
 */
const props = defineProps({
    article: { type: Object, required: true },
});

const emit = defineEmits(['changed']);

const { can, canWrite } = useAuthorization();

const suggestion = ref(null);
const suggestionError = ref(null);
const loading = ref(true);

const history = ref([]);
const historyError = ref(null);

async function load() {
    loading.value = true;

    // El sugerido y el historial tienen permisos DISTINTOS —`costing.suggested_prices.view` y
    // `catalog.prices.history.view`—, así que se piden por separado y cada 403 se maneja solo. Con una
    // sola llamada, no ver el margen escondería también el historial.
    const [sug, hist] = await Promise.all([
        can('costing.suggested_prices.view')
            ? api.get(`/articles/${props.article.ulid}/suggested-price`).catch((e) => e)
            : Promise.resolve(null),
        can('catalog.prices.history.view')
            ? api.get(`/articles/${props.article.ulid}/price-changes`, { per_page: 20 }).catch((e) => e)
            : Promise.resolve(null),
    ]);

    if (sug instanceof ApiError) {
        suggestionError.value = sug;
        suggestion.value = null;
    } else if (sug !== null) {
        suggestionError.value = null;
        suggestion.value = sug.data;
    }

    if (hist instanceof ApiError) {
        historyError.value = hist;
        history.value = [];
    } else if (hist !== null) {
        historyError.value = null;
        history.value = hist.data ?? [];
    }

    loading.value = false;
}

onMounted(load);

const changing = ref(false);
const form = ref({ price: '', reason: '' });

const save = useApiForm(async () => {
    await api.put(`/articles/${props.article.ulid}/price`, {
        price: form.value.price,
        reason: form.value.reason === '' ? null : form.value.reason,
    });
});

function startChange() {
    changing.value = true;
    form.value = { price: props.article.base_price ?? '', reason: '' };
}

/** Llena el campo con el sugerido. NO guarda: la decisión sigue siendo de la persona. */
function useSuggested() {
    if (suggestion.value?.suggested_price) {
        form.value.price = suggestion.value.suggested_price;
    }
}

async function submit() {
    if (await save.submit()) {
        changing.value = false;
        await load();
        emit('changed');
    }
}

/** Verde, ámbar o gris: el estado del precio frente al costo de hoy. */
const trafficLight = computed(() => {
    if (suggestion.value === null) {
        return null;
    }

    if (!suggestion.value.is_computable) {
        return {
            tone: 'unknown',
            title: 'No se puede evaluar',
            detail:
                'Falta el costo de algún componente, así que no hay sugerido con el que comparar. ' +
                'Captura los costos que faltan y este semáforo empezará a funcionar.',
        };
    }

    if (suggestion.value.is_stale) {
        return {
            tone: 'stale',
            title: 'El precio se quedó atrás',
            detail:
                `Se desvía ${suggestion.value.deviation_percent} % del sugerido y la tolerancia del ` +
                `negocio es ${suggestion.value.tolerance_percent} %. Suele significar que el costo subió ` +
                'después de fijar el precio.',
        };
    }

    return {
        tone: 'ok',
        title: 'El precio está en línea',
        detail: `Se desvía ${suggestion.value.deviation_percent} % del sugerido, dentro de la tolerancia.`,
    };
});
</script>

<template>
    <section class="panel">
        <p v-if="loading" class="muted">Cargando…</p>

        <template v-else>
            <!-- ---- Precio vigente y semáforo ---- -->
            <div class="grid">
                <div class="figure">
                    <span class="figure__label">Precio de venta (IVA incluido)</span>
                    <strong class="figure__value">${{ props.article.base_price ?? '—' }}</strong>
                </div>

                <div v-if="suggestion" class="figure">
                    <span class="figure__label">Sugerido hoy</span>
                    <strong class="figure__value">
                        <template v-if="suggestion.is_computable">${{ suggestion.suggested_price }}</template>
                        <span v-else class="muted">No calculable</span>
                    </strong>
                    <!--
                        La pista de «sobre $X» sólo cuando el redondeo movió el número. Con el modo «sin
                        redondeo» los dos son el mismo importe, y decirlo dos veces se lee como un
                        desajuste del sistema en lugar de como la explicación que pretende ser.
                    -->
                    <span v-if="suggestion.is_computable" class="figure__hint">
                        {{ suggestion.rounding_mode_label }}
                        <template v-if="suggestion.raw_suggested_price !== suggestion.suggested_price">
                            · sobre ${{ suggestion.raw_suggested_price }}
                        </template>
                    </span>
                </div>

                <div v-if="suggestion?.is_computable" class="figure">
                    <span class="figure__label">Costo unitario</span>
                    <strong class="figure__value">${{ suggestion.unit_cost }}</strong>
                </div>

                <div v-if="suggestion?.is_computable" class="figure">
                    <!-- Los dos, etiquetados y juntos: es la única forma de que nadie los confunda. -->
                    <span class="figure__label">Markup / Margen</span>
                    <strong class="figure__value">
                        {{ suggestion.markup_percent }} % / {{ suggestion.margin_percent ?? '—' }} %
                    </strong>
                    <span class="figure__hint">
                        Markup = utilidad ÷ costo{{ suggestion.markup_is_override ? ' · propio del artículo' : ' · del negocio' }}
                    </span>
                </div>
            </div>

            <div v-if="trafficLight" class="light" :class="`light--${trafficLight.tone}`">
                <strong>{{ trafficLight.title }}</strong>
                <span>{{ trafficLight.detail }}</span>
            </div>

            <p v-if="suggestion?.missing_costs?.length" class="missing">
                Sin costo:
                <span v-for="(name, index) in suggestion.missing_costs" :key="name">
                    {{ index > 0 ? ', ' : '' }}{{ name }}
                </span>
            </p>

            <p v-if="suggestionError && !suggestionError.isForbidden" class="alert">
                {{ suggestionError.message }}
            </p>
            <p v-else-if="!can('costing.suggested_prices.view')" class="muted small">
                Tu rol no ve costos ni márgenes, así que no se muestra el precio sugerido.
            </p>

            <button
                v-if="canWrite('catalog.prices.update')"
                class="button"
                type="button"
                @click="startChange"
            >
                Cambiar precio
            </button>

            <!-- ---- Historial inmutable ---- -->
            <h3 v-if="can('catalog.prices.history.view')" class="subtitle">Historial de precios</h3>

            <p v-if="historyError && !historyError.isForbidden" class="alert">{{ historyError.message }}</p>

            <p v-else-if="can('catalog.prices.history.view') && history.length === 0" class="muted small">
                Todavía no hay cambios registrados: el precio es el que se fijó al crear el artículo.
            </p>

            <table v-else-if="history.length" class="history">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Cambio</th>
                        <th>Sugerido / costo del momento</th>
                        <th>Markup / margen</th>
                        <th>Sucursal</th>
                        <th>Quién</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="change in history" :key="change.ulid">
                        <td class="nowrap">{{ new Date(change.created_at).toLocaleString('es-MX') }}</td>
                        <td class="nowrap">
                            <!--
                                `previous_price` nulo es la PRIMERA fijación, no un cambio desde cero:
                                «no tenía precio» y «valía $0» son cosas distintas, y la segunda sería
                                una cortesía.
                            -->
                            <template v-if="change.previous_price === null">
                                <span class="muted">Primera fijación</span> → ${{ change.new_price }}
                            </template>
                            <template v-else>
                                ${{ change.previous_price }} → <strong>${{ change.new_price }}</strong>
                            </template>
                        </td>
                        <td class="nowrap">
                            <template v-if="change.suggested_price">
                                ${{ change.suggested_price }} / ${{ change.unit_cost_at_change ?? '—' }}
                            </template>
                            <span v-else class="muted">Sin costo entonces</span>
                        </td>
                        <td class="nowrap">
                            <template v-if="change.markup_percent">
                                {{ change.markup_percent }} % / {{ change.margin_percent ?? '—' }} %
                            </template>
                            <span v-else class="muted">—</span>
                        </td>
                        <td>{{ change.branch?.name ?? 'Todo el negocio' }}</td>
                        <td>{{ change.actor?.name ?? 'Sistema' }}</td>
                        <td>{{ change.reason ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <p v-if="history.length" class="muted small">
                Este historial es <strong>inmutable</strong>: no se edita ni se borra. Una corrección se
                registra como un cambio nuevo.
            </p>
        </template>

        <!-- ---- Cambio de precio ---- -->
        <div v-if="changing" class="drawer-backdrop" @click.self="changing = false">
            <form class="drawer" @submit.prevent="submit">
                <h2>Cambiar el precio de {{ props.article.name }}</h2>

                <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

                <label class="field">
                    <span class="field__label">Nuevo precio (IVA incluido)</span>
                    <input v-model="form.price" class="input" inputmode="decimal" required />
                    <span v-if="save.fieldErrors.value.price" class="field__error">
                        {{ save.fieldErrors.value.price }}
                    </span>
                </label>

                <p v-if="suggestion?.is_computable" class="suggest">
                    <span>
                        Sugerido: <strong>${{ suggestion.suggested_price }}</strong> con
                        {{ suggestion.markup_percent }} % de markup
                    </span>
                    <button class="link-button" type="button" @click="useSuggested">Usar el sugerido</button>
                </p>

                <label class="field">
                    <span class="field__label">Motivo</span>
                    <input v-model="form.reason" class="input" maxlength="200" placeholder="Subió el costo del queso" />
                    <span class="field__hint">
                        Opcional, y vale la pena: es lo que contesta «¿por qué subimos esto en marzo?»
                        cuando nadie se acuerda.
                    </span>
                    <span v-if="save.fieldErrors.value.reason" class="field__error">
                        {{ save.fieldErrors.value.reason }}
                    </span>
                </label>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="changing = false">Cancelar</button>
                    <button type="submit" class="button" :disabled="save.processing.value">
                        Guardar el cambio
                    </button>
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
    grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
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
    opacity: 0.55;
    margin-top: 0.15rem;
}

.light {
    width: 100%;
    padding: 0.7rem 0.85rem;
    border-radius: 0.375rem;
    border: 1px solid;
    font-size: 0.85rem;
}

.light strong {
    display: block;
}

.light--ok {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #166534;
}

.light--stale {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.light--unknown {
    background: #fafaf9;
    border-color: #e7e5e4;
    color: #57534e;
}

.missing {
    margin: 0;
    font-size: 0.8rem;
    color: #92400e;
}

.subtitle {
    margin: 0.6rem 0 0;
    font-size: 0.95rem;
}

.history {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}

.history th {
    text-align: left;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.5;
    padding: 0.3rem 0.5rem;
    border-bottom: 1px solid #e7e5e4;
}

.history td {
    padding: 0.4rem 0.5rem;
    border-bottom: 1px solid #f5f5f4;
}

.nowrap {
    white-space: nowrap;
}

.muted {
    opacity: 0.55;
}

.small {
    font-size: 0.8rem;
    margin: 0;
}

.suggest {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: -0.4rem 0 0.9rem;
    padding: 0.5rem 0.7rem;
    background: #fafaf9;
    border: 1px solid #e7e5e4;
    border-radius: 0.375rem;
    font-size: 0.82rem;
}
</style>
