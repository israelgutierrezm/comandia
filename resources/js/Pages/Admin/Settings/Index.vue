<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Configuración del negocio (ARQUITECTURA_MAESTRA §5).
 *
 * La pantalla se **autoconfigura** desde lo que devuelve la API: cada llave trae su tipo, sus valores
 * permitidos y su nivel máximo de override, así que el control se elige a partir del tipo y no de una
 * tabla en el frontend. Agregar una llave nueva al catálogo la hace aparecer aquí sin tocar este
 * archivo — es la idea de ADR-006 aplicada a la configuración.
 *
 * Se distingue "hereda" de "configurado": si el default del sistema cambia en una versión futura, una
 * llave que hereda sigue el nuevo valor y una con override no, y el usuario tiene que poder verlo.
 */
const settings = ref([]);
const branches = ref([]);
const scope = ref('tenant');
const branchUlid = ref('');
const loading = ref(false);
const error = ref(null);
const saving = ref(null);

/**
 * Agrupa por módulo conservando la etiqueta en español que manda la API.
 *
 * La etiqueta NO se traduce aquí: el identificador del módulo es código —inglés— y su nombre para
 * mostrar vive en el registro de módulos del backend. Traducirlo en el frontend obligaría a tocar
 * este archivo cada vez que se agrega una llave de configuración, que es exactamente lo que el
 * diseño autoconfigurable evita.
 */
const grouped = computed(() => {
    const groups = {};

    for (const setting of settings.value) {
        groups[setting.module] ??= { label: setting.module_label ?? setting.module, items: [] };
        groups[setting.module].items.push(setting);
    }

    return groups;
});

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const path = scope.value === 'branch' && branchUlid.value
            ? `/branches/${branchUlid.value}/settings`
            : '/settings';

        settings.value = (await api.get(path)).data;
    } catch (e) {
        if (!(e instanceof ApiError)) throw e;
        error.value = e;
        settings.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    branches.value = (await api.get('/branches', { status: 'active', per_page: 100 })).data;
    branchUlid.value = branches.value[0]?.ulid ?? '';
    await load();
});

// El ámbito es lo que decide QUÉ configuración se ve; por eso vive tras «Filtros». Cuenta como puesto cuando no es el
// valor por omisión (todo el negocio).
const filtrosActivos = computed(() => (scope.value === 'branch' ? 1 : 0));
function limpiarFiltros() {
    scope.value = 'tenant';
    load();
}

function basePath() {
    return scope.value === 'branch' ? `/branches/${branchUlid.value}/settings` : '/settings';
}

async function save(setting, value) {
    saving.value = setting.key;
    error.value = null;

    try {
        await api.put(`${basePath()}/${setting.key}`, { value });
        await load();
    } catch (e) {
        if (!(e instanceof ApiError)) throw e;
        error.value = e;
    } finally {
        saving.value = null;
    }
}

/**
 * Un valor tal como debe LEERSE, no como se guarda.
 *
 * La insignia de herencia mostraba el valor crudo: «Hereda (branch_default)», «Hereda (false)». El
 * control de al lado ya venía traducido, así que la misma llave se leía de dos formas distintas en
 * la misma fila.
 */
function displayValue(setting, value) {
    if (setting.type === 'bool') {
        return value === true ? 'Activado' : 'Desactivado';
    }

    if (setting.type === 'enum') {
        const option = (setting.allowed_options ?? []).find((o) => o.value === value);

        return option?.label ?? value;
    }

    return value;
}

/** Quita el override para que la llave vuelva a heredar del nivel superior. */
async function reset(setting) {
    saving.value = setting.key;

    try {
        await api.delete(`${basePath()}/${setting.key}`);
        await load();
    } catch (e) {
        if (!(e instanceof ApiError)) throw e;
        error.value = e;
    } finally {
        saving.value = null;
    }
}
</script>

<template>
    <Head title="Configuración" />

    <ListHeader
        title="Configuración"
        subtitle="Lo que no está configurado hereda: del negocio si estás en una sucursal, y del valor por omisión del sistema si estás en el negocio."
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="scope" class="input input--select" @change="load">
                <option value="tenant">Todo el negocio</option>
                <option value="branch">Una sucursal</option>
            </select>

            <select v-if="scope === 'branch'" v-model="branchUlid" class="input input--select" @change="load">
                <option v-for="branch in branches" :key="branch.ulid" :value="branch.ulid">
                    {{ branch.name }}
                </option>
            </select>
        </template>
    </ListHeader>

    <p v-if="error" class="alert">
        {{ error.isForbidden ? 'No tienes permiso para ver la configuración.' : error.message }}
    </p>

    <template v-if="loading"></template>

    <div class="ajustes-grid">
    <section v-for="(group, module) in grouped" :key="module" class="card">
        <h2>{{ group.label }}</h2>

        <div v-for="setting in group.items" :key="setting.key" class="setting">
            <div class="setting__info">
                <p class="setting__desc">{{ setting.description }}</p>
                <code class="setting__key">{{ setting.key }}</code>

                <span v-if="!setting.is_overridden" class="badge badge--off">
                    Hereda ({{ displayValue(setting, setting.inherited_value) }})
                </span>
                <span v-else class="badge badge--warn">Configurado aquí</span>
            </div>

            <div class="setting__control">
                <!-- El control se elige por el TIPO que declara la API, no por una tabla local. -->
                <label v-if="setting.type === 'bool'" class="switch">
                    <input
                        type="checkbox"
                        :checked="setting.value === true"
                        :disabled="saving === setting.key"
                        @change="save(setting, $event.target.checked)"
                    />
                    <span>{{ setting.value ? 'Activado' : 'Desactivado' }}</span>
                </label>

                <select
                    v-else-if="setting.type === 'enum'"
                    class="input"
                    :value="setting.value"
                    :disabled="saving === setting.key"
                    @change="save(setting, $event.target.value)"
                >
                    <!-- El valor viaja como identificador y la etiqueta sólo se muestra: lo que se
                         guarda es `option.value`, nunca el texto. -->
                    <option
                        v-for="option in setting.allowed_options ?? []"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>

                <input
                    v-else
                    class="input input--narrow"
                    :type="setting.type === 'string' ? 'text' : 'number'"
                    :step="setting.type === 'decimal' ? '0.01' : '1'"
                    :value="setting.value"
                    :disabled="saving === setting.key"
                    @change="save(setting, setting.type === 'int' ? Number($event.target.value) : $event.target.value)"
                />

                <button
                    v-if="setting.is_overridden"
                    class="link-button"
                    type="button"
                    :disabled="saving === setting.key"
                    @click="reset(setting)"
                ><Icon name="undo" /> Restaurar</button>
            </div>
        </div>
    </section>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

/* Grupos de ajustes en dos columnas para aprovechar el ancho y no quedar en una tira larga. */
.ajustes-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; align-items: start; }
@media (max-width: 60rem) { .ajustes-grid { grid-template-columns: 1fr; } }

.card {
    background: #fff;
    border: 1px solid #e7e5e4;
    border-radius: var(--radio);
    padding: 1.25rem;
}

.card h2 {
    margin: 0 0 0.9rem;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.6;
}

.setting {
    display: flex;
    align-items: flex-start;
    gap: 1.5rem;
    padding: 0.85rem 0;
    border-top: 1px solid var(--color-borde);
}

.setting:first-of-type {
    border-top: 0;
    padding-top: 0;
}

.setting__info {
    flex: 1;
    min-width: 0;
}

.setting__desc {
    margin: 0 0 0.2rem;
    font-size: 0.9rem;
}

.setting__key {
    display: inline-block;
    margin-right: 0.5rem;
    font-size: 0.75rem;
    color: #78716c;
}

.setting__control {
    flex: none;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.input--narrow {
    width: 7rem;
}

.switch {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.85rem;
}

.muted {
    font-size: 0.9rem;
    color: #78716c;
}
</style>
