<script setup>
import { onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';

/**
 * Tableros (§6.7, D46). Lista los que puedes ver —los tuyos y los publicados a tu rol— y deja crear uno.
 */
const dashboards = ref([]);
const loadError = ref(null);
const creating = ref(false);
const name = ref('');

onMounted(load);

async function load() {
    try {
        dashboards.value = (await api.get('/dashboards')).data;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e; else throw e;
    }
}

const create = useApiForm(async () => {
    const { data } = await api.post('/dashboards', { name: name.value });
    router.visit(`/admin/tableros/${data.ulid}`);
});
</script>

<template>
    <Head title="Tableros" />

    <div class="tableros">
        <header>
            <h1>Tableros</h1>
            <button type="button" @click="creating = ! creating">Nuevo tablero</button>
        </header>

        <section v-if="creating" class="panel">
            <form @submit.prevent="create.submit()">
                <label>Nombre <input v-model="name" type="text" required maxlength="80" /></label>
                <p v-if="create.generalError.value" class="error">{{ create.generalError.value }}</p>
                <div class="acciones">
                    <button type="submit" :disabled="create.processing.value">Crear</button>
                    <button type="button" class="enlace" @click="creating = false">Cancelar</button>
                </div>
            </form>
        </section>

        <p v-if="loadError" class="error">{{ loadError.title }}</p>

        <ul v-if="dashboards.length" class="lista">
            <li v-for="d in dashboards" :key="d.ulid">
                <a :href="`/admin/tableros/${d.ulid}`">{{ d.name }}</a>
                <span v-if="! d.is_mine" class="tag">compartido</span>
                <span v-else-if="d.published_role_ulid" class="tag">publicado</span>
            </li>
        </ul>

        <p v-else class="nota">No hay tableros todavía.</p>
    </div>
</template>

<style scoped>
.tableros { display: grid; gap: 1rem; max-width: 48rem; }
header { display: flex; justify-content: space-between; align-items: baseline; }
header h1 { margin: 0; }
.panel { background: var(--color-superficie); border: 1px solid var(--color-borde); border-radius: var(--radio-lg); box-shadow: var(--sombra-sm); padding: 1.15rem 1.25rem; }
form { display: grid; gap: 0.5rem; max-width: 22rem; }
label { display: grid; gap: 0.2rem; font-size: 0.9rem; }
.acciones { display: flex; gap: 1rem; }
.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.4rem; }
.lista li { display: flex; gap: 0.6rem; align-items: baseline; }
.tag { background: var(--color-fondo); border-radius: 999px; padding: 0.1rem 0.5rem; font-size: 0.75rem; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.enlace { background: none; border: 0; color: var(--color-acento); cursor: pointer; }
.error { color: var(--color-peligro); }
</style>
