<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useAuthorization } from '../../composables/useAuthorization';

/**
 * Inicio de la administración.
 *
 * Deliberadamente **sin métricas**: los tableros con indicadores se construyen sobre el motor de
 * reportes (ADR-006, Iteración 8) y no existen todavía. Inventar aquí un par de contadores sueltos
 * crearía una segunda vía de agregación que después habría que desmontar, justo lo que ADR-006
 * quiere evitar.
 *
 * Lo que sí hace falta ahora es orientar: qué negocio y sucursal están activos, y qué falta por
 * configurar para que el negocio pueda operar.
 */
const page = usePage();
const { can } = useAuthorization();

const context = computed(() => page.props.context);

const pendientes = computed(() => {
    const items = [];

    if (!context.value?.branch_ulid) {
        items.push({
            text: 'Selecciona una sucursal en la barra superior para operar.',
            route: null,
        });
    }

    if (!context.value?.membership?.employee_code && can('identity.users.view')) {
        // Sin código de empleado no se puede autorizar por PIN (D84), y descubrirlo con el cliente
        // delante es peor que avisarlo aquí.
        items.push({
            text: 'Tu cuenta no tiene código de empleado: sin él no podrás autorizar acciones con PIN.',
            route: '/admin/personal',
        });
    }

    return items;
});
</script>

<template>
    <Head title="Inicio" />

    <header class="page-header">
        <div>
            <h1>{{ context?.tenant?.name }}</h1>
            <p class="page-header__hint">
                Operando como <strong>{{ context?.role_name ?? 'sin rol activo' }}</strong>
                <template v-if="context?.branch_name">
                    en <strong>{{ context.branch_name }}</strong>
                </template>
            </p>
        </div>
    </header>

    <section v-if="pendientes.length" class="card card--attention">
        <h2>Para terminar de configurar</h2>
        <ul>
            <li v-for="(item, index) in pendientes" :key="index">
                <Link v-if="item.route" :href="item.route">{{ item.text }}</Link>
                <span v-else>{{ item.text }}</span>
            </li>
        </ul>
    </section>

    <section class="card">
        <h2>Indicadores</h2>
        <p class="muted">
            Los tableros con métricas llegan con el motor de reportes. Se construyen sobre él a
            propósito: cualquier contador que se agregara aquí ahora sería una segunda fuente de
            agregación que después habría que desmontar.
        </p>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.card {
    background: #fff;
    border: 1px solid #e7e5e4;
    border-radius: 0.5rem;
    padding: 1.25rem;
    margin-bottom: 1rem;
    max-width: 44rem;
}

.card--attention {
    border-color: #fde68a;
    background: #fffbeb;
}

.card h2 {
    margin: 0 0 0.6rem;
    font-size: 1rem;
    font-weight: 600;
}

.card ul {
    margin: 0;
    padding-left: 1.1rem;
    font-size: 0.9rem;
    display: grid;
    gap: 0.35rem;
}

.muted {
    margin: 0;
    font-size: 0.9rem;
    color: #78716c;
}
</style>
