<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../api/client';
import { useAuthorization } from '../../composables/useAuthorization';
import { formatMoney } from '../../support/money';
import { pushToast } from '../../stores/useToasts';
import Icon from '../Icon.vue';
import PinAuthorizationDialog from '../inventory/PinAuthorizationDialog.vue';

/**
 * Registrar un gasto (§6.5). Lo usan la pantalla de Gastos y la Caja.
 *
 * ## Captura y nada más
 *
 * El servidor decide todo lo que importa: a qué turno se carga un gasto desde caja (lo resuelve él, nunca el cliente),
 * si el monto pasa del umbral de la sucursal y si la categoría sigue activa. Aquí sólo se juntan los datos y se dice en
 * lenguaje llano qué va a pasar con el dinero.
 *
 * ## Los dos orígenes, y sus dos permisos
 *
 * «Desde caja» sale del efectivo del turno y entra en el arqueo; «fuera de caja» es gasto del negocio y no toca la caja
 * de nadie. La ruta exige el permiso de gasto DESDE caja —el mínimo para entrar— y el de FUERA de caja lo comprueba el
 * endpoint contra el `source`. Por eso «fuera de caja» sólo se ofrece a quien tiene los dos: con uno solo sería un
 * botón que responde 403.
 *
 * ## El 409 del umbral no es un error: es una firma pendiente
 *
 * Sobre el monto configurado por sucursal, el servidor responde `authorization_required` con el permiso que hace falta.
 * Se abre el diálogo de PIN y se reintenta el MISMO gasto con el token (ADR-008). El token no se pide por adelantado:
 * es de un solo uso, y pedirlo «por si acaso» gastaría una firma que quizá no hacía falta.
 *
 * ## Sin comprobante, por ahora
 *
 * `receipt_path` es opcional en el servidor y no existe un endpoint para subir el archivo. Pedir una ruta tecleada a
 * mano no tendría sentido, así que el campo se omite hasta que exista la carga.
 */
const props = defineProps({
    /** Prefijo de los `id` de los campos, para que dos formularios en la misma página no choquen. */
    idPrefix: { type: String, default: 'gasto' },

    /** Sucursal fija (la Caja: la del turno). Vacío = se elige entre `branches`. */
    branchUlid: { type: String, default: '' },

    /** Sucursales entre las que se elige cuando no hay una fija: las del alcance de la persona (`/context`). */
    branches: { type: Array, default: () => [] },

    /** Origen fijo (la Caja: `cash_session`). Vacío = se elige, según los permisos del rol activo. */
    source: { type: String, default: '' },

    /** Categorías ACTIVAS: registrar con una inactiva responde 422. */
    categories: { type: Array, default: () => [] },

    /** Métodos de pago ACTIVOS, para el gasto fuera de caja. */
    paymentMethods: { type: Array, default: () => [] },

    /** Con botón «Cancelar»: la pantalla de Gastos abre y cierra el formulario; en la Caja está siempre a la vista. */
    cancellable: { type: Boolean, default: false },
});

const emit = defineEmits(['registered', 'cancel']);

const page = usePage();
const { canWrite } = useAuthorization();

/** Los orígenes que el rol activo puede registrar. Es presentación: el servidor vuelve a decidir. */
const origenes = computed(() => {
    if (props.source) {
        return [props.source];
    }

    const lista = [];

    if (canWrite('finance.expenses.create_from_cash')) {
        lista.push('cash_session');

        // Con los DOS permisos: la ruta pide el de caja para entrar, y el endpoint el de fuera de caja por el `source`.
        if (canWrite('finance.expenses.create_outside_cash')) {
            lista.push('outside_cash');
        }
    }

    return lista;
});

/** La sucursal por omisión: la activa, si está entre las ofrecidas; si no, la primera. */
function sucursalInicial() {
    if (props.branchUlid) {
        return props.branchUlid;
    }

    const activa = page.props.context?.branch_ulid;

    return props.branches.some((b) => b.ulid === activa) ? activa : (props.branches[0]?.ulid ?? '');
}

// Sucursal, origen y método se CONSERVAN de un gasto al siguiente: quien registra tres gastos seguidos casi siempre los
// registra en la misma caja. Monto, categoría y concepto se limpian, para no duplicar un gasto por descuido.
const nuevo = (anterior = {}) => ({
    branch_ulid: anterior.branch_ulid || sucursalInicial(),
    source: anterior.source || (origenes.value.length === 1 ? origenes.value[0] : ''),
    expense_category_ulid: '',
    amount: '',
    description: '',
    payment_method_ulid: anterior.payment_method_ulid ?? '',
});

const form = ref(nuevo());
const procesando = ref(false);
const errores = ref({});
const errorGeneral = ref(null);

/** El 409 pendiente, `{ permission, reason }`: abre el diálogo de PIN. `null` = nada esperando firma. */
const pendiente = ref(null);

// La Caja monta el formulario antes de conocer el turno, y las sucursales de la pantalla de Gastos llegan después: se
// sincroniza cuando aparecen, sin pisar una elección que la persona ya hizo.
watch(() => props.branchUlid, (ulid) => {
    if (ulid) {
        form.value.branch_ulid = ulid;
    }
});

watch(() => props.branches, () => {
    if (! form.value.branch_ulid) {
        form.value.branch_ulid = sucursalInicial();
    }
});

watch(origenes, (lista) => {
    if (! lista.includes(form.value.source)) {
        form.value.source = lista.length === 1 ? lista[0] : '';
    }
});

const esFueraDeCaja = computed(() => form.value.source === 'outside_cash');

/**
 * Qué dice el formulario cuando el origen no se elige porque el rol sólo tiene uno. Con un origen FIJO no dice nada: la
 * pantalla que lo fija (la Caja) ya explica de dónde sale el dinero, y repetirlo sería ruido.
 */
const origenUnico = computed(() => {
    if (props.source || origenes.value.length !== 1) {
        return null;
    }

    return origenes.value[0] === 'cash_session'
        ? 'Desde caja: sale del efectivo del turno abierto de la sucursal y se descuenta en su arqueo.'
        : 'Fuera de caja: lo pagó el negocio por otro medio y no toca el arqueo de ninguna caja.';
});

/** Los campos que pintan su error debajo. Cualquier otro error —el invariante del módulo llega bajo `finance`— va arriba. */
const camposVisibles = computed(() => [
    ...(props.branchUlid ? [] : ['branch_ulid']),
    ...(origenes.value.length > 1 ? ['source'] : []),
    'expense_category_ulid',
    'amount',
    'description',
    ...(esFueraDeCaja.value ? ['payment_method_ulid'] : []),
]);

const sinCategorias = computed(() => props.categories.length === 0);
const sinMetodos = computed(() => esFueraDeCaja.value && props.paymentMethods.length === 0);

/** El monto como lo leerá quien confirma. Si lo tecleado no es un número, se muestra tal cual: el servidor lo rechaza. */
function montoLegible(valor) {
    const formateado = formatMoney(valor);

    return formateado === '—' ? String(valor ?? '').trim() : formateado;
}

function textoConfirmacion() {
    const monto = montoLegible(form.value.amount);

    if (esFueraDeCaja.value) {
        const metodo = props.paymentMethods.find((m) => m.ulid === form.value.payment_method_ulid)?.name ?? 'el método elegido';

        return `¿Registrar un gasto de ${monto} fuera de caja, pagado con ${metodo}? No toca el arqueo de ninguna caja. `
            + 'Queda asentado en el diario financiero y no se puede editar ni borrar.';
    }

    return `¿Registrar un gasto de ${monto} desde la caja? Sale del efectivo del turno abierto de la sucursal y se `
        + 'descuenta en su arqueo. Queda asentado en el diario financiero y no se puede editar ni borrar.';
}

/**
 * Envía el gasto. Sin token la primera vez; si el servidor pide firma, el diálogo de PIN llama aquí otra vez con ella.
 */
async function intentar(authorizationToken = null) {
    if (procesando.value) {
        return;
    }

    // Se confirma UNA vez: el reintento con la firma es la misma operación que ya se confirmó.
    if (! authorizationToken && ! window.confirm(textoConfirmacion())) {
        return;
    }

    procesando.value = true;
    errores.value = {};
    errorGeneral.value = null;

    const cuerpo = {
        branch_ulid: props.branchUlid || form.value.branch_ulid,
        expense_category_ulid: form.value.expense_category_ulid,
        source: form.value.source,
        amount: String(form.value.amount).trim(),
        description: form.value.description.trim(),
    };

    // El método sólo en el de fuera de caja: el de caja se pagó con el efectivo del turno y eso lo asienta el servidor.
    if (esFueraDeCaja.value) {
        cuerpo.payment_method_ulid = form.value.payment_method_ulid;
    }

    if (authorizationToken) {
        cuerpo.authorization_token = authorizationToken;
    }

    try {
        const { data } = await api.post('/expenses', cuerpo);

        pendiente.value = null;
        form.value = nuevo(form.value);
        pushToast(`Gasto de ${formatMoney(data.amount)} registrado.`);
        emit('registered', data);
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        // No es un error: es la firma que el umbral pide. El permiso viene en el 409.
        if (e.isAuthorizationRequired) {
            pendiente.value = { permission: e.requiredPermission, reason: e.message };

            return;
        }

        // Cualquier otro fallo cierra el PIN, para que el aviso se lea en el formulario y no detrás del diálogo.
        pendiente.value = null;

        if (e.isValidation) {
            errores.value = e.fieldErrors;

            const sinCampo = Object.entries(e.fieldErrors).find(([campo]) => ! camposVisibles.value.includes(campo));
            errorGeneral.value = sinCampo ? sinCampo[1] : null;
        } else {
            // 409 sin caja abierta, 403 sin el permiso de fuera de caja: mensajes escritos para quien opera.
            errorGeneral.value = e.message;
        }
    } finally {
        procesando.value = false;
    }
}

/** `aria-describedby` del campo, sólo cuando tiene error que leer. */
function descrito(campo, sufijo) {
    return errores.value[campo] ? `${props.idPrefix}-${sufijo}-error` : undefined;
}
</script>

<template>
    <form class="gasto" @submit.prevent="intentar()">
        <fieldset v-if="origenes.length > 1" class="gasto__origen">
            <legend class="field__label">¿De dónde salió el dinero?</legend>

            <div class="gasto__opciones">
                <label
                    :for="`${idPrefix}-origen-caja`"
                    class="opcion"
                    :class="{ 'opcion--activa': form.source === 'cash_session' }"
                >
                    <input
                        :id="`${idPrefix}-origen-caja`"
                        v-model="form.source"
                        type="radio"
                        :name="`${idPrefix}-origen`"
                        value="cash_session"
                        required
                    />
                    <span class="opcion__texto">
                        <strong>Desde caja</strong>
                        <span>
                            Del efectivo del turno abierto de la sucursal. Se descuenta en el arqueo del cajero, así que
                            hace falta tener la caja abierta.
                        </span>
                    </span>
                </label>

                <label
                    :for="`${idPrefix}-origen-fuera`"
                    class="opcion"
                    :class="{ 'opcion--activa': form.source === 'outside_cash' }"
                >
                    <input
                        :id="`${idPrefix}-origen-fuera`"
                        v-model="form.source"
                        type="radio"
                        :name="`${idPrefix}-origen`"
                        value="outside_cash"
                    />
                    <span class="opcion__texto">
                        <strong>Fuera de caja</strong>
                        <span>
                            Lo pagó el negocio por otro medio —transferencia, tarjeta de la empresa—. No toca el arqueo
                            de ninguna caja.
                        </span>
                    </span>
                </label>
            </div>

            <span v-if="errores.source" class="field__error">{{ errores.source }}</span>
        </fieldset>

        <p v-else-if="origenUnico" class="gasto__nota">{{ origenUnico }}</p>

        <div class="gasto__rejilla">
            <div v-if="! branchUlid" class="field">
                <label class="field__label" :for="`${idPrefix}-sucursal`">Sucursal</label>
                <select
                    :id="`${idPrefix}-sucursal`"
                    v-model="form.branch_ulid"
                    class="input"
                    required
                    :aria-invalid="errores.branch_ulid ? 'true' : undefined"
                    :aria-describedby="descrito('branch_ulid', 'sucursal')"
                >
                    <option value="" disabled>Elige sucursal…</option>
                    <option v-for="b in branches" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                </select>
                <span v-if="errores.branch_ulid" :id="`${idPrefix}-sucursal-error`" class="field__error">
                    {{ errores.branch_ulid }}
                </span>
            </div>

            <div class="field">
                <label class="field__label" :for="`${idPrefix}-categoria`">Categoría</label>
                <select
                    :id="`${idPrefix}-categoria`"
                    v-model="form.expense_category_ulid"
                    class="input"
                    required
                    :aria-invalid="errores.expense_category_ulid ? 'true' : undefined"
                    :aria-describedby="descrito('expense_category_ulid', 'categoria')"
                >
                    <option value="" disabled>{{ sinCategorias ? 'No hay categorías activas' : 'Elige categoría…' }}</option>
                    <option v-for="c in categories" :key="c.ulid" :value="c.ulid">{{ c.name }}</option>
                </select>
                <span v-if="errores.expense_category_ulid" :id="`${idPrefix}-categoria-error`" class="field__error">
                    {{ errores.expense_category_ulid }}
                </span>
            </div>

            <div class="field">
                <label class="field__label" :for="`${idPrefix}-monto`">Monto</label>
                <input
                    :id="`${idPrefix}-monto`"
                    v-model="form.amount"
                    class="input"
                    type="text"
                    inputmode="decimal"
                    placeholder="0.00"
                    autocomplete="off"
                    required
                    :aria-invalid="errores.amount ? 'true' : undefined"
                    :aria-describedby="descrito('amount', 'monto')"
                />
                <span v-if="errores.amount" :id="`${idPrefix}-monto-error`" class="field__error">{{ errores.amount }}</span>
            </div>

            <div v-if="esFueraDeCaja" class="field">
                <label class="field__label" :for="`${idPrefix}-metodo`">¿Con qué se pagó?</label>
                <select
                    :id="`${idPrefix}-metodo`"
                    v-model="form.payment_method_ulid"
                    class="input"
                    required
                    :aria-invalid="errores.payment_method_ulid ? 'true' : undefined"
                    :aria-describedby="descrito('payment_method_ulid', 'metodo')"
                >
                    <option value="" disabled>{{ paymentMethods.length ? 'Elige método…' : 'No hay métodos activos' }}</option>
                    <option v-for="m in paymentMethods" :key="m.ulid" :value="m.ulid">{{ m.name }}</option>
                </select>
                <span v-if="errores.payment_method_ulid" :id="`${idPrefix}-metodo-error`" class="field__error">
                    {{ errores.payment_method_ulid }}
                </span>
            </div>

            <div class="field gasto__ancho">
                <label class="field__label" :for="`${idPrefix}-concepto`">¿En qué se gastó?</label>
                <textarea
                    :id="`${idPrefix}-concepto`"
                    v-model="form.description"
                    class="input"
                    rows="2"
                    minlength="3"
                    maxlength="300"
                    required
                    placeholder="Ej. Garrafones de agua para la cocina"
                    :aria-invalid="errores.description ? 'true' : undefined"
                    :aria-describedby="descrito('description', 'concepto')"
                ></textarea>
                <span class="field__hint">
                    La categoría sola no lo explica: «Gastos varios: $800» no le dice nada a quien revisa el arqueo.
                </span>
                <span v-if="errores.description" :id="`${idPrefix}-concepto-error`" class="field__error">
                    {{ errores.description }}
                </span>
            </div>
        </div>

        <p v-if="sinCategorias" class="alert alert--notice gasto__aviso" role="status">
            No hay categorías de gasto activas, y sin categoría no se puede registrar un gasto. Quien administra los
            gastos puede crear o activar una en la pantalla de Gastos, pestaña «Categorías».
        </p>

        <p v-else-if="sinMetodos" class="alert alert--notice gasto__aviso" role="status">
            No hay métodos de pago activos que elegir para un gasto fuera de caja.
        </p>

        <p class="gasto__nota">
            Si el monto pasa del umbral que la sucursal tiene configurado, se pedirá el PIN de un superior: la
            autorización queda registrada a su nombre.
        </p>

        <p v-if="errorGeneral" class="alert gasto__aviso" role="alert">{{ errorGeneral }}</p>

        <div class="gasto__acciones">
            <button v-if="cancellable" type="button" class="link-button" :disabled="procesando" @click="emit('cancel')">
                <Icon name="x" /> Cancelar
            </button>
            <button type="submit" class="button" :disabled="procesando || sinCategorias || sinMetodos">
                <Icon name="check" /> {{ procesando ? 'Registrando…' : 'Registrar gasto' }}
            </button>
        </div>
    </form>

    <!--
        A `body` y no aquí: el diálogo es `position: fixed`, y si este formulario vive dentro de un contenedor con
        `transform` —el panel lateral y las animaciones de entrada dejan uno puesto— el «fijo» se mediría contra ese
        contenedor y el fondo no cubriría la pantalla.
    -->
    <Teleport to="body">
        <PinAuthorizationDialog
            v-if="pendiente"
            :required-permission="pendiente.permission"
            :reason="pendiente.reason"
            @granted="(token) => intentar(token)"
            @cancelled="pendiente = null"
        />
    </Teleport>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.gasto {
    display: grid;
    gap: 0.85rem;
}

/* `.field` y `.alert` traen margen inferior pensado para formularios en bloque; aquí la rejilla ya separa con `gap`. */
.gasto .field,
.gasto__aviso {
    margin: 0;
}

.gasto__rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
    gap: 0.85rem;
}

.gasto__ancho {
    grid-column: 1 / -1;
}

.gasto__origen {
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.gasto__opciones {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 0.6rem;
}

.opcion {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.65rem 0.8rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}

.opcion:hover {
    border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde));
}

.opcion--activa {
    border-color: var(--color-acento);
    background: color-mix(in srgb, var(--color-acento) 6%, var(--color-superficie));
}

.opcion input {
    margin-top: 0.15rem;
}

.opcion__texto {
    display: grid;
    gap: 0.15rem;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.opcion__texto span {
    font-size: 0.82rem;
    color: var(--color-suave);
    line-height: 1.4;
}

.gasto__nota {
    margin: 0;
    font-size: 0.82rem;
    color: var(--color-suave);
    line-height: 1.45;
}

.gasto__acciones {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
}

textarea.input {
    resize: vertical;
}
</style>
