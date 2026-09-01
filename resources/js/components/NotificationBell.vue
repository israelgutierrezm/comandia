<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { api, ApiError } from '../api/client';

/**
 * La campana de notificaciones (Tanda D2).
 *
 * Muestra el número de avisos sin leer y, al abrirla, la lista. Marcar uno lo lee y —si trae enlace— lleva a la pantalla
 * relevante. Se refresca al abrir; en desarrollo, los avisos que dependen de la cola (export listo) sólo aparecen si
 * corre un worker.
 */
const items = ref([]);
const unread = ref(0);
const open = ref(false);

onMounted(load);

async function load() {
    try {
        const { data, meta } = await api.get('/notifications');
        items.value = data;
        unread.value = meta.unread;
    } catch (e) {
        if (! (e instanceof ApiError)) throw e;
    }
}

function toggle() {
    open.value = ! open.value;
    if (open.value) load();
}

async function activate(item) {
    if (! item.read_at) {
        await api.post(`/notifications/${item.ulid}/read`);
        item.read_at = new Date().toISOString();
        unread.value = Math.max(0, unread.value - 1);
    }

    if (item.url) {
        open.value = false;
        router.visit(item.url);
    }
}

async function markAll() {
    await api.post('/notifications/read-all');
    await load();
}
</script>

<template>
    <div class="campana">
        <button type="button" class="disparador" :aria-label="`Notificaciones${unread ? `, ${unread} sin leer` : ''}`" @click="toggle">
            🔔
            <span v-if="unread" class="badge">{{ unread > 9 ? '9+' : unread }}</span>
        </button>

        <div v-if="open" class="panel">
            <header>
                <strong>Notificaciones</strong>
                <button v-if="unread" type="button" class="enlace" @click="markAll">Marcar todo leído</button>
            </header>

            <ul v-if="items.length">
                <li v-for="n in items" :key="n.ulid" :class="{ nolei: ! n.read_at }">
                    <button type="button" class="item" @click="activate(n)">
                        <span class="titulo">{{ n.title }}</span>
                        <span v-if="n.body" class="cuerpo">{{ n.body }}</span>
                    </button>
                </li>
            </ul>

            <p v-else class="vacio">Sin notificaciones.</p>
        </div>
    </div>
</template>

<style scoped>
.campana { position: relative; }
.disparador { background: none; border: 0; cursor: pointer; font-size: 1.15rem; position: relative; line-height: 1; }
.badge { position: absolute; top: -0.4rem; right: -0.5rem; background: var(--color-acento); color: #fff; border-radius: 999px; font-size: 0.65rem; padding: 0.05rem 0.3rem; }
.panel { position: absolute; right: 0; top: 2rem; width: 20rem; max-height: 24rem; overflow-y: auto; background: #fff; border: 1px solid #d6d6d6; border-radius: 8px; box-shadow: 0 6px 20px rgb(0 0 0 / 12%); z-index: 30; }
.panel header { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.8rem; border-bottom: 1px solid #eee; }
.panel ul { list-style: none; margin: 0; padding: 0; }
.panel li { border-bottom: 1px solid #f2f2f2; }
.panel li.nolei { background: #f5f9ff; }
.item { display: grid; gap: 0.15rem; width: 100%; text-align: left; background: none; border: 0; cursor: pointer; padding: 0.6rem 0.8rem; }
.titulo { font-size: 0.9rem; font-weight: 600; }
.cuerpo { font-size: 0.8rem; color: #555; }
.vacio { padding: 1rem 0.8rem; color: #666; font-size: 0.9rem; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; font-size: 0.8rem; }
</style>
