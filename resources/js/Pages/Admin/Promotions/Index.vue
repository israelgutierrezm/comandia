<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import Paginacion from '../../../components/Paginacion.vue';
import ListHeader from '../../../components/ListHeader.vue';

/**
 * Promociones (§6.3, D50).
 *
 * ## El formulario cambia con el TIPO, porque cada tipo pide otra cosa
 *
 * Un porcentaje pide un porcentaje; un NxM, cuántas se compran y cuántas se pagan; un precio especial, el precio. Un solo
 * formulario con todos los campos a la vez haría que quien crea una promoción tuviera que saber cuáles ignorar.
 *
 * ## El backend decide; esto sólo define
 *
 * Aquí se declara la promoción. Aplicarla —«mejor gana», la ventana horaria, el monto— lo hace el motor al cobrar. Esta
 * pantalla no calcula ningún descuento.
 */
const list = useResourceList('/promotions', { initialFilters: { status: '', type: '' } });

const filtrosActivos = computed(
    () => [list.filters.type !== '', list.filters.status !== ''].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.type = '';
    list.filters.status = '';
}
const view = ref('list');

const categories = ref([]);
const articles = ref([]);
const branches = ref([]);

const DIAS = [
    { v: 0, l: 'Dom' }, { v: 1, l: 'Lun' }, { v: 2, l: 'Mar' }, { v: 3, l: 'Mié' },
    { v: 4, l: 'Jue' }, { v: 5, l: 'Vie' }, { v: 6, l: 'Sáb' },
];

onMounted(async () => {
    await list.load();

    const [cats, arts, sucs] = await Promise.all([
        api.get('/article-categories', { per_page: 200 }),
        api.get('/articles', { available_in_pos: 1, status: 'active', per_page: 200 }),
        api.get('/branches', { status: 'active', per_page: 100 }),
    ]);

    categories.value = cats.data;
    articles.value = arts.data;
    branches.value = sucs.data;
});

const editing = ref(null);
const form = ref({});

function nuevaForma() {
    return {
        name: '',
        type: 'percentage',
        percent_value: '',
        amount_value: '',
        buy_quantity: 2,
        pay_quantity: 1,
        starts_on: '',
        ends_on: '',
        daily_start: '',
        daily_end: '',
        weekdays: [0, 1, 2, 3, 4, 5, 6],
        all_branches: true,
        branch_ulids: [],
        targets: [],
        priority: 0,
        is_stackable: false,
        status: 'active',
    };
}

function startCreate() {
    editing.value = 'new';
    form.value = nuevaForma();
}

const catByUlid = computed(() => Object.fromEntries(categories.value.map((c) => [c.ulid, c.name])));
const artByUlid = computed(() => Object.fromEntries(articles.value.map((a) => [a.ulid, a.name])));

/** Objetivos: se elige una categoría o un artículo y se agrega a la lista. */
const targetCategory = ref('');
const targetArticle = ref('');

function addCategoryTarget() {
    if (targetCategory.value && ! form.value.targets.some((t) => t.category_ulid === targetCategory.value)) {
        form.value.targets.push({ category_ulid: targetCategory.value });
    }
    targetCategory.value = '';
}

function addArticleTarget() {
    if (targetArticle.value && ! form.value.targets.some((t) => t.article_ulid === targetArticle.value)) {
        form.value.targets.push({ article_ulid: targetArticle.value });
    }
    targetArticle.value = '';
}

function removeTarget(i) {
    form.value.targets.splice(i, 1);
}

function targetLabel(t) {
    return t.category_ulid
        ? `Categoría: ${catByUlid.value[t.category_ulid] ?? '—'}`
        : `Artículo: ${artByUlid.value[t.article_ulid] ?? '—'}`;
}

function toggleWeekday(v) {
    const i = form.value.weekdays.indexOf(v);
    i === -1 ? form.value.weekdays.push(v) : form.value.weekdays.splice(i, 1);
}

const save = useApiForm(async () => {
    // Sólo lo que el tipo usa; el backend limpia el resto, pero no mandamos ruido.
    const cuerpo = {
        name: form.value.name,
        type: form.value.type,
        starts_on: form.value.starts_on || null,
        ends_on: form.value.ends_on || null,
        daily_start: form.value.daily_start || null,
        daily_end: form.value.daily_end || null,
        weekdays: form.value.weekdays,
        all_branches: form.value.all_branches,
        branch_ulids: form.value.all_branches ? [] : form.value.branch_ulids,
        targets: form.value.targets,
        priority: Number(form.value.priority) || 0,
        is_stackable: form.value.is_stackable,
        status: form.value.status,
    };

    if (form.value.type === 'percentage') cuerpo.percent_value = form.value.percent_value;
    if (form.value.type === 'amount' || form.value.type === 'special_price') cuerpo.amount_value = form.value.amount_value;
    if (form.value.type === 'nxm') {
        cuerpo.buy_quantity = Number(form.value.buy_quantity);
        cuerpo.pay_quantity = Number(form.value.pay_quantity);
    }

    if (editing.value === 'new') {
        await api.post('/promotions', cuerpo);
    } else {
        cuerpo.version = editing.value.version;
        await api.patch(`/promotions/${editing.value.ulid}`, cuerpo);
    }

    editing.value = null;
    await list.load();
});

async function startEdit(promoUlid) {
    const { data } = await api.get(`/promotions/${promoUlid}`);

    editing.value = data;
    form.value = {
        name: data.name,
        type: data.type,
        percent_value: data.percent_value ?? '',
        amount_value: data.amount_value ?? '',
        buy_quantity: data.buy_quantity ?? 2,
        pay_quantity: data.pay_quantity ?? 1,
        starts_on: data.starts_on ?? '',
        ends_on: data.ends_on ?? '',
        daily_start: data.daily_start ?? '',
        daily_end: data.daily_end ?? '',
        weekdays: data.weekdays ?? [0, 1, 2, 3, 4, 5, 6],
        all_branches: data.all_branches,
        // Ramas y objetivos ya vuelven por ULID (D3): se usan tal cual, sin remapear contra las listas.
        branch_ulids: (data.branches ?? []).map((b) => b.branch_ulid).filter(Boolean),
        targets: (data.targets ?? []).map((t) => (t.article_ulid
            ? { article_ulid: t.article_ulid }
            : { category_ulid: t.category_ulid })).filter((t) => t.article_ulid || t.category_ulid),
        priority: data.priority,
        is_stackable: data.is_stackable,
        status: data.status,
    };
}

const TIPOS = {
    percentage: 'Porcentaje',
    amount: 'Monto fijo',
    nxm: 'Compra N, paga M',
    special_price: 'Precio especial',
};

const columns = [
    { key: 'name', label: 'Promoción' },
    { key: 'type', label: 'Tipo', width: '12rem' },
    { key: 'status', label: 'Estado', width: '8rem' },
    { key: 'actions', label: '', width: '7rem' },
];
</script>

<template>
    <Head title="Promociones" />

    <ListHeader
        title="Promociones"
        subtitle="Se aplican solas al cobrar, según su vigencia y sus reglas. Cuando varias caben, gana la que más descuenta. Los cupones de la tienda en línea llegan con el módulo de e-commerce."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar por nombre…"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.type" class="input input--select">
                <option value="">Todos los tipos</option>
                <option v-for="(label, value) in TIPOS" :key="value" :value="value">{{ label }}</option>
            </select>

            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todas</option>
                <option value="active">Activas</option>
                <option value="inactive">Inactivas</option>
            </select>
        </template>

        <template #view>
            <ViewToggle v-model="view" persist-key="comandia:view:promotions" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'promotions.promotions.manage'" class="button" type="button" @click="startCreate">
                Nueva promoción
            </button>
        </template>
    </ListHeader>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay promociones que coincidan."
    >
        <template #cell:name="{ row }">
            <button class="row-link" type="button" @click="startEdit(row.ulid)">{{ row.name }}</button>
        </template>

        <template #cell:type="{ row }">{{ row.type_label }}</template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ row.status === 'active' ? 'Activa' : 'Inactiva' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <button v-can.write="'promotions.promotions.manage'" class="link-button" type="button" @click="startEdit(row.ulid)">
                Editar
            </button>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay promociones que coincidan."
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__title">{{ item.name }}</span>
                <span class="card__meta">{{ item.type_label }}</span>
                <span class="card__foot">
                    <span class="badge" :class="item.status === 'active' ? 'badge--ok' : 'badge--off'">
                        {{ item.status === 'active' ? 'Activa' : 'Inactiva' }}
                    </span>
                </span>
                <div class="card__actions">
                    <button v-can.write="'promotions.promotions.manage'" class="link-button" type="button" @click="startEdit(item.ulid)">
                        Editar
                    </button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="promociones" />

    <!-- El formulario, como panel lateral: cambia con el tipo elegido. -->
    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer drawer--wide" @submit.prevent="save.submit()">
            <h2>{{ editing === 'new' ? 'Nueva promoción' : 'Editar promoción' }}</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" type="text" required maxlength="120" />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <label class="field">
                <span class="field__label">Tipo</span>
                <select v-model="form.type" class="input">
                    <option v-for="(l, v) in TIPOS" :key="v" :value="v">{{ l }}</option>
                </select>
            </label>

            <label v-if="form.type === 'percentage'" class="field">
                <span class="field__label">Porcentaje (%)</span>
                <input v-model="form.percent_value" class="input" inputmode="decimal" placeholder="10.00" />
            </label>

            <label v-if="form.type === 'amount' || form.type === 'special_price'" class="field">
                <span class="field__label">{{ form.type === 'amount' ? 'Monto a descontar' : 'Precio especial' }}</span>
                <input v-model="form.amount_value" class="input" inputmode="decimal" placeholder="0.00" />
            </label>

            <div v-if="form.type === 'nxm'" class="pair">
                <label class="field">
                    <span class="field__label">Compra</span>
                    <input v-model="form.buy_quantity" class="input" type="number" min="2" />
                </label>
                <label class="field">
                    <span class="field__label">Paga</span>
                    <input v-model="form.pay_quantity" class="input" type="number" min="1" />
                </label>
            </div>

            <fieldset class="grupo">
                <legend class="section-label">Aplica a</legend>
                <div class="pair">
                    <label class="field">
                        <span class="field__label">Categoría</span>
                        <select v-model="targetCategory" class="input" @change="addCategoryTarget">
                            <option value="">Agregar categoría…</option>
                            <option v-for="c in categories" :key="c.ulid" :value="c.ulid">{{ c.name }}</option>
                        </select>
                    </label>
                    <label class="field">
                        <span class="field__label">Artículo</span>
                        <select v-model="targetArticle" class="input" @change="addArticleTarget">
                            <option value="">Agregar artículo…</option>
                            <option v-for="a in articles" :key="a.ulid" :value="a.ulid">{{ a.name }}</option>
                        </select>
                    </label>
                </div>
                <ul class="chips">
                    <li v-for="(t, i) in form.targets" :key="i" class="chip">
                        {{ targetLabel(t) }}
                        <button type="button" class="chip__x" aria-label="Quitar" @click="removeTarget(i)">×</button>
                    </li>
                </ul>
            </fieldset>

            <fieldset class="grupo">
                <legend class="section-label">Vigencia</legend>
                <div class="pair">
                    <label class="field"><span class="field__label">Desde</span><input v-model="form.starts_on" class="input" type="date" /></label>
                    <label class="field"><span class="field__label">Hasta</span><input v-model="form.ends_on" class="input" type="date" /></label>
                </div>
                <div class="pair">
                    <label class="field"><span class="field__label">Hora inicio</span><input v-model="form.daily_start" class="input" type="time" /></label>
                    <label class="field"><span class="field__label">Hora fin</span><input v-model="form.daily_end" class="input" type="time" /></label>
                </div>
                <div class="dias">
                    <label v-for="d in DIAS" :key="d.v" class="dia">
                        <input type="checkbox" :checked="form.weekdays.includes(d.v)" @change="toggleWeekday(d.v)" />
                        {{ d.l }}
                    </label>
                </div>
            </fieldset>

            <fieldset class="grupo">
                <legend class="section-label">Sucursales</legend>
                <label class="dia"><input v-model="form.all_branches" type="checkbox" /> Todas las sucursales</label>
                <div v-if="! form.all_branches" class="dias">
                    <label v-for="s in branches" :key="s.ulid" class="dia">
                        <input v-model="form.branch_ulids" type="checkbox" :value="s.ulid" /> {{ s.name }}
                    </label>
                </div>
            </fieldset>

            <label class="dia"><input v-model="form.is_stackable" type="checkbox" /> Acumulable con otras (si el negocio lo permite)</label>

            <label class="field">
                <span class="field__label">Estado</span>
                <select v-model="form.status" class="input">
                    <option value="active">Activa</option>
                    <option value="inactive">Inactiva</option>
                </select>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.drawer--wide { width: min(34rem, 100%); }
.row-link {
    background: none; border: 0; padding: 0; font: inherit; font-weight: 500;
    color: var(--color-contenido); cursor: pointer;
    text-decoration: underline; text-decoration-color: var(--color-borde);
}
.grupo { border: 1px solid var(--color-borde); border-radius: 0.6rem; padding: 0.75rem 0.9rem; margin: 0; }
.grupo legend { padding: 0 0.35rem; }
.pair { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.dias { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.5rem; }
.dia { display: flex; gap: 0.4rem; align-items: center; font-size: 0.88rem; }
.chips { list-style: none; margin: 0.6rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.5rem; }
.chip {
    display: inline-flex; align-items: center; gap: 0.4rem;
    background: color-mix(in srgb, var(--color-acento) 10%, transparent);
    color: var(--color-contenido); border-radius: 999px; padding: 0.15rem 0.3rem 0.15rem 0.7rem; font-size: 0.82rem;
}
.chip__x { border: 0; background: none; color: var(--color-suave); cursor: pointer; font-size: 1rem; line-height: 1; padding: 0 0.2rem; }
.chip__x:hover { color: var(--color-peligro); }
</style>
