<script setup>
import { computed, onMounted, ref } from 'vue';

/**
 * La tienda pública (Iteración 8, Tanda B). Pinta el catálogo de la sucursal elegida y maneja el carrito (en sesión, del
 * lado del servidor). No decide precio ni stock: los resuelve el backend según la sucursal y la política del artículo
 * (ADR-007). Sin lógica de negocio aquí — el frontend previsualiza, el backend decide.
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

// --- Cuenta de cliente ---
const customer = ref(null);
const authMode = ref('login'); // 'login' | 'register'
const authForm = ref({ name: '', phone: '', email: '', password: '' });
const authError = ref(null);

const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

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
    } catch (e) {
        authError.value = e.message;
    }
}

async function logout() {
    await api('POST', '/logout');
    customer.value = null;
}

onMounted(async () => {
    await Promise.all([loadCatalog(), loadCart(), loadMe()]);
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
        <header class="store__head">
            <h1>{{ store.name }}</h1>
            <div class="store__tools">
                <label v-if="branches.length > 1" class="branch">
                    Sucursal
                    <select v-model="selectedBranch" @change="changeBranch">
                        <option v-for="b in branches" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                    </select>
                </label>
                <span class="cart-badge">🛒 {{ cart.count }}</span>
            </div>
        </header>

        <div class="auth">
            <template v-if="customer">
                <span>Hola, <strong>{{ customer.name }}</strong></span>
                <button type="button" class="link" @click="logout">Cerrar sesión</button>
            </template>
            <form v-else class="auth__form" @submit.prevent="submitAuth">
                <template v-if="authMode === 'register'">
                    <input v-model="authForm.name" type="text" placeholder="Nombre" required />
                    <input v-model="authForm.phone" type="tel" placeholder="Teléfono" required />
                </template>
                <input v-model="authForm.email" type="email" placeholder="Correo" required />
                <input v-model="authForm.password" type="password" placeholder="Contraseña" required />
                <button type="submit">{{ authMode === 'register' ? 'Crear cuenta' : 'Entrar' }}</button>
                <button type="button" class="link" @click="authMode = authMode === 'register' ? 'login' : 'register'">
                    {{ authMode === 'register' ? 'Ya tengo cuenta' : 'Crear cuenta' }}
                </button>
                <span v-if="authError" class="error">{{ authError }}</span>
            </form>
        </div>

        <p v-if="error" class="error">{{ error }}</p>

        <div class="layout">
            <main class="catalog">
                <p v-if="!branches.length" class="muted">Esta tienda aún no atiende ninguna sucursal.</p>
                <p v-else-if="!loading && !sections.length" class="muted">No hay productos disponibles en esta sucursal.</p>

                <section v-for="section in sections" :key="section.name" class="section">
                    <h2>{{ section.name }}</h2>
                    <ul class="items">
                        <li v-for="item in section.items" :key="item.ulid" class="item">
                            <img v-if="item.image" :src="item.image" :alt="item.name" class="item__img" loading="lazy" />
                            <div class="item__body">
                                <div class="item__name">{{ item.name }}</div>
                                <p v-if="item.description" class="item__desc">{{ item.description }}</p>
                                <div class="item__foot">
                                    <span v-if="item.price" class="item__price">${{ item.price }}</span>
                                    <span v-if="item.out_of_stock" class="agotado">Agotado</span>
                                    <button v-else type="button" class="add" @click="add(item)">Agregar</button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>
            </main>

            <aside class="cart">
                <h2>Tu pedido</h2>
                <p v-if="!cart.items.length" class="muted">Tu carrito está vacío.</p>
                <ul v-else class="cart-lines">
                    <li v-for="line in cart.items" :key="line.article_ulid" class="cart-line">
                        <div class="cart-line__top">
                            <span>{{ line.name }}</span>
                            <button type="button" class="link" @click="remove(line)">×</button>
                        </div>
                        <div class="cart-line__bot">
                            <input
                                type="number" min="0" max="99" :value="line.quantity"
                                @change="setQty(line, Number($event.target.value))"
                            />
                            <span>${{ line.line_total }}</span>
                        </div>
                        <p v-if="line.out_of_stock" class="agotado small">Agotado en esta sucursal</p>
                    </li>
                </ul>
                <div v-if="cart.items.length" class="cart-total">
                    <strong>Total</strong><strong>${{ cart.total }}</strong>
                </div>
                <button v-if="cart.items.length" type="button" class="checkout" disabled>
                    Pagar (próximamente)
                </button>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.store { max-width: 60rem; margin: 0 auto; padding: 1.25rem 1rem 4rem; font-family: ui-sans-serif, system-ui, sans-serif; color: #1c1917; }
.store__head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }
.store__head h1 { margin: 0; color: var(--primary); font-size: 1.5rem; }
.store__tools { display: flex; gap: 1rem; align-items: center; }
.branch { font-size: 0.8rem; display: flex; gap: 0.4rem; align-items: center; }
.cart-badge { font-size: 1rem; }
.error { color: #a11; }
.muted { color: #78716c; }
.auth { margin-bottom: 1rem; padding: 0.6rem 0.8rem; background: #fafaf9; border: 1px solid #e7e5e4; border-radius: 8px; font-size: 0.85rem; }
.auth__form { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; }
.auth__form input { padding: 0.3rem 0.5rem; border: 1px solid #d6d3d1; border-radius: 4px; font: inherit; }
.auth__form button[type="submit"] { background: var(--primary); color: #fff; border: 0; border-radius: 4px; padding: 0.3rem 0.7rem; cursor: pointer; }
.layout { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
@media (min-width: 48rem) { .layout { grid-template-columns: 2fr 1fr; } }
.section { margin-bottom: 1.25rem; }
.section h2 { font-size: 1.05rem; color: var(--primary); border-bottom: 2px solid var(--primary); padding-bottom: 0.2rem; }
.items { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.9rem; }
.item { display: flex; gap: 0.8rem; }
.item__img { width: 4.5rem; height: 4.5rem; object-fit: cover; border-radius: 8px; flex: none; }
.item__body { flex: 1; min-width: 0; }
.item__name { font-weight: 600; }
.item__desc { margin: 0.15rem 0; font-size: 0.88rem; color: #57534e; }
.item__foot { display: flex; gap: 0.75rem; align-items: center; }
.item__price { font-weight: 600; color: var(--primary); }
.agotado { color: #b45309; font-size: 0.85rem; }
.small { font-size: 0.75rem; }
.add { background: var(--primary); color: #fff; border: 0; border-radius: 6px; padding: 0.3rem 0.8rem; cursor: pointer; }
.cart { border: 1px solid #e7e5e4; border-radius: 8px; padding: 1rem; align-self: start; }
.cart h2 { margin-top: 0; font-size: 1.1rem; }
.cart-lines { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.75rem; }
.cart-line__top { display: flex; justify-content: space-between; gap: 0.5rem; }
.cart-line__bot { display: flex; justify-content: space-between; align-items: center; margin-top: 0.25rem; }
.cart-line__bot input { width: 3.5rem; padding: 0.2rem 0.3rem; }
.cart-total { display: flex; justify-content: space-between; border-top: 1px solid #e7e5e4; margin-top: 0.75rem; padding-top: 0.5rem; }
.checkout { width: 100%; margin-top: 0.75rem; padding: 0.5rem; border: 0; border-radius: 6px; background: #d6d3d1; color: #44403c; cursor: not-allowed; }
.link { background: none; border: 0; color: #a11; cursor: pointer; font-size: 1.1rem; }
</style>
