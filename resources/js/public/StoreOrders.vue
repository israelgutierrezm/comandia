<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatMoney } from '../support/money.js';

/**
 * «Mis pedidos» de la tienda pública: la lista de pedidos del cliente, el detalle de cada uno con su avance, y el regreso
 * desde la pasarela de pago (`?pedido=`), donde se le dice al cliente en qué quedó su pago.
 *
 * Vive dentro del panel lateral que abre `StoreApp` y usa su mismo cliente HTTP (`api`). Aquí sólo se pinta y se narra;
 * el backend decide:
 *
 * - Ver pedidos EXIGE sesión de cliente: `GET /orders` y `GET /orders/{ulid}` responden 401 sin ella, y el pedido de otro
 *   cliente responde 404. Como comprar también exige cuenta (D333), no hay pedidos «de invitado»: todo pedido vive en la
 *   cuenta con la que se hizo, y sólo desde ahí se consulta.
 * - El estado y su etiqueta vienen del servidor (`status`, `status_label`); el avance se arma con los hitos que el servidor
 *   ya selló (`placed_at`, `accepted_at`, `ready_at`, `packed_at`, `shipped_at`, `completed_at`).
 * - Tras «aceptado», el camino depende del modo de la tienda (ADR-013): preparación (listo → entregado) o envío (empacado →
 *   enviado → entregado). La API pública hoy no manda el modo, así que se deduce del primer hito que lo delata; mientras no
 *   se sepa, no se dibujan pasos intermedios que quizá no existan. Si algún día llega `fulfillment_mode`, se usa ése.
 * - Las fechas se presentan en la zona horaria del navegador: el pedido no trae la de su sucursal (deuda declarada).
 */
const props = defineProps({
    /** El cliente HTTP de la tienda: `api(método, ruta, cuerpo)`. Sus errores traen `status` (0 = sin conexión). */
    api: { type: Function, required: true },
    /** Si hay sesión de cliente, según `/me`. */
    signedIn: { type: Boolean, default: false },
    /** Lo que trajo `?pedido=` al volver de la pasarela: hoy el FOLIO (`WEB-000123`); también se acepta el ULID. */
    returnRef: { type: String, default: null },
});

const emit = defineEmits(['close', 'login', 'session-lost']);

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/;

// Hasta qué paso del camino llegó cada estado «en marcha». Los que detienen el pedido (fallido, rechazado, cancelado) se
// dibujan aparte, en `progressOf`.
const REACHED = {
    pending_payment: 'placed',
    paid: 'paid',
    accepted: 'accepted',
    ready: 'ready',
    packed: 'packed',
    shipped: 'shipped',
    completed: 'completed',
};

// El color de la pastilla de estado: ámbar = falta el pago, marca = en marcha, verde = listo para recoger o recibir,
// gris = cerrado, rojo = se detuvo.
const TONES = {
    pending_payment: 'wait',
    paid: 'go',
    accepted: 'go',
    ready: 'ok',
    packed: 'go',
    shipped: 'go',
    completed: 'done',
    failed: 'bad',
    rejected: 'bad',
    cancelled: 'bad',
};

// Al volver de la pasarela, el aviso de pago puede llegarle al servidor unos segundos DESPUÉS que el cliente. Mientras el
// pedido siga «pendiente de pago» se vuelve a preguntar un rato (≈40 s), para que vea el cambio sin recargar.
const POLL_EVERY_MS = 5000;
const POLL_TIMES = 8;

const DATE_FORMAT = new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' });

const orders = ref([]);
const listLoaded = ref(false);
const listLoading = ref(false);
const listError = ref(null);

const selected = ref(null); // ULID del pedido abierto; null = la lista
const order = ref(null); // su detalle (mientras llega, el renglón que ya traía la lista)
const detailLoading = ref(false);
const detailError = ref(null);

const returnUlid = ref(null); // el pedido del regreso de la pasarela, ya identificado
const returnMissing = ref(false); // el regreso apuntaba a un pedido que no está en esta cuenta
const sessionEnded = ref(false); // el servidor dejó de reconocer la sesión (401) con el panel abierto

const scroller = ref(null);

let pollTimer = null;
let pollsLeft = 0;

const busy = computed(() => listLoading.value || detailLoading.value);
const title = computed(() => (selected.value ? (order.value?.folio ?? 'Tu pedido') : 'Mis pedidos'));
const steps = computed(() => (order.value ? progressOf(order.value) : []));

// El valor de `?pedido=` es de la URL (lo puede escribir cualquiera): se recorta para que no rompa el panel.
const shortRef = computed(() => (props.returnRef ?? '').slice(0, 40));

/** El aviso del regreso de la pasarela: «pedido recibido» o «pago pendiente», según lo que diga el servidor. */
const returnNotice = computed(() => {
    if (returnMissing.value) {
        return {
            tone: 'warn',
            title: `No encontramos el pedido ${shortRef.value} en esta cuenta.`,
            text: 'Si lo hiciste con otro correo, cierra sesión y entra con esa cuenta para verlo.',
        };
    }

    const o = order.value;

    if (o === null || o.ulid !== returnUlid.value) {
        return null;
    }

    if (o.status === 'pending_payment') {
        return {
            tone: 'wait',
            title: 'Tu pago está pendiente de confirmar',
            text: `Recibimos tu pedido ${o.folio}. Si ya pagaste, la confirmación de la pasarela puede tardar unos momentos: no vuelvas a pagar. Aquí mismo verás cuando quede pagado.`,
            recheck: true,
        };
    }

    if (o.status === 'failed') {
        return {
            tone: 'bad',
            title: 'El pago no se completó',
            text: `Tu pedido ${o.folio} no quedó pagado, así que no avanzará. Si quieres, vuelve a armarlo desde el menú.`,
        };
    }

    // Rechazado o cancelado justo al volver: el encabezado del pedido ya lo explica.
    if (o.status === 'rejected' || o.status === 'cancelled') {
        return null;
    }

    return {
        tone: 'ok',
        title: '¡Pedido recibido!',
        text: `Tu pago de ${formatMoney(o.total)} quedó confirmado. Aquí puedes seguir el avance de tu pedido ${o.folio}.`,
    };
});

function toneOf(status) {
    return TONES[status] ?? 'done';
}

function formatDate(iso) {
    if (!iso) {
        return '';
    }

    const instant = new Date(iso);

    return Number.isNaN(instant.getTime()) ? '' : DATE_FORMAT.format(instant);
}

function deliveryLabel(o) {
    if (o.delivery_type === 'shipping') return 'Envío a domicilio';
    if (o.delivery_type === 'pickup') return 'Recoger en sucursal';
    // Pedido de un marketplace (ADR-015) ligado al cliente por su teléfono: la entrega la hace la plataforma.
    if (o.delivery_type === 'marketplace') return 'Pedido por app de reparto';
    return '';
}

/** Qué está pasando con el pedido, en una frase para el cliente. */
function headlineOf(o) {
    const pickup = o.delivery_type === 'pickup';

    switch (o.status) {
        case 'pending_payment':
            return 'Este pedido aún no tiene un pago confirmado. Si no completaste el pago, no avanzará.';
        case 'paid':
            return 'Tu pago está confirmado; el negocio está por aceptar tu pedido.';
        case 'accepted':
            return 'El negocio aceptó tu pedido y ya está trabajando en él.';
        case 'ready':
            return pickup ? '¡Tu pedido está listo! Ya puedes pasar a recogerlo.' : 'Tu pedido está listo y pronto sale a entrega.';
        case 'packed':
            return 'Tu pedido ya está empacado y pronto se enviará.';
        case 'shipped':
            return 'Tu pedido va en camino.';
        case 'completed':
            return pickup ? 'Recogiste tu pedido. ¡Gracias por tu compra!' : 'Tu pedido fue entregado. ¡Gracias por tu compra!';
        case 'failed':
            return 'El pago no se completó, así que el pedido no siguió adelante.';
        case 'rejected':
            return 'El negocio no pudo tomar tu pedido y se te reembolsó el pago; tu banco puede tardar unos días en reflejarlo.';
        case 'cancelled':
            return 'Este pedido se canceló.';
        default:
            return '';
    }
}

/** El modo de la tienda según los hitos que ya lo delatan (ADR-013); null mientras no se sepa. */
function inferMode(o) {
    if (o.packed_at || o.shipped_at || o.status === 'packed' || o.status === 'shipped') return 'dispatch';
    if (o.ready_at || o.status === 'ready') return 'preparation';
    return null;
}

/**
 * El avance del pedido como pasos `done` (ya pasó), `now` (en curso), `todo` (después) o `bad` (ahí se detuvo). Sólo narra
 * lo que el servidor ya selló; no decide nada.
 */
function progressOf(o) {
    const pickup = o.delivery_type === 'pickup';
    const mode = o.fulfillment_mode ?? inferMode(o);
    const done = (step) => ({ ...step, state: 'done' });

    const path = [
        { key: 'placed', label: 'Pedido realizado', at: o.placed_at },
        { key: 'paid', label: 'Pago confirmado', at: null },
        { key: 'accepted', label: 'Pedido aceptado', at: o.accepted_at },
    ];

    if (mode === 'preparation') {
        path.push({ key: 'ready', label: pickup ? 'Listo para recoger' : 'Listo para entregar', at: o.ready_at });
    } else if (mode === 'dispatch') {
        path.push({ key: 'packed', label: 'Empacado', at: o.packed_at });
        path.push({ key: 'shipped', label: 'Enviado', at: o.shipped_at });
    }

    path.push({ key: 'completed', label: pickup ? 'Recogido' : 'Entregado', at: o.completed_at });

    // Caminos que se detienen: se conserva lo que sí consta y se cierra con el paso donde paró.
    if (o.status === 'failed') {
        return [done(path[0]), { key: 'failed', label: 'Pago no completado', state: 'bad' }];
    }

    if (o.status === 'rejected') {
        // Sólo se rechaza un pedido pagado y aún no aceptado (D2): se reembolsa.
        return [done(path[0]), done(path[1]), { key: 'rejected', label: 'Rechazado por el negocio', note: 'Se te reembolsó el pago.', state: 'bad' }];
    }

    if (o.status === 'cancelled') {
        // No hay sello de cancelación ni de pago: sólo se afirma lo que consta (aceptado implica pagado).
        const kept = o.accepted_at ? path.slice(0, 3) : path.slice(0, 1);

        return [...kept.map(done), { key: 'cancelled', label: 'Cancelado', state: 'bad' }];
    }

    const reached = path.findIndex((step) => step.key === REACHED[o.status]);

    if (reached < 0) {
        // Un estado que esta vista no conoce: se muestra sólo lo seguro, y la pastilla dice el estado real.
        return [done(path[0])];
    }

    return path.map((step, i) => ({ ...step, state: i <= reached ? 'done' : i === reached + 1 ? 'now' : 'todo' }));
}

/** Un error de la API: 401 = el servidor ya no reconoce la sesión (venció o se cerró en otra pestaña). */
function fail(e, target) {
    if (e?.status === 401) {
        sessionEnded.value = true;
        emit('session-lost');
        return;
    }

    target.value = e?.message || 'No se pudo completar la operación.';
}

function toTop() {
    nextTick(() => {
        if (scroller.value) scroller.value.scrollTop = 0;
    });
}

async function loadList() {
    listLoading.value = true;
    listError.value = null;

    try {
        orders.value = await props.api('GET', '/orders');
        listLoaded.value = true;
    } catch (e) {
        fail(e, listError);
    } finally {
        listLoading.value = false;
    }
}

/**
 * Pide el detalle del pedido abierto. Devuelve null si llegó, o el código del error. `quiet` es para la revisión
 * automática del pago: sin «Cargando…» ni avisos de error (el siguiente intento, o el botón, dirán si algo falla).
 */
async function loadDetail({ quiet = false } = {}) {
    const ulid = selected.value;

    if (!quiet) {
        detailLoading.value = true;
        detailError.value = null;
    }

    try {
        const data = await props.api('GET', `/orders/${ulid}`);

        // Si el cliente ya se movió a otro pedido, esta respuesta llegó tarde: no se le pisa.
        if (selected.value === ulid) order.value = data;

        return null;
    } catch (e) {
        if (!quiet && selected.value === ulid) fail(e, detailError);

        return e?.status ?? 0;
    } finally {
        if (!quiet && selected.value === ulid) detailLoading.value = false;
    }
}

async function openOrder(ulid) {
    clearTimeout(pollTimer);
    // El «no encontramos tu pedido» es de la lista: no debe asomarse en el detalle de otro pedido.
    returnMissing.value = false;
    selected.value = ulid;
    // Mientras llega el detalle se pinta lo que ya dice la lista (sin artículos), no una pantalla vacía.
    order.value = orders.value.find((o) => o.ulid === ulid) ?? null;
    detailError.value = null;
    toTop();

    return loadDetail();
}

function back() {
    clearTimeout(pollTimer);
    selected.value = null;
    order.value = null;
    detailError.value = null;
    detailLoading.value = false;

    if (!listLoaded.value && !listLoading.value) loadList();

    toTop();
}

function refresh() {
    return selected.value ? loadDetail() : loadList();
}

/**
 * Ubica el pedido con el que volvió la pasarela y lo abre. Stripe y Mercado Pago regresan hoy con el FOLIO
 * (`?pedido=WEB-000123`) y el detalle se pide por ULID, así que se busca en la lista del cliente (también se acepta un
 * ULID). El folio es único POR SUCURSAL, así que dos sucursales pueden repetirlo: la lista viene del más nuevo al más
 * viejo, y gana el primero que coincide, que es el que se acaba de pagar.
 */
async function resolveReturn(value) {
    returnMissing.value = false;
    await loadList();

    if (!listLoaded.value || !props.signedIn) {
        return; // el error ya se muestra, o la sesión se venció: el panel lo explica
    }

    const wanted = value.trim().toUpperCase();
    const match = orders.value.find((o) => o.ulid === wanted || String(o.folio).toUpperCase() === wanted);
    const ulid = match?.ulid ?? (ULID.test(wanted) ? wanted : null);

    if (ulid === null) {
        returnMissing.value = true;
        return;
    }

    returnUlid.value = ulid;

    if ((await openOrder(ulid)) === 404) {
        // Un ULID que no es de esta cuenta: de vuelta a la lista, con el aviso.
        returnUlid.value = null;
        back();
        returnMissing.value = true;
        return;
    }

    pollsLeft = POLL_TIMES;
    watchPayment();
}

/** ¿Se está mirando el pedido del regreso y sigue sin pago confirmado? */
function awaitingPayment() {
    return order.value !== null
        && selected.value === returnUlid.value
        && order.value.ulid === returnUlid.value
        && order.value.status === 'pending_payment';
}

function watchPayment() {
    clearTimeout(pollTimer);

    if (!awaitingPayment() || pollsLeft <= 0) {
        return;
    }

    pollTimer = setTimeout(async () => {
        if (!awaitingPayment()) return;

        pollsLeft -= 1;
        await loadDetail({ quiet: true });
        watchPayment();
    }, POLL_EVERY_MS);
}

/** «Reintentar» de la lista: si el regreso de la pasarela no alcanzó a resolverse, se retoma ése. */
function retry() {
    const returnPending = props.returnRef && returnUlid.value === null && !returnMissing.value;

    return returnPending ? resolveReturn(props.returnRef) : loadList();
}

function start() {
    if (!props.signedIn) return;

    if (props.returnRef) {
        resolveReturn(props.returnRef);
    } else {
        loadList();
    }
}

watch(() => props.signedIn, (now) => {
    clearTimeout(pollTimer);

    if (now) {
        sessionEnded.value = false;
        start();
        return;
    }

    // Sin sesión, lo que se había cargado ya no es de nadie en esta pantalla.
    orders.value = [];
    listLoaded.value = false;
    selected.value = null;
    order.value = null;
});

onMounted(start);
onBeforeUnmount(() => clearTimeout(pollTimer));
</script>

<template>
    <div class="orders">
        <header class="orders__head">
            <button v-if="signedIn && selected" type="button" class="icon-btn" aria-label="Volver a mis pedidos" @click="back">←</button>
            <h2 class="orders__title">{{ title }}</h2>
            <button v-if="signedIn" type="button" class="icon-btn" :disabled="busy" aria-label="Actualizar" title="Actualizar" @click="refresh">↻</button>
            <button type="button" class="icon-btn" aria-label="Cerrar" @click="emit('close')">✕</button>
        </header>

        <div ref="scroller" class="orders__body">
            <!-- Sin sesión: ver pedidos exige cuenta (el servidor responde 401) y todo pedido se hizo con una. -->
            <div v-if="!signedIn" class="gate">
                <p class="gate__title">{{ sessionEnded ? 'Tu sesión se cerró' : 'Inicia sesión para ver tus pedidos' }}</p>
                <p v-if="returnRef">Para ver en qué va tu pedido <strong>{{ shortRef }}</strong>, entra con el mismo correo con el que lo hiciste.</p>
                <p v-else>Tus pedidos se guardan en tu cuenta. Entra con el mismo correo con el que compraste para ver en qué van.</p>
                <p class="muted small">Por seguridad, un pedido sólo se puede consultar desde la cuenta con la que se hizo.</p>
                <button type="button" class="btn btn--primary btn--block" @click="emit('login')">Iniciar sesión</button>
            </div>

            <!-- Detalle de un pedido -->
            <template v-else-if="selected">
                <div v-if="detailError" class="problem" role="alert">
                    <p class="error">{{ detailError }}</p>
                    <div class="problem__actions">
                        <button type="button" class="link" @click="loadDetail()">Reintentar</button>
                        <button type="button" class="link" @click="back">Volver a mis pedidos</button>
                    </div>
                </div>

                <p v-if="!order && detailLoading" class="muted">Cargando tu pedido…</p>

                <article v-if="order" class="detail">
                    <div v-if="returnNotice" class="notice" :class="`notice--${returnNotice.tone}`" role="status">
                        <p class="notice__title">{{ returnNotice.title }}</p>
                        <p class="notice__text">{{ returnNotice.text }}</p>
                        <button v-if="returnNotice.recheck" type="button" class="link notice__action" :disabled="busy" @click="loadDetail()">
                            {{ detailLoading ? 'Revisando…' : 'Revisar el pago otra vez' }}
                        </button>
                    </div>

                    <div class="detail__status">
                        <span class="pill" :class="`pill--${toneOf(order.status)}`">{{ order.status_label }}</span>
                        <span class="muted small">{{ formatDate(order.placed_at) }}</span>
                    </div>
                    <p v-if="!returnNotice || returnNotice.tone === 'ok'" class="detail__headline">{{ headlineOf(order) }}</p>

                    <ol class="steps" aria-label="Avance del pedido">
                        <li
                            v-for="s in steps"
                            :key="s.key"
                            class="step"
                            :class="`step--${s.state}`"
                            :aria-current="s.state === 'now' ? 'step' : undefined"
                        >
                            <span class="step__dot" aria-hidden="true"></span>
                            <div>
                                <p class="step__label">{{ s.label }}</p>
                                <p v-if="s.state === 'done' && s.at" class="step__at">{{ formatDate(s.at) }}</p>
                                <p v-else-if="s.state === 'now'" class="step__at">En curso</p>
                                <p v-if="s.note" class="step__at">{{ s.note }}</p>
                            </div>
                        </li>
                    </ol>

                    <section class="block">
                        <h3 class="block__title">Entrega</h3>
                        <p>{{ deliveryLabel(order) }}</p>
                        <p v-if="order.delivery_address" class="muted">{{ order.delivery_address }}</p>
                        <!-- Paquetería y guía (modo envío, ADR-013): texto libre que captura el negocio al enviar. -->
                        <p v-if="order.carrier || order.tracking_number" class="ship">
                            Paquetería: <strong>{{ order.carrier || 'sin especificar' }}</strong>
                            <template v-if="order.tracking_number">
                                <br />Número de guía: <strong class="guide">{{ order.tracking_number }}</strong>
                            </template>
                        </p>
                    </section>

                    <section class="block">
                        <h3 class="block__title">Artículos</h3>
                        <p v-if="!order.items" class="muted small">{{ detailLoading ? 'Cargando artículos…' : 'No se pudieron cargar los artículos.' }}</p>
                        <ul v-else class="items">
                            <li v-for="(it, i) in order.items" :key="i" class="item">
                                <span class="item__qty">{{ it.quantity }} ×</span>
                                <span>
                                    {{ it.name }}
                                    <span v-if="Number(it.quantity) > 1" class="item__unit">{{ formatMoney(it.unit_price) }} c/u</span>
                                </span>
                                <span class="item__amt">{{ formatMoney(it.line_total) }}</span>
                            </li>
                        </ul>

                        <!-- Importes congelados por el servidor al hacer el pedido: aquí sólo se presentan. -->
                        <dl class="sums">
                            <div class="sums__row"><dt>Subtotal</dt><dd>{{ formatMoney(order.subtotal) }}</dd></div>
                            <div v-if="Number(order.discount_total) > 0" class="sums__row">
                                <dt>Descuento<template v-if="order.coupon_code"> ({{ order.coupon_code }})</template></dt>
                                <dd>−{{ formatMoney(order.discount_total) }}</dd>
                            </div>
                            <div v-if="order.delivery_type === 'shipping'" class="sums__row">
                                <dt>Envío</dt>
                                <dd>{{ Number(order.shipping_cost) === 0 ? 'Gratis' : formatMoney(order.shipping_cost) }}</dd>
                            </div>
                            <div class="sums__row sums__row--total"><dt>Total</dt><dd>{{ formatMoney(order.total) }}</dd></div>
                        </dl>
                    </section>

                    <section v-if="order.notes" class="block">
                        <h3 class="block__title">Tus notas</h3>
                        <p>{{ order.notes }}</p>
                    </section>
                </article>
            </template>

            <!-- Lista de pedidos -->
            <template v-else>
                <div v-if="returnNotice" class="notice" :class="`notice--${returnNotice.tone}`" role="status">
                    <p class="notice__title">{{ returnNotice.title }}</p>
                    <p class="notice__text">{{ returnNotice.text }}</p>
                </div>

                <div v-if="listError" class="problem" role="alert">
                    <p class="error">{{ listError }}</p>
                    <div class="problem__actions">
                        <button type="button" class="link" :disabled="listLoading" @click="retry">Reintentar</button>
                    </div>
                </div>

                <p v-if="!listLoaded && listLoading" class="muted">Cargando tus pedidos…</p>

                <div v-else-if="listLoaded && !orders.length" class="empty">
                    <p>Aún no tienes pedidos en esta tienda.</p>
                    <button type="button" class="btn btn--primary" @click="emit('close')">Ver el menú</button>
                </div>

                <ul v-else-if="orders.length" class="rows">
                    <li v-for="o in orders" :key="o.ulid">
                        <button type="button" class="row" @click="openOrder(o.ulid)">
                            <span class="row__top">
                                <strong>{{ o.folio }}</strong>
                                <span class="pill" :class="`pill--${toneOf(o.status)}`">{{ o.status_label }}</span>
                            </span>
                            <span class="row__meta">{{ formatDate(o.placed_at) }}<template v-if="deliveryLabel(o)"> · {{ deliveryLabel(o) }}</template></span>
                            <span class="row__total">{{ formatMoney(o.total) }}</span>
                        </button>
                    </li>
                </ul>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* Colores y tipografía: las variables (`--primary`, `--ink`, `--muted`, `--line`, `--card`, `--bg`) las define la raíz de
   la tienda (`StoreApp`) y se heredan hasta aquí. Los botones repiten el lenguaje de la tienda, no el del admin. */
.orders { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.orders__head { display: flex; align-items: center; gap: 0.5rem; padding: 1rem; border-bottom: 1px solid var(--line); }
.orders__title { margin: 0; flex: 1; min-width: 0; font-size: 1.1rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.orders__body { flex: 1; overflow-y: auto; padding: 1rem; }

.muted { color: var(--muted); }
.small { font-size: 0.8rem; }
.error { color: #b91c1c; margin: 0; }

.icon-btn {
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid var(--line); background: var(--card); color: var(--ink);
    border-radius: 999px; padding: 0.4rem 0.7rem; cursor: pointer; font: inherit; font-size: 0.85rem;
    transition: border-color 0.15s ease;
}
.icon-btn:hover:not(:disabled) { border-color: color-mix(in srgb, var(--primary) 40%, var(--line)); }

.btn { font: inherit; font-weight: 700; border: 0; border-radius: 10px; padding: 0.5rem 0.9rem; cursor: pointer; }
.btn--primary { background: var(--primary); color: #fff; box-shadow: 0 8px 18px -10px color-mix(in srgb, var(--primary) 80%, transparent); }
.btn--primary:hover:not(:disabled) { filter: brightness(1.08); }
.btn--block { width: 100%; padding: 0.65rem; }
.link { background: none; border: 0; color: var(--primary); cursor: pointer; font: inherit; font-weight: 600; padding: 0; text-decoration: underline; text-underline-offset: 2px; }
button:disabled { opacity: 0.6; cursor: not-allowed; }

/* Sin sesión */
.gate { display: grid; gap: 0.7rem; margin-top: 1.5rem; text-align: center; line-height: 1.45; }
.gate p { margin: 0; }
.gate__title { font-weight: 800; font-size: 1.05rem; }

/* Avisos */
.notice { border-radius: 10px; padding: 0.8rem 0.9rem; margin-bottom: 1rem; overflow-wrap: anywhere; }
.notice p { margin: 0; }
.notice p + p { margin-top: 0.25rem; }
.notice__title { font-weight: 800; }
.notice__text { font-size: 0.85rem; line-height: 1.45; }
.notice__action { margin-top: 0.5rem; font-size: 0.85rem; color: inherit; }
.notice--ok { background: #dcfce7; color: #14532d; }
.notice--wait, .notice--warn { background: #fef3c7; color: #78350f; }
.notice--bad { background: #fee2e2; color: #7f1d1d; }

.problem { display: grid; gap: 0.4rem; margin-bottom: 1rem; }
.problem__actions { display: flex; gap: 1rem; flex-wrap: wrap; }

/* Pastilla de estado */
.pill { display: inline-block; padding: 0.12rem 0.55rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
.pill--wait { background: #fef3c7; color: #92400e; }
.pill--go { background: color-mix(in srgb, var(--primary) 12%, #fff); color: var(--primary); }
.pill--ok { background: #dcfce7; color: #15803d; }
.pill--done { background: #f5f5f4; color: #57534e; }
.pill--bad { background: #fee2e2; color: #b91c1c; }

/* Lista */
.empty { display: grid; justify-items: center; gap: 0.8rem; margin-top: 2.5rem; text-align: center; }
.empty p { margin: 0; color: var(--muted); }
.rows { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.6rem; }
.row {
    width: 100%; display: grid; grid-template-columns: 1fr auto; gap: 0.3rem 0.75rem; align-items: center;
    text-align: left; font: inherit; color: var(--ink); cursor: pointer;
    background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 0.75rem 0.85rem;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.row:hover { border-color: color-mix(in srgb, var(--primary) 45%, var(--line)); box-shadow: 0 10px 24px -18px rgb(0 0 0 / 0.45); }
.row:focus-visible { outline: 2px solid color-mix(in srgb, var(--primary) 55%, transparent); outline-offset: 2px; }
.row__top { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
.row__meta { grid-column: 1; font-size: 0.8rem; color: var(--muted); }
.row__total { grid-column: 2; grid-row: 1 / span 2; font-weight: 800; color: var(--primary); font-variant-numeric: tabular-nums; }

/* Detalle */
.detail__status { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; }
.detail__headline { margin: 0.6rem 0 0; font-weight: 600; line-height: 1.4; }

/* Avance: puntos unidos por una línea; la línea se pinta del color de la marca hasta donde va el pedido. */
.steps { list-style: none; margin: 1rem 0 0; padding: 0; }
.step { position: relative; display: flex; gap: 0.75rem; padding-bottom: 0.9rem; }
.step:last-child { padding-bottom: 0; }
.step::before { content: ""; position: absolute; left: calc(0.45rem - 1px); top: 1.15rem; bottom: 0; width: 2px; background: var(--line); }
.step:last-child::before { display: none; }
.step--done::before { background: var(--primary); }
.step__dot { position: relative; z-index: 1; flex: none; width: 0.9rem; height: 0.9rem; margin-top: 0.2rem; border-radius: 999px; border: 2px solid var(--line); background: var(--card); box-sizing: border-box; }
.step--done .step__dot { background: var(--primary); border-color: var(--primary); }
.step--now .step__dot { border-color: var(--primary); box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary) 18%, transparent); }
.step--bad .step__dot { background: #b91c1c; border-color: #b91c1c; }
.step__label { margin: 0; font-size: 0.9rem; font-weight: 600; }
.step--todo .step__label { color: var(--muted); font-weight: 500; }
.step--bad .step__label { color: #b91c1c; }
.step__at { margin: 0.1rem 0 0; font-size: 0.78rem; color: var(--muted); }

.block { margin-top: 1rem; padding-top: 0.9rem; border-top: 1px solid var(--line); }
.block p { margin: 0.15rem 0; overflow-wrap: anywhere; }
.block__title { margin: 0 0 0.5rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
.block .ship { margin-top: 0.4rem; font-size: 0.88rem; }
/* La guía se selecciona completa con un clic, para pegarla en el sitio de la paquetería. */
.guide { user-select: all; font-variant-numeric: tabular-nums; }

.items { list-style: none; margin: 0 0 0.7rem; padding: 0; display: grid; gap: 0.45rem; }
.item { display: grid; grid-template-columns: auto 1fr auto; gap: 0.5rem; align-items: baseline; font-size: 0.9rem; }
.item__qty { color: var(--muted); font-variant-numeric: tabular-nums; }
.item__unit { display: block; font-size: 0.75rem; color: var(--muted); }
.item__amt { font-weight: 600; font-variant-numeric: tabular-nums; }

.sums { margin: 0; display: grid; gap: 0.3rem; font-size: 0.9rem; }
.sums__row { display: flex; justify-content: space-between; gap: 1rem; }
.sums dt, .sums dd { margin: 0; }
.sums dd { font-variant-numeric: tabular-nums; }
.sums__row--total { padding-top: 0.4rem; border-top: 1px dashed var(--line); font-size: 1.05rem; font-weight: 800; }
.sums__row--total dd { color: var(--primary); }
</style>
