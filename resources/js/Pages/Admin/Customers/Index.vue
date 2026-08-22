<script setup>
import { onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';

/**
 * Clientes (§6.6).
 *
 * ## Alta express: sólo el nombre (D43)
 *
 * Todo lo demás es opcional. El caso normal es registrar a alguien que está pagando, en dos toques; pedir más haría que
 * nadie registrara clientes, y sin clientes el crédito y la factura no existen. El expediente completo —perfiles
 * fiscales, direcciones— se llena en la ficha del cliente, cuando hace falta.
 */
const list = useResourceList('/customers', { initialFilters: { status: '', with_debt: '' } });

onMounted(() => list.load());

const creating = ref(false);
const form = ref({ name: '', phone: '', birthday: '' });

const save = useApiForm(async () => {
    const cuerpo = { name: form.value.name };
    if (form.value.phone) cuerpo.phone = form.value.phone;
    if (form.value.birthday) cuerpo.birthday = form.value.birthday;

    const { data } = await api.post('/customers', cuerpo);

    creating.value = false;
    form.value = { name: '', phone: '', birthday: '' };

    // Recién creado: se va a su ficha para llenar el expediente si hace falta.
    router.visit(`/admin/clientes/${data.ulid}`);
});
</script>

<template>
    <Head title="Clientes" />

    <div class="clientes">
        <header class="clientes__cabecera">
            <h1>Clientes</h1>
            <button type="button" @click="creating = ! creating">Nuevo cliente</button>
        </header>

        <section v-if="creating" class="panel">
            <h2>Alta rápida</h2>
            <form @submit.prevent="save.submit()">
                <label>Nombre <input v-model="form.name" type="text" required minlength="2" maxlength="120" /></label>
                <label>Teléfono <input v-model="form.phone" type="text" maxlength="20" /></label>
                <label>Cumpleaños <input v-model="form.birthday" type="date" /></label>
                <p v-if="save.generalError.value" class="error">{{ save.generalError.value }}</p>
                <div class="acciones">
                    <button type="submit" :disabled="save.processing.value">Guardar</button>
                    <button type="button" class="enlace" @click="creating = false">Cancelar</button>
                </div>
            </form>
        </section>

        <div class="filtros">
            <label>
                <input type="checkbox" :checked="list.filters.with_debt === '1'"
                    @change="list.filters.with_debt = $event.target.checked ? '1' : ''; list.reload()" />
                Sólo con deuda
            </label>
        </div>

        <table v-if="list.items.value.length" class="tabla">
            <thead>
                <tr><th>Nombre</th><th>Teléfono</th><th>Saldo</th><th></th></tr>
            </thead>
            <tbody>
                <tr v-for="c in list.items.value" :key="c.ulid">
                    <td>{{ c.name }}</td>
                    <td>{{ c.phone ?? '—' }}</td>
                    <td>{{ c.credit ? `$${c.credit.balance}` : '—' }}</td>
                    <td><a :href="`/admin/clientes/${c.ulid}`">Abrir</a></td>
                </tr>
            </tbody>
        </table>

        <p v-else class="nota">No hay clientes.</p>
    </div>
</template>

<style scoped>
.clientes { display: grid; gap: 1rem; max-width: 48rem; }
.clientes__cabecera { display: flex; justify-content: space-between; align-items: baseline; }
.clientes__cabecera h1 { margin: 0; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
.panel h2 { margin-top: 0; }
form { display: grid; gap: 0.5rem; max-width: 22rem; }
label { display: grid; gap: 0.2rem; font-size: 0.9rem; }
.filtros { font-size: 0.9rem; }
.tabla { width: 100%; border-collapse: collapse; }
.tabla th, .tabla td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #eee; }
.acciones { display: flex; gap: 1rem; }
.nota { color: #555; font-size: 0.9rem; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; }
.error { color: #a11; }
</style>
