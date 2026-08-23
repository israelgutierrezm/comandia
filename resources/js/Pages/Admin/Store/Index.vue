<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

/**
 * Configuración de la tienda en línea (Iteración 8, Tanda B). Una tienda por negocio: dirección pública, nombre, color, y
 * **qué sucursales atiende** (el cliente elige una al comprar). Sólo aparece si el módulo Ecommerce está activo.
 */
const form = ref({ slug: '', name: '', is_active: false, theme_primary: '#1c1917', branch_ulids: [] });
const branches = ref([]);
const publicUrl = ref(null);
const error = ref(null);
const saved = ref(false);
const saving = ref(false);

onMounted(async () => {
    const [ctx, store] = await Promise.all([api.get('/context'), api.get('/store')]);
    branches.value = ctx.data.branches ?? [];

    if (store.data) {
        form.value = {
            slug: store.data.slug,
            name: store.data.name,
            is_active: store.data.is_active,
            theme_primary: store.data.theme_primary,
            branch_ulids: store.data.branch_ulids ?? [],
        };
        publicUrl.value = store.data.public_url;
    }
});

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

    <div class="tienda">
        <h1>Tienda en línea</h1>
        <p class="nota">Configura la dirección pública de tu tienda y qué sucursales atiende.</p>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="saved" class="ok">Guardado.</p>

        <div class="campos">
            <label>Nombre <input v-model="form.name" type="text" maxlength="120" /></label>
            <label>Dirección (slug) <input v-model="form.slug" type="text" maxlength="80" placeholder="mi-tienda" /></label>
            <label class="chk"><input v-model="form.is_active" type="checkbox" /> Tienda activa (visible al público)</label>
            <label>Color <input v-model="form.theme_primary" type="color" /></label>
        </div>

        <fieldset class="sucursales">
            <legend>Sucursales que atiende</legend>
            <label v-for="b in branches" :key="b.ulid" class="chk">
                <input type="checkbox" :checked="form.branch_ulids.includes(b.ulid)" @change="toggleBranch(b.ulid)" />
                {{ b.name }}
            </label>
        </fieldset>

        <div class="acciones">
            <button type="button" :disabled="saving" @click="save">Guardar</button>
            <a v-if="publicUrl && form.is_active" :href="publicUrl" target="_blank" rel="noopener" class="enlace">Ver tienda</a>
        </div>
    </div>
</template>

<style scoped>
.tienda { display: grid; gap: 1rem; max-width: 40rem; }
.tienda h1 { margin: 0; }
.nota { color: #555; font-size: 0.9rem; margin: 0; }
.error { color: #a11; }
.ok { color: #166534; }
.campos { display: grid; gap: 0.75rem; }
.campos label { display: grid; gap: 0.2rem; font-size: 0.85rem; max-width: 22rem; }
.campos .chk, .sucursales .chk { display: flex; gap: 0.4rem; align-items: center; }
.sucursales { border: 1px solid #e2e2e2; border-radius: 6px; display: grid; gap: 0.4rem; padding: 0.75rem 1rem; }
.sucursales legend { font-size: 0.85rem; color: #444; padding: 0 0.4rem; }
.acciones { display: flex; gap: 1rem; align-items: center; }
.enlace { color: #06c; font-size: 0.9rem; }
</style>
