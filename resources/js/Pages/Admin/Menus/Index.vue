<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Menús digitales (Iteración 8, Tanda A). Un menú por sucursal: el propietario le pone slug, lo activa, decide si muestra
 * precios y su color, y descarga el PDF. La dirección pública (`/m/{slug}`) sirve el QR.
 *
 * Sólo aparece si el módulo `DigitalMenus` está activo (el guard de navegación del shell lo filtra); el backend lo vuelve a
 * exigir con `module:DigitalMenus`.
 *
 * Desactivar un menú publicado lo saca de línea (su dirección pública responde 404: `PublicMenuController` sólo sirve
 * menús activos), así que guardar ese cambio pide confirmación.
 */
const branches = ref([]);
const menusByBranch = ref({});
// Si el menú está publicado según el SERVIDOR, por sucursal. La casilla «Activo» se edita antes de guardar, así que no
// sirve para saber si guardar lo va a sacar de línea: esto sí.
const activeOnServer = ref({});
const loading = ref(true);
// La falla de la carga va aparte de la de guardar/crear: decide si se puede afirmar «No hay sucursales».
const loadError = ref(null);
const error = ref(null);
const busy = ref('');

// Borrador de slug para las sucursales que aún no tienen menú.
const draftSlug = ref({});

onMounted(load);

async function load() {
    try {
        const [ctx, menus] = await Promise.all([api.get('/context'), api.get('/digital-menus')]);
        branches.value = ctx.data.branches ?? [];

        const map = {};
        const active = {};
        for (const m of menus.data) {
            if (m.branch) {
                map[m.branch.ulid] = { ...m };
                active[m.branch.ulid] = m.is_active === true;
            }
        }
        menusByBranch.value = map;
        activeOnServer.value = active;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
}

async function create(branch) {
    const slug = (draftSlug.value[branch.ulid] ?? '').trim();
    if (!slug) return;

    busy.value = branch.ulid;
    error.value = null;
    try {
        const { data } = await api.post('/digital-menus', { branch_ulid: branch.ulid, slug });
        menusByBranch.value = { ...menusByBranch.value, [branch.ulid]: data };
        activeOnServer.value = { ...activeOnServer.value, [branch.ulid]: data.is_active === true };
        draftSlug.value[branch.ulid] = '';
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        busy.value = '';
    }
}

async function save(branchUlid) {
    const menu = menusByBranch.value[branchUlid];

    // Desmarcar «Activo» y guardar saca el menú de línea: `/m/{slug}` responde «no encontrado» y el QR impreso deja de
    // funcionar. Se pregunta sólo en ese paso —de activo a inactivo—, no en cada guardado. La dirección que se nombra es
    // la que está publicada ahora (`public_url` viene del servidor), aunque el slug se haya editado en el mismo cambio.
    if (activeOnServer.value[branchUlid] && ! menu.is_active) {
        const sucursal = branches.value.find((b) => b.ulid === branchUlid)?.name ?? 'esta sucursal';

        if (! window.confirm(`¿Desactivar el menú de «${sucursal}»? Su dirección pública (${menu.public_url}) y su código QR dejarán de funcionar: quien los abra verá «no encontrado» hasta que lo vuelvas a activar.`)) {
            return;
        }
    }

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
        activeOnServer.value = { ...activeOnServer.value, [branchUlid]: data.is_active === true };
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
        <ListHeader
            title="Menús digitales"
            subtitle="Un menú por sucursal. Compártelo con su dirección pública o su código QR, o descárgalo en PDF."
        />

        <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
        <p v-if="error" class="alert" role="alert">{{ error }}</p>

        <!-- «No hay sucursales» sólo cuando la carga terminó bien: mientras carga o si falló, no se sabe. -->
        <p v-if="loading" class="nota">Cargando…</p>
        <p v-else-if="!loadError && !branches.length" class="nota">No hay sucursales.</p>

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
                    <button type="button" class="button" :disabled="busy === branch.ulid" @click="save(branch.ulid)">Guardar</button>
                    <!-- Enlaces normales, no navegación de Inertia: uno descarga un archivo y el otro abre la página pública. -->
                    <a :href="`/api/v1/digital-menus/${menusByBranch[branch.ulid].ulid}/pdf`" class="link-button">
                        <Icon name="receive" /> Descargar PDF
                    </a>
                    <a :href="menusByBranch[branch.ulid].public_url" target="_blank" rel="noopener" class="link-button">
                        <Icon name="eye" /> Ver menú público
                    </a>
                </div>
            </template>

            <template v-else>
                <p class="nota">Esta sucursal no tiene menú.</p>
                <div class="crear">
                    <input
                        v-model="draftSlug[branch.ulid]"
                        type="text"
                        maxlength="80"
                        placeholder="dirección, p. ej. mi-fonda"
                        :aria-label="`Dirección del menú de ${branch.name}`"
                    />
                    <button
                        type="button"
                        class="button"
                        :disabled="busy === branch.ulid || !(draftSlug[branch.ulid] || '').trim()"
                        @click="create(branch)"
                    >
                        <Icon name="plus" /> Crear menú
                    </button>
                </div>
            </template>
        </section>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.menus { display: grid; gap: 1rem; max-width: 48rem; }
/* En la rejilla el espacio lo pone el `gap`; el margen propio del aviso lo duplicaría. */
.menus > .alert { margin: 0; }
.nota { color: var(--color-suave); font-size: 0.9rem; margin: 0; }
.tarjeta { border: 1px solid var(--color-borde); border-radius: 6px; padding: 1rem 1.25rem; display: grid; gap: 0.75rem; }
.tarjeta h2 { margin: 0; font-size: 1.1rem; }
.campos { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; }
.campos label { display: grid; gap: 0.2rem; font-size: 0.85rem; }
.campos .chk { display: flex; gap: 0.4rem; align-items: center; }
.acciones { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
.crear { display: flex; gap: 0.5rem; align-items: center; }
</style>
