<script setup>
import { computed, onMounted, ref } from 'vue';

/**
 * La tienda pública (Iteración 8, Tanda B; rediseño de UI). Pinta el catálogo de la sucursal elegida y maneja el carrito
 * (en sesión, del lado del servidor). No decide precio ni stock: los resuelve el backend según la sucursal y la política
 * del artículo (ADR-007). Sin lógica de negocio aquí — el frontend previsualiza, el backend decide.
 *
 * El rediseño es SÓLO de presentación: header pegajoso con buscador y carrito lateral (drawer), chips de categorías,
 * rejilla de tarjetas y footer. Las llamadas a la API, el estado y el flujo (catálogo → carrito → checkout → pago) son
 * los mismos de antes.
 */
const props = defineProps({
    store: { type: Object, required: true },
});

const base = `/t/${props.store.slug}`;
const primary = computed(() => props.store.theme?.primary || '#1c1917');

const branches = ref(props.store.branches ?? []);
const selectedBranch = ref(branches.value[0]?.ulid ?? '');
const sections = ref([]);
const cart = ref({ items: [], total: '0.00', count: 0 });
const error = ref(null);
const loading = ref(false);

// Presentación (no toca la lógica): búsqueda del menú, carrito lateral y panel de cuenta.
const search = ref('');
const cartOpen = ref(false);
const authOpen = ref(false);

// --- Cuenta de cliente ---
const customer = ref(null);
const authMode = ref('login'); // 'login' | 'register'
const authForm = ref({ name: '', phone: '', email: '', password: '' });
const authError = ref(null);

// --- Checkout ---
const checkingOut = ref(false);
const zones = ref([]);
const checkoutForm = ref({ delivery_type: 'pickup', zone_ulid: '', address: '', notes: '', coupon_code: '' });
const placedOrder = ref(null);
const checkoutError = ref(null);
const placingOrder = ref(false);

const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** El menú filtrado por la búsqueda; las secciones que se quedan sin resultados no se pintan. */
const sectionsFiltradas = computed(() => {
    const q = search.value.trim().toLowerCase();

    if (!q) {
        return sections.value;
    }

    return sections.value
        .map((s) => ({ ...s, items: s.items.filter((i) =>
            i.name.toLowerCase().includes(q) || (i.description ?? '').toLowerCase().includes(q)) }))
        .filter((s) => s.items.length > 0);
});

/** Un ancla estable por sección, para que los chips de categoría salten a ella. */
function anchor(name) {
    return 'cat-' + name.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

/** El nombre de la sucursal elegida, para el hero. */
const branchName = computed(() => branches.value.find((b) => b.ulid === selectedBranch.value)?.name ?? '');

async function api(method, path, body) {
    const res = await fetch(base + path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const payload = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(payload.title ?? 'No se pudo completar la operación.');
    return payload.data;
}

async function loadCatalog() {
    loading.value = true;
    error.value = null;
    try {
        const data = await api('GET', `/catalog?branch=${selectedBranch.value}`);
        sections.value = data.catalog;
        selectedBranch.value = data.selected_branch;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function loadCart() {
    cart.value = await api('GET', '/cart');
}

async function loadMe() {
    const data = await api('GET', '/me');
    customer.value = data;
}

async function submitAuth() {
    authError.value = null;
    try {
        const path = authMode.value === 'register' ? '/register' : '/login';
        const body = authMode.value === 'register' ? authForm.value : { email: authForm.value.email, password: authForm.value.password };
        customer.value = await api('POST', path, body);
        authForm.value = { name: '', phone: '', email: '', password: '' };
        authOpen.value = false;
    } catch (e) {
        authError.value = e.message;
    }
}

async function logout() {
    await api('POST', '/logout');
    customer.value = null;
    authOpen.value = false;
}

async function loadZones() {
    const data = await api('GET', '/shipping-zones');
    zones.value = data;
}

async function placeOrder() {
    checkoutError.value = null;
    placingOrder.value = true;
    try {
        // El checkout devuelve el pedido y a dónde cobrar. `api()` sólo devuelve `data`, así que aquí se lee el sobre
        // completo para tomar también `payment_url`.
        const res = await fetch(base + '/checkout', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(checkoutForm.value),
        });
        const payload = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(payload.title ?? 'No se pudo completar la operación.');

        // El pedido nace `pending_payment`: se manda al cliente a la pasarela a pagar (checkout alojado, ADR-007).
        if (payload.payment_url) {
            window.location.href = payload.payment_url;
            return; // no se toca el carrito: se vacía al confirmarse el pago, no antes
        }

        // Sin pasarela (el back la exige, pero por si acaso): se muestra el pedido ya colocado.
        placedOrder.value = payload.data;
        checkingOut.value = false;
        await loadCart();
    } catch (e) {
        checkoutError.value = e.message;
    } finally {
        placingOrder.value = false;
    }
}

onMounted(async () => {
    await Promise.all([loadCatalog(), loadCart(), loadMe(), loadZones()]);
});

async function changeBranch() {
    await loadCatalog();
    await loadCart();
}

async function add(item) {
    error.value = null;
    try {
        cart.value = await api('POST', '/cart', {
            article_ulid: item.ulid,
            branch_ulid: selectedBranch.value,
            quantity: 1,
        });
        // Abrir el carrito da la confirmación de que se agregó (como el mini-carrito de las tiendas grandes).
        cartOpen.value = true;
    } catch (e) {
        error.value = e.message;
    }
}

async function setQty(line, quantity) {
    cart.value = await api('PATCH', '/cart', { article_ulid: line.article_ulid, quantity });
}

async function remove(line) {
    cart.value = await api('DELETE', `/cart/${line.article_ulid}`);
}
</script>

<template>
    <div class="store" :style="{ '--primary': primary }">
        <!-- Barra de promo/servicio (como el «compra en línea y recoge en tienda» de las tiendas grandes). -->
        <div class="promo">Pide en línea y recoge gratis en sucursal · Te avisamos cuando esté listo</div>

        <!-- Encabezado pegajoso: marca, buscador, sucursal, cuenta y carrito. -->
        <header class="head">
            <div class="head__brand">
                <span class="head__logo" aria-hidden="true">🍽️</span>
                <span class="head__name">{{ store.name }}</span>
            </div>

            <div class="head__search">
                <span class="head__search-i" aria-hidden="true">🔎</span>
                <input v-model="search" type="search" placeholder="Buscar en el menú…" aria-label="Buscar en el menú" />
            </div>

            <div class="head__actions">
                <label v-if="branches.length > 1" class="branch">
                    <select v-model="selectedBranch" @change="changeBranch" aria-label="Sucursal">
                        <option v-for="b in branches" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                    </select>
                </label>

                <div class="account">
                    <button type="button" class="icon-btn" @click="authOpen = !authOpen">
                        <span aria-hidden="true">👤</span>
                        <span class="icon-btn__txt">{{ customer ? customer.name.split(' ')[0] : 'Entrar' }}</span>
                    </button>

                    <div v-if="authOpen" class="account__pop">
                        <template v-if="customer">
                            <p class="account__hi">Hola, <strong>{{ customer.name }}</strong></p>
                            <button type="button" class="link" @click="logout">Cerrar sesión</button>
                        </template>
                        <form v-else class="auth" @submit.prevent="submitAuth">
                            <p class="auth__title">{{ authMode === 'register' ? 'Crear cuenta' : 'Iniciar sesión' }}</p>
                            <template v-if="authMode === 'register'">
                                <input v-model="authForm.name" type="text" placeholder="Nombre" required />
                                <input v-model="authForm.phone" type="tel" placeholder="Teléfono" required />
                            </template>
                            <input v-model="authForm.email" type="email" placeholder="Correo" required />
                            <input v-model="authForm.password" type="password" placeholder="Contraseña" required />
                            <button type="submit" class="btn btn--primary">{{ authMode === 'register' ? 'Crear cuenta' : 'Entrar' }}</button>
                            <button type="button" class="link" @click="authMode = authMode === 'register' ? 'login' : 'register'">
                                {{ authMode === 'register' ? 'Ya tengo cuenta' : 'Crear una cuenta' }}
                            </button>
                            <span v-if="authError" class="error small">{{ authError }}</span>
                        </form>
                    </div>
                </div>

                <button type="button" class="icon-btn cart-btn" @click="cartOpen = true" aria-label="Ver carrito">
                    <span aria-hidden="true">🛒</span>
                    <span v-if="cart.count" class="cart-btn__badge">{{ cart.count }}</span>
                </button>
            </div>
        </header>

        <!-- Chips de categorías: saltan a cada sección; pegajosos bajo el header. -->
        <nav v-if="sectionsFiltradas.length" class="cats">
            <a v-for="s in sectionsFiltradas" :key="s.name" :href="`#${anchor(s.name)}`" class="cats__chip">{{ s.name }}</a>
        </nav>

        <!-- Hero de la tienda: nombre, promesa y sucursal. Sin promociones inventadas. -->
        <section class="hero">
            <h1 class="hero__title">{{ store.name }}</h1>
            <p class="hero__sub">
                Nuestro menú, directo a tu mesa o para recoger<span v-if="branchName"> · {{ branchName }}</span>
            </p>
        </section>

        <p v-if="error" class="error wrap">{{ error }}</p>

        <!-- Catálogo: rejilla de tarjetas por categoría. -->
        <main class="catalog">
            <p v-if="!branches.length" class="muted wrap">Esta tienda aún no atiende ninguna sucursal.</p>
            <p v-else-if="loading" class="muted wrap">Cargando el menú…</p>
            <p v-else-if="!sectionsFiltradas.length && search" class="muted wrap">Nada coincide con «{{ search }}».</p>
            <p v-else-if="!sections.length" class="muted wrap">No hay productos disponibles en esta sucursal.</p>

            <section v-for="section in sectionsFiltradas" :id="anchor(section.name)" :key="section.name" class="cat">
                <h2 class="cat__title">{{ section.name }}</h2>
                <div class="grid">
                    <article v-for="item in section.items" :key="item.ulid" class="card" :class="{ 'card--off': item.out_of_stock }">
                        <div class="card__media">
                            <img v-if="item.image" :src="item.image" :alt="item.name" class="card__img" loading="lazy" />
                            <div v-else class="card__ph" aria-hidden="true">🍽️</div>
                            <span v-if="item.out_of_stock" class="card__badge">Agotado</span>
                        </div>
                        <div class="card__body">
                            <h3 class="card__name">{{ item.name }}</h3>
                            <p v-if="item.description" class="card__desc">{{ item.description }}</p>
                            <div class="card__foot">
                                <span v-if="item.price" class="card__price">${{ item.price }}</span>
                                <button v-if="!item.out_of_stock" type="button" class="btn btn--primary card__add" @click="add(item)">
                                    Agregar
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </main>

        <!-- Footer informativo (como el de las tiendas grandes, adaptado a una fonda). -->
        <footer class="foot">
            <div class="foot__col">
                <h4>Comprar en línea</h4>
                <p>Recoge gratis en sucursal</p>
                <p>Envío a domicilio por zonas</p>
                <p>Pago seguro en línea</p>
            </div>
            <div class="foot__col">
                <h4>Ayuda</h4>
                <p>Estatus de mi pedido</p>
                <p>Formas de pago</p>
                <p>Contáctanos</p>
            </div>
            <div class="foot__col">
                <h4>{{ store.name }}</h4>
                <p>Aviso de privacidad</p>
                <p>Términos y condiciones</p>
            </div>
        </footer>

        <!-- Carrito lateral (drawer). -->
        <transition name="drawer">
            <div v-if="cartOpen" class="drawer-back" @click.self="cartOpen = false">
                <aside class="drawer" role="dialog" aria-label="Tu pedido">
                    <header class="drawer__head">
                        <h2>Tu pedido</h2>
                        <button type="button" class="icon-btn" @click="cartOpen = false" aria-label="Cerrar">✕</button>
                    </header>

                    <div class="drawer__body">
                        <!-- Confirmación de un pedido recién hecho (cuando no hay pasarela que redirija). -->
                        <div v-if="placedOrder" class="placed">
                            <p class="placed__ok">✓ ¡Pedido recibido!</p>
                            <p><strong>{{ placedOrder.folio }}</strong> — total ${{ placedOrder.total }}</p>
                            <p class="muted small">Pendiente de pago.</p>
                        </div>

                        <p v-else-if="!cart.items.length" class="muted drawer__empty">Tu carrito está vacío.<br />Agrega algo del menú 🍽️</p>

                        <template v-else>
                            <ul class="lines">
                                <li v-for="line in cart.items" :key="line.article_ulid" class="line">
                                    <div class="line__info">
                                        <span class="line__name">{{ line.name }}</span>
                                        <span class="line__total">${{ line.line_total }}</span>
                                    </div>
                                    <div class="line__ctl">
                                        <div class="stepper">
                                            <button type="button" @click="setQty(line, Math.max(0, Number(line.quantity) - 1))" aria-label="Quitar uno">−</button>
                                            <span>{{ line.quantity }}</span>
                                            <button type="button" @click="setQty(line, Number(line.quantity) + 1)" aria-label="Agregar uno">+</button>
                                        </div>
                                        <button type="button" class="link line__del" @click="remove(line)">Quitar</button>
                                    </div>
                                    <p v-if="line.out_of_stock" class="error small">Agotado en esta sucursal</p>
                                </li>
                            </ul>

                            <div class="total">
                                <span>Total</span><strong>${{ cart.total }}</strong>
                            </div>

                            <!-- Checkout dentro del drawer. -->
                            <p v-if="!customer" class="muted small">
                                <button type="button" class="link" @click="cartOpen = false; authOpen = true">Inicia sesión</button>
                                para completar tu pedido.
                            </p>

                            <button v-else-if="!checkingOut" type="button" class="btn btn--primary btn--block" @click="checkingOut = true">
                                Realizar pedido
                            </button>

                            <form v-else class="checkout" @submit.prevent="placeOrder">
                                <p v-if="checkoutError" class="error small">{{ checkoutError }}</p>

                                <div class="seg">
                                    <label class="seg__opt" :class="{ 'seg__opt--on': checkoutForm.delivery_type === 'pickup' }">
                                        <input v-model="checkoutForm.delivery_type" type="radio" value="pickup" /> Recoger en sucursal
                                    </label>
                                    <label class="seg__opt" :class="{ 'seg__opt--on': checkoutForm.delivery_type === 'shipping' }">
                                        <input v-model="checkoutForm.delivery_type" type="radio" value="shipping" /> Envío a domicilio
                                    </label>
                                </div>

                                <template v-if="checkoutForm.delivery_type === 'shipping'">
                                    <select v-model="checkoutForm.zone_ulid" required>
                                        <option value="" disabled>Elige zona de envío…</option>
                                        <option v-for="z in zones" :key="z.ulid" :value="z.ulid">{{ z.name }} (+${{ z.cost }})</option>
                                    </select>
                                    <input v-model="checkoutForm.address" type="text" placeholder="Dirección de entrega" required />
                                </template>

                                <input v-model="checkoutForm.notes" type="text" placeholder="Notas (opcional)" />
                                <input v-model="checkoutForm.coupon_code" type="text" placeholder="¿Tienes un cupón?" />

                                <button type="submit" class="btn btn--primary btn--block" :disabled="placingOrder">
                                    {{ placingOrder ? 'Redirigiendo al pago…' : 'Ir a pagar' }}
                                </button>
                                <button type="button" class="link" @click="checkingOut = false" :disabled="placingOrder">Volver</button>
                            </form>
                        </template>
                    </div>
                </aside>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.store {
    --ink: #1f1b18;
    --muted: #78716c;
    --line: #ece7e1;
    --bg: #faf7f2;
    --card: #ffffff;
    min-height: 100vh;
    background: var(--bg);
    color: var(--ink);
    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}

.wrap { max-width: 72rem; margin-inline: auto; padding-inline: 1rem; }
.muted { color: var(--muted); }
.error { color: #b91c1c; }
.small { font-size: 0.8rem; }

/* Barra de promo */
.promo {
    background: var(--primary);
    color: #fff;
    text-align: center;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 0.4rem 1rem;
    letter-spacing: 0.01em;
}

/* Header pegajoso */
.head {
    position: sticky;
    top: 0;
    z-index: 20;
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.7rem 1rem;
    background: color-mix(in srgb, var(--card) 92%, transparent);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid var(--line);
    flex-wrap: wrap;
}
.head__brand { display: flex; align-items: center; gap: 0.5rem; font-weight: 800; font-size: 1.15rem; color: var(--primary); }
.head__logo { font-size: 1.4rem; }
.head__name { white-space: nowrap; }
.head__search { position: relative; flex: 1 1 14rem; min-width: 10rem; }
.head__search-i { position: absolute; left: 0.7rem; top: 50%; transform: translateY(-50%); font-size: 0.85rem; opacity: 0.6; }
.head__search input {
    width: 100%;
    padding: 0.5rem 0.8rem 0.5rem 2.1rem;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: var(--bg);
    font: inherit;
    font-size: 0.9rem;
}
.head__search input:focus { outline: 2px solid color-mix(in srgb, var(--primary) 45%, transparent); outline-offset: 1px; }
.head__actions { display: flex; align-items: center; gap: 0.5rem; }
.branch select { border: 1px solid var(--line); border-radius: 999px; padding: 0.4rem 0.6rem; font: inherit; font-size: 0.85rem; background: var(--card); }

.icon-btn {
    display: inline-flex; align-items: center; gap: 0.35rem;
    border: 1px solid var(--line); background: var(--card);
    border-radius: 999px; padding: 0.4rem 0.7rem; cursor: pointer; font: inherit; font-size: 0.85rem; color: var(--ink);
    transition: border-color 0.15s ease, background 0.15s ease;
}
.icon-btn:hover { border-color: color-mix(in srgb, var(--primary) 40%, var(--line)); }
.icon-btn__txt { font-weight: 600; }
.cart-btn { position: relative; }
.cart-btn__badge {
    position: absolute; top: -0.35rem; right: -0.35rem;
    background: var(--primary); color: #fff; font-size: 0.7rem; font-weight: 700;
    min-width: 1.15rem; height: 1.15rem; border-radius: 999px; display: grid; place-items: center; padding: 0 0.25rem;
}

/* Panel de cuenta */
.account { position: relative; }
.account__pop {
    position: absolute; right: 0; top: calc(100% + 0.5rem); z-index: 30;
    width: min(19rem, 86vw); background: var(--card); border: 1px solid var(--line);
    border-radius: 12px; padding: 0.9rem; box-shadow: 0 18px 40px -20px rgb(0 0 0 / 0.35);
}
.account__hi { margin: 0 0 0.5rem; }
.auth { display: grid; gap: 0.5rem; }
.auth__title { margin: 0 0 0.2rem; font-weight: 700; }
.auth input { padding: 0.5rem 0.6rem; border: 1px solid var(--line); border-radius: 8px; font: inherit; }

/* Chips de categorías */
.cats {
    position: sticky; top: 3.4rem; z-index: 15;
    display: flex; gap: 0.5rem; overflow-x: auto; padding: 0.6rem 1rem;
    background: color-mix(in srgb, var(--bg) 92%, transparent); backdrop-filter: blur(8px);
    border-bottom: 1px solid var(--line); scrollbar-width: thin;
}
.cats__chip {
    white-space: nowrap; text-decoration: none; color: var(--ink);
    border: 1px solid var(--line); background: var(--card);
    border-radius: 999px; padding: 0.35rem 0.85rem; font-size: 0.85rem; font-weight: 600;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.cats__chip:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

/* Hero */
.hero { max-width: 72rem; margin-inline: auto; padding: 1.6rem 1rem 0.4rem; }
.hero__title { margin: 0; font-size: clamp(1.6rem, 4vw, 2.4rem); font-weight: 800; color: var(--primary); text-wrap: balance; }
.hero__sub { margin: 0.3rem 0 0; color: var(--muted); }

/* Catálogo */
.catalog { max-width: 72rem; margin-inline: auto; padding: 1rem 1rem 3rem; }
.cat { scroll-margin-top: 7rem; margin-top: 1.6rem; }
.cat__title { font-size: 1.2rem; font-weight: 800; margin: 0 0 0.9rem; display: flex; align-items: center; gap: 0.6rem; }
.cat__title::after { content: ""; flex: 1; height: 1px; background: var(--line); }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: 1rem; }
.card {
    display: flex; flex-direction: column; background: var(--card);
    border: 1px solid var(--line); border-radius: 14px; overflow: hidden;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.card:hover { transform: translateY(-3px); box-shadow: 0 16px 30px -20px rgb(0 0 0 / 0.4); }
.card--off { opacity: 0.72; }
.card__media { position: relative; aspect-ratio: 4 / 3; background: color-mix(in srgb, var(--primary) 8%, var(--bg)); }
.card__img { width: 100%; height: 100%; object-fit: cover; display: block; }
.card__ph { width: 100%; height: 100%; display: grid; place-items: center; font-size: 2.4rem; opacity: 0.45; }
.card__badge {
    position: absolute; top: 0.5rem; left: 0.5rem; background: #b45309; color: #fff;
    font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 999px;
}
.card__body { display: flex; flex-direction: column; gap: 0.35rem; padding: 0.8rem 0.85rem 0.9rem; flex: 1; }
.card__name { margin: 0; font-size: 0.98rem; font-weight: 700; line-height: 1.2; }
.card__desc { margin: 0; font-size: 0.83rem; color: var(--muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.card__foot { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding-top: 0.3rem; }
.card__price { font-weight: 800; font-size: 1.05rem; color: var(--primary); font-variant-numeric: tabular-nums; }

/* Botones */
.btn { font: inherit; font-weight: 700; border: 0; border-radius: 10px; padding: 0.5rem 0.9rem; cursor: pointer; }
.btn--primary { background: var(--primary); color: #fff; box-shadow: 0 8px 18px -10px color-mix(in srgb, var(--primary) 80%, transparent); transition: filter 0.15s ease, transform 0.15s ease; }
.btn--primary:hover:not(:disabled) { filter: brightness(1.08); transform: translateY(-1px); }
.btn--block { width: 100%; padding: 0.65rem; }
.card__add { padding: 0.4rem 0.85rem; border-radius: 999px; }
.link { background: none; border: 0; color: var(--primary); cursor: pointer; font: inherit; font-weight: 600; padding: 0; text-decoration: underline; text-underline-offset: 2px; }
button:disabled { opacity: 0.6; cursor: not-allowed; }

/* Footer */
.foot {
    max-width: 72rem; margin-inline: auto; padding: 2rem 1rem 3rem;
    display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 1.5rem;
    border-top: 1px solid var(--line); margin-top: 1rem;
}
.foot__col h4 { margin: 0 0 0.6rem; font-size: 0.9rem; color: var(--primary); }
.foot__col p { margin: 0.25rem 0; font-size: 0.85rem; color: var(--muted); }

/* Carrito lateral (drawer) */
.drawer-back { position: fixed; inset: 0; z-index: 50; background: rgb(0 0 0 / 0.4); display: flex; justify-content: flex-end; }
.drawer {
    width: min(26rem, 100%); height: 100%; background: var(--card);
    display: flex; flex-direction: column; box-shadow: -20px 0 50px -30px rgb(0 0 0 / 0.6);
}
.drawer__head { display: flex; align-items: center; justify-content: space-between; padding: 1rem; border-bottom: 1px solid var(--line); }
.drawer__head h2 { margin: 0; font-size: 1.1rem; }
.drawer__body { padding: 1rem; overflow-y: auto; flex: 1; }
.drawer__empty { text-align: center; margin-top: 3rem; line-height: 1.8; }

.lines { list-style: none; margin: 0 0 0.5rem; padding: 0; display: grid; gap: 0.9rem; }
.line { border-bottom: 1px solid var(--line); padding-bottom: 0.9rem; }
.line__info { display: flex; justify-content: space-between; gap: 0.6rem; font-weight: 600; }
.line__total { font-variant-numeric: tabular-nums; }
.line__ctl { display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem; }
.line__del { font-size: 0.82rem; font-weight: 500; }
.stepper { display: inline-flex; align-items: center; gap: 0.6rem; border: 1px solid var(--line); border-radius: 999px; padding: 0.15rem 0.6rem; }
.stepper button { border: 0; background: none; font-size: 1.1rem; cursor: pointer; color: var(--primary); line-height: 1; width: 1.4rem; }
.stepper span { min-width: 1.2rem; text-align: center; font-variant-numeric: tabular-nums; }

.total { display: flex; justify-content: space-between; align-items: baseline; padding: 0.8rem 0; font-size: 1.1rem; }
.total strong { font-size: 1.35rem; color: var(--primary); font-variant-numeric: tabular-nums; }

.placed { background: #dcfce7; border-radius: 10px; padding: 0.9rem; text-align: center; }
.placed p { margin: 0.15rem 0; }
.placed__ok { font-weight: 800; color: #15803d; }

.checkout { display: grid; gap: 0.6rem; margin-top: 0.5rem; }
.checkout input, .checkout select { padding: 0.55rem 0.6rem; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
.seg { display: grid; gap: 0.5rem; }
.seg__opt { display: flex; align-items: center; gap: 0.5rem; border: 1px solid var(--line); border-radius: 10px; padding: 0.6rem 0.7rem; cursor: pointer; font-size: 0.9rem; }
.seg__opt--on { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 8%, transparent); font-weight: 600; }

/* Transición del drawer */
.drawer-enter-active, .drawer-leave-active { transition: opacity 0.2s ease; }
.drawer-enter-active .drawer, .drawer-leave-active .drawer { transition: transform 0.25s ease; }
.drawer-enter-from, .drawer-leave-to { opacity: 0; }
.drawer-enter-from .drawer, .drawer-leave-to .drawer { transform: translateX(100%); }

@media (prefers-reduced-motion: reduce) {
    .card:hover, .btn--primary:hover { transform: none; }
    .drawer-enter-active .drawer, .drawer-leave-active .drawer { transition: none; }
}
</style>
