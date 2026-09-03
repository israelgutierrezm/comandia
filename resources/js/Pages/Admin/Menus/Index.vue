<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

/**
 * Menús digitales (Iteración 8, Tanda A). Un menú por sucursal: el propietario le pone slug, lo activa, decide si muestra
 * precios y su color, y descarga el PDF. La dirección pública (`/m/{slug}`) sirve el QR.
 *
 * Sólo aparece si el módulo `DigitalMenus` está activo (el guard de navegación del shell lo filtra); el backend lo vuelve a
 * exigir con `module:DigitalMenus`.
 */
const branches = ref([]);
const menusByBranch = ref({});
const error = ref(null);
const busy = ref('');

// Borrador de slug para las sucursales que aún no tienen menú.
const draftSlug = ref({});

onMounted(load);

async function load() {
    const [ctx, menus] = await Promise.all([api.get('/context'), api.get('/digital-menus')]);
    branches.value = ctx.data.branches ?? [];

    const map = {};
    for (const m of menus.data) {
        if (m.branch) map[m.branch.ulid] = { ...m };
    }
    menusByBranch.value = map;
}

async function create(branch) {
    const slug = (draftSlug.value[branch.ulid] ?? '').trim();
    if (!slug) return;

    busy.value = branch.ulid;
    error.value = null;
    try {
        const { data } = await api.post('/digital-menus', { branch_ulid: branch.ulid, slug });
        menusByBranch.value = { ...menusByBranch.value, [branch.ulid]: data };
        draftSlug.value[branch.ulid] = '';
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        busy.value = '';
    }
}

async function save(branchUlid) {
    const menu = menusByBranch.value[branchUlid];
    busy.value = branchUlid;
    error.value = null;
    try {
        const { data } = await api.put(`/digital-menus/${menu.ulid}`, {
            slug: menu.slug,
            is_active: menu.is_active,
            show_prices: menu.show_prices,
            theme_primary: menu.theme_primary,
        });
        menusByBranch.value = { ...menusByBranch.value, [branchUlid]: data };
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        busy.value = '';
    }
}
</script>

<template>
    <Head title="Menús" />

    <div class="menus">
        <h1>Menús digitales</h1>
        <p class="nota">Un menú por sucursal. Compártelo con su dirección pública o su código QR, o descárgalo en PDF.</p>

        <p v-if="error" class="error">{{ error }}</p>

        <p v-if="!branches.length" class="nota">No hay sucursales.</p>

        <section v-for="branch in branches" :key="branch.ulid" class="tarjeta">
            <h2>{{ branch.name }}</h2>

            <template v-if="menusByBranch[branch.ulid]">
                <div class="campos">
                    <label>Dirección (slug)
                        <input v-model="menusByBranch[branch.ulid].slug" type="text" maxlength="80" />
                    </label>
                    <label class="chk">
                        <input v-model="menusByBranch[branch.ulid].is_active" type="checkbox" /> Activo (visible al público)
                    </label>
                    <label class="chk">
                        <input v-model="menusByBranch[branch.ulid].show_prices" type="checkbox" /> Mostrar precios
                    </label>
                    <label>Color
                        <input v-model="menusByBranch[branch.ulid].theme_primary" type="color" />
                    </label>
                </div>

                <div class="acciones">
                    <button type="button" :disabled="busy === branch.ulid" @click="save(branch.ulid)">Guardar</button>
                    <a :href="`/api/v1/digital-menus/${menusByBranch[branch.ulid].ulid}/pdf`" class="enlace">Descargar PDF</a>
                    <a :href="menusByBranch[branch.ulid].public_url" target="_blank" rel="noopener" class="enlace">
                        Ver menú público
                    </a>
                </div>
            </template>

            <template v-else>
                <p class="nota">Esta sucursal no tiene menú.</p>
                <div class="crear">
                    <input v-model="draftSlug[branch.ulid]" type="text" maxlength="80" placeholder="dirección, p. ej. mi-fonda" />
                    <button type="button" :disabled="busy === branch.ulid || !(draftSlug[branch.ulid] || '').trim()" @click="create(branch)">
                        Crear menú
                    </button>
                </div>
            </template>
        </section>
    </div>
</template>

<style scoped>
.menus { display: grid; gap: 1rem; max-width: 48rem; }
.menus h1 { margin: 0; }
.nota { color: var(--color-suave); font-size: 0.9rem; margin: 0; }
.error { color: var(--color-peligro); }
.tarjeta { border: 1px solid var(--color-borde); border-radius: 6px; padding: 1rem 1.25rem; display: grid; gap: 0.75rem; }
.tarjeta h2 { margin: 0; font-size: 1.1rem; }
.campos { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; }
.campos label { display: grid; gap: 0.2rem; font-size: 0.85rem; }
.campos .chk { display: flex; gap: 0.4rem; align-items: center; }
.acciones { display: flex; gap: 1rem; align-items: center; }
.crear { display: flex; gap: 0.5rem; align-items: center; }
.enlace { color: var(--color-acento); font-size: 0.9rem; }
</style>
