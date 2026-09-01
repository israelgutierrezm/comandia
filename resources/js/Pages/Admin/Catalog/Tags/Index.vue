<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';
import ResourceGrid from '../../../../components/ResourceGrid.vue';
import ViewToggle from '../../../../components/ViewToggle.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import Icon from '../../../../components/Icon.vue';

const view = ref('list');

/**
 * Etiquetas libres (D19).
 *
 * Son el único registro del catálogo que se **borra de verdad**, y por eso es el único que pide
 * confirmación con el nombre a la vista: una etiqueta no aparece en ningún documento —no hay
 * comandas ni cuentas que la citen—, así que borrarla no deja un hueco en nada. Cualquier otra cosa
 * del catálogo se da de baja.
 *
 * Se administran aquí y no dentro del artículo porque la pregunta que resuelven es transversal:
 * «¿qué etiquetas tengo?» antes de «¿qué etiquetas le pongo a esto?». Sin esta pantalla, una
 * etiqueta mal escrita —«sin gluten» y «Sin Gluten»— se quedaría para siempre, invisible hasta que
 * alguien filtrara por ella.
 *
 * El endpoint devuelve TODAS las etiquetas sin paginar ni filtrar, así que el buscador filtra en el
 * cliente. Es deliberado y no una simplificación: mandarle `search` a un endpoint que no lo declara
 * en su whitelist dejaría un buscador que no hace nada —la lista completa siempre—, y eso es peor
 * que no tenerlo. Un tenant tiene decenas de etiquetas, no miles.
 */
const all = ref([]);
const loading = ref(true);
const error = ref(null);
const search = ref('');

const visible = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase('es');

    return needle === ''
        ? all.value
        : all.value.filter((tag) => tag.name.toLocaleLowerCase('es').includes(needle));
});

async function load() {
    loading.value = true;
    error.value = null;

    try {
        all.value = (await api.get('/tags')).data ?? [];
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        error.value = e;
        all.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const creating = ref(false);
const form = ref({ name: '' });

const save = useApiForm(async () => {
    await api.post('/tags', { name: form.value.name });
});

const remove = useApiForm(async (tag) => {
    await api.delete(`/tags/${tag.ulid}`);
});

async function submit() {
    if (await save.submit()) {
        creating.value = false;
        form.value = { name: '' };
        await load();
    }
}

async function confirmRemove(tag) {
    // El mensaje dice el efecto y no sólo la acción: «se quitará de los artículos que la usan» es la
    // consecuencia que el usuario no ve desde esta pantalla.
    if (!window.confirm(`¿Borrar la etiqueta «${tag.name}»? Se quitará de los artículos que la usan.`)) {
        return;
    }

    if (await remove.submit(tag)) {
        await load();
    }
}

const columns = [
    { key: 'name', label: 'Etiqueta' },
    { key: 'actions', label: '', width: '6rem' },
];
</script>

<template>
    <Head title="Etiquetas" />

    <ListHeader
        title="Etiquetas"
        subtitle="Clasificación libre y transversal: un artículo puede llevar varias, y una etiqueta cruza categorías. Es lo que permite marcar «vegetariano» o «promoción» sin tocar la estructura del menú."
        :count="visible.length"
        v-model:search="search"
    >
        <template #view>
            <ViewToggle v-model="view" persist-key="comandia:view:tags" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'catalog.tags.manage'" class="button" type="button" @click="creating = true">
                Nueva etiqueta
            </button>
        </template>
    </ListHeader>

    <p v-if="remove.generalError.value" class="alert">{{ remove.generalError.value }}</p>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="visible"
        :loading="loading"
        :error="error"
        :empty-message="search ? 'Ninguna etiqueta coincide.' : 'Todavía no hay etiquetas.'"
    >
        <template #cell:actions="{ row }">
            <button
                v-can.write="'catalog.tags.manage'"
                class="link-button link-button--danger"
                type="button"
                @click="confirmRemove(row)"
            ><Icon name="trash" /> Borrar</button>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="visible"
        :loading="loading"
        :error="error"
        min-card="11rem"
        :empty-message="search ? 'Ninguna etiqueta coincide.' : 'Todavía no hay etiquetas.'"
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__title">{{ item.name }}</span>
                <div class="card__actions">
                    <button v-can.write="'catalog.tags.manage'" class="link-button link-button--danger" type="button" @click="confirmRemove(item)"><Icon name="trash" /> Borrar</button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <div v-if="creating" class="drawer-backdrop" @click.self="creating = false">
        <form class="drawer" @submit.prevent="submit">
            <h2>Nueva etiqueta</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="40" required placeholder="Vegetariano" />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="creating = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value"><Icon name="check" /> Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';
</style>
