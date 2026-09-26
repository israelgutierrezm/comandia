<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import { formatMoney } from '../../../support/money';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Cupones de la tienda (Iteración 8, Tanda D, D3). Crear, listar, editar, activar/desactivar y eliminar cupones: código,
 * tipo (%/monto/envío gratis), vigencia y topes de uso. Sólo aparece con el módulo Ecommerce y `ecommerce.coupons.manage`.
 * El canje ocurre en el checkout (parte 2).
 *
 * ## Editar reemplaza el cupón COMPLETO
 *
 * `PUT /coupons/{cupón}` valida con el mismo `SaveCouponRequest` que el alta: código, tipo, valor y estado son
 * obligatorios, y el servidor sólo actualiza las llaves que llegan. Por eso se manda todo —también al activar o
 * desactivar desde la tabla— y un opcional vaciado viaja como `null` explícito: omitido, conservaría el valor anterior y
 * «quitar el tope» no quitaría nada.
 *
 * ## Nada se congela tras el primer canje
 *
 * El servidor deja editar cualquier campo aunque el cupón ya se haya usado. Lo que ya pasó no cambia —cada pedido guarda
 * su descuento y cada canje su monto—; lo editado aplica a los canjes siguientes. La pantalla lo avisa en lugar de
 * impedirlo: la regla es del backend.
 */
const TYPES = [
    { value: 'percentage', label: 'Porcentaje' },
    { value: 'fixed', label: 'Monto fijo' },
    { value: 'free_shipping', label: 'Envío gratis' },
];

const blank = () => ({
    code: '', type: 'percentage', value: '', valid_from: '', valid_until: '', max_uses: '', per_customer_limit: '', is_active: true,
});

const { canWrite } = useAuthorization();

// Sin el permiso de escritura (o con el negocio en sólo lectura) no se ofrece nada que el servidor rechazaría con 403.
const puedeEscribir = computed(() => canWrite('ecommerce.coupons.manage'));

const coupons = ref([]);
// `loaded` sólo se prende con una lectura buena: sin ella, «Aún no hay cupones» mentiría sobre una carga que falló.
const loaded = ref(false);
const loadError = ref(null);
const form = ref(blank());
// El cupón que se está editando; `null` = el formulario da de alta uno nuevo.
const editing = ref(null);
const error = ref(null);
const removing = ref(null);
const toggling = ref(null);
const formEl = ref(null);
const codeInput = ref(null);

const needsValue = computed(() => form.value.type !== 'free_shipping');

onMounted(load);

async function load() {
    try {
        const { data } = await api.get('/coupons');
        coupons.value = data;
        loadError.value = null;
        loaded.value = true;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    }
}

/**
 * El cuerpo que valida `SaveCouponRequest`, igual para crear, editar y activar/desactivar. Los opcionales vacíos van como
 * `null`: al crear equivale a omitirlos, y al editar es la única forma de vaciarlos.
 */
function payload(c) {
    const vacio = (v) => v === '' || v === null || v === undefined;

    return {
        code: c.code,
        type: c.type,
        // El envío gratis no lleva valor: el servidor lo fija en cero de todos modos (CHECK de la tabla).
        value: c.type === 'free_shipping' ? 0 : c.value,
        valid_from: vacio(c.valid_from) ? null : c.valid_from,
        valid_until: vacio(c.valid_until) ? null : c.valid_until,
        max_uses: vacio(c.max_uses) ? null : c.max_uses,
        per_customer_limit: vacio(c.per_customer_limit) ? null : c.per_customer_limit,
        is_active: c.is_active,
    };
}

const save = useApiForm(
    async () => {
        if (editing.value) {
            await api.put(`/coupons/${editing.value.ulid}`, payload(form.value));
        } else {
            await api.post('/coupons', payload(form.value));
        }
    },
    {
        success: () => ({ kind: editing.value ? 'update' : 'create', entity: 'Cupón', gender: 'm' }),
    },
);

function clearFormErrors() {
    save.fieldErrors.value = {};
    save.generalError.value = null;
}

async function submit() {
    const original = editing.value;
    const nuevo = form.value.code.trim();

    // Cambiar el código rompe el que ya se repartió: el checkout busca el cupón por código. Mayúsculas aparte, porque la
    // columna compara sin distinguirlas.
    if (original && nuevo.toUpperCase() !== original.code.toUpperCase()
        && !window.confirm(`¿Cambiar el código «${original.code}» por «${nuevo}»? Quien tenga «${original.code}» ya no podrá canjearlo en el checkout.`)) {
        return;
    }

    if (await save.submit()) {
        editing.value = null;
        form.value = blank();
        await load();
    }
}

async function startEdit(c) {
    editing.value = c;
    form.value = {
        code: c.code,
        type: c.type,
        value: c.type === 'free_shipping' ? '' : c.value,
        valid_from: c.valid_from ?? '',
        valid_until: c.valid_until ?? '',
        max_uses: c.max_uses ?? '',
        per_customer_limit: c.per_customer_limit ?? '',
        is_active: c.is_active,
    };
    clearFormErrors();

    // El formulario está arriba de la tabla: sin llevar la vista hasta él, en un teléfono «Editar» parecería no hacer nada.
    await nextTick();
    const reducido = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    formEl.value?.scrollIntoView({ behavior: reducido ? 'auto' : 'smooth', block: 'start' });
    codeInput.value?.focus({ preventScroll: true });
}

function cancelEdit() {
    editing.value = null;
    form.value = blank();
    clearFormErrors();
}

const toggleForm = useApiForm(
    // Sin devolver la respuesta: `useApiForm` lee `null` (lo que da un 204) como fallo; `undefined`, como éxito.
    async (c) => {
        await api.put(`/coupons/${c.ulid}`, payload({ ...c, is_active: !c.is_active }));
    },
    {
        success: (result, [c]) => (c.is_active
            ? `Cupón «${c.code}» desactivado: el checkout ya no lo acepta.`
            : `Cupón «${c.code}» activado.`),
    },
);

async function toggleActive(c) {
    // Desactivar es reversible, pero surte efecto al instante: quien tenga el código deja de poder usarlo.
    if (c.is_active && !window.confirm(
        `¿Desactivar el cupón «${c.code}»? El checkout lo rechazará desde ahora y hasta que lo vuelvas a activar. `
        + 'Los pedidos que ya lo usaron conservan su descuento.',
    )) return;

    toggling.value = c.ulid;
    error.value = null;
    try {
        if (await toggleForm.submit(c)) await load();
    } finally {
        toggling.value = null;
    }
}

async function remove(coupon) {
    // Es un borrado real, no una baja: el cupón desaparece y quien tenga el código ya no puede canjearlo.
    if (!window.confirm(`¿Eliminar el cupón «${coupon.code}»? Dejará de aplicarse en el checkout y no se puede deshacer.`)) return;

    removing.value = coupon.ulid;
    error.value = null;
    toggleForm.generalError.value = null;
    try {
        await api.delete(`/coupons/${coupon.ulid}`);
        await load();
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        removing.value = null;
    }
}

/** Una fila con una acción en curso no admite otra encima. */
function ocupado(c) {
    return removing.value === c.ulid || toggling.value === c.ulid;
}

/** El cupón abierto en el formulario: activarlo o borrarlo desde la tabla dejaría al formulario guardando algo viejo. */
function enEdicion(c) {
    return editing.value?.ulid === c.ulid;
}

function describe(c) {
    if (c.type === 'free_shipping') return 'Envío gratis';
    if (c.type === 'percentage') return `${c.value}% de descuento`;
    return `${formatMoney(c.value)} de descuento`;
}
</script>

<template>
    <Head title="Cupones" />

    <div class="cupones animar-entrada">
        <ListHeader
            title="Cupones"
            subtitle="Códigos de descuento para el checkout de la tienda. El canje respeta la vigencia y los topes de uso."
            :count="loaded ? coupons.length : null"
        />

        <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
        <p v-if="error" class="alert" role="alert">{{ error }}</p>
        <p v-else-if="toggleForm.generalError.value" class="alert" role="alert">{{ toggleForm.generalError.value }}</p>

        <!-- Cada control con su etiqueta visible, como «Desde»/«Hasta»: un placeholder desaparece al teclear, y los dos
             topes numéricos quedaban indistinguibles en cuanto se llenaban. El mismo formulario crea y edita. -->
        <form v-if="puedeEscribir" ref="formEl" class="tarjeta nuevo" :class="{ 'nuevo--editando': editing }" @submit.prevent="submit">
            <p v-if="editing" class="nuevo__linea nuevo__titulo">
                Editando el cupón <strong>«{{ editing.code }}»</strong>
            </p>
            <p v-if="editing && editing.uses_count > 0" class="nuevo__linea nuevo__aviso">
                Ya se canjeó {{ editing.uses_count }} {{ editing.uses_count === 1 ? 'vez' : 'veces' }}. Los pedidos que lo
                usaron conservan su descuento; lo que cambies aplica a los canjes siguientes, y los topes cuentan los usos que
                ya lleva.
            </p>
            <p v-if="save.generalError.value" class="alert nuevo__linea nuevo__error" role="alert">{{ save.generalError.value }}</p>

            <label class="campo" for="cupon-codigo">Código
                <input id="cupon-codigo" ref="codeInput" v-model="form.code" class="input" :class="{ 'input--error': save.fieldErrors.value.code }"
                       type="text" maxlength="40" placeholder="p. ej. BIENVENIDO" required
                       :aria-invalid="save.fieldErrors.value.code ? 'true' : undefined" />
            </label>
            <label class="campo" for="cupon-tipo">Tipo
                <select id="cupon-tipo" v-model="form.type" class="input input--select" :class="{ 'input--error': save.fieldErrors.value.type }">
                    <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </label>
            <label v-if="needsValue" class="campo" for="cupon-valor">{{ form.type === 'percentage' ? 'Porcentaje' : 'Monto' }}
                <input id="cupon-valor" v-model="form.value" class="input campo--valor" :class="{ 'input--error': save.fieldErrors.value.value }"
                       type="text" inputmode="decimal" :placeholder="form.type === 'percentage' ? '1–100' : '0.00'" required
                       :aria-invalid="save.fieldErrors.value.value ? 'true' : undefined" />
            </label>
            <label class="campo" for="cupon-desde">Desde
                <input id="cupon-desde" v-model="form.valid_from" class="input" :class="{ 'input--error': save.fieldErrors.value.valid_from }" type="date" />
            </label>
            <label class="campo" for="cupon-hasta">Hasta
                <input id="cupon-hasta" v-model="form.valid_until" class="input" :class="{ 'input--error': save.fieldErrors.value.valid_until }" type="date"
                       :aria-invalid="save.fieldErrors.value.valid_until ? 'true' : undefined" />
            </label>
            <label class="campo" for="cupon-tope">Tope de usos
                <input id="cupon-tope" v-model="form.max_uses" class="input campo--num" :class="{ 'input--error': save.fieldErrors.value.max_uses }"
                       type="number" min="1" placeholder="Sin tope" />
            </label>
            <label class="campo" for="cupon-por-cliente">Por cliente
                <input id="cupon-por-cliente" v-model="form.per_customer_limit" class="input campo--num" :class="{ 'input--error': save.fieldErrors.value.per_customer_limit }"
                       type="number" min="1" placeholder="Sin tope" />
            </label>
            <label class="check" for="cupon-activo"><input id="cupon-activo" v-model="form.is_active" type="checkbox" /> Activo</label>
            <button type="submit" class="button" :disabled="save.processing.value">
                <template v-if="editing"><Icon name="check" /> Guardar cambios</template>
                <template v-else>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                    Crear cupón
                </template>
            </button>
            <button v-if="editing" type="button" class="link-button" :disabled="save.processing.value" @click="cancelEdit">
                <Icon name="x" /> Cancelar
            </button>
        </form>

        <div v-if="coupons.length" class="tabla-envoltura">
            <table class="lista">
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Descuento</th>
                        <th scope="col">Vigencia</th>
                        <th scope="col">Usos</th>
                        <th v-if="puedeEscribir" scope="col"><span class="oculto">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in coupons" :key="c.ulid" :class="{ inactivo: !c.is_active, editando: enEdicion(c) }">
                        <td>
                            <strong>{{ c.code }}</strong>
                            <span v-if="!c.is_active" class="badge badge--off">Inactivo</span>
                        </td>
                        <td>{{ describe(c) }}</td>
                        <td class="min">{{ c.valid_from ?? '—' }} … {{ c.valid_until ?? '—' }}</td>
                        <td class="min">{{ c.uses_count }}{{ c.max_uses ? ' / ' + c.max_uses : '' }}</td>
                        <td v-if="puedeEscribir" class="acciones-celda">
                            <div class="row-actions">
                                <button type="button" class="link-button link-button--warning" :disabled="ocupado(c) || enEdicion(c)" @click="startEdit(c)">
                                    <Icon name="edit" /> Editar
                                </button>
                                <!-- Sin rojo: desactivar se deshace con un clic. El rojo queda para lo que no se deshace. -->
                                <button type="button" class="link-button" :disabled="ocupado(c) || enEdicion(c)" @click="toggleActive(c)">
                                    {{ toggling === c.ulid ? (c.is_active ? 'Desactivando…' : 'Activando…') : (c.is_active ? 'Desactivar' : 'Activar') }}
                                </button>
                                <button type="button" class="link-button link-button--danger" :disabled="ocupado(c) || enEdicion(c)" @click="remove(c)">
                                    <Icon name="trash" /> Eliminar
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else-if="loaded && !loadError" class="page-header__hint">Aún no hay cupones.</p>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.cupones {
    display: grid;
    gap: 1rem;
}

.nuevo {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    align-items: center;
    padding: 1.1rem;
    border: 1px solid var(--color-borde);
}

/* En edición, el borde del acento dice que el formulario ya no da de alta: cambia uno existente. */
.nuevo--editando {
    border-color: var(--color-acento);
}

.nuevo .input {
    flex: 0 0 auto;
}

/* Título, aviso y error ocupan su propio renglón dentro del formulario en fila. */
.nuevo__linea {
    flex: 1 1 100%;
    margin: 0;
}

.nuevo__titulo {
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.nuevo__aviso {
    font-size: 0.82rem;
    color: var(--color-suave);
    max-width: 46rem;
}

.campo--valor {
    width: 9rem;
}

.campo--num {
    width: 8.5rem;
}

.campo {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    font-size: 0.8rem;
    color: var(--color-suave);
}

.campo .input {
    font-size: 0.85rem;
}

.check {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.tabla-envoltura {
    overflow-x: auto;
}

.lista {
    border-collapse: collapse;
    width: 100%;
    font-size: 0.9rem;
}

.lista th,
.lista td {
    text-align: left;
    padding: 0.55rem 0.7rem;
    border-bottom: 1px solid var(--color-borde);
}

.lista th {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-suave);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.lista .min {
    color: var(--color-suave);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

/* Se atenúan los datos, no las acciones: «Activar» en una fila inactiva tiene que verse disponible. */
.lista tr.inactivo td:not(.acciones-celda) {
    opacity: 0.55;
}

.lista tr.editando td {
    background: color-mix(in srgb, var(--color-acento) 6%, transparent);
}

.acciones-celda {
    text-align: right;
}

.acciones-celda .row-actions {
    justify-content: flex-end;
    flex-wrap: wrap;
}

.oculto {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
