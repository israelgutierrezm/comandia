<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Cupones de la tienda (Iteración 8, Tanda D, D3). Crear, listar y quitar cupones: código, tipo (%/monto/envío gratis),
 * vigencia y topes de uso. Sólo aparece con el módulo Ecommerce y `ecommerce.coupons.manage`. El canje ocurre en el
 * checkout (parte 2).
 */
const TYPES = [
    { value: 'percentage', label: 'Porcentaje' },
    { value: 'fixed', label: 'Monto fijo' },
    { value: 'free_shipping', label: 'Envío gratis' },
];

const blank = () => ({
    code: '', type: 'percentage', value: '', valid_from: '', valid_until: '', max_uses: '', per_customer_limit: '', is_active: true,
});

const coupons = ref([]);
const form = ref(blank());
const error = ref(null);
const saving = ref(false);

const needsValue = computed(() => form.value.type !== 'free_shipping');

onMounted(load);

async function load() {
    const { data } = await api.get('/coupons');
    coupons.value = data;
}

async function create() {
    saving.value = true;
    error.value = null;
    try {
        // Se omiten los opcionales vacíos para que lleguen como ausentes (nullable), no como cadena vacía.
        const payload = {
            code: form.value.code,
            type: form.value.type,
            value: needsValue.value ? form.value.value : 0,
            is_active: form.value.is_active,
        };
        for (const k of ['valid_from', 'valid_until', 'max_uses', 'per_customer_limit']) {
            if (form.value[k] !== '') payload[k] = form.value[k];
        }
        await api.post('/coupons', payload);
        form.value = blank();
        await load();
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        saving.value = false;
    }
}

async function remove(ulid) {
    await api.delete(`/coupons/${ulid}`);
    await load();
}

function describe(c) {
    if (c.type === 'free_shipping') return 'Envío gratis';
    if (c.type === 'percentage') return `${c.value}% de descuento`;
    return `$${c.value} de descuento`;
}
</script>

<template>
    <Head title="Cupones" />

    <div class="cupones animar-entrada">
        <ListHeader
            title="Cupones"
            subtitle="Códigos de descuento para el checkout de la tienda. El canje respeta la vigencia y los topes de uso."
            :count="coupons.length"
        />

        <p v-if="error" class="alert" role="alert">{{ error }}</p>

        <form class="tarjeta nuevo" @submit.prevent="create">
            <input v-model="form.code" class="input" type="text" maxlength="40" placeholder="Código (p. ej. BIENVENIDO)" required />
            <select v-model="form.type" class="input input--select">
                <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <input v-if="needsValue" v-model="form.value" class="input campo--valor" type="text" inputmode="decimal"
                   :placeholder="form.type === 'percentage' ? '% (1–100)' : 'Monto'" required />
            <label class="campo">Desde <input v-model="form.valid_from" class="input" type="date" /></label>
            <label class="campo">Hasta <input v-model="form.valid_until" class="input" type="date" /></label>
            <input v-model="form.max_uses" class="input campo--num" type="number" min="1" placeholder="Tope de usos" />
            <input v-model="form.per_customer_limit" class="input campo--num" type="number" min="1" placeholder="Por cliente" />
            <label class="check"><input v-model="form.is_active" type="checkbox" /> Activo</label>
            <button type="submit" class="button" :disabled="saving">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Crear cupón
            </button>
        </form>

        <div v-if="coupons.length" class="tabla-envoltura">
            <table class="lista">
                <thead>
                    <tr><th>Código</th><th>Descuento</th><th>Vigencia</th><th>Usos</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in coupons" :key="c.ulid" :class="{ inactivo: !c.is_active }">
                        <td><strong>{{ c.code }}</strong></td>
                        <td>{{ describe(c) }}</td>
                        <td class="min">{{ c.valid_from ?? '—' }} … {{ c.valid_until ?? '—' }}</td>
                        <td class="min">{{ c.uses_count }}{{ c.max_uses ? ' / ' + c.max_uses : '' }}</td>
                        <td class="acciones-celda">
                            <button type="button" class="link-button link-button--danger" @click="remove(c.ulid)"><Icon name="trash" /> Quitar</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="page-header__hint">Aún no hay cupones.</p>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.cupones {
    display: grid;
    gap: 1rem;
    max-width: 54rem;
}

.nuevo {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    align-items: center;
    padding: 1.1rem;
    border: 1px solid var(--color-borde);
}

.nuevo .input {
    flex: 0 0 auto;
}

.campo--valor {
    width: 9rem;
}

.campo--num {
    width: 8.5rem;
}

.campo {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    font-size: 0.8rem;
    color: var(--color-suave);
}

.campo .input {
    font-size: 0.85rem;
}

.check {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.tabla-envoltura {
    overflow-x: auto;
}

.lista {
    border-collapse: collapse;
    width: 100%;
    font-size: 0.9rem;
}

.lista th,
.lista td {
    text-align: left;
    padding: 0.55rem 0.7rem;
    border-bottom: 1px solid var(--color-borde);
}

.lista th {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-suave);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.lista .min {
    color: var(--color-suave);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.lista tr.inactivo {
    opacity: 0.5;
}

.acciones-celda {
    text-align: right;
}
</style>
