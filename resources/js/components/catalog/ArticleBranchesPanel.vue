<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';

/**
 * Precio y disponibilidad propios de cada sucursal (§6.1).
 *
 * ## Heredar y decidir no son lo mismo
 *
 * La distinción que esta pantalla existe para hacer visible: «Polanco cobra $85 porque el negocio cobra
 * $85» y «Polanco decidió cobrar $85» se ven idénticas hasta el día que el negocio suba a $95. La
 * primera sube; la segunda no. Es la misma diferencia que la configuración jerárquica hace entre
 * heredar y estar configurado aquí, y por eso cada fila dice cuál de las dos es.
 *
 * ## Volver a heredar es una acción propia
 *
 * No se «pone el precio del negocio»: se **quita** el precio propio. Teclear el mismo número dejaría la
 * sucursal desconectada del maestro para siempre, y nadie lo notaría — hasta que el negocio subiera los
 * precios y esa sucursal se quedara atrás sin explicación.
 *
 * ## Dos permisos distintos, a propósito
 *
 * El precio lo cambia `catalog.prices.update` y la disponibilidad `catalog.articles.manage`. No es un
 * descuido: un encargado que puede ocultar un platillo agotado no debería poder cambiarle el precio.
 */
const props = defineProps({
    article: { type: Object, required: true },
    branches: { type: Array, required: true },
});

const emit = defineEmits(['changed']);

const { canWrite } = useAuthorization();

const master = ref(null);
const overrides = ref([]);
const loading = ref(true);
const error = ref(null);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.get(`/articles/${props.article.ulid}/branch-overrides`);

        master.value = response.data;
        overrides.value = response.data.overrides ?? [];
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        error.value = e;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

/**
 * Una fila por sucursal, tenga override o no.
 *
 * El endpoint sólo devuelve las que tienen algo propio, y con eso la pantalla mostraría dos sucursales
 * de cinco: quien administra necesita ver las cinco para saber dónde puede decidir algo.
 */
const rows = computed(() =>
    props.branches.map((branch) => {
        const override = overrides.value.find((o) => o.branch.ulid === branch.ulid) ?? null;

        return {
            branch,
            hasOwnPrice: override?.price !== null && override?.price !== undefined,
            price: override?.price ?? master.value?.master_price ?? null,
            hasOwnAvailability: override?.is_available_in_pos !== null && override?.is_available_in_pos !== undefined,
            isAvailable: override?.is_available_in_pos ?? master.value?.master_is_available_in_pos ?? false,
        };
    }),
);

// ---- Precio por sucursal ----

const pricing = ref(null);
const priceForm = ref({ price: '', reason: '' });

const savePrice = useApiForm(async () => {
    await api.put(`/articles/${props.article.ulid}/branches/${pricing.value.branch.ulid}/price`, {
        price: priceForm.value.price,
        reason: priceForm.value.reason === '' ? null : priceForm.value.reason,
    });
});

const clearPrice = useApiForm(async (row) => {
    await api.delete(`/articles/${props.article.ulid}/branches/${row.branch.ulid}/price`);
});

const setAvailability = useApiForm(async (row, value) => {
    await api.put(`/articles/${props.article.ulid}/branches/${row.branch.ulid}/availability`, {
        is_available_in_pos: value,
    });
});

function startPricing(row) {
    pricing.value = row;
    priceForm.value = { price: row.price ?? '', reason: '' };
}

async function submitPrice() {
    if (await savePrice.submit()) {
        pricing.value = null;
        await load();
        emit('changed');
    }
}

async function confirmClearPrice(row) {
    if (
        !window.confirm(
            `¿Quitar el precio propio de ${row.branch.name}? Volverá a heredar $${master.value?.master_price} ` +
                'y seguirá al precio del negocio cuando cambie.',
        )
    ) {
        return;
    }

    if (await clearPrice.submit(row)) {
        await load();
        emit('changed');
    }
}

/**
 * Tres estados y no dos: disponible aquí, oculto aquí, o «lo que diga el negocio». El tercero no es un
 * valor — es la ausencia de decisión, y por eso se manda `null`.
 */
async function changeAvailability(row, value) {
    if (await setAvailability.submit(row, value)) {
        await load();
        emit('changed');
    }
}
</script>

<template>
    <section class="panel">
        <p v-if="loading" class="muted">Cargando…</p>

        <div v-else-if="error" class="alert">
            <template v-if="error.isForbidden">Tu rol no tiene permiso para ver precios.</template>
            <template v-else>{{ error.message }}</template>
        </div>

        <template v-else>
            <p class="master">
                El negocio cobra <strong>${{ master?.master_price ?? '—' }}</strong> y el artículo está
                <strong>{{ master?.master_is_available_in_pos ? 'disponible' : 'oculto' }}</strong> por
                omisión. Cada sucursal puede decidir lo suyo; lo que no decide, lo hereda.
            </p>

            <p v-if="clearPrice.generalError.value" class="alert">{{ clearPrice.generalError.value }}</p>
            <p v-if="setAvailability.generalError.value" class="alert">{{ setAvailability.generalError.value }}</p>

            <table class="rows">
                <thead>
                    <tr>
                        <th>Sucursal</th>
                        <th class="num">Precio efectivo</th>
                        <th>Disponibilidad</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.branch.ulid">
                        <td>{{ row.branch.name }}</td>

                        <td class="num">
                            <span class="money">${{ row.price ?? '—' }}</span>
                            <span v-if="row.hasOwnPrice" class="badge badge--warn">propio</span>
                            <span v-else class="badge badge--off">hereda</span>
                        </td>

                        <td>
                            <select
                                class="input input--tight"
                                :disabled="!canWrite('catalog.articles.manage')"
                                :value="row.hasOwnAvailability ? String(row.isAvailable) : ''"
                                @change="changeAvailability(row, $event.target.value === '' ? null : $event.target.value === 'true')"
                            >
                                <option value="">
                                    Lo que diga el negocio ({{ master?.master_is_available_in_pos ? 'disponible' : 'oculto' }})
                                </option>
                                <option value="true">Disponible aquí</option>
                                <option value="false">Oculto aquí</option>
                            </select>
                        </td>

                        <td>
                            <div class="row-actions">
                                <button
                                    v-if="canWrite('catalog.prices.update')"
                                    class="link-button"
                                    type="button"
                                    @click="startPricing(row)"
                                >
                                    {{ row.hasOwnPrice ? 'Cambiar precio' : 'Poner precio propio' }}
                                </button>
                                <button
                                    v-if="row.hasOwnPrice && canWrite('catalog.prices.update')"
                                    class="link-button link-button--danger"
                                    type="button"
                                    @click="confirmClearPrice(row)"
                                >
                                    Volver a heredar
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="4" class="muted">
                            Este negocio no tiene sucursales activas en tu alcance.
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="muted small">
                Cada precio por sucursal se registra en el mismo historial inmutable que el precio maestro,
                con la sucursal anotada.
            </p>
        </template>

        <div v-if="pricing" class="drawer-backdrop" @click.self="pricing = null">
            <form class="drawer" @submit.prevent="submitPrice">
                <h2>Precio de {{ props.article.name }} en {{ pricing.branch.name }}</h2>

                <p v-if="savePrice.generalError.value" class="alert">{{ savePrice.generalError.value }}</p>

                <label class="field">
                    <span class="field__label">Precio en esta sucursal (IVA incluido)</span>
                    <input v-model="priceForm.price" class="input" inputmode="decimal" required />
                    <span class="field__hint">
                        El negocio cobra ${{ master?.master_price }}. Al poner un precio propio, esta
                        sucursal deja de seguir al maestro.
                    </span>
                    <span v-if="savePrice.fieldErrors.value.price" class="field__error">
                        {{ savePrice.fieldErrors.value.price }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Motivo</span>
                    <input v-model="priceForm.reason" class="input" maxlength="200" placeholder="Zona de renta más alta" />
                    <span v-if="savePrice.fieldErrors.value.reason" class="field__error">
                        {{ savePrice.fieldErrors.value.reason }}
                    </span>
                </label>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="pricing = null">Cancelar</button>
                    <button type="submit" class="button" :disabled="savePrice.processing.value">Guardar</button>
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
    gap: 0.75rem;
    align-items: flex-start;
    width: 100%;
}

.master {
    margin: 0;
    font-size: 0.9rem;
}

.rows {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.rows th {
    text-align: left;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.5;
    padding: 0.3rem 0.5rem;
    border-bottom: 1px solid #e7e5e4;
}

.rows th.num,
.rows td.num {
    text-align: right;
}

.rows td {
    padding: 0.4rem 0.5rem;
    border-bottom: 1px solid #f5f5f4;
}

.money {
    font-variant-numeric: tabular-nums;
    margin-right: 0.35rem;
}

.input--tight {
    padding: 0.3rem 0.4rem;
    font-size: 0.82rem;
}

.muted {
    opacity: 0.55;
}

.small {
    font-size: 0.8rem;
    margin: 0;
}
</style>
