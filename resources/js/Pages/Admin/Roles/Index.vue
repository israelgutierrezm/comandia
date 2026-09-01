<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import Paginacion from '../../../components/Paginacion.vue';

const view = ref('list');

/**
 * Roles del negocio (D10).
 *
 * El tenant **combina** permisos del catálogo del sistema; no los inventa. Por eso la lista de
 * permisos disponibles se pide a la API —que la devuelve agrupada por módulo y filtrada por los
 * módulos contratados— en lugar de escribirla aquí: ofrecerle permisos de e-commerce a quien no lo
 * tiene contratado le haría armar un rol que no funciona.
 *
 * El rol *Propietario* es de sistema: la UI ni ofrece editarlo, porque "todos los permisos" es su
 * definición y no su configuración.
 */
const list = useResourceList('/roles');
const catalog = ref({});

/**
 * Etiquetas en español de los módulos, para agrupar los permisos.
 *
 * Las manda la API junto al catálogo. El identificador del módulo es inglés porque es código, y sin
 * estas etiquetas el editor agrupaba los permisos bajo `Pos` y `Costing` en crudo.
 */
const moduleLabels = ref({});

onMounted(async () => {
    await list.load();

    const respuesta = await api.get('/permissions');

    catalog.value = respuesta.data;
    moduleLabels.value = respuesta.modules ?? {};
});

const editing = ref(null);
const form = ref({ name: '', description: '', permissions: [] });

const save = useApiForm(async () => {
    const payload = {
        name: form.value.name,
        description: form.value.description,
        permissions: form.value.permissions,
    };

    if (editing.value === 'new') {
        await api.post('/roles', payload);
    } else {
        await api.patch(`/roles/${editing.value.ulid}`, payload);
    }
});

const remove = useApiForm(async (role) => {
    await api.delete(`/roles/${role.ulid}`);
});

function startCreate() {
    editing.value = 'new';
    form.value = { name: '', description: '', permissions: [] };
}

function startEdit(role) {
    editing.value = role;
    form.value = {
        name: role.name,
        description: role.description ?? '',
        permissions: (role.permissions ?? []).map((p) => p.name),
    };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

async function confirmRemove(role) {
    if (!window.confirm(`¿Eliminar el rol «${role.name}»?`)) {
        return;
    }

    // Puede fallar con 409 si alguien lo tiene asignado: dejarlas sin rol las dejaría sin poder
    // operar, y el descubrimiento llegaría en hora pico.
    if (await remove.submit(role)) {
        await list.load();
    }
}

const columns = [
    { key: 'name', label: 'Rol' },
    { key: 'description', label: 'Para qué sirve' },
    { key: 'permissions_count', label: 'Permisos', width: '7rem' },
    { key: 'members', label: 'Personas', width: '7rem' },
    { key: 'actions', label: '', width: '9rem' },
];

/**
 * Cuántos permisos se pueden asignar en ESTE negocio.
 *
 * No es el tamaño del catálogo completo: `/permissions` viene filtrado por los módulos contratados,
 * porque ofrecer permisos de comercio electrónico a quien no lo tiene contratado le haría armar un
 * rol que no funciona.
 *
 * Por eso este número sólo se muestra en el editor y NO como denominador de la lista. La primera
 * versión pintaba «121 de 116»: el numerador contaba todos los permisos del rol —Gerente se
 * construye por sustracción y conserva los de módulos no contratados— y el denominador sólo los
 * asignables. Dos universos distintos en una misma fracción es una cifra falsa, y encima se veía
 * como un error de cálculo.
 */
const assignablePermissions = computed(() =>
    Object.values(catalog.value).reduce((total, group) => total + group.length, 0),
);

function toggle(permission) {
    const index = form.value.permissions.indexOf(permission);

    if (index === -1) {
        form.value.permissions.push(permission);
    } else {
        form.value.permissions.splice(index, 1);
    }
}
</script>

<template>
    <Head title="Roles" />

    <header class="page-header">
        <div>
            <h1>Roles</h1>
            <p class="page-header__hint">
                Un rol es una combinación de permisos del catálogo del sistema. Recuerda que cada
                persona opera bajo <strong>un rol a la vez</strong>: sus permisos no se suman.
            </p>
        </div>

        <button v-can.write="'identity.roles.create'" class="button" type="button" @click="startCreate">
            Nuevo rol
        </button>
    </header>

    <p v-if="remove.generalError.value" class="alert">{{ remove.generalError.value }}</p>

    <div class="toolbar">
        <ViewToggle v-model="view" persist-key="comandia:view:roles" class="toolbar__view" />
    </div>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
    >
        <template #cell:name="{ row }">
            {{ row.name }}
            <span v-if="row.is_system" class="badge badge--warn">De sistema</span>
        </template>

        <template #cell:permissions_count="{ row }">
            {{ (row.permissions ?? []).length }}
        </template>

        <template #cell:members="{ row }">
            {{ row.members_count ?? 0 }}
        </template>

        <template #cell:actions="{ row }">
            <div v-if="!row.is_system" class="row-actions">
                <button v-can.write="'identity.roles.update'" class="link-button" type="button" @click="startEdit(row)">
                    Editar
                </button>
                <button
                    v-can.write="'identity.roles.delete'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="confirmRemove(row)"
                >
                    Eliminar
                </button>
            </div>
            <span v-else class="muted">No editable</span>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay roles."
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__title">
                    {{ item.name }}
                    <span v-if="item.is_system" class="badge badge--warn">De sistema</span>
                </span>
                <span v-if="item.description" class="card__meta">{{ item.description }}</span>
                <span class="card__foot">
                    <span class="card__meta">{{ (item.permissions ?? []).length }} permisos</span>
                    <span class="card__meta">· {{ item.members_count ?? 0 }} personas</span>
                </span>
                <div v-if="!item.is_system" class="card__actions">
                    <button v-can.write="'identity.roles.update'" class="link-button" type="button" @click="startEdit(item)">
                        Editar
                    </button>
                    <button v-can.write="'identity.roles.delete'" class="link-button link-button--danger" type="button" @click="confirmRemove(item)">
                        Eliminar
                    </button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="roles" />

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer drawer--wide" @submit.prevent="submit">
            <h2>{{ editing === 'new' ? 'Nuevo rol' : `Editar ${editing.name}` }}</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="80" required />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <label class="field">
                <span class="field__label">Para qué sirve</span>
                <input v-model="form.description" class="input" maxlength="160" />
            </label>

            <p class="field__label">
                Permisos: {{ form.permissions.length }} de {{ assignablePermissions }} asignables en
                este negocio
            </p>

            <div v-for="(group, module) in catalog" :key="module" class="perm-group">
                <p class="perm-group__title">{{ moduleLabels[module] ?? module }}</p>

                <label v-for="permission in group" :key="permission.name" class="perm">
                    <input
                        type="checkbox"
                        :checked="form.permissions.includes(permission.name)"
                        @change="toggle(permission.name)"
                    />
                    <span>
                        {{ permission.description }}
                        <code>{{ permission.name }}</code>
                    </span>
                </label>
            </div>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.drawer--wide {
    width: min(34rem, 100%);
}

.perm-group {
    margin-bottom: 1rem;
}

.perm-group__title {
    margin: 0 0 0.35rem;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.55;
}

.perm {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.2rem 0;
    font-size: 0.85rem;
}

.perm code {
    display: block;
    font-size: 0.72rem;
    color: #a8a29e;
}

.muted {
    color: #a8a29e;
    font-size: 0.85rem;
}
</style>
