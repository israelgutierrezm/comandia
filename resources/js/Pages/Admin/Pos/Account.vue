<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import Icon from '../../../components/Icon.vue';

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
const payForm = ref({ payment_method_ulid: '', amount: '', tendered_amount: '', tip_amount: '' });

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

// Un aviso efímero: la comanda enviada, o el error de una acción de un toque que no tiene formulario donde pintarlo.
const toast = ref(null);
let toastTimer = null;

function pushToast(texto, tipo = 'ok') {
    toast.value = { texto, tipo };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.value = null; }, 3000);
}

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
    await refreshPromoPreview();
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
    // Al cobrar, las promociones se materializan y ya viven en el total: el preview vuelve vacío.
    await refreshPromoPreview();
});

const requestBill = useApiForm(async () => {
    const respuesta = await api.post(`/pos-accounts/${props.accountUlid}/bill-request`, { version: version() });
    account.value = respuesta.data;
});

// Reabrir vuelve al modo marcar: la cuenta solicitada/cerrada acepta items de nuevo.
const reopen = useApiForm(async () => {
    const respuesta = await api.post(`/pos-accounts/${props.accountUlid}/reopen`, { version: version() });
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

// El cobro y el descuento son el SEGUNDO paso: sólo tras «pedir la cuenta» (bill_requested) o cerrarla. En Abierta el
// paso que toca es marcar y pedir la cuenta, no cobrar.
const canCharge = computed(() => account.value && ['bill_requested', 'closed'].includes(account.value.status));

/** Modo marcar: el catálogo (grid) sólo mientras la cuenta está Abierta. Solicitada/Cerrada pasan al modo cobro. */
const isMarcar = computed(() => account.value?.status === 'open');

function money(value) {
    return value === null || value === undefined ? '—' : `$${value}`;
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

// Precuenta desde la barra: pide la cuenta y avisa si el servidor la rechaza (la barra no tiene dónde pintar el error).
async function pedirCuenta() {
    await requestBill.submit();

    if (requestBill.generalError.value) {
        pushToast(requestBill.generalError.value, 'error');
    }
}
</script>

<template>
    <Head :title="account ? account.display_name : 'Cuenta'" />

    <div class="cuenta">
        <template v-if="loading"></template>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <template v-else-if="account">
            <header class="cuenta__cabecera">
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

            <div class="marco" :class="{ 'marco--doble': isMarcar }">
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
                                    <span class="stepper__n">{{ i.quantity }}</span>
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

                    <!-- Enviados: lo que ya salió a preparar (y lo cancelado, tachado). El menú ⋮ para cancelar con PIN
                         llega en el siguiente incremento. -->
                    <div class="tarjeta">
                        <h2>Enviados</h2>

                        <p v-if="enviados.length === 0" class="nota">Todavía no se ha enviado nada a preparar.</p>

                        <table v-else>
                            <thead>
                                <tr><th>Cant.</th><th>Artículo</th><th>Importe</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in enviados" :key="i.ulid" :class="{ cancelado: i.status === 'cancelled' }">
                                    <td>{{ i.quantity }}</td>
                                    <td>
                                        {{ i.article_name }}
                                        <span v-if="i.is_courtesy" class="etiqueta">cortesía</span>
                                    </td>
                                    <td>{{ money(i.line_total) }}</td>
                                    <td>{{ i.status_label }}</td>
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

                    <div v-if="canCharge" class="tarjeta">
                        <h2>Descuento</h2>

                        <p class="nota">
                            El monto lo calcula el servidor: se manda el tipo y el valor, nunca el resultado. Y siempre
                            pide el PIN de un superior — el permiso lo tiene la terminal, el PIN lo tiene la persona.
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

                            <button type="submit" class="principal" :disabled="discount.processing.value">Aplicar</button>
                        </form>
                    </div>

                    <div v-if="canCharge" class="tarjeta cobro">
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

                            <button type="submit" class="principal" :disabled="pay.processing.value">Cobrar</button>
                        </form>

                        <p v-if="account.totals.change_total !== '0.00'" class="cambio-linea">
                            Cambio a entregar: <strong>{{ money(account.totals.change_total) }}</strong>
                        </p>
                    </div>

                    <div v-if="account.status === 'bill_requested' || account.status === 'closed'" class="tarjeta">
                        <p class="nota">Para agregar más productos, reabre la cuenta.</p>
                        <button type="button" class="secundario" :disabled="reopen.processing.value" @click="reopen.submit()">
                            Reabrir para marcar
                        </button>
                        <p v-if="reopen.generalError.value" class="error">{{ reopen.generalError.value }}</p>
                    </div>
                </section>
            </div>

            <!-- Barra inferior fija: total en vivo y la acción que toca según el estado. El rediseño del cobro como
                 pantalla dedicada llega en el siguiente incremento; por ahora «Cobrar» salta a su tarjeta. -->
            <footer class="barra">
                <div class="barra__total">
                    <span>Total</span>
                    <strong>{{ money(account.totals.total) }}</strong>
                </div>

                <div class="barra__acciones">
                    <button v-if="isMarcar" type="button" class="barra__b" @click="scrollTo('.catalogo')">
                        <Icon name="plus" :size="16" /> Más
                    </button>
                    <button
                        v-if="account.status === 'open'"
                        type="button"
                        class="barra__b"
                        :disabled="requestBill.processing.value"
                        @click="pedirCuenta"
                    >
                        <Icon name="printer" :size="16" /> Precuenta
                    </button>
                    <button v-if="canCharge" type="button" class="barra__b barra__b--principal" @click="scrollTo('.cobro')">
                        <Icon name="receive" :size="16" /> Cobrar
                    </button>
                </div>
            </footer>

            <!-- Aviso efímero: comanda enviada, o el error de una acción sin formulario donde pintarlo. -->
            <transition name="toast">
                <div v-if="toast" class="toast" :class="`toast--${toast.tipo}`" role="status">{{ toast.texto }}</div>
            </transition>
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

/* Toast efímero, centrado sobre la barra. */
.toast {
    position: fixed;
    left: 50%;
    bottom: 5.5rem;
    transform: translateX(-50%);
    z-index: 20;
    max-width: min(92vw, 32rem);
    padding: 0.75rem 1.1rem;
    border-radius: 0.7rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: #fff;
    text-align: center;
    box-shadow: 0 8px 24px rgb(0 0 0 / 0.18);
}
.toast--ok { background: color-mix(in srgb, var(--color-exito) 90%, black); }
.toast--error { background: color-mix(in srgb, var(--color-peligro) 90%, black); }
.toast-enter-active, .toast-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }

@media (prefers-reduced-motion: reduce) {
    .prod:hover { transform: none; }
    .principal:hover:not(:disabled) { transform: none; }
    .toast-enter-active, .toast-leave-active { transition: opacity 0.25s ease; }
    .toast-enter-from, .toast-leave-to { transform: translateX(-50%); }
}
</style>
