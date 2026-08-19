<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';

/**
 * Proveedores (D26).
 *
 * ## El código no se puede editar, y la pantalla lo dice
 *
 * Es el identificador con el que la gente llama al proveedor en papeles y conversaciones, así que
 * reasignarlo haría que los documentos viejos parecieran ser de otro (D201). El campo sólo aparece al
 * crear, igual que el tipo de un almacén.
 *
 * ## Y no se borra: se da de baja
 *
 * Sus recepciones y su historial de precios lo citan. No hay botón de borrar porque no hay endpoint —
 * la baja conserva el historial consultable y sólo impide compras nuevas.
 */
const list = useResourceList('/suppliers', { initialFilters: { status: '' } });

const editing = ref(null);
const form = ref({});

const save = useApiForm(async () => {
    const payload = {
        legal_name: form.value.legal_name,
        trade_name: form.value.trade_name || null,
        rfc: form.value.rfc || null,
        contact_name: form.value.contact_name || null,
        phone: form.value.phone || null,
        email: form.value.email || null,
        // Cadena vacía a `null`: «no se sabe» y «de contado» son cosas distintas, y un cero significa
        // la segunda. Mandar `''` haría que el servidor lo tomara por cero.
        payment_terms_days: form.value.payment_terms_days === '' ? null : Number(form.value.payment_terms_days),
        notes: form.value.notes || null,
    };

    if (editing.value === 'new') {
        await api.post('/suppliers', { ...payload, code: form.value.code });
    } else {
        await api.patch(`/suppliers/${editing.value.ulid}`, payload);
    }
});

const changeStatus = useApiForm(async (supplier, status) => {
    await api.patch(`/suppliers/${supplier.ulid}`, { status });
});

onMounted(() => list.load());

function startCreate() {
    editing.value = 'new';
    form.value = {
        code: '',
        legal_name: '',
        trade_name: '',
        rfc: '',
        contact_name: '',
        phone: '',
        email: '',
        payment_terms_days: '',
        notes: '',
    };
}

function startEdit(supplier) {
    editing.value = supplier;
    form.value = {
        legal_name: supplier.legal_name,
        trade_name: supplier.trade_name ?? '',
        rfc: supplier.rfc ?? '',
        contact_name: supplier.contact_name ?? '',
        phone: supplier.phone ?? '',
        email: supplier.email ?? '',
        payment_terms_days: supplier.payment_terms_days ?? '',
        notes: supplier.notes ?? '',
    };
}

async function submit() {
    if (await save.submit()) {
        editing.value = null;
        await list.load();
    }
}

async function toggleStatus(supplier) {
    const next = supplier.is_active ? 'inactive' : 'active';

    if (supplier.is_active && !window.confirm(`¿Dar de baja a «${supplier.display_name}»?`)) {
        return;
    }

    if (await changeStatus.submit(supplier, next)) {
        await list.load();
    }
}

const columns = [
    { key: 'code', label: 'Código', width: '9rem' },
    { key: 'name', label: 'Proveedor' },
    { key: 'rfc', label: 'RFC', width: '10rem' },
    { key: 'contact', label: 'Contacto' },
    { key: 'terms', label: 'Crédito', width: '7rem' },
    { key: 'status', label: 'Estado', width: '7rem' },
    { key: 'actions', label: '', width: '10rem' },
];
</script>

<template>
    <Head title="Proveedores" />

    <header class="page-header">
        <div>
            <h1>Proveedores</h1>
            <p class="page-header__hint">
                El <strong>código</strong> no se puede cambiar después: es como lo identifican los
                documentos ya capturados. Y un proveedor no se borra, se da de baja — sus compras y su
                historial de precios lo citan.
            </p>
        </div>

        <button v-can.write="'purchasing.suppliers.manage'" class="button" type="button" @click="startCreate">
            Nuevo proveedor
        </button>
    </header>

    <div class="toolbar">
        <input v-model="list.filters.search" type="search" class="input" placeholder="Buscar por nombre, código o RFC…" />

        <select v-model="list.filters.status" class="input input--select">
            <option value="">Todos</option>
            <option value="active">Activos</option>
            <option value="inactive">Dados de baja</option>
        </select>
    </div>

    <p v-if="changeStatus.generalError.value" class="alert">{{ changeStatus.generalError.value }}</p>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="Todavía no hay proveedores que coincidan."
    >
        <template #cell:name="{ row }">
            <div>
                <strong>{{ row.display_name }}</strong>
                <!-- La razón social aparte cuando difiere: va en la factura, mientras el nombre
                     comercial es como lo llama la cocina. -->
                <small v-if="row.trade_name" class="muted">{{ row.legal_name }}</small>
            </div>
        </template>

        <template #cell:rfc="{ row }">
            <span v-if="row.rfc">{{ row.rfc }}</span>
            <span v-else class="muted">—</span>
        </template>

        <template #cell:contact="{ row }">
            <div class="contact">
                <span v-if="row.contact_name">{{ row.contact_name }}</span>
                <small v-if="row.phone" class="muted">{{ row.phone }}</small>
                <small v-if="row.email" class="muted">{{ row.email }}</small>
                <span v-if="!row.contact_name && !row.phone && !row.email" class="muted">—</span>
            </div>
        </template>

        <template #cell:terms="{ row }">
            <!-- `null` y cero son distintos: «no se sabe» frente a «de contado». -->
            <span v-if="row.payment_terms_days === null" class="muted">—</span>
            <span v-else-if="row.payment_terms_days === 0">De contado</span>
            <span v-else>{{ row.payment_terms_days }} días</span>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="row.is_active ? 'badge--ok' : 'badge--off'">
                {{ row.is_active ? 'Activo' : 'Baja' }}
            </span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button v-can.write="'purchasing.suppliers.manage'" class="link-button" type="button" @click="startEdit(row)">
                    Editar
                </button>
                <button
                    v-can.write="'purchasing.suppliers.manage'"
                    class="link-button"
                    :class="{ 'link-button--danger': row.is_active }"
                    type="button"
                    @click="toggleStatus(row)"
                >
                    {{ row.is_active ? 'Dar de baja' : 'Reactivar' }}
                </button>
            </div>
        </template>
    </DataTable>

    <div v-if="editing" class="drawer-backdrop" @click.self="editing = null">
        <form class="drawer" @submit.prevent="submit">
            <h2>{{ editing === 'new' ? 'Nuevo proveedor' : `Editar ${editing.display_name}` }}</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label v-if="editing === 'new'" class="field">
                <span class="field__label">Código</span>
                <input v-model="form.code" class="input" maxlength="20" required placeholder="DON-BETO" />
                <span class="field__hint">No se puede cambiar después.</span>
                <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
            </label>

            <label class="field">
                <span class="field__label">Razón social</span>
                <input v-model="form.legal_name" class="input" maxlength="200" required />
                <span class="field__hint">La que va en la factura.</span>
                <span v-if="save.fieldErrors.value.legal_name" class="field__error">
                    {{ save.fieldErrors.value.legal_name }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Nombre comercial</span>
                <input v-model="form.trade_name" class="input" maxlength="120" placeholder="Don Beto" />
                <span class="field__hint">Como lo llama la cocina. Opcional.</span>
            </label>

            <label class="field">
                <span class="field__label">RFC</span>
                <input v-model="form.rfc" class="input" maxlength="13" placeholder="DAB120315ABC" />
                <span class="field__hint">Opcional, y único: dos proveedores con el mismo RFC son el mismo.</span>
                <span v-if="save.fieldErrors.value.rfc" class="field__error">{{ save.fieldErrors.value.rfc }}</span>
            </label>

            <label class="field">
                <span class="field__label">Contacto</span>
                <input v-model="form.contact_name" class="input" maxlength="120" />
            </label>

            <div class="field-row">
                <label class="field">
                    <span class="field__label">Teléfono</span>
                    <input v-model="form.phone" class="input" maxlength="30" />
                </label>

                <label class="field">
                    <span class="field__label">Correo</span>
                    <input v-model="form.email" type="email" class="input" maxlength="160" />
                    <span v-if="save.fieldErrors.value.email" class="field__error">{{ save.fieldErrors.value.email }}</span>
                </label>
            </div>

            <label class="field">
                <span class="field__label">Días de crédito</span>
                <input v-model="form.payment_terms_days" type="number" min="0" max="365" class="input" />
                <span class="field__hint">Vacío = no se sabe. Cero = de contado.</span>
                <span v-if="save.fieldErrors.value.payment_terms_days" class="field__error">
                    {{ save.fieldErrors.value.payment_terms_days }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Notas</span>
                <textarea v-model="form.notes" class="input" rows="2" maxlength="500"></textarea>
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

.muted {
    color: #6b7280;
    display: block;
    font-size: 0.8rem;
}

.contact {
    display: flex;
    flex-direction: column;
}

.field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}
</style>
