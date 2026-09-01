<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';
import ResourceGrid from '../../../../components/ResourceGrid.vue';
import ViewToggle from '../../../../components/ViewToggle.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import FilterBar from '../../../../components/FilterBar.vue';

const view = ref('list');

/**
 * Unidades de medida (D22).
 *
 * ## Por qué el factor no se puede editar
 *
 * El formulario de edición sólo deja cambiar el nombre y el estado, y el servidor tampoco acepta
 * más. El factor de conversión es la constante con la que se convirtieron TODAS las cantidades ya
 * capturadas: cambiar «caja» de 12 a 24 piezas no corregiría un dato, reinterpretaría cada receta
 * y cada costo histórico que la usó. La corrección es crear la unidad correcta y dar de baja la
 * equivocada, que es lo que deja rastro.
 *
 * Las cinco unidades del sistema —g, kg, ml, l, pza— se siembran al dar de alta el tenant (D97) y
 * las bases de cada magnitud no se pueden desactivar.
 */
const list = useResourceList('/units', { initialFilters: { dimension: '', status: '' } });

const filtrosActivos = computed(
    () => [list.filters.dimension !== '', list.filters.status !== ''].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.dimension = '';
    list.filters.status = '';
}

/**
 * Todas las unidades, sin filtrar, cargadas una vez.
 *
 * No es un duplicado del listado: es el catálogo de REFERENCIA, y hace falta porque dos cosas de esta
 * pantalla no pueden depender de lo que el filtro dejó en la tabla.
 *
 *   - Las **magnitudes del selector**: derivarlas de la lista filtrada las reduciría a la que ya está
 *     seleccionada, y el filtro se volvería un callejón sin salida.
 *   - La **unidad base de cada magnitud**, para poder decir «1 kg = 1000 g». Al filtrar por «dadas de
 *     baja», las bases —que están activas— desaparecen de la tabla y la equivalencia se quedaba sin la
 *     mitad de la frase: «1 kg = 1000». Lo encontró el navegador.
 */
const reference = ref([]);

onMounted(async () => {
    await list.load();

    reference.value = (await api.get('/units', { per_page: 100 })).data ?? [];
});

/**
 * Las magnitudes que existen, con la etiqueta que da el SERVIDOR.
 *
 * Estaban escritas a mano en el selector y decían «Piezas» mientras la tabla decía «Conteo», porque la
 * etiqueta la traduce el enum del servidor (D87) y el cliente tenía su propia copia. Dos nombres para la
 * misma cosa en la misma pantalla: exactamente el fallo que D87 existe para evitar, cometido otra vez en
 * el sitio donde nadie lo estaba vigilando.
 */
const dimensions = computed(() => {
    const seen = new Map();

    for (const unit of reference.value) {
        seen.set(unit.dimension, unit.dimension_label);
    }

    return [...seen].map(([value, label]) => ({ value, label }));
});

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    if (editing.value === 'new') {
        await api.post('/units', {
            code: form.value.code,
            name: form.value.name,
            dimension: form.value.dimension,
            factor_to_base: form.value.factor_to_base,
        });
    } else {
        await api.patch(`/units/${editing.value.ulid}`, {
            name: form.value.name,
            status: form.value.status,
        });
    }
});

function startCreate() {
    editing.value = 'new';
    // La primera magnitud que exista, no una escrita a mano: los valores del enum son estables, pero
    // fijar uno aquí volvería a poner al cliente a decidir qué magnitudes hay.
    form.value = {
        code: '',
        name: '',
        dimension: dimensions.value[0]?.value ?? '',
        factor_to_base: '',
    };
}

function startEdit(unit) {
    editing.value = unit;
    form.value = { name: unit.name, status: unit.status };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

/**
 * La unidad base de la magnitud, para poder decir «1 caja = ? g» en lugar de «factor de
 * conversión», que no le dice nada a quien administra un restaurante.
 *
 * Se busca en el catálogo de referencia y no en una tabla fija en el cliente: las bases son dato del
 * servidor y una copia aquí sería una copia que se desactualiza.
 */
function baseUnitOf(dimension) {
    return reference.value.find((unit) => unit.dimension === dimension && unit.is_system_base);
}

/**
 * El factor sin los ceros que no dicen nada: `1000.00000000` se lee `1000`.
 *
 * La columna es `DECIMAL(18,8)` porque un factor puede necesitar esos decimales —una onza son
 * 28.34952312 g—, pero mostrarlos siempre convierte «mil gramos» en una cadena que hay que descifrar.
 *
 * Es recorte de CADENA, no aritmética: el valor llega como texto justamente para que el cliente no lo
 * convierta a número, y `parseFloat` aquí sería la puerta de entrada del error de punto flotante al
 * único dato que multiplica todas las cantidades del sistema.
 */
function formatFactor(value) {
    if (typeof value !== 'string' || !value.includes('.')) {
        return value;
    }

    return value.replace(/0+$/, '').replace(/\.$/, '');
}

const columns = [
    { key: 'code', label: 'Código', width: '7rem' },
    { key: 'name', label: 'Nombre' },
    { key: 'dimension', label: 'Magnitud', width: '9rem' },
    { key: 'factor', label: 'Equivale a', width: '12rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '6rem' },
];
</script>

<template>
    <Head title="Unidades de medida" />

    <header class="page-header">
        <div>
            <h1>Unidades de medida</h1>
            <p class="page-header__hint">
                El <strong>factor no se puede cambiar</strong> después: es la constante con la que se
                convirtieron todas las cantidades ya capturadas. Para corregirlo, crea la unidad
                correcta y da de baja la equivocada.
            </p>
        </div>

        <button v-can.write="'catalog.units.manage'" class="button" type="button" @click="startCreate">
            Nueva unidad
        </button>
    </header>

    <FilterBar
        v-model:search="list.filters.search"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.dimension" class="input input--select">
                <option value="">Todas las magnitudes</option>
                <option v-for="dimension in dimensions" :key="dimension.value" :value="dimension.value">
                    {{ dimension.label }}
                </option>
            </select>

            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todas</option>
                <option value="active">Activas</option>
                <option value="inactive">Dadas de baja</option>
            </select>
        </template>

        <template #view>
            <ViewToggle v-model="view" persist-key="comandia:view:units" class="toolbar__view" />
        </template>
    </FilterBar>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay unidades que coincidan."
    >
        <template #cell:dimension="{ row }">{{ row.dimension_label }}</template>

        <template #cell:factor="{ row }">
            <span v-if="row.is_system_base" class="badge badge--warn">Unidad base</span>
            <span v-else class="mono">
                1 {{ row.code }} = {{ formatFactor(row.factor_to_base) }}
                {{ baseUnitOf(row.dimension)?.code ?? '' }}
            </span>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                {{ row.status === 'active' ? 'Activa' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <button v-can.write="'catalog.units.manage'" class="link-button" type="button" @click="startEdit(row)">
                Editar
            </button>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay unidades que coincidan."
    >
        <template #card="{ item }">
            <div class="card">
                <span class="card__code">{{ item.code }} · {{ item.dimension_label }}</span>
                <span class="card__title">{{ item.name }}</span>
                <span class="card__meta mono">
                    <template v-if="item.is_system_base">Unidad base</template>
                    <template v-else>1 {{ item.code }} = {{ formatFactor(item.factor_to_base) }} {{ baseUnitOf(item.dimension)?.code ?? '' }}</template>
                </span>
                <span class="card__foot">
                    <span class="badge" :class="item.status === 'active' ? 'badge--ok' : 'badge--off'">
                        {{ item.status === 'active' ? 'Activa' : 'Baja' }}
                    </span>
                </span>
                <div class="card__actions">
                    <button v-can.write="'catalog.units.manage'" class="link-button" type="button" @click="startEdit(item)">
                        Editar
                    </button>
                </div>
            </div>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="unidades" />

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <h2>{{ editing === 'new' ? 'Nueva unidad' : `Editar ${editing.name}` }}</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <template v-if="editing === 'new'">
                <label class="field">
                    <span class="field__label">Código</span>
                    <input v-model="form.code" class="input" maxlength="20" required placeholder="caja" />
                    <span class="field__hint">Es lo que se ve al capturar cantidades.</span>
                    <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
                </label>

                <label class="field">
                    <span class="field__label">Magnitud</span>
                    <select v-model="form.dimension" class="input">
                        <option v-for="dimension in dimensions" :key="dimension.value" :value="dimension.value">
                            {{ dimension.label }} (base: {{ baseUnitOf(dimension.value)?.code ?? '—' }})
                        </option>
                    </select>
                    <span class="field__hint">
                        No se puede convertir entre magnitudes: los gramos no se vuelven mililitros.
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">
                        1 {{ form.code || 'unidad' }} equivale a ¿cuántos
                        {{ baseUnitOf(form.dimension)?.code ?? '…' }}?
                    </span>
                    <input v-model="form.factor_to_base" class="input" inputmode="decimal" required placeholder="1000" />
                    <span v-if="save.fieldErrors.value.factor_to_base" class="field__error">
                        {{ save.fieldErrors.value.factor_to_base }}
                    </span>
                </label>
            </template>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" maxlength="60" required />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <label v-if="editing !== 'new' && !editing.is_system_base" class="field">
                <span class="field__label">Estado</span>
                <select v-model="form.status" class="input">
                    <option value="active">Activa</option>
                    <option value="inactive">Dada de baja</option>
                </select>
                <span class="field__hint">
                    Dar de baja no borra nada: las recetas que ya la usan la siguen mostrando.
                </span>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editing = null">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.mono {
    font-variant-numeric: tabular-nums;
    font-size: 0.85rem;
}
</style>
