<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { api, ApiError, MAX_PER_PAGE, orEmptyWhenForbidden } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';
import { formatMoney } from '../../support/money';
import Icon from '../Icon.vue';

/**
 * El crédito del cliente en su ficha: saldo, límite y disponible; cambiar el límite o suspenderlo; registrar un abono
 * (§6.3, D280–D282).
 *
 * ## Cada bloque con su permiso
 *
 * Las cifras llegan con el cliente (`CustomerResource`): las ve quien ve la ficha, igual que quien cobra en el POS.
 * Cambiar el límite y registrar abonos exigen `finance.customer_credit.manage` y que el negocio admita escrituras
 * (`canWrite`). El estado de cuenta vive aparte, en `CustomerCreditStatement`, con `finance.customer_credit.view`.
 *
 * ## El servidor calcula; aquí sólo se presenta
 *
 * Saldo, disponible y el saldo que deja un abono vienen del servidor (D134). Lo único que se compara aquí sirve para
 * ELEGIR QUÉ AVISO pintar —¿debe algo?, ¿el límite nuevo queda por debajo de lo que ya debe?—, y se compara en centavos
 * enteros sacados de la cadena decimal, sin pasar por un flotante. Que un abono no supere la deuda lo decide el
 * servidor (409, con su motivo): esa regla no se duplica aquí.
 *
 * ## Suspender no es bajar el límite a cero
 *
 * Con el crédito habilitado, pasarse del límite en el POS pide el PIN de un superior —también con límite $0—. Suspendido,
 * no se le fía ni con autorización, y el límite se conserva para cuando se vuelva a habilitar (D281).
 *
 * ## El abono entra a una caja
 *
 * El servidor lo registra en el turno abierto de la sucursal ACTIVA y cuenta en su corte; si el método mueve el cajón
 * (efectivo), el arqueo lo espera. Sin caja abierta responde 409, y la pantalla lo dice ANTES de capturar la cifra. Un
 * abono no se edita ni se borra, por eso se confirma con esa consecuencia a la vista.
 */
const props = defineProps({
    /** El cliente tal como lo sirve `CustomerResource`, con `credit` cargado. */
    customer: { type: Object, required: true },
});

/**
 * `updated`: el cliente fresco del servidor (tras guardar el límite o registrar un abono), para que la ficha lo
 * reemplace. `repaid`: el movimiento recién registrado, para que el estado de cuenta se relea.
 */
const emit = defineEmits(['updated', 'repaid']);

/** Un importe en pesos: hasta siete enteros (el tope del servidor es 9,999,999.99) y dos decimales, sin comas. */
const AMOUNT_PATTERN = '\\d{1,7}(\\.\\d{1,2})?';
const AMOUNT_TITLE = 'Un importe en pesos con hasta dos decimales y sin comas, por ejemplo 150 o 150.50.';

const page = usePage();
const { can, canWrite } = useAuthorization();

const credit = computed(() => props.customer.credit ?? null);
const canManage = computed(() => canWrite('finance.customer_credit.manage'));

/**
 * Centavos ENTEROS de un importe decimal («1500.5» → 150050), o `null` si la cadena no es un importe.
 *
 * Sólo para decidir qué aviso mostrar. La cadena se parte en enteros y decimales, así que ningún importe pasa por un
 * flotante; un DECIMAL(12,2) cabe de sobra en un entero exacto de JavaScript.
 */
function cents(value) {
    const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(String(value ?? '').trim());

    if (! match) {
        return null;
    }

    const total = Number(match[2]) * 100 + Number((match[3] ?? '').padEnd(2, '0'));

    return match[1] === '-' ? -total : total;
}

/** ¿Debe algo? Sin saldo no hay qué abonar: el servidor rechaza cualquier abono mayor que la deuda. */
const owes = computed(() => (cents(credit.value?.balance) ?? 0) > 0);

/**
 * La sucursal activa: el abono entra al turno abierto de ESA sucursal.
 *
 * Llaves planas del contexto de Inertia (`branch_ulid`, `branch_name`), no el `active_branch` anidado de
 * `/api/v1/context`: la misma trampa que documenta la pantalla de caja.
 */
const activeBranch = computed(() => {
    const context = page.props.context;

    return context?.branch_ulid ? { ulid: context.branch_ulid, name: context.branch_name } : null;
});

// ---------------------------------------------------------------------------
// Métodos de pago
// ---------------------------------------------------------------------------

const methods = ref([]);
const methodsLoading = ref(false);
const methodsLoaded = ref(false);
const methodsError = ref(null);

/** Verlos es otro permiso (`finance.payment_methods.view`): quien administra crédito podría no tenerlo. */
const canSeeMethods = computed(() => can('finance.payment_methods.view'));

onMounted(loadMethods);

async function loadMethods() {
    if (! canSeeMethods.value) {
        return;
    }

    methodsLoading.value = true;

    try {
        // Opcional para la ficha: un 403 llega como lista vacía y la pantalla lo explica, sin tumbar lo demás.
        const response = await orEmptyWhenForbidden(
            api.get('/payment-methods', { status: 'active', per_page: MAX_PER_PAGE }),
        );

        methods.value = response.data ?? [];
        methodsLoaded.value = true;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        methodsError.value = e;
    } finally {
        methodsLoading.value = false;
    }
}

/**
 * Con qué se recibe un abono: los métodos activos menos el propio crédito — pagar lo que se debe fiando más no es un
 * abono. El servidor también lo rechaza (422 en el método); filtrarlo aquí sólo evita ofrecer lo que no va a pasar.
 */
const repaymentMethods = computed(() => methods.value.filter((m) => m.kind !== 'customer_credit'));

/**
 * ¿Está encendido «Crédito del cliente»? `null` cuando no se sabe: el rol no ve los métodos o la lista llegó vacía (un
 * 403). Sólo se afirma «apagado» con una lista que sí se pudo leer.
 */
const creditMethodOn = computed(() => {
    if (! methodsLoaded.value || methods.value.length === 0) {
        return null;
    }

    return methods.value.some((m) => m.kind === 'customer_credit');
});

// ---------------------------------------------------------------------------
// Límite
// ---------------------------------------------------------------------------

const editingLimit = ref(false);
const limitForm = ref({ credit_limit: '', is_enabled: true });

// ---------------------------------------------------------------------------
// Abono (se declara aquí porque los dos formularios se cierran entre sí)
// ---------------------------------------------------------------------------

const repaying = ref(false);
const repayForm = ref({ amount: '', payment_method_ulid: '' });
const amountError = ref(null);
const refreshError = ref(null);

function startLimit() {
    repaying.value = false;
    limitForm.value = { credit_limit: credit.value.limit, is_enabled: credit.value.is_enabled };
    editingLimit.value = true;
}

const saveLimit = useApiForm(async () => {
    const response = await api.patch(`/customers/${props.customer.ulid}/credit`, {
        credit_limit: String(limitForm.value.credit_limit).trim(),
        is_enabled: limitForm.value.is_enabled,
    });

    return response.data;
}, { success: { kind: 'update', entity: 'Crédito', gender: 'm' } });

const limitFieldError = computed(() => saveLimit.fieldErrors.value.credit_limit ?? null);

/**
 * Lo que el cambio provoca de verdad, para confirmarlo. `null` si no hay nada que advertir (subir el límite, habilitar).
 */
function limitConsequences() {
    const current = credit.value;
    const draft = limitForm.value;
    const notes = [];

    if (current.is_enabled && ! draft.is_enabled) {
        notes.push(
            'En el punto de venta ya no se le podrá fiar, ni con el PIN de un superior, hasta que lo vuelvas a '
            + `habilitar. Su límite y lo que debe (${formatMoney(current.balance)}) se conservan, y puede seguir `
            + 'abonando.',
        );
    }

    const newLimit = cents(draft.credit_limit);
    const balance = cents(current.balance);

    if (draft.is_enabled && newLimit !== null && balance !== null && newLimit < balance) {
        notes.push(
            `El límite nuevo (${formatMoney(String(draft.credit_limit).trim())}) queda por debajo de lo que ya debe `
            + `(${formatMoney(current.balance)}): no le quedará disponible y cada consumo que se le fíe pedirá el PIN `
            + 'de un superior. Lo que ya debe no cambia.',
        );
    }

    if (notes.length === 0) {
        return null;
    }

    const action = draft.is_enabled ? 'Guardar el límite' : 'Suspender el crédito';

    return `¿${action} de «${props.customer.name}»?\n\n${notes.join('\n\n')}`;
}

async function submitLimit() {
    const current = credit.value;
    const draft = limitForm.value;

    // Sin cambios no se manda nada: cada guardado deja una entrada en la bitácora, y una sin diferencia es ruido.
    if (cents(draft.credit_limit) === cents(current.limit) && draft.is_enabled === current.is_enabled) {
        editingLimit.value = false;

        return;
    }

    const consequences = limitConsequences();

    if (consequences !== null && ! window.confirm(consequences)) {
        return;
    }

    const updated = await saveLimit.submit();

    if (! updated) {
        return;
    }

    editingLimit.value = false;

    if (typeof updated === 'object') {
        emit('updated', updated);
    }
}

// ---------------------------------------------------------------------------
// Abono
// ---------------------------------------------------------------------------

function startRepayment() {
    editingLimit.value = false;
    amountError.value = null;
    refreshError.value = null;

    // Con un solo método posible se elige solo; con varios se elige a mano: registrar como efectivo un abono con
    // tarjeta descuadraría el arqueo.
    repayForm.value = {
        amount: '',
        payment_method_ulid: repaymentMethods.value.length === 1 ? repaymentMethods.value[0].ulid : '',
    };

    repaying.value = true;
}

const selectedMethod = computed(
    () => repaymentMethods.value.find((m) => m.ulid === repayForm.value.payment_method_ulid) ?? null,
);

const repay = useApiForm(async () => {
    const response = await api.post(`/customers/${props.customer.ulid}/credit-repayments`, {
        branch_ulid: activeBranch.value.ulid,
        amount: repayForm.value.amount.trim(),
        payment_method_ulid: repayForm.value.payment_method_ulid,
    });

    return response.data;
}, {
    success: (movement) => `Abono registrado. Ahora debe ${formatMoney(movement?.balance_after)}.`,
});

const amountFieldError = computed(() => amountError.value ?? repay.fieldErrors.value.amount ?? null);

async function submitRepayment() {
    amountError.value = null;

    if (! activeBranch.value || ! selectedMethod.value) {
        return;
    }

    const amount = repayForm.value.amount.trim();

    // Formato, no regla de negocio: un abono de cero nunca es válido (el servidor exige más de cero).
    if ((cents(amount) ?? 0) <= 0) {
        amountError.value = 'Escribe un importe mayor que cero.';

        return;
    }

    const destination = selectedMethod.value.affects_cash_drawer
        ? `El dinero entra al cajón del turno abierto de ${activeBranch.value.name} y el arqueo lo va a esperar.`
        : `Se registra en el turno abierto de ${activeBranch.value.name}, sin entrar al efectivo del cajón.`;

    const confirmed = window.confirm(
        `¿Registrar un abono de ${formatMoney(amount)} de «${props.customer.name}» con ${selectedMethod.value.name}?\n\n`
        + `${destination} Queda en su estado de cuenta y no se puede editar ni borrar.`,
    );

    if (! confirmed) {
        return;
    }

    const movement = await repay.submit();

    if (! movement) {
        return;
    }

    repaying.value = false;
    emit('repaid', movement);

    await refreshCustomer();
}

/**
 * Relee al cliente para traer el saldo que calculó el servidor.
 *
 * Va FUERA del envío a propósito: si releer fallara dentro, `useApiForm` lo pintaría como un fallo del abono —que ya
 * quedó registrado— y la persona lo capturaría otra vez.
 */
async function refreshCustomer() {
    refreshError.value = null;

    try {
        const response = await api.get(`/customers/${props.customer.ulid}`);

        emit('updated', response.data);
    } catch {
        refreshError.value = 'El abono quedó registrado, pero no se pudo releer el saldo. Recarga la página para ver la cifra al día.';
    }
}
</script>

<template>
    <section class="panel" aria-labelledby="credito-titulo">
        <h2 id="credito-titulo">
            Crédito
            <span v-if="credit" class="badge" :class="credit.is_enabled ? 'badge--ok' : 'badge--off'">
                {{ credit.is_enabled ? 'Habilitado' : 'Suspendido' }}
            </span>
        </h2>

        <p v-if="! credit" class="nota">Este cliente no tiene cuenta de crédito.</p>

        <template v-else>
            <dl class="cifras">
                <div>
                    <dt>Saldo (lo que debe)</dt>
                    <dd>{{ formatMoney(credit.balance) }}</dd>
                </div>
                <div>
                    <dt>Límite</dt>
                    <dd>{{ formatMoney(credit.limit) }}</dd>
                </div>
                <div>
                    <dt>Disponible</dt>
                    <dd>{{ formatMoney(credit.available) }}</dd>
                </div>
            </dl>

            <p v-if="credit.is_enabled" class="nota">
                En el punto de venta se le puede fiar hasta su disponible; por encima, hace falta el PIN de un superior.
            </p>
            <p v-else class="nota">
                Suspendido: en el punto de venta no se le puede fiar, ni con autorización. Su límite se conserva y puede
                seguir abonando.
            </p>

            <p :class="creditMethodOn === false ? 'alert alert--notice' : 'nota'">
                Para fiar en el punto de venta, el negocio debe tener encendido el método de pago «Crédito del cliente»
                (se configura en Finanzas › Métodos de pago).
                <strong v-if="creditMethodOn === false">Hoy está apagado.</strong>
            </p>

            <p v-if="refreshError" class="error" role="alert">{{ refreshError }}</p>

            <div v-if="canManage && ! editingLimit && ! repaying" class="acciones">
                <button type="button" class="link-button link-button--warning" @click="startLimit">
                    <Icon name="edit" /> Editar límite
                </button>
                <button v-if="owes" type="button" class="button" @click="startRepayment">
                    <Icon name="receive" /> Registrar abono
                </button>
                <span v-else class="nota">Sin saldo pendiente: no hay nada que abonar.</span>
            </div>

            <!-- ---- Límite y habilitación ---- -->
            <form v-if="editingLimit" class="sub" aria-labelledby="credito-limite-titulo" @submit.prevent="submitLimit">
                <h3 id="credito-limite-titulo">Límite de crédito</h3>

                <p v-if="saveLimit.generalError.value" class="error" role="alert">{{ saveLimit.generalError.value }}</p>

                <div class="field">
                    <label class="field__label" for="credito-limite">Límite (pesos)</label>
                    <input
                        id="credito-limite"
                        v-model="limitForm.credit_limit"
                        class="input"
                        :class="{ 'input--error': limitFieldError }"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        required
                        :pattern="AMOUNT_PATTERN"
                        :title="AMOUNT_TITLE"
                        :aria-invalid="limitFieldError ? 'true' : undefined"
                        :aria-describedby="limitFieldError ? 'credito-limite-ayuda credito-limite-error' : 'credito-limite-ayuda'"
                    />
                    <span id="credito-limite-ayuda" class="field__hint">
                        Hasta cuánto puede deber sin autorización. Con $0, cada consumo que se le fíe pedirá el PIN de un
                        superior.
                    </span>
                    <span v-if="limitFieldError" id="credito-limite-error" class="field__error">{{ limitFieldError }}</span>
                </div>

                <div class="field">
                    <div class="check">
                        <input
                            id="credito-habilitado"
                            v-model="limitForm.is_enabled"
                            type="checkbox"
                            aria-describedby="credito-habilitado-ayuda"
                        />
                        <label for="credito-habilitado">Crédito habilitado</label>
                    </div>
                    <span id="credito-habilitado-ayuda" class="field__hint">
                        Desmárcalo para suspenderlo: se deja de fiarle sin perder su límite, y puede seguir abonando.
                    </span>
                    <span v-if="saveLimit.fieldErrors.value.is_enabled" class="field__error">
                        {{ saveLimit.fieldErrors.value.is_enabled }}
                    </span>
                </div>

                <div class="acciones">
                    <button type="submit" class="button" :disabled="saveLimit.processing.value">
                        <Icon name="check" /> Guardar
                    </button>
                    <button type="button" class="link-button" :disabled="saveLimit.processing.value" @click="editingLimit = false">
                        <Icon name="x" /> Cancelar
                    </button>
                </div>
            </form>

            <!-- ---- Abono ---- -->
            <form v-if="repaying" class="sub" aria-labelledby="abono-titulo" @submit.prevent="submitRepayment">
                <h3 id="abono-titulo">Registrar abono</h3>

                <p v-if="! activeBranch" class="alert alert--notice" role="alert">
                    No hay una sucursal activa. Elígela en el selector de sucursal: el abono entra a la caja de esa
                    sucursal.
                </p>

                <template v-else>
                    <p class="alert alert--notice">
                        Todo abono se registra en el <strong>turno de caja abierto de {{ activeBranch.name }}</strong> y
                        aparece en su corte. Si esa sucursal no tiene una caja abierta, no se puede registrar<template
                            v-if="can('pos.sessions.open')">: ábrela primero en <Link href="/admin/pos/caja">Punto de venta › Caja</Link></template>.
                    </p>

                    <p v-if="repay.generalError.value" class="error" role="alert">{{ repay.generalError.value }}</p>

                    <div class="field">
                        <label class="field__label" for="abono-importe">Importe (pesos)</label>
                        <input
                            id="abono-importe"
                            v-model="repayForm.amount"
                            class="input"
                            :class="{ 'input--error': amountFieldError }"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="0.00"
                            required
                            :pattern="AMOUNT_PATTERN"
                            :title="AMOUNT_TITLE"
                            :aria-invalid="amountFieldError ? 'true' : undefined"
                            :aria-describedby="amountFieldError ? 'abono-importe-ayuda abono-importe-error' : 'abono-importe-ayuda'"
                        />
                        <span id="abono-importe-ayuda" class="field__hint">
                            Debe {{ formatMoney(credit.balance) }}. No se abona más de lo que debe: si entrega de más, dale
                            su cambio.
                        </span>
                        <span v-if="amountFieldError" id="abono-importe-error" class="field__error">{{ amountFieldError }}</span>
                    </div>

                    <div v-if="repaymentMethods.length" class="field">
                        <label class="field__label" for="abono-metodo">Método de pago</label>
                        <select
                            id="abono-metodo"
                            v-model="repayForm.payment_method_ulid"
                            class="input"
                            :class="{ 'input--error': repay.fieldErrors.value.payment_method_ulid }"
                            required
                            aria-describedby="abono-metodo-ayuda"
                        >
                            <option value="" disabled>Elige…</option>
                            <option v-for="m in repaymentMethods" :key="m.ulid" :value="m.ulid">{{ m.name }}</option>
                        </select>
                        <span id="abono-metodo-ayuda" class="field__hint">
                            <template v-if="selectedMethod">
                                {{ selectedMethod.affects_cash_drawer
                                    ? 'Entra al efectivo del cajón: el arqueo lo va a esperar.'
                                    : 'No entra al efectivo del cajón.' }}
                                <template v-if="selectedMethod.requires_reference">
                                    El abono todavía no guarda la referencia (folio o autorización): anótala aparte si la
                                    necesitas para conciliar.
                                </template>
                            </template>
                            <template v-else>Con qué entrega el dinero.</template>
                        </span>
                        <span v-if="repay.fieldErrors.value.payment_method_ulid" class="field__error">
                            {{ repay.fieldErrors.value.payment_method_ulid }}
                        </span>
                    </div>

                    <p v-else-if="methodsLoading" class="nota">Cargando los métodos de pago…</p>
                    <p v-else-if="methodsError" class="error" role="alert">
                        No se pudieron cargar los métodos de pago: {{ methodsError.title }}
                    </p>
                    <p v-else-if="! canSeeMethods" class="alert alert--notice">
                        Tu rol no puede consultar los métodos de pago, así que desde aquí no se puede elegir con qué abona.
                        Pídeselo a alguien con acceso a Finanzas.
                    </p>
                    <p v-else class="alert alert--notice">
                        No hay métodos de pago activos con los que recibir el abono (o tu rol no puede verlos).
                    </p>
                </template>

                <div class="acciones">
                    <button
                        v-if="activeBranch"
                        type="submit"
                        class="button"
                        :disabled="repay.processing.value || ! repaymentMethods.length"
                    >
                        <Icon name="receive" /> Registrar abono
                    </button>
                    <button type="button" class="link-button" :disabled="repay.processing.value" @click="repaying = false">
                        <Icon name="x" /> Cancelar
                    </button>
                </div>
            </form>
        </template>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

/* La misma tarjeta que el resto de la ficha: superficie, borde, radio y sombra de los tokens. */
.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.1rem 1.25rem;
}
.panel h2 { margin-top: 0; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: baseline; }
.sub h3 { margin: 0; font-size: 1rem; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.error { color: var(--color-peligro); }

/* Las tres cifras del crédito; en pantalla angosta se acomodan en dos columnas en lugar de salirse del panel. */
.cifras {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr));
    gap: 0.75rem;
    margin: 0 0 0.25rem;
}
.cifras div {
    background: var(--color-fondo);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-sm);
    padding: 0.55rem 0.7rem;
}
.cifras dt { font-size: 0.75rem; color: var(--color-suave); }
.cifras dd { margin: 0.15rem 0 0; font-weight: 600; font-size: 1.1rem; font-variant-numeric: tabular-nums; }

.acciones { display: flex; flex-wrap: wrap; gap: 0.75rem 1rem; align-items: center; }
.sub { display: grid; gap: 0.5rem; margin-top: 0.75rem; border-top: 1px solid var(--color-borde); padding-top: 0.75rem; }
.sub .field { margin-bottom: 0.25rem; }
.sub .alert { margin: 0; }
.check { display: flex; gap: 0.4rem; align-items: center; font-size: 0.9rem; }
</style>
