<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { pushToast } from '../../../stores/useToasts';
import Icon from '../../../components/Icon.vue';
import PinAuthorizationDialog from '../../../components/inventory/PinAuthorizationDialog.vue';

const props = defineProps({
    accountUlid: { type: String, required: true },
});

/**
 * Una cuenta: capturar, comandar, descontar y cobrar (§6.3).
 *
 * ## Marcar es tocar, y capturar ya no es un paso aparte (rediseño)
 *
 * A la izquierda, el catálogo del POS como una rejilla de botones por categoría. A la derecha, el ticket en vivo. Un
 * toque en un producto lo captura AL INSTANTE en el servidor —no hay botón «Capturar» ni carrito local—: la línea nace
 * en «Pendiente por enviar», donde se ajusta con +/− grandes, y de un solo toque en «Enviar comanda» sale a preparar,
 * repartida por área. Antes era un `<select>` de doscientos artículos y un paso de captura extra, que en una tablet de
 * caja es justo lo que no se puede en hora pico.
 *
 * ## El precio NO se manda desde aquí
 *
 * Se capturan artículo y cantidad; el precio lo resuelve y lo congela el servidor (§6.9). Aceptarlo del cliente sería
 * la puerta más ancha del sistema — cualquiera podría cobrarse un café a un peso desde la consola del navegador. El
 * precio de la rejilla es sólo una referencia para elegir; nunca viaja.
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
const categoryTree = ref([]); // categorías de nivel 1 con sus subcategorías (nivel 2) anidadas
const methods = ref([]);
const promoPreview = ref(null);
const loading = ref(true);
const loadError = ref(null);

const search = ref('');
const activeCategory = ref(null); // categoría de nivel 1 (pestaña); null = todas
const activeSub = ref(null); // subcategoría de nivel 2 (chip); null = todas dentro de la pestaña

const discountForm = ref({ kind: 'percentage', value: '', reason: '', item_ulid: '', authorization_token: '' });
const payForm = ref({ payment_method_ulid: '', amount: '', tendered_amount: '', tip_amount: '', reference: '' });

// La pantalla tiene dos vistas: «orden» (marcar) y «cobro» (pantalla dedicada). El éxito («pagada») no es una vista:
// se deriva del estado de la cuenta. Los modales de descuento y de confirmación viven encima de la de cobro.
const vista = ref('orden');
const mostrarDescuento = ref(false);
const confirmarCobro = ref(false);
const regresoEn = ref(0); // cuenta regresiva del regreso automático tras cerrar la cuenta

onMounted(async () => {
    await load();
    await refreshPromoPreview();
});

/**
 * La vista previa de promociones: qué se descontará al cobrar (paso 11 del diseño).
 *
 * Es informativa —las promociones se materializan al cobrar, no ahora— así que su fallo NO debe tumbar la pantalla: se
 * traga aquí y la cuenta sigue en pie. Se refresca cada vez que cambian los items, porque agregar una cerveza puede
 * disparar el 2x1 y pasar de las ocho puede apagar el happy hour.
 */
async function refreshPromoPreview() {
    try {
        const { data } = await api.get(`/pos-accounts/${props.accountUlid}/promotions-preview`);
        promoPreview.value = data;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }
        promoPreview.value = null;
    }
}

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const [cuenta, categorias, catalogo, metodos] = await Promise.all([
            api.get(`/pos-accounts/${props.accountUlid}`),
            // El árbol de categorías (nivel 1 con subcategorías nivel 2) para las pestañas y chips del grid.
            api.get('/article-categories'),
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
        categoryTree.value = categorias.data;
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

// ---------------------------------------------------------------------------
// Catálogo (columna izquierda): categorías, búsqueda y toque para agregar.
// ---------------------------------------------------------------------------

/** Las pestañas: categorías de nivel 1, del árbol. */
const categories = computed(() => categoryTree.value);

/** Mapa de cualquier categoría (nivel 1 o 2) a su categoría de nivel 1, para filtrar por pestaña aunque el artículo cuelgue de una subcategoría. */
const topOf = computed(() => {
    const map = new Map();

    for (const top of categoryTree.value) {
        map.set(top.ulid, top.ulid);

        for (const sub of top.children ?? []) {
            map.set(sub.ulid, top.ulid);
        }
    }

    return map;
});

/** Las subcategorías (nivel 2) de la pestaña activa; vacío si no hay pestaña o no tiene hijas. */
const subcategories = computed(() => {
    if (! activeCategory.value) {
        return [];
    }

    const top = categoryTree.value.find((c) => c.ulid === activeCategory.value);

    return top?.children ?? [];
});

/** Lo que se pinta en la rejilla: filtrado por pestaña, subcategoría y texto del buscador. */
const filteredArticles = computed(() => {
    const q = search.value.trim().toLowerCase();
    const map = topOf.value;

    return articles.value.filter((a) => {
        const top = a.category ? map.get(a.category.ulid) : null;
        const enTop = ! activeCategory.value || top === activeCategory.value;
        const enSub = ! activeSub.value || a.category?.ulid === activeSub.value;
        const nombre = (a.display_name ?? a.name ?? '').toLowerCase();
        const coincide = q === '' || nombre.includes(q) || (a.code ?? '').toLowerCase().includes(q);

        return enTop && enSub && coincide;
    });
});

/** Cambiar de pestaña reinicia la subcategoría: los chips de abajo son de OTRA categoría. */
function selectCategory(ulid) {
    activeCategory.value = ulid;
    activeSub.value = null;
}

// Toda escritura de marcado pasa por esta cola: se ejecutan de una en una, y cada una usa la `version` que dejó la
// anterior. Así tocar rápido —dos cafés, +, +, ×— no choca con el candado optimista, que le respondería 409 a la
// segunda por mandar una versión ya vencida.
let cola = Promise.resolve();
const encolar = (fn) => (cola = cola.then(fn).catch(() => {}));

/** Manda una escritura que devuelve la cuenta entera y la deja en pantalla; el error se avisa sin tumbar nada. */
async function pedir(hacer) {
    try {
        account.value = (await hacer()).data;
        await refreshPromoPreview();
    } catch (e) {
        pushToast(e instanceof ApiError ? e.title : 'No se pudo completar la operación.', 'error');
    }
}

/** El ítem vivo por ulid, leído de la cuenta al momento de ejecutar —no del render—, para que un +/− encolado sume sobre la cantidad ya al día. */
const itemVivo = (ulid) => (account.value?.items ?? []).find((i) => i.ulid === ulid);

/**
 * Un toque agrega una unidad. No hay paso «Capturar»: la línea nace capturada en el servidor y la respuesta trae la
 * cuenta entera. Tocar el mismo artículo (sin modificadores) suma en su línea —el servidor la fusiona—, así que quedan
 * «×2» y una sola línea, no dos renglones.
 */
function add(article) {
    encolar(() => pedir(() => api.post(`/pos-accounts/${props.accountUlid}/orders`, {
        version: version(),
        lines: [{ article_ulid: article.ulid, quantity: '1' }],
    })));
}

/** Enter en el buscador agrega el primer resultado: marcar sin soltar el teclado. */
function addFirstMatch() {
    const primero = filteredArticles.value[0];

    if (primero) {
        add(primero);
        search.value = '';
    }
}

// Pendiente por enviar (capturado, aún sin comandar) y ya enviado (todo lo demás: comandado en adelante y cancelado).
// Se leen de la cuenta —la única verdad—; aquí sólo se parten por estado.
const pendientes = computed(() => (account.value?.items ?? []).filter((i) => i.status === 'captured'));
const enviados = computed(() => (account.value?.items ?? []).filter((i) => i.status !== 'captured'));

/** Lo que se cobra (todo lo no cancelado): lo que la precuenta lista como consumo. */
const itemsCuenta = computed(() => (account.value?.items ?? []).filter((i) => i.status !== 'cancelled'));

/** Cuántas unidades hay por enviar (para el botón de comanda). */
const pendingCount = computed(() => pendientes.value.reduce((suma, i) => suma + Number(i.quantity), 0));

/** Fija la cantidad de una línea pendiente (los +/− grandes). Lee la cantidad viva al ejecutar, no al encolar. */
const setItemQty = (ulid, cantidad) => encolar(() => pedir(
    () => api.post(`/pos-accounts/${props.accountUlid}/items/${ulid}/quantity`, {
        version: version(),
        quantity: String(cantidad),
    }),
));

function incItem(item) {
    encolar(() => {
        const vivo = itemVivo(item.ulid);

        return vivo ? pedir(() => api.post(`/pos-accounts/${props.accountUlid}/items/${item.ulid}/quantity`, {
            version: version(),
            quantity: String(Number(vivo.quantity) + 1),
        })) : Promise.resolve();
    });
}

function decItem(item) {
    encolar(() => {
        const vivo = itemVivo(item.ulid);

        if (! vivo) {
            return Promise.resolve();
        }

        const n = Number(vivo.quantity) - 1;

        // Bajar de uno es quitar la línea: sin comandar, cancelar la borra (no pide motivo ni PIN).
        return n <= 0
            ? pedir(() => api.post(`/pos-accounts/${props.accountUlid}/items/cancel`, {
                version: version(),
                item_ulids: [item.ulid],
            }))
            : pedir(() => api.post(`/pos-accounts/${props.accountUlid}/items/${item.ulid}/quantity`, {
                version: version(),
                quantity: String(n),
            }));
    });
}

/** La × de una línea pendiente: cancelar sin comandar la borra. */
function quitarItem(item) {
    encolar(() => pedir(() => api.post(`/pos-accounts/${props.accountUlid}/items/cancel`, {
        version: version(),
        item_ulids: [item.ulid],
    })));
}

/**
 * Enviar comanda: manda TODO lo pendiente de un toque. Lo capturado vive en una sola orden borrador (capturar anexa),
 * así que se comanda esa orden y el servidor reparte por área —la cocina recibe lo suyo y la barra lo suyo (D28)—. El
 * toast resume cuánto fue a cada área, armado ANTES de mandar porque después `pendientes` queda vacío.
 */
const enviando = ref(false);

function enviarComanda() {
    encolar(async () => {
        const orden = pendientes.value[0]?.order_ulid;

        if (! orden) {
            return;
        }

        const porArea = {};

        for (const i of pendientes.value) {
            const area = i.preparation_area?.name ?? 'Sin área';
            porArea[area] = (porArea[area] ?? 0) + Number(i.quantity);
        }

        enviando.value = true;

        try {
            await api.post(`/pos-accounts/${props.accountUlid}/orders/${orden}/command`, { version: version() });
            // Comandar devuelve las COMANDAS, no la cuenta: se recarga para ver los items en su nuevo estado y la versión.
            await load();
            await refreshPromoPreview();

            const resumen = Object.entries(porArea).map(([area, n]) => `${area} ${n}`).join(' · ');
            pushToast(`Comanda enviada · ${resumen}`);
        } catch (e) {
            pushToast(e instanceof ApiError ? e.title : 'No se pudo enviar la comanda.', 'error');
        } finally {
            enviando.value = false;
        }
    });
}

// Los avisos efímeros (comanda enviada, artículo cancelado, errores de una acción de un toque) van al toastr global
// —`pushToast` importado del store—, que un solo host pinta desde el layout.

// ---------------------------------------------------------------------------
// Cancelar un artículo YA COMANDADO (§6.3): pide motivo, destino (merma/reingreso) y el PIN de un superior. El menú ⋮
// de la lista de enviados abre la ventana; el 409 `authorization_required` pide la firma y se reintenta con el token.
// ---------------------------------------------------------------------------
const menuAbierto = ref(null); // ulid de la línea con el menú ⋮ abierto
const itemACancelar = ref(null); // la línea que se está cancelando (abre la ventana)
const cancelForm = ref({ reason: '', destination: '' });
const cancelError = ref(null);
const cancelProcesando = ref(false);
const pendingAuthorization = ref(null); // { permission, reason } del 409; abre el diálogo de PIN

const abrirMenu = (ulid) => { menuAbierto.value = menuAbierto.value === ulid ? null : ulid; };

function pedirCancelar(item) {
    menuAbierto.value = null;
    itemACancelar.value = item;
    cancelForm.value = { reason: '', destination: '' };
    cancelError.value = null;
    pendingAuthorization.value = null;
}

/**
 * Manda la cancelación del comandado. Sin token la primera vez: si el servidor pide firma responde 409 y se abre el
 * PIN; con la firma se reintenta la MISMA cancelación (el token es de un solo uso, por eso no se pide por adelantado).
 */
async function trySubmitCancel(authorizationToken = null) {
    if (! itemACancelar.value) {
        return;
    }

    cancelProcesando.value = true;
    cancelError.value = null;

    try {
        const { data } = await api.post(`/pos-accounts/${props.accountUlid}/items/cancel`, {
            version: version(),
            item_ulids: [itemACancelar.value.ulid],
            reason: cancelForm.value.reason,
            destination: cancelForm.value.destination,
            authorization_token: authorizationToken,
        });

        account.value = data;
        await refreshPromoPreview();
        pushToast('Artículo comandado cancelado');
        itemACancelar.value = null;
        pendingAuthorization.value = null;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        // No es un error: es una firma pendiente. El permiso viene en el 409, así que la pantalla no lleva su propia
        // tabla de «qué permiso pide cada operación».
        if (e.isAuthorizationRequired) {
            pendingAuthorization.value = { permission: e.requiredPermission, reason: e.message };

            return;
        }

        // Cualquier otro fallo (p. ej. el reintento tras la firma choca con la versión): se cierra el PIN para que el
        // aviso se vea en la ventana de cancelación, no detrás del diálogo.
        pendingAuthorization.value = null;
        cancelError.value = e.message;
    } finally {
        cancelProcesando.value = false;
    }
}

const onGrantedCancel = (token) => trySubmitCancel(token);

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
    mostrarDescuento.value = false;
    await refreshPromoPreview();
}, { success: 'Descuento aplicado' });

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
    confirmarCobro.value = false;
    // Al cobrar, las promociones se materializan y ya viven en el total: el preview vuelve vacío.
    await refreshPromoPreview();

    // Si quedó saldo (pago parcial), se deja listo el siguiente cobro por lo que falta. Si quedó cubierta, el estado
    // pasa a «pagada» y el watch de abajo dispara el cierre con regreso automático.
    const falta = account.value?.totals?.due;
    payForm.value = {
        payment_method_ulid: methods.value[0]?.ulid ?? '',
        amount: falta && falta !== '0.00' ? falta : '',
        tendered_amount: '',
        tip_amount: '',
        reference: '',
    };
}, { silent: true });

const requestBill = useApiForm(async () => {
    const respuesta = await api.post(`/pos-accounts/${props.accountUlid}/bill-request`, { version: version() });
    account.value = respuesta.data;
}, { silent: true });

/**
 * Los pagos que dejaron cambio por devolver.
 *
 * Sólo los que lo tienen: un pago con tarjeta o uno en efectivo exacto no genera cambio, y pintarle «$0.00» al cajero
 * es ruido en el momento de menos margen para leer.
 */
const pagosConCambio = computed(
    () => (account.value?.payments ?? []).filter((p) => p.change_amount && p.change_amount !== '0.00'),
);

// Cobrar acepta cuenta abierta, solicitada o cerrada (en una barra se paga sin «pedir la cuenta» antes); sólo quedan
// fuera la pagada y la cancelada. El descuento, igual.
const puedeCobrar = computed(() => account.value && ['open', 'bill_requested', 'closed'].includes(account.value.status));

/** Modo marcar: el catálogo (grid) sólo mientras la cuenta está Abierta. */
const isMarcar = computed(() => account.value?.status === 'open');

/** El éxito: la cuenta quedó pagada. Dispara el cierre con regreso automático. */
const cerrada = computed(() => account.value?.status === 'paid');

/** El método elegido, para saber si pide referencia o da cambio. */
const selectedMethod = computed(() => methods.value.find((m) => m.ulid === payForm.value.payment_method_ulid) ?? null);

/** Lo que cubre esta línea: monto + propina (la propina NO reduce el cambio; se suma a lo que hay que entregar). */
const aCubrir = computed(() => (Number(payForm.value.amount || 0) + Number(payForm.value.tip_amount || 0)).toFixed(2));

/** Cambio de referencia: lo entregado menos lo que cubre. Sólo si el método da cambio y alcanzó. El definitivo lo manda el servidor. */
const cambioPreview = computed(() => {
    if (! selectedMethod.value?.allows_change || payForm.value.tendered_amount === '') {
        return null;
    }

    const cambio = Number(payForm.value.tendered_amount) - Number(aCubrir.value);

    return cambio >= 0 ? cambio.toFixed(2) : null;
});

/** Lo entregado no alcanza a cubrir (efectivo corto): se avisa antes de mandar, que el servidor rechazaría igual. */
const faltaEntregado = computed(() => selectedMethod.value?.allows_change
    && payForm.value.tendered_amount !== ''
    && Number(payForm.value.tendered_amount) < Number(aCubrir.value));

/** Cuánto falta recibir cuando el efectivo entregado se queda corto (para el aviso). */
const faltaRecibir = computed(() => (Number(aCubrir.value) - Number(payForm.value.tendered_amount || 0)).toFixed(2));

/** Entrar al cobro: deja el monto en lo que falta y limpia el resto. */
function irACobro() {
    const falta = account.value?.totals?.due;
    payForm.value = {
        payment_method_ulid: selectedMethod.value?.ulid ?? methods.value[0]?.ulid ?? '',
        amount: falta && falta !== '0.00' ? falta : (account.value?.totals?.total ?? ''),
        tendered_amount: '',
        tip_amount: '',
        reference: '',
    };
    vista.value = 'cobro';
}

const volverAOrden = () => { vista.value = 'orden'; };

/** Propina rápida: un porcentaje del total (0 la borra). El monto exacto lo puede teclear el cajero. */
function propinaPct(pct) {
    const base = Number(account.value?.totals?.total || 0);
    payForm.value.tip_amount = pct === 0 ? '' : ((base * pct) / 100).toFixed(2);
}

// Al quedar pagada la cuenta se cierra con regreso automático a mesas: el cajero ve el cambio y la pantalla vuelve sola
// en cinco segundos (o antes, con «Volver ahora»).
let regresoTimer = null;

function volverAMesas() {
    clearInterval(regresoTimer);
    router.visit('/admin/pos/cuentas');
}

watch(cerrada, (esCerrada) => {
    if (! esCerrada) {
        return;
    }

    confirmarCobro.value = false;
    regresoEn.value = 5;
    clearInterval(regresoTimer);
    regresoTimer = setInterval(() => {
        regresoEn.value -= 1;

        if (regresoEn.value <= 0) {
            volverAMesas();
        }
    }, 1000);
});

onBeforeUnmount(() => clearInterval(regresoTimer));

function money(value) {
    return value === null || value === undefined ? '—' : `$${value}`;
}

/**
 * Cantidad para mostrar: entera cuando es exacta (1, 2) y con decimales sólo si los tiene (2.5, 0.25). Las cantidades
 * llegan como DECIMAL(12,4) —«1.0000»—, que en un ticket de cara al cliente se ve raro; `parseFloat` recorta los ceros.
 */
function qty(value) {
    return value === null || value === undefined ? '—' : String(parseFloat(value));
}

// Cambiar mesa: para atender varias a la vez sin salir a la lista. El switcher muestra las cuentas vivas y su estado,
// para saber cuál necesita atención (marcar, cobrar…) y saltar a ella.
const openAccounts = ref([]);
const switcherOpen = ref(false);

async function loadOpenAccounts() {
    try {
        const { data } = await api.get('/pos-accounts', { only_open: 1, per_page: 50 });
        openAccounts.value = data;
    } catch {
        openAccounts.value = [];
    }
}

function toggleSwitcher() {
    switcherOpen.value = ! switcherOpen.value;
    if (switcherOpen.value) {
        loadOpenAccounts();
    }
}

function goToAccount(ulid) {
    switcherOpen.value = false;
    if (ulid !== props.accountUlid) {
        router.visit(`/admin/pos/cuentas/${ulid}`);
    }
}

// La barra inferior fija salta a la parte que toca: «Más» al catálogo (en pantallas angostas el ticket queda debajo) y
// «Cobrar» a la tarjeta de cobro. El rediseño del cobro como pantalla dedicada es el siguiente incremento.
function scrollTo(selector) {
    document.querySelector(selector)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Imprimir la precuenta desde el panel: pide la cuenta (marca «solicitada» e imprime el ticket de cierre) y avisa el
// resultado con un toast, porque el botón vive en el panel y no tiene dónde pintar el error.
async function pedirCuenta() {
    await requestBill.submit();

    pushToast(
        requestBill.generalError.value ?? 'Precuenta enviada a impresión',
        requestBill.generalError.value ? 'error' : 'ok',
    );
}
</script>

<template>
    <Head :title="account ? account.display_name : 'Cuenta'" />

    <div class="cuenta">
        <template v-if="loading"></template>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <template v-else-if="account">
            <header v-if="vista === 'orden' && ! cerrada" class="cuenta__cabecera">
                <div>
                    <h1>{{ account.display_name }}</h1>
                    <p class="folio">
                        {{ account.folio }}
                        <span class="estado-pill" :class="`estado-pill--${account.status}`">{{ account.status_label }}</span>
                        <span v-if="account.waiter"> · {{ account.waiter.name }}</span>
                    </p>
                </div>

                <div class="switcher">
                    <button type="button" class="enlace-volver" @click="toggleSwitcher"><Icon name="swap" :size="16" /> Cambiar mesa ▾</button>

                    <div v-if="switcherOpen" class="switcher__backdrop" @click="switcherOpen = false"></div>
                    <div v-if="switcherOpen" class="switcher__menu">
                        <p class="switcher__title">Cuentas abiertas</p>
                        <button
                            v-for="c in openAccounts"
                            :key="c.ulid"
                            type="button"
                            class="switcher__item"
                            :class="{ 'switcher__item--actual': c.ulid === account.ulid }"
                            @click="goToAccount(c.ulid)"
                        >
                            <span class="switcher__nombre">{{ c.display_name }}</span>
                            <span class="estado-pill estado-pill--sm" :class="`estado-pill--${c.status}`">{{ c.status_label }}</span>
                        </button>
                        <p v-if="openAccounts.length === 0" class="nota switcher__vacio">Sin cuentas abiertas.</p>
                        <Link href="/admin/pos/cuentas" class="switcher__todas">Abrir otra / ver todas →</Link>
                    </div>
                </div>
            </header>

            <div v-if="vista === 'orden' && ! cerrada" class="marco" :class="{ 'marco--doble': isMarcar }">
                <!-- IZQUIERDA: el catálogo, sólo en modo marcar (cuenta Abierta). Al pedir la cuenta pasa al modo cobro. -->
                <section v-if="isMarcar" class="catalogo">
                    <input
                        v-model="search"
                        type="search"
                        class="buscador"
                        placeholder="Buscar artículo…  (Enter agrega el primero)"
                        @keydown.enter.prevent="addFirstMatch"
                    />

                    <template v-if="categories.length">
                        <p class="grid-label">Clasificación</p>
                        <div class="cats">
                            <button type="button" class="cat" :class="{ 'cat--activa': !activeCategory }" @click="selectCategory(null)">
                                Todas
                            </button>
                            <button
                                v-for="c in categories"
                                :key="c.ulid"
                                type="button"
                                class="cat"
                                :class="{ 'cat--activa': activeCategory === c.ulid }"
                                @click="selectCategory(c.ulid)"
                            >
                                {{ c.name }}
                            </button>
                        </div>
                    </template>

                    <template v-if="subcategories.length">
                        <p class="grid-label">Subclasificación</p>
                        <div class="subcats">
                            <button type="button" class="sub" :class="{ 'sub--activa': !activeSub }" @click="activeSub = null">
                                Todos
                            </button>
                            <button
                                v-for="s in subcategories"
                                :key="s.ulid"
                                type="button"
                                class="sub"
                                :class="{ 'sub--activa': activeSub === s.ulid }"
                                @click="activeSub = s.ulid"
                            >
                                {{ s.name }}
                            </button>
                        </div>
                    </template>

                    <div class="grid">
                        <button
                            v-for="a in filteredArticles"
                            :key="a.ulid"
                            type="button"
                            class="prod"
                            @click="add(a)"
                        >
                            <span class="prod__foto">
                                <img v-if="a.image_url" :src="a.image_url" :alt="a.display_name ?? a.name" loading="lazy" />
                                <span v-else class="prod__foto-vacia" aria-hidden="true">🍽️</span>
                            </span>
                            <span class="prod__info">
                                <span class="prod__nombre">{{ a.display_name ?? a.name }}</span>
                                <span class="prod__precio">{{ money(a.base_price) }}</span>
                            </span>
                            <span class="prod__mas" aria-hidden="true">+</span>
                        </button>

                        <p v-if="filteredArticles.length === 0" class="nota">Sin resultados.</p>
                    </div>
                </section>

                <!-- DERECHA: el ticket. -->
                <section class="ticket">
                    <!-- Pendiente por enviar: lo capturado que aún no sale a preparar. Se ajusta con +/− grandes y se
                         manda todo de un toque con «Enviar comanda». -->
                    <div v-if="isMarcar || pendientes.length > 0" class="tarjeta pendientes">
                        <h2>Pendiente por enviar</h2>

                        <p v-if="pendientes.length === 0" class="nota">
                            Toca un producto del catálogo para agregarlo. Aquí lo revisas antes de mandarlo a preparar.
                        </p>

                        <ul v-else class="pend">
                            <li v-for="i in pendientes" :key="i.ulid">
                                <div class="pend__datos">
                                    <span class="pend__nombre">{{ i.article_name }}</span>
                                    <span class="pend__meta">
                                        <span v-if="i.preparation_area" class="pend__area">{{ i.preparation_area.name }}</span>
                                        {{ money(i.unit_price) }} c/u · {{ money(i.line_total) }}
                                    </span>
                                </div>
                                <div class="stepper stepper--grande">
                                    <button type="button" class="stepper__b" aria-label="Quitar uno" @click="decItem(i)">−</button>
                                    <span class="stepper__n">{{ qty(i.quantity) }}</span>
                                    <button type="button" class="stepper__b" aria-label="Agregar uno" @click="incItem(i)">+</button>
                                </div>
                                <button type="button" class="quitar" aria-label="Quitar la línea" @click="quitarItem(i)">
                                    <Icon name="x" :size="20" />
                                </button>
                            </li>
                        </ul>

                        <button
                            v-if="pendientes.length > 0"
                            type="button"
                            class="principal enviar"
                            :disabled="enviando"
                            @click="enviarComanda"
                        >
                            <Icon name="send" :size="18" /> Enviar comanda · {{ pendingCount }}
                        </button>
                    </div>

                    <!-- Enviados: lo que ya salió a preparar (y lo cancelado, tachado). El ⋮ de una línea comandada la
                         cancela: pide motivo, destino (merma/reingreso) y el PIN de un superior. -->
                    <div class="tarjeta">
                        <h2>Enviados</h2>

                        <p v-if="enviados.length === 0" class="nota">Todavía no se ha enviado nada a preparar.</p>

                        <table v-else>
                            <thead>
                                <tr><th>Cant.</th><th>Artículo</th><th>Importe</th><th>Estado</th><th class="col-acc"></th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in enviados" :key="i.ulid" :class="{ cancelado: i.status === 'cancelled' }">
                                    <td>{{ qty(i.quantity) }}</td>
                                    <td>
                                        {{ i.article_name }}
                                        <span v-if="i.is_courtesy" class="etiqueta">cortesía</span>
                                    </td>
                                    <td>{{ money(i.line_total) }}</td>
                                    <td>{{ i.status_label }}</td>
                                    <td class="col-acc">
                                        <div v-if="i.was_commanded" class="menu-linea">
                                            <button type="button" class="menu-linea__b" aria-label="Más acciones" @click="abrirMenu(i.ulid)">
                                                <Icon name="dots" :size="18" />
                                            </button>
                                            <template v-if="menuAbierto === i.ulid">
                                                <div class="menu-linea__fondo" @click="menuAbierto = null"></div>
                                                <div class="menu-linea__pop">
                                                    <button type="button" class="menu-linea__accion" @click="pedirCancelar(i)">
                                                        <Icon name="trash" :size="15" /> Cancelar artículo
                                                    </button>
                                                </div>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="tarjeta totales">
                        <div><span>Subtotal</span><strong>{{ money(account.totals.subtotal) }}</strong></div>
                        <div><span>Descuentos</span><strong>{{ money(account.totals.discount_total) }}</strong></div>
                        <!-- El IVA va aparte y NO se suma: los precios son IVA incluido, así que ya está dentro del total. -->
                        <div><span>IVA incluido</span><strong>{{ money(account.totals.vat_total) }}</strong></div>
                        <div><span>Total</span><strong>{{ money(account.totals.total) }}</strong></div>
                        <div><span>Pagado</span><strong>{{ money(account.totals.paid_total) }}</strong></div>
                        <div><span>Falta</span><strong>{{ money(account.totals.due) }}</strong></div>
                    </div>

                    <!--
                        Vista previa de promociones. Se pinta lo que el SERVIDOR calculó; no se resta aquí. El total de
                        arriba todavía NO incluye estas promociones: se materializan al cobrar (una sola vez, sobre el
                        diario de descuentos, que es inmutable). Por eso el aviso dice «al cobrar» y no muestra un total
                        ya rebajado —eso sería una segunda aritmética del dinero, y la cifra que el cliente vería sería
                        la equivocada—.
                    -->
                    <div v-if="promoPreview && promoPreview.applied.length > 0" class="tarjeta promo">
                        <h2>Promociones</h2>
                        <p class="nota">Se aplican al cobrar; el total de arriba todavía no las incluye.</p>

                        <ul class="promo__lista">
                            <li v-for="(p, indice) in promoPreview.applied" :key="indice">
                                <span>{{ p.name }}</span>
                                <strong>−{{ money(p.amount) }}</strong>
                            </li>
                        </ul>

                        <p class="promo__total">Descuento por promociones al cobrar: <strong>−{{ money(promoPreview.total) }}</strong></p>
                    </div>

                    <!--
                        EL CAMBIO, que es el número que el cajero necesita en la mano y con el que no puede equivocarse.
                        Se pinta lo que devolvió el SERVIDOR; la propina NO cuenta para el cambio.
                    -->
                    <div v-if="pagosConCambio.length > 0" class="tarjeta cambio">
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
                    </div>

                </section>
            </div>

            <!-- Barra inferior fija (sólo en la vista de orden): total en vivo y la acción que toca según el estado. -->
            <footer v-if="vista === 'orden' && ! cerrada" class="barra">
                <div class="barra__total">
                    <span>Total</span>
                    <strong>{{ money(account.totals.total) }}</strong>
                </div>

                <div class="barra__acciones">
                    <button v-if="isMarcar" type="button" class="barra__b" @click="scrollTo('.catalogo')">
                        <Icon name="plus" :size="16" /> Más
                    </button>
                    <button
                        v-if="account.status === 'open' || account.status === 'bill_requested'"
                        type="button"
                        class="barra__b"
                        @click="vista = 'precuenta'"
                    >
                        <Icon name="printer" :size="16" /> Precuenta
                    </button>
                    <button v-if="puedeCobrar" type="button" class="barra__b barra__b--principal" @click="irACobro">
                        <Icon name="receive" :size="16" /> Cobrar
                    </button>
                </div>
            </footer>

            <!-- PANEL DE PRECUENTA: el ticket como lo ve el cliente, para revisar (e imprimir) antes de cobrar. -->
            <section v-if="vista === 'precuenta' && ! cerrada" class="precuenta">
                <header class="cobro-screen__cab">
                    <button type="button" class="enlace-volver" @click="volverAOrden">
                        <Icon name="undo" :size="16" /> Volver a la orden
                    </button>
                    <div>
                        <h2>Precuenta</h2>
                        <p class="folio">Revísala antes de cobrar</p>
                    </div>
                </header>

                <div class="ticket-preview">
                    <div class="ticket-preview__cab">
                        <strong>{{ account.display_name }}</strong>
                        <span>{{ account.folio }}</span>
                        <span v-if="account.waiter">Atiende: {{ account.waiter.name }}</span>
                    </div>

                    <ul class="ticket-preview__items">
                        <li v-for="i in itemsCuenta" :key="i.ulid">
                            <span class="tpi__cant">{{ qty(i.quantity) }}×</span>
                            <span class="tpi__nombre">
                                {{ i.article_name }}
                                <span v-if="i.is_courtesy" class="etiqueta">cortesía</span>
                            </span>
                            <span class="tpi__importe">{{ money(i.line_total) }}</span>
                        </li>
                        <li v-if="itemsCuenta.length === 0" class="nota">La cuenta todavía no tiene consumo.</li>
                    </ul>

                    <div class="ticket-preview__totales">
                        <div><span>Subtotal</span><strong>{{ money(account.totals.subtotal) }}</strong></div>
                        <div v-if="account.totals.discount_total !== '0.00'">
                            <span>Descuentos</span><strong>−{{ money(account.totals.discount_total) }}</strong>
                        </div>
                        <div><span>IVA incluido</span><strong>{{ money(account.totals.vat_total) }}</strong></div>
                        <div class="tpt__total"><span>Total</span><strong>{{ money(account.totals.total) }}</strong></div>
                    </div>

                    <p v-if="promoPreview && promoPreview.applied.length > 0" class="ticket-preview__promo">
                        Al cobrar se aplican promociones por −{{ money(promoPreview.total) }}.
                    </p>
                </div>

                <div class="precuenta__acciones">
                    <button
                        v-if="account.status === 'open'"
                        type="button"
                        class="secundario"
                        :disabled="requestBill.processing.value"
                        @click="pedirCuenta"
                    >
                        <Icon name="printer" :size="16" /> Imprimir precuenta
                    </button>
                    <p v-else class="precuenta__marcada"><Icon name="check" :size="15" /> Precuenta solicitada</p>

                    <button type="button" class="principal" @click="irACobro">
                        <Icon name="receive" :size="16" /> Cobrar {{ money(account.totals.due) }}
                    </button>
                </div>
            </section>

            <!-- PANTALLA DE COBRO: reemplaza la orden. Método → monto → (referencia / recibido) → propina → Cobrar. -->
            <section v-if="vista === 'cobro' && ! cerrada" class="cobro-screen">
                <header class="cobro-screen__cab">
                    <button type="button" class="enlace-volver" @click="volverAOrden">
                        <Icon name="undo" :size="16" /> Volver a la orden
                    </button>
                    <div>
                        <h2>Cobrar</h2>
                        <p class="folio">{{ account.display_name }} · {{ account.folio }}</p>
                    </div>
                </header>

                <!-- Lo que falta es la cifra grande; total y pagado son contexto. -->
                <div class="cobro-resumen">
                    <div class="cobro-resumen__falta">
                        <span>Falta por cobrar</span>
                        <strong>{{ money(account.totals.due) }}</strong>
                    </div>
                    <div class="cobro-resumen__aparte">
                        <div><span>Total</span><strong>{{ money(account.totals.total) }}</strong></div>
                        <div v-if="account.totals.paid_total !== '0.00'">
                            <span>Pagado</span><strong>{{ money(account.totals.paid_total) }}</strong>
                        </div>
                    </div>
                    <button type="button" class="cobro-descuento" @click="mostrarDescuento = true">
                        <Icon name="edit" :size="15" /> Aplicar descuento
                    </button>
                </div>

                <form class="cobro-form" @submit.prevent="confirmarCobro = true">
                    <fieldset class="cobro-metodos">
                        <legend>Método de pago</legend>
                        <div class="metodos-grid">
                            <button
                                v-for="m in methods"
                                :key="m.ulid"
                                type="button"
                                class="metodo"
                                :class="{ 'metodo--activo': payForm.payment_method_ulid === m.ulid }"
                                @click="payForm.payment_method_ulid = m.ulid"
                            >
                                {{ m.name }}
                            </button>
                        </div>
                    </fieldset>

                    <label class="campo campo--monto">
                        <span>Monto a cobrar</span>
                        <input v-model="payForm.amount" type="text" inputmode="decimal" required />
                    </label>

                    <label v-if="selectedMethod && selectedMethod.requires_reference" class="campo">
                        <span>Referencia · {{ selectedMethod.name }}</span>
                        <input v-model="payForm.reference" type="text" maxlength="60" required />
                    </label>

                    <div class="campo">
                        <span>Propina <template v-if="account.waiter">para {{ account.waiter.name }}</template></span>
                        <div class="propina">
                            <div class="propina__rapidas">
                                <button type="button" class="chip" @click="propinaPct(0)">Sin propina</button>
                                <button type="button" class="chip" @click="propinaPct(10)">10 %</button>
                                <button type="button" class="chip" @click="propinaPct(15)">15 %</button>
                            </div>
                            <input v-model="payForm.tip_amount" type="text" inputmode="decimal" placeholder="0.00" aria-label="Propina" />
                        </div>
                    </div>

                    <template v-if="selectedMethod && selectedMethod.allows_change">
                        <label class="campo">
                            <span>Recibido (efectivo)</span>
                            <input v-model="payForm.tendered_amount" type="text" inputmode="decimal" placeholder="0.00" />
                        </label>

                        <div class="cobro-cambio" :class="{ 'cobro-cambio--corto': faltaEntregado }">
                            <span>{{ faltaEntregado ? 'Falta recibir' : 'Cambio' }}</span>
                            <strong v-if="faltaEntregado">{{ money(faltaRecibir) }}</strong>
                            <strong v-else-if="cambioPreview !== null">{{ money(cambioPreview) }}</strong>
                            <strong v-else>—</strong>
                        </div>
                    </template>

                    <p v-if="pay.generalError.value" class="error">{{ pay.generalError.value }}</p>

                    <button type="submit" class="principal cobro-cta" :disabled="pay.processing.value || faltaEntregado">
                        <Icon name="receive" :size="18" /> Cobrar {{ money(aCubrir) }}
                    </button>
                </form>
            </section>

            <!-- ÉXITO: cuenta pagada. Muestra el cambio y regresa solo a mesas en 5 s. -->
            <section v-if="cerrada" class="cerrada">
                <div class="cerrada__marca"><Icon name="check" :size="40" /></div>
                <h2>Cuenta cerrada</h2>
                <p class="cerrada__folio">{{ account.display_name }} · {{ account.folio }}</p>

                <div class="cerrada__total">
                    <span>Cobrado</span>
                    <strong>{{ money(account.totals.paid_total) }}</strong>
                </div>

                <div v-if="account.totals.change_total !== '0.00'" class="cerrada__cambio">
                    <span>Cambio a entregar</span>
                    <strong>{{ money(account.totals.change_total) }}</strong>
                </div>

                <p class="cerrada__regreso">Regresando a mesas en {{ regresoEn }}…</p>
                <button type="button" class="principal" @click="volverAMesas">
                    <Icon name="grid" :size="16" /> Volver a mesas
                </button>
            </section>

            <!-- MODAL de descuento (§6.3): se manda tipo y valor; el servidor calcula y exige PIN de un superior. -->
            <transition name="modal">
                <div v-if="mostrarDescuento" class="modal-fondo" @click.self="mostrarDescuento = false">
                    <div class="modal" role="dialog" aria-modal="true" aria-label="Aplicar descuento">
                        <header class="modal__cab">
                            <h2>Descuento</h2>
                            <button type="button" class="modal__x" aria-label="Cerrar" @click="mostrarDescuento = false">
                                <Icon name="x" :size="18" />
                            </button>
                        </header>

                        <p class="nota">
                            El monto lo calcula el servidor: se manda el tipo y el valor, nunca el resultado. Pide el PIN
                            de un superior — el permiso lo tiene la terminal, el PIN lo tiene la persona.
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
                                Artículo (vacío = toda la cuenta)
                                <select v-model="discountForm.item_ulid">
                                    <option value="">Toda la cuenta</option>
                                    <option v-for="i in account.items" :key="i.ulid" :value="i.ulid">{{ i.article_name }}</option>
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

                            <div class="modal__acciones">
                                <button type="button" class="secundario" @click="mostrarDescuento = false">Cancelar</button>
                                <button type="submit" class="principal" :disabled="discount.processing.value">Aplicar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </transition>

            <!-- MODAL de confirmación del cobro: pequeño, con lo esencial antes de escribir el pago (inmutable). -->
            <transition name="modal">
                <div v-if="confirmarCobro" class="modal-fondo" @click.self="confirmarCobro = false">
                    <div class="modal modal--chico" role="dialog" aria-modal="true" aria-label="Confirmar cobro">
                        <h2>Confirmar cobro</h2>

                        <p class="confirmar__linea">
                            <span>{{ selectedMethod?.name }}</span>
                            <strong>{{ money(aCubrir) }}</strong>
                        </p>
                        <p v-if="Number(payForm.tip_amount) > 0" class="nota">Incluye {{ money(payForm.tip_amount) }} de propina.</p>
                        <p v-if="cambioPreview !== null && Number(cambioPreview) > 0" class="nota">Cambio a entregar: {{ money(cambioPreview) }}.</p>

                        <p v-if="pay.generalError.value" class="error">{{ pay.generalError.value }}</p>

                        <div class="modal__acciones">
                            <button type="button" class="secundario" :disabled="pay.processing.value" @click="confirmarCobro = false">Cancelar</button>
                            <button type="button" class="principal" :disabled="pay.processing.value" @click="pay.submit()">Confirmar cobro</button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- MODAL de cancelación de un artículo YA COMANDADO: motivo + destino. El PIN se pide después, con el 409. -->
            <transition name="modal">
                <div v-if="itemACancelar" class="modal-fondo" @click.self="itemACancelar = null">
                    <div class="modal modal--chico" role="dialog" aria-modal="true" aria-label="Cancelar artículo comandado">
                        <header class="modal__cab">
                            <h2>Cancelar artículo</h2>
                            <button type="button" class="modal__x" aria-label="Cerrar" @click="itemACancelar = null"><Icon name="x" :size="18" /></button>
                        </header>

                        <p class="nota">
                            <strong>{{ itemACancelar.article_name }}</strong> ya salió a preparar. Cancelarlo pide el PIN
                            de un superior y queda en la bitácora a su nombre.
                        </p>

                        <form @submit.prevent="trySubmitCancel()">
                            <label>
                                Motivo
                                <input v-model="cancelForm.reason" type="text" minlength="3" maxlength="300" required />
                            </label>

                            <div class="campo">
                                <span>¿Qué pasa con el producto?</span>
                                <div class="segmento">
                                    <button
                                        type="button"
                                        class="segmento__b"
                                        :class="{ 'segmento__b--activo': cancelForm.destination === 'waste' }"
                                        @click="cancelForm.destination = 'waste'"
                                    >
                                        <Icon name="trash" :size="15" /> Merma
                                    </button>
                                    <button
                                        type="button"
                                        class="segmento__b"
                                        :class="{ 'segmento__b--activo': cancelForm.destination === 'restock' }"
                                        @click="cancelForm.destination = 'restock'"
                                    >
                                        <Icon name="undo" :size="15" /> Reingreso
                                    </button>
                                </div>
                                <p class="nota">Merma: se pierde y se descuenta del inventario. Reingreso: vuelve a existencias.</p>
                            </div>

                            <p v-if="cancelError" class="error">{{ cancelError }}</p>

                            <div class="modal__acciones">
                                <button type="button" class="secundario" :disabled="cancelProcesando" @click="itemACancelar = null">Cerrar</button>
                                <button type="submit" class="principal" :disabled="cancelProcesando || ! cancelForm.destination">
                                    Cancelar artículo
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </transition>

            <!-- La firma que el servidor pide con un 409 al cancelar un comandado (ADR-008): PIN de un superior. -->
            <PinAuthorizationDialog
                v-if="pendingAuthorization"
                :required-permission="pendingAuthorization.permission"
                :reason="pendingAuthorization.reason"
                @granted="onGrantedCancel"
                @cancelled="pendingAuthorization = null"
            />
        </template>
    </div>
</template>

<style scoped>
.cuenta { display: grid; gap: 1.25rem; }

.cuenta__cabecera {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}
.cuenta__cabecera h1 { margin: 0; font-size: 1.4rem; font-weight: 650; letter-spacing: -0.015em; }
.folio { color: var(--color-suave); margin: 0.2rem 0 0; }

.enlace-volver {
    flex: none;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 600;
    padding: 0.5rem 0.95rem;
    border: 1px solid color-mix(in srgb, var(--color-acento) 35%, transparent);
    border-radius: 0.55rem;
    background: transparent;
    color: var(--color-acento);
    text-decoration: none;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease;
}
.enlace-volver:hover { background: color-mix(in srgb, var(--color-acento) 10%, transparent); }

/* Cambiar mesa: menú flotante con las cuentas vivas y su estado. */
.switcher { position: relative; flex: none; }
.switcher__backdrop { position: fixed; inset: 0; z-index: 20; }
.switcher__menu {
    position: absolute;
    right: 0;
    top: calc(100% + 0.4rem);
    z-index: 30;
    min-width: 15rem;
    max-height: 70vh;
    overflow-y: auto;
    padding: 0.5rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.65rem;
    box-shadow: 0 12px 30px -12px rgb(0 0 0 / 35%);
}
.switcher__title { margin: 0.15rem 0.5rem 0.4rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-suave); }
.switcher__item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    width: 100%;
    padding: 0.5rem 0.55rem;
    border: none;
    border-radius: 0.45rem;
    background: transparent;
    font: inherit;
    font-size: 0.9rem;
    color: var(--color-contenido);
    text-align: left;
    cursor: pointer;
}
.switcher__item:hover { background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
.switcher__item--actual { background: color-mix(in srgb, var(--color-acento) 16%, transparent); font-weight: 600; }
.switcher__nombre { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.switcher__vacio { padding: 0.3rem 0.55rem; }
.switcher__todas {
    display: block;
    margin-top: 0.35rem;
    padding: 0.5rem 0.55rem;
    border-top: 1px solid var(--color-borde);
    font-size: 0.85rem;
    color: var(--color-acento);
    text-decoration: none;
}
.switcher__todas:hover { text-decoration: underline; }

/* Píldora de estado del ciclo de vida. */
.estado-pill {
    display: inline-block;
    padding: 0.05rem 0.5rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
    vertical-align: middle;
    background: color-mix(in srgb, var(--color-contenido) 10%, transparent);
    color: var(--color-contenido);
}
.estado-pill--sm { font-size: 0.68rem; padding: 0.03rem 0.45rem; }
.estado-pill--open { background: color-mix(in srgb, #16a34a 20%, transparent); color: #15803d; }
.estado-pill--bill_requested { background: color-mix(in srgb, var(--color-acento) 22%, transparent); color: var(--color-acento); }
.estado-pill--closed { background: color-mix(in srgb, #d97706 22%, transparent); color: #b45309; }
.estado-pill--paid { background: color-mix(in srgb, #16a34a 22%, transparent); color: #15803d; }

/* Dos columnas cuando se puede capturar; una sola cuando la cuenta ya está cerrada. */
.marco { display: grid; gap: 1.25rem; align-items: start; }
.marco--doble { grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); }
@media (max-width: 64rem) {
    .marco--doble { grid-template-columns: 1fr; }
}

/* ---- Catálogo (izquierda) ---- */
.catalogo {
    display: grid;
    gap: 0.75rem;
    align-content: start;
    position: sticky;
    top: 1rem;
}

.buscador {
    font: inherit;
    font-size: 0.95rem;
    padding: 0.65rem 0.85rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
}

.grid-label { margin: 0.5rem 0 0.15rem; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-suave); }
.cats { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.cat {
    font: inherit;
    font-size: 0.82rem;
    padding: 0.3rem 0.8rem;
    border: 1px solid var(--color-borde);
    border-radius: 999px;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.cat:hover:not(.cat--activa) { border-color: color-mix(in srgb, var(--color-acento) 45%, transparent); }
.cat--activa { background: var(--color-acento); color: var(--color-acento-texto); border-color: var(--color-acento); }

/* Subcategorías (nivel 2): chips más discretos que las pestañas de categoría. */
.subcats { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.sub {
    font: inherit;
    font-size: 0.78rem;
    padding: 0.22rem 0.7rem;
    border: 1px solid var(--color-borde);
    border-radius: 999px;
    background: transparent;
    color: var(--color-suave);
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
}
.sub:hover:not(.sub--activa) { border-color: color-mix(in srgb, var(--color-acento) 40%, transparent); }
.sub--activa {
    background: color-mix(in srgb, var(--color-acento) 12%, transparent);
    color: var(--color-acento);
    border-color: color-mix(in srgb, var(--color-acento) 45%, transparent);
    font-weight: 600;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
    gap: 0.7rem;
}

/* Tarjeta de producto: foto arriba, nombre + precio + «+» abajo. Toda la tarjeta agrega una unidad. */
.prod {
    position: relative;
    display: flex;
    flex-direction: column;
    padding: 0;
    overflow: hidden;
    text-align: left;
    font: inherit;
    color: var(--color-contenido);
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.7rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04);
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.prod:hover {
    border-color: var(--color-acento);
    box-shadow: 0 6px 14px -6px color-mix(in srgb, var(--color-acento) 45%, transparent);
    transform: translateY(-1px);
}
.prod:active { transform: translateY(0); }

.prod__foto {
    display: block;
    aspect-ratio: 4 / 3;
    background: color-mix(in srgb, var(--color-contenido) 6%, var(--color-superficie));
}
.prod__foto img { display: block; width: 100%; height: 100%; object-fit: cover; }
.prod__foto-vacia {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    font-size: 1.7rem;
    opacity: 0.5;
}

.prod__info {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.5rem 2.2rem 0.55rem 0.6rem; /* deja hueco a la derecha para el «+» */
}
.prod__nombre { font-weight: 600; font-size: 0.88rem; line-height: 1.2; }
.prod__precio { font-weight: 700; font-size: 0.85rem; color: var(--color-acento); font-variant-numeric: tabular-nums; }

/* El «+»: afordancia táctil. La tarjeta entera agrega; el círculo lo hace obvio. */
.prod__mas {
    position: absolute;
    right: 0.5rem;
    bottom: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.6rem;
    height: 1.6rem;
    border-radius: 999px;
    background: var(--color-acento);
    color: var(--color-acento-texto);
    font-size: 1.15rem;
    line-height: 1;
}

/* ---- Ticket (derecha) ---- */
.ticket { display: grid; gap: 1rem; align-content: start; }

.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
    padding: 1.1rem 1.2rem;
    display: grid;
    gap: 0.6rem;
}
.tarjeta h2 { margin: 0; font-size: 1.05rem; font-weight: 650; }

.pendientes { border-color: color-mix(in srgb, var(--color-acento) 40%, var(--color-borde)); }

.pend { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.55rem; }
.pend li { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 0.75rem; padding: 0.3rem 0; }
.pend__datos { display: grid; gap: 0.15rem; min-width: 0; }
.pend__nombre { font-weight: 600; font-size: 0.95rem; min-width: 0; }
.pend__meta { display: inline-flex; align-items: center; gap: 0.4rem; color: var(--color-suave); font-size: 0.8rem; font-variant-numeric: tabular-nums; }
.pend__area {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.05rem 0.4rem;
    border-radius: 0.4rem;
    background: color-mix(in srgb, var(--color-acento) 14%, transparent);
    color: var(--color-acento);
}

.stepper { display: inline-flex; align-items: center; gap: 0.5rem; }
.stepper__b {
    width: 1.9rem;
    height: 1.9rem;
    display: grid;
    place-items: center;
    font: inherit;
    font-size: 1.1rem;
    line-height: 1;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.stepper__b:hover { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 8%, transparent); }
.stepper__n { min-width: 1.5rem; text-align: center; font-weight: 600; font-variant-numeric: tabular-nums; }

/* Los +/− grandes de «Pendiente por enviar»: táctiles para hora pico (≈48 px). */
.stepper--grande { gap: 0.35rem; }
.stepper--grande .stepper__b { width: 3rem; height: 3rem; font-size: 1.5rem; border-radius: 0.6rem; }
.stepper--grande .stepper__n { min-width: 2.25rem; font-size: 1.25rem; }

.quitar {
    width: 1.8rem;
    height: 1.8rem;
    display: grid;
    place-items: center;
    font: inherit;
    font-size: 1.1rem;
    border: 0;
    border-radius: 0.5rem;
    background: transparent;
    color: var(--color-suave);
    cursor: pointer;
    transition: color 0.15s ease, background-color 0.15s ease;
}
.quitar:hover { color: var(--color-peligro); background: var(--color-peligro-tenue); }

.nota { color: var(--color-suave); font-size: 0.85rem; margin: 0; }
.error { color: var(--color-peligro); margin: 0; }

/* Botón principal de acción: relleno de acento, táctil, afordante. */
.principal {
    font: inherit;
    font-size: 0.95rem;
    font-weight: 600;
    padding: 0.7rem 1.25rem;
    border: 1px solid transparent;
    border-radius: 0.55rem;
    background: var(--color-acento);
    color: var(--color-acento-texto);
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.06);
    cursor: pointer;
    transition: filter 0.15s ease, transform 0.15s ease;
}
.principal:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); }
.principal:disabled { opacity: 0.55; cursor: not-allowed; }

/* «Enviar comanda»: la acción estrella del panel de pendientes, a todo el ancho. */
.enviar { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 0.5rem; padding: 0.95rem 1.25rem; font-size: 1.05rem; }

/* Acción secundaria (p. ej. Reabrir): contorno, no compite con la principal. */
.secundario {
    font: inherit;
    font-size: 0.9rem;
    font-weight: 600;
    padding: 0.6rem 1.1rem;
    border: 1px solid color-mix(in srgb, var(--color-acento) 40%, var(--color-borde));
    border-radius: 0.55rem;
    background: transparent;
    color: var(--color-acento);
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.secundario:hover:not(:disabled) { background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
.secundario:disabled { opacity: 0.55; cursor: not-allowed; }

.acciones-fila { display: flex; flex-wrap: wrap; gap: 0.5rem; }

form { display: grid; gap: 0.6rem; }
label { display: grid; gap: 0.3rem; font-size: 0.85rem; }
input[type="text"],
select {
    font: inherit;
    font-size: 0.9rem;
    padding: 0.55rem 0.65rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
}

table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 0.45rem 0.5rem; border-bottom: 1px solid var(--color-borde); }
th { font-size: 0.76rem; font-weight: 600; color: var(--color-suave); text-transform: uppercase; letter-spacing: 0.03em; }
.cancelado { color: var(--color-suave); text-decoration: line-through; }
.etiqueta { background: var(--color-aviso-tenue); color: var(--color-aviso); border-radius: 999px; font-size: 0.72rem; padding: 0.1rem 0.5rem; margin-left: 0.4rem; }

.totales { grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr)); }
.totales div { display: grid; gap: 0.1rem; }
.totales span { font-size: 0.8rem; color: var(--color-suave); }
.totales strong { font-variant-numeric: tabular-nums; }

.promo { background: var(--color-exito-tenue); border-color: color-mix(in srgb, var(--color-exito) 30%, transparent); }
.promo__lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.3rem; }
.promo__lista li { display: flex; justify-content: space-between; gap: 1rem; }
.promo__total { margin: 0; }

.cambio__linea { display: flex; gap: 0.6rem; align-items: baseline; margin: 0; }
.cambio__linea strong { font-size: 1.6rem; }
.cambio__linea span { color: var(--color-suave); font-size: 0.9rem; }
.cambio-linea { font-size: 1.1rem; margin: 0; }

/* Barra inferior fija: se pega al borde inferior mientras el ticket es más alto que la pantalla. Total a la izquierda,
   la acción que toca a la derecha. */
.barra {
    position: sticky;
    bottom: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.7rem 1rem;
    background: color-mix(in srgb, var(--color-superficie) 92%, transparent);
    backdrop-filter: blur(8px);
    border: 1px solid var(--color-borde);
    border-radius: 0.85rem;
    box-shadow: 0 -2px 14px rgb(0 0 0 / 0.07);
}
.barra__total { display: flex; flex-direction: column; line-height: 1.15; }
.barra__total span { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-suave); }
.barra__total strong { font-size: 1.35rem; font-variant-numeric: tabular-nums; }
.barra__acciones { display: flex; gap: 0.5rem; }
.barra__b {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 600;
    padding: 0.65rem 1.05rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.barra__b:hover:not(:disabled) { border-color: var(--color-acento); }
.barra__b:disabled { opacity: 0.55; cursor: not-allowed; }
.barra__b--principal { background: var(--color-acento); color: var(--color-acento-texto); border-color: transparent; }

/* ---------------------------------------------------------------------------
   Pantalla de cobro: una columna centrada y enfocada (no las dos del marcado).
   --------------------------------------------------------------------------- */
.cobro-screen { max-width: 34rem; margin: 0 auto; width: 100%; display: grid; gap: 1.1rem; }
.cobro-screen__cab { display: flex; align-items: center; gap: 1rem; }
.cobro-screen__cab h2 { margin: 0; font-size: 1.25rem; font-weight: 650; }

.cobro-resumen {
    display: grid;
    gap: 0.85rem;
    padding: 1rem 1.15rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.85rem;
    background: var(--color-superficie);
}
.cobro-resumen__falta { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; }
.cobro-resumen__falta span { color: var(--color-suave); font-size: 0.85rem; }
.cobro-resumen__falta strong { font-size: 2rem; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
.cobro-resumen__aparte { display: flex; gap: 1.5rem; }
.cobro-resumen__aparte div { display: flex; gap: 0.4rem; align-items: baseline; }
.cobro-resumen__aparte span { color: var(--color-suave); font-size: 0.78rem; }
.cobro-resumen__aparte strong { font-variant-numeric: tabular-nums; }
.cobro-descuento {
    justify-self: start;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--color-acento);
    background: none;
    border: 0;
    padding: 0;
    cursor: pointer;
}
.cobro-descuento:hover { text-decoration: underline; }

.cobro-form { display: grid; gap: 1rem; }
.cobro-metodos { border: 0; padding: 0; margin: 0; display: grid; gap: 0.5rem; }
.cobro-metodos legend { padding: 0; font-size: 0.85rem; font-weight: 600; color: var(--color-suave); }
.metodos-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: 0.5rem; }
.metodo {
    font: inherit;
    font-size: 0.92rem;
    font-weight: 600;
    padding: 0.8rem 0.6rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.metodo:hover { border-color: var(--color-acento); }
.metodo--activo { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 12%, transparent); color: var(--color-acento); }

.campo { display: grid; gap: 0.35rem; }
.campo > span { font-size: 0.85rem; color: var(--color-suave); }
.campo--monto > span { font-weight: 600; color: var(--color-contenido); }
.campo--monto input { font-size: 1.4rem; font-weight: 650; padding: 0.7rem 0.8rem; font-variant-numeric: tabular-nums; }

.propina { display: grid; gap: 0.5rem; }
.propina__rapidas { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.chip {
    font: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 0.4rem 0.7rem;
    border: 1px solid var(--color-borde);
    border-radius: 999px;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.chip:hover { border-color: var(--color-acento); }

.cobro-cambio {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.7rem 0.9rem;
    border-radius: 0.6rem;
    background: var(--color-exito-tenue);
    border: 1px solid color-mix(in srgb, var(--color-exito) 30%, transparent);
}
.cobro-cambio span { color: var(--color-suave); font-size: 0.85rem; }
.cobro-cambio strong { font-size: 1.5rem; font-weight: 700; font-variant-numeric: tabular-nums; color: color-mix(in srgb, var(--color-exito) 85%, var(--color-contenido)); }
.cobro-cambio--corto { background: var(--color-peligro-tenue); border-color: color-mix(in srgb, var(--color-peligro) 30%, transparent); }
.cobro-cambio--corto strong { color: var(--color-peligro); }

.cobro-cta { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 1rem; font-size: 1.1rem; }

/* Éxito: cuenta pagada. */
.cerrada { max-width: 26rem; margin: 2rem auto; text-align: center; display: grid; gap: 0.55rem; justify-items: center; }
.cerrada__marca { width: 4.5rem; height: 4.5rem; border-radius: 50%; display: grid; place-items: center; color: var(--color-exito); background: var(--color-exito-tenue); }
.cerrada h2 { margin: 0.5rem 0 0; font-size: 1.6rem; font-weight: 700; }
.cerrada__folio { color: var(--color-suave); margin: 0; }
.cerrada__total { margin-top: 1rem; display: grid; gap: 0.15rem; }
.cerrada__total span { color: var(--color-suave); font-size: 0.85rem; }
.cerrada__total strong { font-size: 2rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.cerrada__cambio {
    display: grid;
    gap: 0.15rem;
    margin-top: 0.4rem;
    padding: 0.75rem 1.5rem;
    border-radius: 0.7rem;
    background: var(--color-exito-tenue);
    border: 1px solid color-mix(in srgb, var(--color-exito) 30%, transparent);
}
.cerrada__cambio span { color: var(--color-suave); font-size: 0.8rem; }
.cerrada__cambio strong { font-size: 1.8rem; font-weight: 700; font-variant-numeric: tabular-nums; color: color-mix(in srgb, var(--color-exito) 85%, var(--color-contenido)); }
.cerrada__regreso { color: var(--color-suave); font-size: 0.9rem; margin: 1rem 0 0.3rem; }

/* Modales (descuento y confirmación de cobro). */
.modal-fondo { position: fixed; inset: 0; z-index: 30; display: grid; place-items: center; padding: 1rem; background: rgb(0 0 0 / 0.45); }
.modal {
    width: 100%;
    max-width: 30rem;
    max-height: 90vh;
    overflow-y: auto;
    display: grid;
    gap: 0.85rem;
    padding: 1.25rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.9rem;
    box-shadow: 0 20px 50px rgb(0 0 0 / 0.3);
}
.modal--chico { max-width: 22rem; }
.modal h2 { margin: 0; font-size: 1.15rem; font-weight: 650; }
.modal__cab { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.modal__x { display: grid; place-items: center; width: 2rem; height: 2rem; border: 0; border-radius: 0.5rem; background: transparent; color: var(--color-suave); cursor: pointer; }
.modal__x:hover { background: color-mix(in srgb, var(--color-contenido) 8%, transparent); color: var(--color-contenido); }
.modal__acciones { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.3rem; }
.confirmar__linea {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
    margin: 0;
    padding: 0.6rem 0;
    border-top: 1px solid var(--color-borde);
    border-bottom: 1px solid var(--color-borde);
}
.confirmar__linea span { color: var(--color-suave); }
.confirmar__linea strong { font-size: 1.6rem; font-weight: 700; font-variant-numeric: tabular-nums; }

.modal-enter-active, .modal-leave-active { transition: opacity 0.2s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-enter-active .modal, .modal-leave-active .modal { transition: transform 0.2s ease; }
.modal-enter-from .modal, .modal-leave-to .modal { transform: translateY(12px); }

/* Menú ⋮ por línea en «Enviados» (cancelar un comandado). */
.col-acc { width: 2.5rem; text-align: right; }
.menu-linea { position: relative; display: inline-flex; }
.menu-linea__b {
    display: grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border: 0;
    border-radius: 0.5rem;
    background: transparent;
    color: var(--color-suave);
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.menu-linea__b:hover { background: color-mix(in srgb, var(--color-contenido) 8%, transparent); color: var(--color-contenido); }
.menu-linea__fondo { position: fixed; inset: 0; z-index: 6; }
.menu-linea__pop {
    position: absolute;
    top: calc(100% + 0.25rem);
    right: 0;
    z-index: 7;
    min-width: 12rem;
    padding: 0.3rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    box-shadow: 0 10px 30px rgb(0 0 0 / 0.15);
}
.menu-linea__accion {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    font: inherit;
    font-size: 0.88rem;
    font-weight: 600;
    text-align: left;
    padding: 0.55rem 0.6rem;
    border: 0;
    border-radius: 0.45rem;
    background: transparent;
    color: var(--color-peligro);
    cursor: pointer;
}
.menu-linea__accion:hover { background: var(--color-peligro-tenue); }

/* Segmentado del destino de la cancelación (merma / reingreso). */
.segmento { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.segmento__b {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    font: inherit;
    font-size: 0.9rem;
    font-weight: 600;
    padding: 0.7rem 0.6rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.segmento__b:hover { border-color: var(--color-acento); }
.segmento__b--activo { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 12%, transparent); color: var(--color-acento); }

/* Los botones de acción con icono van alineados (no afecta a los de sólo texto). */
.principal, .secundario { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; }

/* Panel de precuenta: el ticket como lo ve el cliente. */
.precuenta { max-width: 30rem; margin: 0 auto; width: 100%; display: grid; gap: 1.1rem; }
.ticket-preview {
    display: grid;
    gap: 0.9rem;
    padding: 1.25rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.85rem;
}
.ticket-preview__cab { display: grid; gap: 0.15rem; text-align: center; padding-bottom: 0.8rem; border-bottom: 1px dashed var(--color-borde); }
.ticket-preview__cab strong { font-size: 1.05rem; }
.ticket-preview__cab span { color: var(--color-suave); font-size: 0.82rem; }
.ticket-preview__items { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.ticket-preview__items li { display: grid; grid-template-columns: auto 1fr auto; gap: 0.6rem; align-items: baseline; font-size: 0.9rem; }
.tpi__cant { color: var(--color-suave); font-variant-numeric: tabular-nums; }
.tpi__nombre { min-width: 0; }
.tpi__importe { font-variant-numeric: tabular-nums; text-align: right; }
.ticket-preview__totales { display: grid; gap: 0.3rem; padding-top: 0.8rem; border-top: 1px dashed var(--color-borde); }
.ticket-preview__totales div { display: flex; justify-content: space-between; gap: 1rem; font-size: 0.9rem; }
.ticket-preview__totales span { color: var(--color-suave); }
.ticket-preview__totales strong { font-variant-numeric: tabular-nums; }
.tpt__total { padding-top: 0.5rem; margin-top: 0.2rem; border-top: 1px solid var(--color-borde); }
.tpt__total span { color: var(--color-contenido); font-weight: 650; }
.tpt__total strong { font-size: 1.25rem; font-weight: 700; }
.ticket-preview__promo { margin: 0; color: var(--color-suave); font-size: 0.82rem; text-align: center; }
.precuenta__acciones { display: flex; gap: 0.6rem; justify-content: flex-end; align-items: center; flex-wrap: wrap; }
.precuenta__marcada { display: inline-flex; align-items: center; gap: 0.35rem; margin: 0 auto 0 0; color: var(--color-exito); font-size: 0.85rem; font-weight: 600; }

@media (prefers-reduced-motion: reduce) {
    .prod:hover { transform: none; }
    .principal:hover:not(:disabled) { transform: none; }
    .modal-enter-active .modal, .modal-leave-active .modal { transition: none; }
    .modal-enter-from .modal, .modal-leave-to .modal { transform: none; }
}
</style>
