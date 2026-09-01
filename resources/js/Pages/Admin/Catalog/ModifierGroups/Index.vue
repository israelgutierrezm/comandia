<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import { useReorder } from '../../../../composables/useReorder';
import FilterBar from '../../../../components/FilterBar.vue';

/**
 * Grupos de modificadores (D7).
 *
 * ## Por qué se administran aquí y no dentro del artículo
 *
 * Un grupo es del negocio y se REUTILIZA: «Término de la carne» lo usan los ocho cortes de la carta.
 * Editarlo desde la ficha de un corte esconde que se están cambiando los ocho, y ésa es exactamente
 * la información que hace falta antes de guardar. Desde el artículo se ASIGNAN grupos ya existentes
 * y se ordenan; se definen aquí.
 *
 * ## Las reglas de selección no son adorno
 *
 * `is_required`, el mínimo, el máximo y la captura por cantidad son lo que el punto de venta evalúa
 * antes de mandar la comanda. Un grupo obligatorio con mínimo cero es una contradicción que el
 * servidor rechaza, y la pantalla lo dice antes de intentarlo — pero la que decide es la validación
 * del servidor, no esta advertencia.
 */
const list = useResourceList('/modifier-groups', { initialFilters: { status: 'active' } });

const filtrosActivos = computed(() => (list.filters.status !== 'active' ? 1 : 0));
function limpiarFiltros() {
    list.filters.status = 'active';
}

onMounted(list.load);

/** Grupo cuyas opciones se están viendo; `null` = ninguno desplegado. */
const expanded = ref(null);

function toggle(group) {
    expanded.value = expanded.value === group.ulid ? null : group.ulid;
}

// ---- Grupo ----

const editingGroup = ref(null);
const groupForm = ref({});

const saveGroup = useApiForm(async () => {
    const body = {
        name: groupForm.value.name,
        is_required: groupForm.value.is_required,
        min_selections: Number(groupForm.value.min_selections || 0),

        // Cadena vacía = sin límite. Se manda `null` explícito y no se omite el campo: omitirlo en un
        // PATCH significaría «no lo cambies», que es lo contrario de «quítale el límite».
        max_selections: groupForm.value.max_selections === '' ? null : Number(groupForm.value.max_selections),
        allows_quantity: groupForm.value.allows_quantity,
    };

    if (editingGroup.value === 'new') {
        await api.post('/modifier-groups', body);
    } else {
        await api.patch(`/modifier-groups/${editingGroup.value.ulid}`, {
            ...body,
            status: groupForm.value.status,
        });
    }
});

const archiveGroup = useApiForm(async (group) => {
    await api.post(`/modifier-groups/${group.ulid}/archive`);
});

function startCreateGroup() {
    editingGroup.value = 'new';
    groupForm.value = {
        name: '',
        is_required: false,
        min_selections: '0',
        max_selections: '',
        allows_quantity: false,
    };
}

function startEditGroup(group) {
    editingGroup.value = group;
    groupForm.value = {
        name: group.name,
        is_required: group.is_required,
        min_selections: String(group.min_selections),
        max_selections: group.max_selections === null ? '' : String(group.max_selections),
        allows_quantity: group.allows_quantity,
        status: group.status,
    };
}

/**
 * La contradicción más fácil de cometer, dicha antes de que el servidor la rechace: obligatorio con
 * mínimo cero no obliga a nada.
 */
const groupWarning = computed(() => {
    const min = Number(groupForm.value.min_selections || 0);
    const max = groupForm.value.max_selections === '' ? null : Number(groupForm.value.max_selections);

    if (groupForm.value.is_required && min < 1) {
        return 'Un grupo obligatorio necesita al menos un mínimo de 1: con mínimo 0 no obliga a nada.';
    }

    if (max !== null && max < min) {
        return 'El máximo no puede ser menor que el mínimo.';
    }

    return null;
});

async function submitGroup() {
    if (await saveGroup.submit()) {
        editingGroup.value = null;
        await list.load();
    }
}

async function confirmArchiveGroup(group) {
    if (!window.confirm(`¿Dar de baja el grupo «${group.name}»? Dejará de ofrecerse en el punto de venta.`)) {
        return;
    }

    if (await archiveGroup.submit(group)) {
        await list.load();
    }
}

// ---- Opciones del grupo ----

/** `{ group, modifier }`; `modifier === null` = nueva opción. */
const editingModifier = ref(null);
const modifierForm = ref({});

const saveModifier = useApiForm(async () => {
    const body = {
        name: modifierForm.value.name,
        extra_price: modifierForm.value.extra_price === '' ? '0' : modifierForm.value.extra_price,
        sort_order: Number(modifierForm.value.sort_order || 0),
    };

    if (editingModifier.value.modifier === null) {
        await api.post(`/modifier-groups/${editingModifier.value.group.ulid}/modifiers`, body);
    } else {
        await api.patch(`/modifiers/${editingModifier.value.modifier.ulid}`, {
            ...body,
            status: modifierForm.value.status,
        });
    }
});

const archiveModifier = useApiForm(async (modifier) => {
    await api.post(`/modifiers/${modifier.ulid}/archive`);
});

function startCreateModifier(group) {
    editingModifier.value = { group, modifier: null };
    modifierForm.value = {
        name: '',
        extra_price: '0.00',
        sort_order: String(group.modifiers?.length ?? 0),
    };
}

function startEditModifier(group, modifier) {
    editingModifier.value = { group, modifier };
    modifierForm.value = {
        name: modifier.name,
        extra_price: modifier.extra_price,
        sort_order: String(modifier.sort_order),
        status: modifier.status,
    };
}

async function submitModifier() {
    if (await saveModifier.submit()) {
        editingModifier.value = null;
        await list.load();
    }
}

async function confirmArchiveModifier(modifier) {
    if (!window.confirm(`¿Dar de baja la opción «${modifier.name}»?`)) {
        return;
    }

    if (await archiveModifier.submit(modifier)) {
        await list.load();
    }
}

// Reordenar las opciones de un grupo arrastrando. El orden es el que ve el mesero al elegir.
const dragOpt = useReorder();
const reorderError = ref(null);

/** Las opciones del grupo por su `sort_order`, para que arrastrar tenga sentido. */
function opciones(group) {
    return [...(group.modifiers ?? [])].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
}

async function soltarOpcion(group, index) {
    const nuevas = dragOpt.reorder(index, opciones(group));

    if (! nuevas) {
        return;
    }

    reorderError.value = null;

    try {
        const cambios = nuevas
            .map((m, i) => ({ ulid: m.ulid, sort_order: (i + 1) * 10, antes: Number(m.sort_order ?? 0) }))
            .filter((c) => c.sort_order !== c.antes);

        await Promise.all(cambios.map((c) => api.patch(`/modifiers/${c.ulid}`, { sort_order: c.sort_order })));
        await list.load();
    } catch (e) {
        if (e instanceof ApiError) {
            reorderError.value = e.title;
        } else {
            throw e;
        }
    }
}

/** «Elige 1», «Elige de 1 a 3», «Elige los que quieras»: la regla en la frase que el mesero oye. */
function ruleLabel(group) {
    const min = group.min_selections;
    const max = group.max_selections;

    if (!group.has_selection_limit) {
        return min > 0 ? `Al menos ${min}, sin tope` : 'Los que quiera, sin tope';
    }

    if (min === max) {
        return `Exactamente ${min}`;
    }

    return `De ${min} a ${max}`;
}
</script>

<template>
    <Head title="Modificadores" />

    <header class="page-header">
        <div>
            <h1>Grupos de modificadores</h1>
            <p class="page-header__hint">
                Un grupo se <strong>reutiliza entre artículos</strong>: cambiar «Término de la carne»
                cambia los cortes que lo usan. Desde la ficha del artículo se asignan y se ordenan;
                aquí se definen.
            </p>
        </div>

        <button v-can.write="'catalog.modifiers.manage'" class="button" type="button" @click="startCreateGroup">
            Nuevo grupo
        </button>
    </header>

    <FilterBar
        v-model:search="list.filters.search"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.status" class="input input--select">
                <option value="active">Activos</option>
                <option value="inactive">Dados de baja</option>
                <option value="">Todos</option>
            </select>
        </template>
    </FilterBar>

    <p v-if="archiveGroup.generalError.value" class="alert">{{ archiveGroup.generalError.value }}</p>
    <p v-if="archiveModifier.generalError.value" class="alert">{{ archiveModifier.generalError.value }}</p>
    <p v-if="reorderError" class="alert">{{ reorderError }}</p>

    <div v-if="list.error.value" class="card card--error">
        <p v-if="list.error.value.isForbidden">No tienes permiso para ver los modificadores.</p>
        <p v-else>{{ list.error.value.message }}</p>
    </div>

    <p v-else-if="list.loading.value" class="card card--quiet">Cargando…</p>

    <p v-else-if="list.items.value.length === 0" class="card card--quiet">
        No hay grupos que coincidan.
    </p>

    <div v-else class="groups">
        <article v-for="group in list.items.value" :key="group.ulid" class="group">
            <header class="group__head">
                <button class="group__toggle" type="button" @click="toggle(group)">
                    <span class="group__caret">{{ expanded === group.ulid ? '▾' : '▸' }}</span>
                    <span class="group__name">{{ group.name }}</span>
                </button>

                <span class="badge" :class="group.is_required ? 'badge--warn' : 'badge--off'">
                    {{ group.is_required ? 'Obligatorio' : 'Opcional' }}
                </span>

                <span class="group__rule">{{ ruleLabel(group) }}</span>

                <span v-if="group.allows_quantity" class="badge badge--off">Por cantidad</span>
                <span v-if="group.status !== 'active'" class="badge badge--off">Baja</span>

                <span class="group__count">
                    {{ group.modifiers?.length ?? 0 }}
                    {{ (group.modifiers?.length ?? 0) === 1 ? 'opción' : 'opciones' }}
                </span>

                <span class="group__actions">
                    <button
                        v-can.write="'catalog.modifiers.manage'"
                        class="link-button"
                        type="button"
                        @click="startEditGroup(group)"
                    >
                        Editar
                    </button>
                    <button
                        v-if="group.status === 'active'"
                        v-can.write="'catalog.modifiers.manage'"
                        class="link-button link-button--danger"
                        type="button"
                        @click="confirmArchiveGroup(group)"
                    >
                        Dar de baja
                    </button>
                </span>
            </header>

            <div v-if="expanded === group.ulid" class="group__body">
                <table v-if="group.modifiers?.length" class="options">
                    <thead>
                        <tr>
                            <th class="opt-handle" aria-label="Orden"></th>
                            <th>Opción</th>
                            <th style="width: 8rem">Precio extra</th>
                            <th style="width: 5rem">Orden</th>
                            <th style="width: 6rem">Estado</th>
                            <th style="width: 10rem"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(modifier, i) in opciones(group)"
                            :key="modifier.ulid"
                            :class="{ 'opt--over': dragOpt.over.value === i && dragOpt.from.value !== i }"
                            @dragover.prevent="dragOpt.enter(i)"
                            @drop="soltarOpcion(group, i)"
                            @dragend="dragOpt.end()"
                        >
                            <td class="opt-handle">
                                <span
                                    class="opt-drag"
                                    draggable="true"
                                    title="Arrastra para reordenar"
                                    aria-label="Arrastra para reordenar"
                                    @dragstart="dragOpt.start(i)"
                                >
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor">
                                        <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
                                        <circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" />
                                        <circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" />
                                    </svg>
                                </span>
                            </td>
                            <td>{{ modifier.name }}</td>
                            <td>
                                <!--
                                    Un modificador sin precio es gratis, y decirlo con la palabra evita
                                    que «0.00» se lea como un dato faltante.
                                -->
                                <span v-if="modifier.is_paid" class="money">${{ modifier.extra_price }}</span>
                                <span v-else class="muted">Sin costo</span>
                            </td>
                            <td class="muted">{{ modifier.sort_order }}</td>
                            <td>
                                <span class="badge" :class="modifier.status === 'active' ? 'badge--ok' : 'badge--off'">
                                    {{ modifier.status === 'active' ? 'Activa' : 'Baja' }}
                                </span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button
                                        v-can.write="'catalog.modifiers.manage'"
                                        class="link-button"
                                        type="button"
                                        @click="startEditModifier(group, modifier)"
                                    >
                                        Editar
                                    </button>
                                    <button
                                        v-if="modifier.status === 'active'"
                                        v-can.write="'catalog.modifiers.manage'"
                                        class="link-button link-button--danger"
                                        type="button"
                                        @click="confirmArchiveModifier(modifier)"
                                    >
                                        Baja
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="muted">
                    Este grupo no tiene opciones todavía, así que el punto de venta no puede ofrecerlo.
                </p>

                <p class="hint">
                    Una opción puede tener <strong>receta propia</strong> —«extra queso» consume 30 g de
                    queso— y se captura desde la ficha del artículo, en el panel de modificadores.
                </p>

                <button
                    v-can.write="'catalog.modifiers.manage'"
                    class="link-button"
                    type="button"
                    @click="startCreateModifier(group)"
                >
                    + Agregar opción
                </button>
            </div>
        </article>
    </div>

    <!-- ---- Formulario de grupo ---- -->
    <div v-if="editingGroup" class="drawer-backdrop" @click.self="editingGroup = null">
        <form class="drawer" @submit.prevent="submitGroup">
            <h2>{{ editingGroup === 'new' ? 'Nuevo grupo' : `Editar ${editingGroup.name}` }}</h2>

            <p v-if="saveGroup.generalError.value" class="alert">{{ saveGroup.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="groupForm.name" class="input" maxlength="80" required placeholder="Término de la carne" />
                <span v-if="saveGroup.fieldErrors.value.name" class="field__error">
                    {{ saveGroup.fieldErrors.value.name }}
                </span>
            </label>

            <label class="field field--check">
                <input v-model="groupForm.is_required" type="checkbox" />
                <span>
                    <span class="field__label">Obligatorio</span>
                    <span class="field__hint">
                        El punto de venta no deja mandar la comanda sin elegir.
                    </span>
                </span>
            </label>

            <div class="pair">
                <label class="field">
                    <span class="field__label">Mínimo</span>
                    <input v-model="groupForm.min_selections" class="input" inputmode="numeric" />
                    <span v-if="saveGroup.fieldErrors.value.min_selections" class="field__error">
                        {{ saveGroup.fieldErrors.value.min_selections }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Máximo</span>
                    <input v-model="groupForm.max_selections" class="input" inputmode="numeric" placeholder="Sin tope" />
                    <span class="field__hint">Vacío = sin tope.</span>
                    <span v-if="saveGroup.fieldErrors.value.max_selections" class="field__error">
                        {{ saveGroup.fieldErrors.value.max_selections }}
                    </span>
                </label>
            </div>

            <label class="field field--check">
                <input v-model="groupForm.allows_quantity" type="checkbox" />
                <span>
                    <span class="field__label">Se captura por cantidad</span>
                    <span class="field__hint">
                        Para «doble queso» sin crear una opción por cada múltiplo.
                    </span>
                </span>
            </label>

            <label v-if="editingGroup !== 'new'" class="field">
                <span class="field__label">Estado</span>
                <select v-model="groupForm.status" class="input">
                    <option value="active">Activo</option>
                    <option value="inactive">Dado de baja</option>
                </select>
            </label>

            <p v-if="groupWarning" class="alert alert--soft">{{ groupWarning }}</p>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editingGroup = null">Cancelar</button>
                <button type="submit" class="button" :disabled="saveGroup.processing.value">Guardar</button>
            </div>
        </form>
    </div>

    <!-- ---- Formulario de opción ---- -->
    <div v-if="editingModifier" class="drawer-backdrop" @click.self="editingModifier = null">
        <form class="drawer" @submit.prevent="submitModifier">
            <h2>
                {{ editingModifier.modifier === null ? 'Nueva opción' : `Editar ${editingModifier.modifier.name}` }}
            </h2>
            <p class="drawer__sub">en {{ editingModifier.group.name }}</p>

            <p v-if="saveModifier.generalError.value" class="alert">{{ saveModifier.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="modifierForm.name" class="input" maxlength="80" required placeholder="Término medio" />
                <span v-if="saveModifier.fieldErrors.value.name" class="field__error">
                    {{ saveModifier.fieldErrors.value.name }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Precio adicional</span>
                <input v-model="modifierForm.extra_price" class="input" inputmode="decimal" />
                <span class="field__hint">
                    Cero si no cuesta más. No admite negativos: para restar están los descuentos, que
                    piden autorización y dejan rastro.
                </span>
                <span v-if="saveModifier.fieldErrors.value.extra_price" class="field__error">
                    {{ saveModifier.fieldErrors.value.extra_price }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Orden</span>
                <input v-model="modifierForm.sort_order" class="input" inputmode="numeric" />
            </label>

            <label v-if="editingModifier.modifier !== null" class="field">
                <span class="field__label">Estado</span>
                <select v-model="modifierForm.status" class="input">
                    <option value="active">Activa</option>
                    <option value="inactive">Dada de baja</option>
                </select>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editingModifier = null">Cancelar</button>
                <button type="submit" class="button" :disabled="saveModifier.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.card {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    padding: 1.25rem;
    margin: 0;
    color: var(--color-suave);
}

.card--quiet {
    opacity: 0.85;
}

.card--error {
    border-color: color-mix(in srgb, var(--color-peligro) 35%, transparent);
    color: var(--color-peligro);
}

.groups {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.group {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
}

.group__head {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.6rem 0.85rem;
    flex-wrap: wrap;
}

.group__toggle {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    background: none;
    border: 0;
    padding: 0;
    font: inherit;
    cursor: pointer;
}

.group__caret {
    font-size: 0.7rem;
    opacity: 0.5;
    width: 0.8rem;
}

.group__name {
    font-weight: 600;
}

.group__rule,
.group__count {
    font-size: 0.75rem;
    opacity: 0.55;
}

.group__actions {
    margin-left: auto;
    display: flex;
    gap: 0.75rem;
}

.group__body {
    padding: 0 0.85rem 0.85rem 2.2rem;
    border-top: 1px solid var(--color-borde);
}

.options {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    margin: 0.6rem 0;
}

.options th {
    text-align: left;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.5;
    padding: 0.3rem 0.4rem;
}

.options td {
    padding: 0.35rem 0.4rem;
    border-top: 1px solid var(--color-borde);
}

.money {
    font-variant-numeric: tabular-nums;
}

/* Reordenar opciones arrastrando. */
.opt-handle { width: 1.8rem; padding-right: 0 !important; }
.opt-drag {
    display: inline-grid; place-items: center; color: var(--color-suave);
    cursor: grab; border-radius: 0.3rem; padding: 0.1rem;
}
.opt-drag:hover { color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
.opt-drag:active { cursor: grabbing; }
.opt--over td { box-shadow: inset 0 2px 0 var(--color-acento); }

.muted {
    opacity: 0.5;
}

.hint {
    margin: 0.5rem 0;
    font-size: 0.8rem;
    opacity: 0.6;
}

.pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.field--check {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.field--check input {
    margin-top: 0.2rem;
}

.alert--soft {
    background: var(--color-aviso-tenue);
    border-color: color-mix(in srgb, var(--color-aviso) 35%, transparent);
    color: var(--color-aviso);
}

.drawer__sub {
    margin: -0.5rem 0 0.75rem;
    font-size: 0.8rem;
    opacity: 0.6;
}
</style>
