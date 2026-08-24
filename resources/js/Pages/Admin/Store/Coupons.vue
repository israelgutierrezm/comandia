<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

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

    <div class="cupones">
        <h1>Cupones</h1>
        <p class="nota">Códigos de descuento para el checkout de la tienda. El canje respeta la vigencia y los topes de uso.</p>

        <p v-if="error" class="error">{{ error }}</p>

        <form class="nuevo" @submit.prevent="create">
            <input v-model="form.code" type="text" maxlength="40" placeholder="Código (p. ej. BIENVENIDO)" required />
            <select v-model="form.type">
                <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <input v-if="needsValue" v-model="form.value" type="text" inputmode="decimal"
                   :placeholder="form.type === 'percentage' ? '% (1–100)' : 'Monto'" required />
            <label class="campo">Desde <input v-model="form.valid_from" type="date" /></label>
            <label class="campo">Hasta <input v-model="form.valid_until" type="date" /></label>
            <input v-model="form.max_uses" type="number" min="1" placeholder="Tope de usos" />
            <input v-model="form.per_customer_limit" type="number" min="1" placeholder="Por cliente" />
            <label class="chk"><input v-model="form.is_active" type="checkbox" /> Activo</label>
            <button type="submit" :disabled="saving">Crear cupón</button>
        </form>

        <table v-if="coupons.length" class="lista">
            <thead>
                <tr><th>Código</th><th>Descuento</th><th>Vigencia</th><th>Usos</th><th></th></tr>
            </thead>
            <tbody>
                <tr v-for="c in coupons" :key="c.ulid" :class="{ inactivo: !c.is_active }">
                    <td><strong>{{ c.code }}</strong></td>
                    <td>{{ describe(c) }}</td>
                    <td class="min">{{ c.valid_from ?? '—' }} … {{ c.valid_until ?? '—' }}</td>
                    <td class="min">{{ c.uses_count }}{{ c.max_uses ? ' / ' + c.max_uses : '' }}</td>
                    <td><button type="button" class="enlace" @click="remove(c.ulid)">quitar</button></td>
                </tr>
            </tbody>
        </table>
        <p v-else class="nota">Aún no hay cupones.</p>
    </div>
</template>

<style scoped>
.cupones { display: grid; gap: 1rem; max-width: 52rem; }
.cupones h1 { margin: 0; }
.nota { color: #555; font-size: 0.9rem; margin: 0; }
.error { color: #a11; }
.nuevo { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.nuevo input, .nuevo select { padding: 0.35rem 0.5rem; border: 1px solid #d6d3d1; border-radius: 4px; font: inherit; }
.nuevo input[type="text"], .nuevo input[inputmode="decimal"] { min-width: 8rem; }
.nuevo input[type="number"] { width: 8rem; }
.nuevo .campo { display: flex; gap: 0.3rem; align-items: center; font-size: 0.8rem; color: #555; }
.nuevo .chk { display: flex; gap: 0.3rem; align-items: center; font-size: 0.85rem; }
.nuevo button[type="submit"] { background: #1c1917; color: #fff; border: 0; border-radius: 6px; padding: 0.4rem 1rem; cursor: pointer; }
.lista { border-collapse: collapse; width: 100%; font-size: 0.9rem; }
.lista th, .lista td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid #eee; }
.lista .min { color: #555; white-space: nowrap; }
.lista tr.inactivo { opacity: 0.5; }
.enlace { color: #b91c1c; background: none; border: 0; cursor: pointer; padding: 0; font: inherit; }
</style>
