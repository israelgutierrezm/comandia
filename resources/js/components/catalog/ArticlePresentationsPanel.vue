<script setup>
import { onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';

/**
 * Presentaciones de compra (D22).
 *
 * Es la respuesta a un problema muy concreto: el jitomate se compra por caja y se consume por gramo.
 * Sin presentaciones, quien captura costos tiene que dividir a mano —$180 entre 12 000 g— y ese es el
 * momento exacto en que un costo entra con dos ceros de más y nadie lo nota hasta el corte.
 *
 * La cantidad se expresa en la unidad base del artículo, que es lo que la hace utilizable como divisor
 * sin conversiones intermedias.
 *
 * ## Por qué la cantidad no se edita
 *
 * Se puede cambiar el nombre y el código de barras, no la cantidad: es el divisor con el que se
 * calcularon los costos ya capturados a través de esta presentación. Cambiarla no corregiría un costo
 * pasado, reinterpretaría todos. Si el proveedor cambia el tamaño de la caja, es otra presentación.
 */
const props = defineProps({
    article: { type: Object, required: true },
});

const { canWrite } = useAuthorization();

const items = ref([]);
const loading = ref(true);
const error = ref(null);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        items.value = (await api.get(`/articles/${props.article.ulid}/presentations`)).data ?? [];
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

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    if (editing.value === 'new') {
        await api.post(`/articles/${props.article.ulid}/presentations`, {
            name: form.value.name,
            quantity_in_base_unit: form.value.quantity_in_base_unit,
            barcode: form.value.barcode === '' ? null : form.value.barcode,
            is_default: form.value.is_default,
        });
    } else {
        await api.patch(`/articles/${props.article.ulid}/presentations/${editing.value.ulid}`, {
            name: form.value.name,
            barcode: form.value.barcode === '' ? null : form.value.barcode,
            is_default: form.value.is_default,
        });
    }
});

const archive = useApiForm(async (presentation) => {
    await api.post(`/articles/${props.article.ulid}/presentations/${presentation.ulid}/archive`);
});

function startCreate() {
    editing.value = 'new';
    form.value = {
        name: '',
        quantity_in_base_unit: '',
        barcode: '',
        // La primera es la de omisión sin que nadie lo pida: si sólo hay una forma de comprarlo, es
        // ésa. Marcar una casilla para decir lo obvio es una casilla de más.
        is_default: items.value.length === 0,
    };
}

function startEdit(presentation) {
    editing.value = presentation;
    form.value = {
        name: presentation.name,
        barcode: presentation.barcode ?? '',
        is_default: presentation.is_default,
    };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await load();
    }
}

async function confirmArchive(presentation) {
    if (!window.confirm(`¿Dar de baja la presentación «${presentation.name}»?`)) {
        return;
    }

    if (await archive.submit(presentation)) {
        await load();
    }
}
</script>

<template>
    <section class="panel">
        <p v-if="loading" class="muted">Cargando…</p>

        <div v-else-if="error" class="alert">{{ error.message }}</div>

        <template v-else>
            <p class="muted small">
                Sirven para capturar el costo como llega la factura —«$180 la caja»— en lugar de dividir a
                mano. La cantidad va en {{ props.article.base_unit?.code ?? 'la unidad base' }}, la unidad
                base de este artículo.
            </p>

            <p v-if="archive.generalError.value" class="alert">{{ archive.generalError.value }}</p>

            <table v-if="items.length" class="rows">
                <thead>
                    <tr>
                        <th>Presentación</th>
                        <th class="num">Rinde</th>
                        <th>Código de barras</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.ulid">
                        <td>
                            {{ item.name }}
                            <span v-if="item.is_default" class="badge badge--warn">por omisión</span>
                        </td>
                        <td class="num">
                            {{ item.quantity_in_base_unit }} {{ props.article.base_unit?.code ?? '' }}
                        </td>
                        <td>{{ item.barcode ?? '—' }}</td>
                        <td>
                            <span class="badge" :class="item.status === 'active' ? 'badge--ok' : 'badge--off'">
                                {{ item.status === 'active' ? 'Activa' : 'Baja' }}
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button
                                    v-if="canWrite('catalog.articles.manage')"
                                    class="link-button"
                                    type="button"
                                    @click="startEdit(item)"
                                >
                                    Editar
                                </button>
                                <button
                                    v-if="item.status === 'active' && canWrite('catalog.articles.manage')"
                                    class="link-button link-button--danger"
                                    type="button"
                                    @click="confirmArchive(item)"
                                >
                                    Baja
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="muted">
                Este artículo no tiene presentaciones de compra. Sin ellas, el costo se captura por
                {{ props.article.base_unit?.code ?? 'unidad base' }}.
            </p>

            <button v-if="canWrite('catalog.articles.manage')" class="button" type="button" @click="startCreate">
                Nueva presentación
            </button>
        </template>

        <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
            <form class="drawer" @submit.prevent="submit">
                <h2>{{ editing === 'new' ? 'Nueva presentación' : `Editar ${editing.name}` }}</h2>

                <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

                <label class="field">
                    <span class="field__label">Nombre</span>
                    <input v-model="form.name" class="input" maxlength="80" required placeholder="Caja de 12 kg" />
                    <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
                </label>

                <label v-if="editing === 'new'" class="field">
                    <span class="field__label">
                        ¿Cuántos {{ props.article.base_unit?.code ?? '' }} rinde?
                    </span>
                    <input v-model="form.quantity_in_base_unit" class="input" inputmode="decimal" required />
                    <span class="field__hint">
                        <strong>No se puede cambiar después:</strong> es el divisor con el que se
                        calcularon los costos capturados por esta presentación.
                    </span>
                    <span v-if="save.fieldErrors.value.quantity_in_base_unit" class="field__error">
                        {{ save.fieldErrors.value.quantity_in_base_unit }}
                    </span>
                </label>

                <p v-else class="locked">
                    Rinde <strong>{{ editing.quantity_in_base_unit }} {{ props.article.base_unit?.code ?? '' }}</strong>.
                    Si el proveedor cambió el tamaño, crea otra presentación en lugar de editar ésta.
                </p>

                <label class="field">
                    <span class="field__label">Código de barras</span>
                    <input v-model="form.barcode" class="input" maxlength="32" placeholder="Opcional" />
                    <span v-if="save.fieldErrors.value.barcode" class="field__error">
                        {{ save.fieldErrors.value.barcode }}
                    </span>
                </label>

                <label class="field field--check">
                    <input v-model="form.is_default" type="checkbox" />
                    <span>
                        <span class="field__label">Es la forma habitual de comprarlo</span>
                        <span class="field__hint">Sale preseleccionada al capturar costos.</span>
                    </span>
                </label>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="editing = null">Cancelar</button>
                    <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
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
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.rows td {
    padding: 0.4rem 0.5rem;
    border-bottom: 1px solid #f5f5f4;
}

.field--check {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.field--check input {
    margin-top: 0.2rem;
}

.locked {
    margin: 0 0 0.9rem;
    padding: 0.55rem 0.7rem;
    background: #fafaf9;
    border: 1px solid #e7e5e4;
    border-radius: 0.375rem;
    font-size: 0.8rem;
}

.muted {
    opacity: 0.55;
}

.small {
    font-size: 0.8rem;
    margin: 0;
}
</style>
