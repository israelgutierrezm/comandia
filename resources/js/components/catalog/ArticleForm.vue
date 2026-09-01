<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import Icon from '../../components/Icon.vue';

/**
 * Formulario de artículo: alta y edición.
 *
 * ## Las capacidades primero, y el resto se adapta
 *
 * D17 dice que no hay «productos» y «insumos»: hay UN artículo con cuatro capacidades. El formulario
 * lo hace evidente poniéndolas arriba y mostrando después sólo lo que esas capacidades implican —el
 * precio aparece cuando se marca vendible, y desaparece cuando se desmarca—. Un formulario con todos
 * los campos siempre visibles enseñaría que un insumo «debería» tener precio de venta.
 *
 * Las reglas que se muestran son las mismas que impone el servidor, y el servidor decide: aquí sólo
 * se anticipan para que el usuario no descubra por 422 lo que se podía decir antes. El modelo las
 * vuelve a imponer al guardar (invariantes I2 y I11), así que ni este formulario ni el Form Request
 * son la única defensa.
 *
 * ## Lo que NO se puede cambiar al editar
 *
 * La **unidad base** desaparece en edición, porque el servidor la rechaza (I6, D96): es la unidad en
 * la que están expresadas todas las cantidades, costos y existencias ya capturadas. Cambiarla de
 * gramos a kilos no convertiría nada — reinterpretaría cada número histórico multiplicándolo por mil
 * sin que ninguna fila cambie.
 *
 * El **precio** tampoco se cambia aquí: tiene su propia pantalla porque cada cambio se historiza con
 * el estado del costeo y un motivo (D15). Un campo de precio en este formulario invitaría a cambiarlo
 * de paso, sin motivo, junto con el nombre.
 */
const props = defineProps({
    /** `null` = alta. */
    article: { type: Object, default: null },
    categories: { type: Array, required: true },
    units: { type: Array, required: true },
    tags: { type: Array, required: true },
});

const emit = defineEmits(['close', 'saved']);

const isNew = computed(() => props.article === null);

const form = ref({
    code: '',
    name: '',
    short_name: '',
    category_ulid: '',
    base_unit_ulid: '',
    is_sellable: true,
    is_inventoriable: false,
    is_supply: false,
    is_producible: false,
    base_price: '',
    markup_percent: '',
    is_available_in_pos: true,
    tag_ulids: [],
});

watch(
    () => props.article,
    (article) => {
        if (article === null) {
            return;
        }

        form.value = {
            code: article.code ?? '',
            name: article.name,
            short_name: article.short_name ?? '',
            category_ulid: article.category?.ulid ?? '',
            base_unit_ulid: article.base_unit?.ulid ?? '',
            is_sellable: article.capabilities?.sellable ?? false,
            is_inventoriable: article.capabilities?.inventoriable ?? false,
            is_supply: article.capabilities?.supply ?? false,
            is_producible: article.capabilities?.producible ?? false,
            base_price: article.base_price ?? '',
            markup_percent: article.markup_percent ?? '',
            is_available_in_pos: article.is_available_in_pos ?? true,
            tag_ulids: (article.tags ?? []).map((tag) => tag.ulid),
        };
    },
    { immediate: true },
);

const hasCapability = computed(
    () =>
        form.value.is_sellable ||
        form.value.is_inventoriable ||
        form.value.is_supply ||
        form.value.is_producible,
);

/**
 * Las advertencias son las tres combinaciones que el servidor rechaza, dichas antes de intentarlo.
 * No sustituyen a la validación: si esta lista se quedara corta, el 422 aparece igual.
 */
const warnings = computed(() => {
    const list = [];

    if (!hasCapability.value) {
        list.push(
            'Marca al menos una capacidad: un artículo que no se vende, no se inventaría, no es ' +
                'insumo y no se produce no se puede usar para nada.',
        );
    }

    if (form.value.is_sellable && form.value.category_ulid === '') {
        list.push('Un artículo vendible necesita categoría: el punto de venta agrupa la pantalla por categoría.');
    }

    if (form.value.is_sellable && form.value.base_price === '') {
        list.push('Un artículo vendible necesita precio: sin precio no se puede cobrar.');
    }

    if (form.value.is_producible && !form.value.is_supply && !form.value.is_sellable) {
        list.push(
            'Producible sin ser vendible ni insumo describe algo que se prepara y no se usa en ningún ' +
                'sitio. Es válido, pero suele faltar una capacidad.',
        );
    }

    return list;
});

const save = useApiForm(async () => {
    const body = {
        code: form.value.code === '' ? null : form.value.code,
        name: form.value.name,
        short_name: form.value.short_name === '' ? null : form.value.short_name,
        category_ulid: form.value.category_ulid === '' ? null : form.value.category_ulid,
        is_sellable: form.value.is_sellable,
        is_inventoriable: form.value.is_inventoriable,
        is_supply: form.value.is_supply,
        is_producible: form.value.is_producible,

        // El markup vacío significa «usa el del negocio», que es una configuración jerárquica: se
        // manda `null` y no se omite, porque omitirlo en un PATCH querría decir «déjalo como está».
        markup_percent: form.value.markup_percent === '' ? null : form.value.markup_percent,
        is_available_in_pos: form.value.is_available_in_pos,
        tag_ulids: form.value.tag_ulids,
    };

    if (isNew.value) {
        await api.post('/articles', {
            ...body,
            base_unit_ulid: form.value.base_unit_ulid,

            // El precio se fija SÓLO al crear. Después pasa por la pantalla de precio, que lo
            // historiza con el estado del costeo y un motivo (D15).
            base_price: form.value.is_sellable ? form.value.base_price : null,
        });
    } else {
        await api.patch(`/articles/${props.article.ulid}`, body);
    }
});

async function submit() {
    if (await save.submit()) {
        emit('saved');
    }
}

function toggleTag(ulid) {
    const index = form.value.tag_ulids.indexOf(ulid);

    if (index === -1) {
        form.value.tag_ulids.push(ulid);
    } else {
        form.value.tag_ulids.splice(index, 1);
    }
}

const capabilities = [
    {
        key: 'is_sellable',
        label: 'Vendible',
        hint: 'Se cobra. Aparece en el punto de venta y necesita precio y categoría.',
    },
    {
        key: 'is_inventoriable',
        label: 'Inventariable',
        hint: 'Se controlan sus existencias: entra por compra y sale por venta o merma.',
    },
    {
        key: 'is_supply',
        label: 'Insumo',
        hint: 'Puede aparecer como ingrediente en la receta de otro artículo.',
    },
    {
        key: 'is_producible',
        label: 'Producible',
        hint: 'Tiene receta propia: su costo se calcula a partir de lo que consume.',
    },
];
</script>

<template>
    <div class="drawer-backdrop" @click.self="emit('close')">
        <form class="drawer drawer--wide" @submit.prevent="submit">
            <h2>{{ isNew ? 'Nuevo artículo' : `Editar ${props.article.name}` }}</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <fieldset class="caps">
                <legend class="field__label">¿Qué es este artículo?</legend>

                <label v-for="cap in capabilities" :key="cap.key" class="cap">
                    <input v-model="form[cap.key]" type="checkbox" />
                    <span>
                        <span class="cap__label">{{ cap.label }}</span>
                        <span class="cap__hint">{{ cap.hint }}</span>
                    </span>
                </label>

                <span v-if="save.fieldErrors.value.is_sellable" class="field__error">
                    {{ save.fieldErrors.value.is_sellable }}
                </span>
            </fieldset>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="160" required placeholder="Enchiladas suizas" />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <div class="pair">
                <label class="field">
                    <span class="field__label">Nombre corto</span>
                    <input v-model="form.short_name" class="input" maxlength="40" placeholder="Ench. suizas" />
                    <span class="field__hint">Lo que cabe en la comanda y en el botón del POS.</span>
                    <span v-if="save.fieldErrors.value.short_name" class="field__error">
                        {{ save.fieldErrors.value.short_name }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Código</span>
                    <input v-model="form.code" class="input" maxlength="40" placeholder="Opcional" />
                    <span class="field__hint">
                        Opcional: un restaurante no le pone código a un platillo.
                    </span>
                    <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
                </label>
            </div>

            <label class="field">
                <span class="field__label">
                    Categoría<template v-if="form.is_sellable"> (obligatoria si se vende)</template>
                </span>
                <select v-model="form.category_ulid" class="input">
                    <option value="">Sin categoría</option>
                    <option v-for="option in props.categories" :key="option.ulid" :value="option.ulid">
                        {{ option.label }}
                    </option>
                </select>
                <span v-if="save.fieldErrors.value.category_ulid" class="field__error">
                    {{ save.fieldErrors.value.category_ulid }}
                </span>
            </label>

            <label v-if="isNew" class="field">
                <span class="field__label">Unidad base</span>
                <select v-model="form.base_unit_ulid" class="input" required>
                    <option value="">Elige una…</option>
                    <option v-for="unit in props.units" :key="unit.ulid" :value="unit.ulid">
                        {{ unit.name }} ({{ unit.code }}) · {{ unit.dimension_label }}
                    </option>
                </select>
                <span class="field__hint">
                    <strong>No se puede cambiar después.</strong> Es la unidad en la que se guardan
                    todas las cantidades, costos y existencias de este artículo.
                </span>
                <span v-if="save.fieldErrors.value.base_unit_ulid" class="field__error">
                    {{ save.fieldErrors.value.base_unit_ulid }}
                </span>
            </label>

            <p v-else class="locked">
                Unidad base: <strong>{{ props.article.base_unit?.code ?? '—' }}</strong>. No se puede
                cambiar: reinterpretaría todas las cantidades y costos ya capturados.
            </p>

            <template v-if="form.is_sellable">
                <label v-if="isNew" class="field">
                    <span class="field__label">Precio de venta (IVA incluido)</span>
                    <input v-model="form.base_price" class="input" inputmode="decimal" placeholder="0.00" />
                    <span class="field__hint">
                        El precio se captura con IVA incluido, como se dice en voz alta. El desglose lo
                        calcula el sistema.
                    </span>
                    <span v-if="save.fieldErrors.value.base_price" class="field__error">
                        {{ save.fieldErrors.value.base_price }}
                    </span>
                </label>

                <p v-else class="locked">
                    Precio actual: <strong>${{ props.article.base_price }}</strong>. Se cambia en la
                    ficha del artículo, donde queda registrado con su motivo y el costo del momento.
                </p>

                <label class="field">
                    <span class="field__label">Markup propio (%)</span>
                    <input v-model="form.markup_percent" class="input" inputmode="decimal" placeholder="Hereda del negocio" />
                    <span class="field__hint">
                        <strong>Markup = utilidad ÷ costo</strong>, no margen. Vacío hereda el del
                        negocio; ponlo sólo si este artículo trabaja distinto.
                    </span>
                    <span v-if="save.fieldErrors.value.markup_percent" class="field__error">
                        {{ save.fieldErrors.value.markup_percent }}
                    </span>
                </label>

                <label class="field field--check">
                    <input v-model="form.is_available_in_pos" type="checkbox" />
                    <span>
                        <span class="field__label">Disponible en el punto de venta</span>
                        <span class="field__hint">
                            Desmarcado lo oculta sin archivarlo: sirve para lo que sólo se vende algunos
                            días. Cada sucursal puede decidir lo suyo desde la ficha.
                        </span>
                    </span>
                </label>
            </template>

            <fieldset v-if="props.tags.length" class="tags">
                <legend class="field__label">Etiquetas</legend>

                <label v-for="tag in props.tags" :key="tag.ulid" class="tag">
                    <input
                        type="checkbox"
                        :checked="form.tag_ulids.includes(tag.ulid)"
                        @change="toggleTag(tag.ulid)"
                    />
                    <span>{{ tag.name }}</span>
                </label>
            </fieldset>

            <ul v-if="warnings.length" class="warnings">
                <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
            </ul>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="emit('close')"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value"><Icon name="check" /> Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.drawer--wide {
    max-width: 32rem;
}

.caps,
.tags {
    border: 1px solid #e7e5e4;
    border-radius: 0.375rem;
    padding: 0.75rem;
    margin: 0 0 0.9rem;
}

.caps legend,
.tags legend {
    padding: 0 0.35rem;
}

.cap {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.3rem 0;
}

.cap input {
    margin-top: 0.25rem;
}

.cap__label {
    display: block;
    font-size: 0.9rem;
    font-weight: 500;
}

.cap__hint {
    display: block;
    font-size: 0.78rem;
    opacity: 0.6;
}

.pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.field--check {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.field--check input {
    margin-top: 0.2rem;
}

.tag {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    margin: 0.15rem 0.6rem 0.15rem 0;
    font-size: 0.85rem;
}

.locked {
    margin: 0 0 0.9rem;
    padding: 0.55rem 0.7rem;
    background: #fafaf9;
    border: 1px solid #e7e5e4;
    border-radius: 0.375rem;
    font-size: 0.8rem;
    opacity: 0.85;
}

/*
   Las advertencias son ámbar y no rojas a propósito: rojo dice «esto falló», y aquí todavía no ha
   fallado nada. Son lo que el servidor va a rechazar si se guarda así.
*/
.warnings {
    margin: 0 0 0.9rem;
    padding: 0.6rem 0.85rem 0.6rem 1.9rem;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 0.375rem;
    font-size: 0.82rem;
    color: #92400e;
}

.warnings li + li {
    margin-top: 0.35rem;
}
</style>
