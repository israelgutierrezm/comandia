<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';

/**
 * Las cuentas vivas: el piso (§6.3).
 *
 * ## Abre con lo VIVO, no con todo
 *
 * `only_open=1`. A quien atiende no le interesa una cuenta pagada hace tres horas, y una lista que empieza por el
 * historial obliga a buscar lo de ahora entre lo de antes. El historial completo es otra pregunta y tiene su propio
 * filtro.
 *
 * ## Tres formas de abrir una cuenta, porque son tres cosas distintas
 *
 * En **mesa** (comer aquí), de **barra** con nombre libre («Señor de lentes») y **para llevar** con número de mostrador.
 * No son variantes de un formulario: cambian qué identifica a la cuenta, y una sola caja de texto haría que el mesero
 * tuviera que saber cuál llenar.
 */
const accounts = ref([]);
const tables = ref([]);
const branches = ref([]);
const loading = ref(true);
const loadError = ref(null);
const onlyOpen = ref(true);

const form = ref({ kind: 'table', table_ulid: '', label: '', branch_ulid: '' });

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const [cuentas, mesas, sucursales] = await Promise.all([
            api.get('/pos-accounts', { only_open: onlyOpen.value ? 1 : 0, per_page: 50 }),
            api.get('/restaurant-tables', { per_page: 100 }),
            api.get('/branches', { status: 'active', per_page: 50 }),
        ]);

        accounts.value = cuentas.data;
        tables.value = mesas.data;
        branches.value = sucursales.data;

        if (! form.value.branch_ulid && branches.value.length > 0) {
            form.value.branch_ulid = branches.value[0].ulid;
        }
    } catch (e) {
        if (e instanceof ApiError) {
            loadError.value = e;
        } else {
            throw e;
        }
    } finally {
        loading.value = false;
    }
}

const openAccount = useApiForm(async () => {
    // El cuerpo cambia según la forma de abrir: mandar las tres llaves y dejar que el servidor elija sería mandarle
    // una contradicción —una cuenta no está en la mesa 4 y además se llama «Señor de lentes»— que el propio endpoint
    // rechaza con 422.
    const cuerpo = form.value.kind === 'table'
        ? { table_ulid: form.value.table_ulid }
        : form.value.kind === 'takeout'
            ? { branch_ulid: form.value.branch_ulid, takeout: true }
            : { branch_ulid: form.value.branch_ulid, label: form.value.label };

    const creada = await api.post('/pos-accounts', cuerpo);

    form.value.table_ulid = '';
    form.value.label = '';

    await load();

    return creada.data;
});

/** Las mesas donde se puede sentar a alguien ahora mismo. */
const freeTables = computed(() => tables.value.filter((m) => m.status === 'free' && ! m.joined_to));

function accountUrl(account) {
    return `/admin/pos/cuentas/${account.ulid}`;
}

function money(value) {
    return value === null || value === undefined ? '—' : `$${value}`;
}
</script>

<template>
    <Head title="Cuentas" />

    <div class="piso">
        <h1>Cuentas</h1>

        <section class="panel">
            <h2>Abrir cuenta</h2>

            <form @submit.prevent="openAccount.submit()">
                <div class="tipos">
                    <label><input v-model="form.kind" type="radio" value="table" /> En mesa</label>
                    <label><input v-model="form.kind" type="radio" value="walkin" /> De barra</label>
                    <label><input v-model="form.kind" type="radio" value="takeout" /> Para llevar</label>
                </div>

                <label v-if="form.kind === 'table'">
                    Mesa
                    <select v-model="form.table_ulid" required>
                        <option value="">Elige…</option>
                        <option v-for="m in freeTables" :key="m.ulid" :value="m.ulid">
                            {{ m.code }} ({{ m.seats }} lugares)
                        </option>
                    </select>
                </label>

                <p v-if="form.kind === 'table' && freeTables.length === 0" class="nota">
                    No hay mesas libres. Una mesa ocupada no admite una segunda cuenta: cóbrala o mueve la que tiene.
                </p>

                <label v-if="form.kind === 'walkin'">
                    Nombre
                    <input v-model="form.label" type="text" placeholder="Señor de lentes" required />
                </label>

                <label v-if="form.kind !== 'table'">
                    Sucursal
                    <select v-model="form.branch_ulid" required>
                        <option v-for="s in branches" :key="s.ulid" :value="s.ulid">{{ s.name }}</option>
                    </select>
                </label>

                <p v-if="form.kind === 'takeout'" class="nota">
                    El número de mostrador lo asigna el sistema y vuelve a 1 cada jornada: es el que se grita.
                </p>

                <p v-if="openAccount.generalError.value" class="error">{{ openAccount.generalError.value }}</p>

                <button type="submit" :disabled="openAccount.processing.value">Abrir</button>
            </form>
        </section>

        <section class="panel">
            <h2>
                {{ onlyOpen ? 'Cuentas vivas' : 'Todas las cuentas' }}
                <button type="button" class="enlace" @click="onlyOpen = ! onlyOpen; load()">
                    {{ onlyOpen ? 'ver todas' : 'ver sólo las vivas' }}
                </button>
            </h2>

            <p v-if="loading">Cargando…</p>
            <div v-else-if="loadError" class="error">{{ loadError.title }}</div>
            <p v-else-if="accounts.length === 0" class="nota">No hay cuentas.</p>

            <table v-else>
                <thead>
                    <tr><th>Cuenta</th><th>Folio</th><th>Estado</th><th>Total</th><th>Falta</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in accounts" :key="c.ulid">
                        <td>{{ c.display_name }}</td>
                        <td>{{ c.folio }}</td>
                        <td>{{ c.status_label }}</td>
                        <td>{{ money(c.totals.total) }}</td>
                        <td>{{ money(c.totals.due) }}</td>
                        <td><a :href="accountUrl(c)">Abrir</a></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>

<style scoped>
.piso { display: grid; gap: 1.5rem; max-width: 60rem; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
.panel h2 { margin-top: 0; display: flex; gap: 1rem; align-items: baseline; }
.nota { color: #555; font-size: 0.9rem; }
.error { color: #a11; }
form { display: grid; gap: 0.5rem; max-width: 24rem; }
label { display: grid; gap: 0.2rem; }
.tipos { display: flex; gap: 1rem; }
.tipos label { display: flex; gap: 0.3rem; align-items: center; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 0.35rem 0.5rem; border-bottom: 1px solid #eee; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; font-size: 0.85rem; padding: 0; }
</style>
