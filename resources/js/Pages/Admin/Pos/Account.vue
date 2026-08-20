<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';

const props = defineProps({
    accountUlid: { type: String, required: true },
});

/**
 * Una cuenta: capturar, comandar, descontar y cobrar (§6.3).
 *
 * ## El precio NO se manda desde aquí
 *
 * Se capturan artículo y cantidad; el precio lo resuelve y lo congela el servidor (§6.9). Aceptarlo del cliente sería
 * la puerta más ancha del sistema — cualquiera podría cobrarse un café a un peso desde la consola del navegador.
 *
 * ## Cada respuesta trae la cuenta entera, y se usa
 *
 * Después de capturar, descontar o cobrar, el servidor devuelve la cuenta completa con sus totales recalculados. Esta
 * pantalla la reemplaza tal cual en lugar de sumar por su cuenta: dos aritméticas darían dos cifras, y la que el
 * cliente vería sería la equivocada.
 *
 * ## El candado optimista se manda siempre
 *
 * `version` viaja en cada escritura. Si otra terminal tocó la cuenta mientras ésta la tenía en pantalla, el servidor
 * responde 409 y hay que recargar — en lugar de escribir sobre lo que no se vio.
 */
const account = ref(null);
const articles = ref([]);
const methods = ref([]);
const loading = ref(true);
const loadError = ref(null);

const captureLines = ref([{ article_ulid: '', quantity: '1' }]);
const discountForm = ref({ kind: 'percentage', value: '', reason: '', item_ulid: '', authorization_token: '' });
const payForm = ref({ payment_method_ulid: '', amount: '', tendered_amount: '', tip_amount: '' });

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const [cuenta, catalogo, metodos] = await Promise.all([
            api.get(`/pos-accounts/${props.accountUlid}`),
            // El filtro se llama `available_in_pos`, no `is_sellable`: la lista blanca de `/articles` sólo admite
            // `status` y `available_in_pos`, y un filtro no permitido responde 422 (D182). Con el nombre inventado
            // la pantalla de la cuenta salía COMPLETAMENTE EN BLANCO — el error del catálogo tumbaba el
            // `Promise.all` entero, incluida la cuenta que sí había cargado bien.
            //
            // Y no es lo mismo que «vendible»: un artículo puede ser vendible y estar retirado de la carta hoy.
            // Lo que el POS debe ofrecer es lo disponible EN EL POS.
            api.get('/articles', { available_in_pos: 1, status: 'active', per_page: 200 }),
            api.get('/payment-methods', { status: 'active', per_page: 50 }),
        ]);

        account.value = cuenta.data;
        articles.value = catalogo.data;
        methods.value = metodos.data;

        if (! payForm.value.payment_method_ulid && methods.value.length > 0) {
            payForm.value.payment_method_ulid = methods.value[0].ulid;
        }
    } catch (e) {
        if (e instanceof ApiError) {
            loadError.value = e;
        } else {
            throw e;
        }
    } finally {
        loading.value = false;
    }
}

/** La versión que se manda en cada escritura. */
function version() {
    return account.value?.version;
}

const capture = useApiForm(async () => {
    const lines = captureLines.value.filter((l) => l.article_ulid !== '');

    const respuesta = await api.post(`/pos-accounts/${props.accountUlid}/orders`, {
        version: version(),
        lines,
    });

    account.value = respuesta.data;
    captureLines.value = [{ article_ulid: '', quantity: '1' }];
});

const command = useApiForm(async (orderUlid) => {
    await api.post(`/pos-accounts/${props.accountUlid}/orders/${orderUlid}/command`, { version: version() });

    // Comandar devuelve las COMANDAS, no la cuenta: hay que recargarla para ver los items en su nuevo estado y la
    // versión al día.
    await load();
});

const discount = useApiForm(async () => {
    const cuerpo = { version: version(), ...discountForm.value };

    if (cuerpo.kind === 'courtesy') {
        delete cuerpo.value;
    }

    if (cuerpo.item_ulid === '') {
        delete cuerpo.item_ulid;
    }

    if (cuerpo.authorization_token === '') {
        delete cuerpo.authorization_token;
    }

    const respuesta = await api.post(`/pos-accounts/${props.accountUlid}/discounts`, cuerpo);

    account.value = respuesta.data;
    discountForm.value = { kind: 'percentage', value: '', reason: '', item_ulid: '', authorization_token: '' };
});

const pay = useApiForm(async () => {
    const linea = { ...payForm.value };

    Object.keys(linea).forEach((k) => {
        if (linea[k] === '') {
            delete linea[k];
        }
    });

    const respuesta = await api.post(`/pos-accounts/${props.accountUlid}/payments`, {
        version: version(),
        payments: [linea],
    });

    account.value = respuesta.data;
    payForm.value = { payment_method_ulid: methods.value[0]?.ulid ?? '', amount: '', tendered_amount: '', tip_amount: '' };
});

const requestBill = useApiForm(async () => {
    const respuesta = await api.post(`/pos-accounts/${props.accountUlid}/bill-request`, { version: version() });
    account.value = respuesta.data;
});

/**
 * Los pagos que dejaron cambio por devolver.
 *
 * Sólo los que lo tienen: un pago con tarjeta o uno en efectivo exacto no genera cambio, y pintarle «$0.00» al cajero
 * es ruido en el momento de menos margen para leer.
 */
const pagosConCambio = computed(
    () => (account.value?.payments ?? []).filter((p) => p.change_amount && p.change_amount !== '0.00'),
);

/** Los items que todavía no salieron a preparar, agrupados por orden. */
const ordersToCommand = computed(() => {
    if (! account.value) {
        return [];
    }

    const porOrden = new Map();

    for (const item of account.value.items ?? []) {
        if (item.status !== 'captured') {
            continue;
        }

        const orden = (account.value.orders ?? []).find((o) => ! o.sent_at) ?? null;

        if (orden) {
            porOrden.set(orden.ulid, orden);
        }
    }

    // Las órdenes con algo pendiente. Comandar una orden ya comandada no hace nada —es idempotente— pero ofrecer el
    // botón sugeriría que sí.
    return [...porOrden.values()];
});

const canCharge = computed(() => account.value && ['open', 'bill_requested', 'closed'].includes(account.value.status));

function money(value) {
    return value === null || value === undefined ? '—' : `$${value}`;
}
</script>

<template>
    <Head :title="account ? account.display_name : 'Cuenta'" />

    <div class="cuenta">
        <p v-if="loading">Cargando…</p>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <template v-else-if="account">
            <header>
                <h1>{{ account.display_name }}</h1>
                <p class="folio">{{ account.folio }} · {{ account.status_label }}</p>
            </header>

            <section class="panel totales">
                <div><span>Subtotal</span><strong>{{ money(account.totals.subtotal) }}</strong></div>
                <div><span>Descuentos</span><strong>{{ money(account.totals.discount_total) }}</strong></div>
                <!-- El IVA va aparte y NO se suma: los precios son IVA incluido, así que ya está dentro del total. -->
                <div><span>IVA incluido</span><strong>{{ money(account.totals.vat_total) }}</strong></div>
                <div><span>Total</span><strong>{{ money(account.totals.total) }}</strong></div>
                <div><span>Pagado</span><strong>{{ money(account.totals.paid_total) }}</strong></div>
                <div><span>Falta</span><strong>{{ money(account.totals.due) }}</strong></div>
            </section>

            <!--
                EL CAMBIO, que es el número que el cajero necesita en la mano y con el que no puede equivocarse.

                No estaba: se cobró en el navegador con $300 entregados sobre $196 y $20 de propina, el servidor
                devolvió $84.00 correctamente —la propina NO cuenta para el cambio— y la pantalla no lo enseñaba en
                ningún lado. El dato viajaba y nadie lo pintaba, que es la peor forma de que falte: quien cobra tiene
                que hacer la resta de cabeza, y la propina es justo lo que la vuelve fácil de errar.

                Se pinta lo que devolvió el SERVIDOR. Restar aquí sería reintroducir el cálculo que la pantalla no hace
                a propósito.
            -->
            <section v-if="pagosConCambio.length > 0" class="panel cambio">
                <h2>Cambio</h2>

                <p v-for="p in pagosConCambio" :key="p.ulid" class="cambio__linea">
                    <strong>{{ money(p.change_amount) }}</strong>
                    <span>
                        de {{ money(p.tendered_amount) }} entregados sobre {{ money(p.amount) }}
                        <template v-if="p.tip_amount && p.tip_amount !== '0.00'">
                            más {{ money(p.tip_amount) }} de propina para {{ p.tip_to?.name }}
                        </template>
                    </span>
                </p>
            </section>

            <section class="panel">
                <h2>Consumo</h2>

                <p v-if="(account.items ?? []).length === 0" class="nota">Todavía no se ha capturado nada.</p>

                <table v-else>
                    <thead>
                        <tr><th>Cant.</th><th>Artículo</th><th>Precio</th><th>Importe</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in account.items" :key="i.ulid" :class="{ cancelado: i.status === 'cancelled' }">
                            <td>{{ i.quantity }}</td>
                            <td>
                                {{ i.article_name }}
                                <span v-if="i.is_courtesy" class="etiqueta">cortesía</span>
                            </td>
                            <td>{{ money(i.unit_price) }}</td>
                            <td>{{ money(i.line_total) }}</td>
                            <td>{{ i.status_label }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="account.accepts_items" class="panel">
                <h2>Capturar</h2>

                <form @submit.prevent="capture.submit()">
                    <div v-for="(linea, indice) in captureLines" :key="indice" class="linea">
                        <select v-model="linea.article_ulid">
                            <option value="">Artículo…</option>
                            <option v-for="a in articles" :key="a.ulid" :value="a.ulid">
                                {{ a.name }} — {{ money(a.base_price) }}
                            </option>
                        </select>

                        <input v-model="linea.quantity" type="text" inputmode="decimal" class="cantidad" />
                    </div>

                    <button type="button" class="enlace" @click="captureLines.push({ article_ulid: '', quantity: '1' })">
                        + otra línea
                    </button>

                    <p v-if="capture.generalError.value" class="error">{{ capture.generalError.value }}</p>

                    <button type="submit" :disabled="capture.processing.value">Capturar</button>
                </form>
            </section>

            <section v-if="ordersToCommand.length > 0" class="panel">
                <h2>Comandar</h2>

                <p class="nota">
                    Manda a preparar lo capturado. Sale una comanda por área: la cocina recibe lo suyo y la barra lo
                    suyo.
                </p>

                <button
                    v-for="o in ordersToCommand"
                    :key="o.ulid"
                    type="button"
                    :disabled="command.processing.value"
                    @click="command.submit(o.ulid)"
                >
                    Comandar orden {{ o.sequence }}
                </button>

                <p v-if="command.generalError.value" class="error">{{ command.generalError.value }}</p>
            </section>

            <section v-if="canCharge" class="panel">
                <h2>Descuento</h2>

                <p class="nota">
                    El monto lo calcula el servidor: se manda el tipo y el valor, nunca el resultado. Y siempre pide el
                    PIN de un superior — el permiso lo tiene la terminal, el PIN lo tiene la persona.
                </p>

                <form @submit.prevent="discount.submit()">
                    <label>
                        Tipo
                        <select v-model="discountForm.kind">
                            <option value="percentage">Porcentaje</option>
                            <option value="amount">Importe</option>
                            <option value="courtesy">Cortesía</option>
                        </select>
                    </label>

                    <label v-if="discountForm.kind !== 'courtesy'">
                        Valor
                        <input v-model="discountForm.value" type="text" inputmode="decimal" />
                    </label>

                    <label>
                        Item (vacío = toda la cuenta)
                        <select v-model="discountForm.item_ulid">
                            <option value="">Toda la cuenta</option>
                            <option v-for="i in account.items" :key="i.ulid" :value="i.ulid">
                                {{ i.article_name }}
                            </option>
                        </select>
                    </label>

                    <label>
                        Motivo
                        <input v-model="discountForm.reason" type="text" required />
                    </label>

                    <label>
                        Token de autorización
                        <input v-model="discountForm.authorization_token" type="text" />
                    </label>

                    <p v-if="discount.generalError.value" class="error">{{ discount.generalError.value }}</p>

                    <button type="submit" :disabled="discount.processing.value">Aplicar</button>
                </form>
            </section>

            <section v-if="canCharge" class="panel">
                <h2>Cobrar</h2>

                <form @submit.prevent="pay.submit()">
                    <label>
                        Método
                        <select v-model="payForm.payment_method_ulid" required>
                            <option v-for="m in methods" :key="m.ulid" :value="m.ulid">{{ m.name }}</option>
                        </select>
                    </label>

                    <label>
                        Monto
                        <input v-model="payForm.amount" type="text" inputmode="decimal" required />
                    </label>

                    <label>
                        Entregado (efectivo)
                        <input v-model="payForm.tendered_amount" type="text" inputmode="decimal" />
                    </label>

                    <label>
                        Propina
                        <input v-model="payForm.tip_amount" type="text" inputmode="decimal" />
                    </label>

                    <p class="nota">La propina no cuenta para el cambio: se devuelve lo entregado menos el monto más la propina.</p>

                    <p v-if="pay.generalError.value" class="error">{{ pay.generalError.value }}</p>

                    <button type="submit" :disabled="pay.processing.value">Cobrar</button>
                </form>

                <p v-if="account.totals.change_total !== '0.00'" class="cambio">
                    Cambio a entregar: <strong>{{ money(account.totals.change_total) }}</strong>
                </p>
            </section>

            <section v-if="account.status === 'open'" class="panel">
                <button type="button" :disabled="requestBill.processing.value" @click="requestBill.submit()">
                    Pedir la cuenta
                </button>
                <p v-if="requestBill.generalError.value" class="error">{{ requestBill.generalError.value }}</p>
            </section>

            <p><a href="/admin/pos/cuentas">← Volver a las cuentas</a></p>
        </template>
    </div>
</template>

<style scoped>
.cuenta { display: grid; gap: 1.5rem; max-width: 60rem; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
.panel h2 { margin-top: 0; }
.folio { color: #666; margin-top: -0.75rem; }
.nota { color: #555; font-size: 0.9rem; }
.error { color: #a11; }
.cambio__linea { display: flex; gap: 0.6rem; align-items: baseline; margin: 0.2rem 0; }
.cambio__linea strong { font-size: 1.6rem; }
.cambio__linea span { color: #555; font-size: 0.9rem; }
.cambio { font-size: 1.1rem; }
form { display: grid; gap: 0.5rem; max-width: 24rem; }
label { display: grid; gap: 0.2rem; }
.linea { display: grid; grid-template-columns: 1fr 5rem; gap: 0.5rem; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 0.35rem 0.5rem; border-bottom: 1px solid #eee; }
.cancelado { color: #999; text-decoration: line-through; }
.etiqueta { background: #eef; border-radius: 3px; font-size: 0.75rem; padding: 0.1rem 0.35rem; margin-left: 0.4rem; }
.totales { display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: 0.75rem; }
.totales div { display: grid; }
.totales span { font-size: 0.8rem; color: #666; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; padding: 0; text-align: left; }
</style>
