<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Configuración de la tienda en línea (Iteración 8, Tanda B). Una tienda por negocio: dirección pública, nombre, color, y
 * **qué sucursales atiende** (el cliente elige una al comprar). Sólo aparece si el módulo Ecommerce está activo.
 */
const form = ref({ slug: '', name: '', is_active: false, theme_primary: '#1c1917', auto_accept_orders: false, branch_ulids: [] });
const branches = ref([]);
const publicUrl = ref(null);
const error = ref(null);
const saved = ref(false);
const saving = ref(false);

const zones = ref([]);
const zoneForm = ref({ name: '', cost: '' });

onMounted(async () => {
    const [ctx, store] = await Promise.all([api.get('/context'), api.get('/store')]);
    branches.value = ctx.data.branches ?? [];

    if (store.data) {
        form.value = {
            slug: store.data.slug,
            name: store.data.name,
            is_active: store.data.is_active,
            theme_primary: store.data.theme_primary,
            auto_accept_orders: store.data.auto_accept_orders,
            branch_ulids: store.data.branch_ulids ?? [],
        };
        publicUrl.value = store.data.public_url;
    }

    await loadZones();
});

async function loadZones() {
    const { data } = await api.get('/shipping-zones');
    zones.value = data;
}

async function addZone() {
    error.value = null;
    try {
        await api.post('/shipping-zones', { name: zoneForm.value.name, cost: zoneForm.value.cost, is_active: true });
        zoneForm.value = { name: '', cost: '' };
        await loadZones();
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    }
}

async function deleteZone(ulid) {
    await api.delete(`/shipping-zones/${ulid}`);
    await loadZones();
}

function toggleBranch(ulid) {
    const i = form.value.branch_ulids.indexOf(ulid);
    i === -1 ? form.value.branch_ulids.push(ulid) : form.value.branch_ulids.splice(i, 1);
}

async function save() {
    saving.value = true;
    error.value = null;
    saved.value = false;
    try {
        const { data } = await api.put('/store', form.value);
        publicUrl.value = data.public_url;
        saved.value = true;
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Tienda" />

    <div class="tienda animar-entrada">
        <ListHeader
            title="Tienda en línea"
            subtitle="Configura la dirección pública de tu tienda y qué sucursales atiende."
        />

        <p v-if="error" class="alert" role="alert">{{ error }}</p>
        <p v-else-if="saved" class="alert alert--ok" role="status">Cambios guardados.</p>

        <section class="tarjeta bloque">
            <div class="field">
                <label class="field__label" for="store-name">Nombre</label>
                <input id="store-name" v-model="form.name" class="input" type="text" maxlength="120" />
            </div>

            <div class="field">
                <label class="field__label" for="store-slug">Dirección (slug)</label>
                <input id="store-slug" v-model="form.slug" class="input" type="text" maxlength="80" placeholder="mi-tienda" />
            </div>

            <label class="check"><input v-model="form.is_active" type="checkbox" /> Tienda activa (visible al público)</label>
            <label class="check"><input v-model="form.auto_accept_orders" type="checkbox" /> Aceptar pedidos automáticamente al pagarse</label>

            <div class="field field--color">
                <label class="field__label" for="store-color">Color de la tienda</label>
                <input id="store-color" v-model="form.theme_primary" type="color" class="color" />
            </div>
        </section>

        <fieldset class="tarjeta bloque">
            <legend>Sucursales que atiende</legend>
            <label v-for="b in branches" :key="b.ulid" class="check">
                <input type="checkbox" :checked="form.branch_ulids.includes(b.ulid)" @change="toggleBranch(b.ulid)" />
                {{ b.name }}
            </label>
        </fieldset>

        <div class="acciones">
            <button type="button" class="button" :disabled="saving" @click="save">
                {{ saving ? 'Guardando…' : 'Guardar' }}
            </button>
            <a v-if="publicUrl && form.is_active" :href="publicUrl" target="_blank" rel="noopener" class="link-button">
                Ver tienda
            </a>
        </div>

        <fieldset class="tarjeta bloque">
            <legend>Zonas de envío</legend>
            <ul v-if="zones.length" class="zonas__lista">
                <li v-for="z in zones" :key="z.ulid">
                    <span>{{ z.name }} — ${{ z.cost }}</span>
                    <button type="button" class="link-button link-button--danger" @click="deleteZone(z.ulid)"><Icon name="trash" /> Quitar</button>
                </li>
            </ul>
            <p v-else class="page-header__hint">Sin zonas. Con recoger en sucursal no hacen falta; para envío, agrega al menos una.</p>
            <form class="zonas__nueva" @submit.prevent="addZone">
                <input v-model="zoneForm.name" class="input" type="text" maxlength="120" placeholder="Nombre (p. ej. Centro)" required />
                <input v-model="zoneForm.cost" class="input" type="text" inputmode="decimal" placeholder="Costo" required />
                <button type="submit" class="button button--ghost"><Icon name="plus" /> Agregar zona</button>
            </form>
        </fieldset>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.tienda {
    display: grid;
    gap: 1rem;
    max-width: 42rem;
}

.bloque {
    display: grid;
    gap: 0.85rem;
    padding: 1.15rem;
    border: 1px solid var(--color-borde);
}

.bloque legend {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--color-contenido);
    padding: 0 0.35rem;
}

.field {
    margin-bottom: 0;
}

.field--color .color {
    width: 3.5rem;
    height: 2.25rem;
    padding: 0.15rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background: var(--color-superficie);
    cursor: pointer;
}

.check {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.acciones {
    display: flex;
    gap: 0.85rem;
    align-items: center;
}

.zonas__lista {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.4rem;
    font-size: 0.9rem;
}

.zonas__lista li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.zonas__nueva {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    flex-wrap: wrap;
}

.zonas__nueva .input {
    flex: 1 1 10rem;
}
</style>
