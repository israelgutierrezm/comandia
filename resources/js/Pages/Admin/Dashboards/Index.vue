<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Tableros (§6.7, D46). Lista los que puedes ver —los tuyos y los publicados a tu rol— y deja crear uno.
 *
 * Crear exige `dashboards.dashboards.manage` escribible (`POST /dashboards` va con `can.write`): sin él, «Nuevo tablero»
 * no se ofrece. Antes se mostraba a cualquiera que viera tableros y el clic respondía 403.
 */
const { canWrite } = useAuthorization();
const canCreate = computed(() => canWrite('dashboards.dashboards.manage'));

const dashboards = ref([]);
const loading = ref(true);
const loadError = ref(null);
const creating = ref(false);
const name = ref('');

onMounted(load);

async function load() {
    try {
        dashboards.value = (await api.get('/dashboards')).data;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
}

const create = useApiForm(async () => {
    const { data } = await api.post('/dashboards', { name: name.value });
    router.visit(`/admin/tableros/${data.ulid}`);
}, { success: { kind: 'create', entity: 'Tablero', gender: 'm' } });
</script>

<template>
    <Head title="Tableros" />

    <div class="tableros">
        <ListHeader
            title="Tableros"
            subtitle="Los tuyos y los publicados a tu rol. Cada indicador se calcula con los permisos de quien lo mira."
        >
            <template v-if="canCreate" #action>
                <button type="button" class="button" :aria-expanded="creating" @click="creating = ! creating">
                    <Icon name="plus" /> Nuevo tablero
                </button>
            </template>
        </ListHeader>

        <section v-if="creating && canCreate" class="panel">
            <form @submit.prevent="create.submit()">
                <label>Nombre <input v-model="name" type="text" required maxlength="80" /></label>
                <p v-if="create.generalError.value" class="alert" role="alert">{{ create.generalError.value }}</p>
                <div class="acciones">
                    <button type="submit" class="button" :disabled="create.processing.value">
                        {{ create.processing.value ? 'Creando…' : 'Crear' }}
                    </button>
                    <button type="button" class="link-button" @click="creating = false"><Icon name="x" /> Cancelar</button>
                </div>
            </form>
        </section>

        <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>

        <!-- «No hay tableros» sólo cuando la carga terminó bien: mientras carga o si falló, no se sabe si hay. -->
        <p v-if="loading" class="nota">Cargando…</p>

        <section v-else-if="dashboards.length" class="panel">
            <ul class="lista">
                <li v-for="d in dashboards" :key="d.ulid">
                    <Link :href="`/admin/tableros/${d.ulid}`" class="lista__enlace">{{ d.name }}</Link>
                    <span v-if="! d.is_mine" class="badge badge--off">Compartido</span>
                    <span v-else-if="d.published_role_ulid" class="badge badge--ok">Publicado</span>
                </li>
            </ul>
        </section>

        <p v-else-if="! loadError" class="nota">
            {{ canCreate ? 'No hay tableros todavía: crea el primero con «Nuevo tablero».' : 'No hay tableros publicados a tu rol todavía.' }}
        </p>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.tableros { display: grid; gap: 1rem; max-width: 48rem; }
/* En las rejillas el espacio lo pone el `gap`; el margen propio del aviso lo duplicaría. */
.tableros > .alert, form .alert { margin: 0; }
.panel { background: var(--color-superficie); border: 1px solid var(--color-borde); border-radius: var(--radio-lg); box-shadow: var(--sombra-sm); padding: 1.15rem 1.25rem; }
form { display: grid; gap: 0.5rem; max-width: 22rem; }
label { display: grid; gap: 0.2rem; font-size: 0.9rem; }
.acciones { display: flex; gap: 1rem; align-items: center; }
.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.lista li { display: flex; gap: 0.6rem; align-items: center; }
/* Con el reset de Tailwind un enlace hereda el color del texto: sin esto, el nombre no se leía como algo que se abre. */
.lista__enlace { color: var(--color-acento); font-weight: 600; }
.lista__enlace:hover { text-decoration: underline; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
</style>
