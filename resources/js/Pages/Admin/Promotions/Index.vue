<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';

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
        branch_ulids: (data.branches ?? []).map((b) => branches.value.find((x) => x.id === b.branch_id)?.ulid).filter(Boolean),
        // Los objetivos vuelven con ids internos; para editar se remapean a ulid.
        targets: (data.targets ?? []).map((t) => t.article_id
            ? { article_ulid: articles.value.find((a) => a.id === t.article_id)?.ulid }
            : { category_ulid: categories.value.find((c) => c.id === t.article_category_id)?.ulid }).filter((t) => t.article_ulid || t.category_ulid),
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
</script>

<template>
    <Head title="Promociones" />

    <div class="promos">
        <header class="promos__cabecera">
            <h1>Promociones</h1>
            <button type="button" @click="startCreate">Nueva promoción</button>
        </header>

        <p class="nota">
            Las promociones se aplican solas al cobrar, según su vigencia y sus reglas. Cuando varias caben, gana la que
            más descuenta. Los cupones de la tienda en línea llegan con el módulo de e-commerce.
        </p>

        <table v-if="list.items.value.length" class="tabla">
            <thead>
                <tr><th>Nombre</th><th>Tipo</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
                <tr v-for="p in list.items.value" :key="p.ulid">
                    <td>{{ p.name }}</td>
                    <td>{{ p.type_label }}</td>
                    <td>{{ p.status === 'active' ? 'Activa' : 'Inactiva' }}</td>
                    <td><button type="button" class="enlace" @click="startEdit(p.ulid)">Editar</button></td>
                </tr>
            </tbody>
        </table>

        <p v-else class="nota">Todavía no hay promociones.</p>

        <!-- El formulario, como panel. -->
        <section v-if="editing" class="panel">
            <h2>{{ editing === 'new' ? 'Nueva promoción' : 'Editar promoción' }}</h2>

            <form @submit.prevent="save.submit()">
                <label>Nombre <input v-model="form.name" type="text" required maxlength="120" /></label>

                <label>
                    Tipo
                    <select v-model="form.type">
                        <option v-for="(l, v) in TIPOS" :key="v" :value="v">{{ l }}</option>
                    </select>
                </label>

                <label v-if="form.type === 'percentage'">
                    Porcentaje (%) <input v-model="form.percent_value" type="text" inputmode="decimal" placeholder="10.00" />
                </label>

                <label v-if="form.type === 'amount' || form.type === 'special_price'">
                    {{ form.type === 'amount' ? 'Monto a descontar' : 'Precio especial' }}
                    <input v-model="form.amount_value" type="text" inputmode="decimal" placeholder="0.00" />
                </label>

                <div v-if="form.type === 'nxm'" class="fila">
                    <label>Compra <input v-model="form.buy_quantity" type="number" min="2" /></label>
                    <label>Paga <input v-model="form.pay_quantity" type="number" min="1" /></label>
                </div>

                <fieldset>
                    <legend>Aplica a</legend>
                    <div class="fila">
                        <label>
                            Categoría
                            <select v-model="targetCategory" @change="addCategoryTarget">
                                <option value="">Agregar categoría…</option>
                                <option v-for="c in categories" :key="c.ulid" :value="c.ulid">{{ c.name }}</option>
                            </select>
                        </label>
                        <label>
                            Artículo
                            <select v-model="targetArticle" @change="addArticleTarget">
                                <option value="">Agregar artículo…</option>
                                <option v-for="a in articles" :key="a.ulid" :value="a.ulid">{{ a.name }}</option>
                            </select>
                        </label>
                    </div>
                    <ul class="chips">
                        <li v-for="(t, i) in form.targets" :key="i">
                            {{ targetLabel(t) }}
                            <button type="button" class="enlace" @click="removeTarget(i)">quitar</button>
                        </li>
                    </ul>
                </fieldset>

                <fieldset>
                    <legend>Vigencia</legend>
                    <div class="fila">
                        <label>Desde <input v-model="form.starts_on" type="date" /></label>
                        <label>Hasta <input v-model="form.ends_on" type="date" /></label>
                    </div>
                    <div class="fila">
                        <label>Hora inicio <input v-model="form.daily_start" type="time" /></label>
                        <label>Hora fin <input v-model="form.daily_end" type="time" /></label>
                    </div>
                    <div class="dias">
                        <label v-for="d in DIAS" :key="d.v" class="dia">
                            <input type="checkbox" :checked="form.weekdays.includes(d.v)" @change="toggleWeekday(d.v)" />
                            {{ d.l }}
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Sucursales</legend>
                    <label class="check"><input v-model="form.all_branches" type="checkbox" /> Todas las sucursales</label>
                    <div v-if="! form.all_branches" class="dias">
                        <label v-for="s in branches" :key="s.ulid" class="dia">
                            <input type="checkbox" :value="s.ulid" v-model="form.branch_ulids" /> {{ s.name }}
                        </label>
                    </div>
                </fieldset>

                <label class="check"><input v-model="form.is_stackable" type="checkbox" /> Acumulable con otras (si el negocio lo permite)</label>

                <label>
                    Estado
                    <select v-model="form.status"><option value="active">Activa</option><option value="inactive">Inactiva</option></select>
                </label>

                <p v-if="save.generalError.value" class="error">{{ save.generalError.value }}</p>

                <div class="acciones">
                    <button type="submit" :disabled="save.processing.value">Guardar</button>
                    <button type="button" class="enlace" @click="editing = null">Cancelar</button>
                </div>
            </form>
        </section>
    </div>
</template>

<style scoped>
.promos { display: grid; gap: 1rem; max-width: 52rem; }
.promos__cabecera { display: flex; gap: 1rem; align-items: baseline; justify-content: space-between; }
.promos__cabecera h1 { margin: 0; }
.nota { color: #555; font-size: 0.9rem; }
.tabla { width: 100%; border-collapse: collapse; }
.tabla th, .tabla td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #eee; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
.panel h2 { margin-top: 0; }
form { display: grid; gap: 0.6rem; }
label { display: grid; gap: 0.2rem; font-size: 0.9rem; }
.fila { display: flex; gap: 1rem; }
.fila label { flex: 1; }
fieldset { border: 1px solid #e2e2e2; border-radius: 6px; }
legend { font-size: 0.85rem; color: #444; padding: 0 0.4rem; }
.chips { list-style: none; margin: 0.4rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.5rem; }
.chips li { background: #f0f0f0; border-radius: 999px; padding: 0.15rem 0.7rem; font-size: 0.85rem; }
.dias { display: flex; flex-wrap: wrap; gap: 0.75rem; }
.dia { display: flex; gap: 0.3rem; align-items: center; }
.check { display: flex; gap: 0.4rem; align-items: center; }
.acciones { display: flex; gap: 1rem; align-items: center; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; padding: 0; }
.error { color: #a11; }
</style>
