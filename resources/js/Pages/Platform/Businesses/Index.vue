<script setup>
import { Head, Link } from '@inertiajs/vue3';

/**
 * Los negocios de la plataforma. Aquí se ven todos —es la superficie que agrega entre negocios— y desde aquí se da de
 * alta uno nuevo.
 */
defineProps({
    businesses: { type: Array, required: true },
});

// Color de la pastilla por estado: verde = opera, ámbar = requiere atención, gris = fuera de servicio.
const BADGE = {
    active: 'ok',
    pending_activation: 'warn',
    read_only: 'warn',
    suspended: 'off',
    pending_deletion: 'off',
    cancelled: 'off',
};
</script>

<template>
    <Head title="Plataforma · Negocios" />

    <div class="negocios">
        <header class="cab">
            <h1>Negocios</h1>
            <Link href="/plataforma/negocios/nuevo" class="btn">Nuevo negocio</Link>
        </header>

        <div v-if="businesses.length" class="tabla-envoltura tarjeta">
            <table>
                <thead>
                    <tr><th>Negocio</th><th>Dirección</th><th>Estado</th><th>Contacto</th><th>Alta</th></tr>
                </thead>
                <tbody>
                    <tr v-for="b in businesses" :key="b.ulid">
                        <td class="nombre">{{ b.name }}</td>
                        <td class="min">{{ b.slug }}</td>
                        <td><span class="badge" :class="`badge--${BADGE[b.status] ?? 'off'}`">{{ b.status_label }}</span></td>
                        <td class="min">{{ b.contact_email }}</td>
                        <td class="min">{{ b.created_at }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else class="vacio">Aún no hay negocios. Da de alta el primero.</p>
    </div>
</template>

<style scoped>
.negocios { --plat: #4f46e5; display: grid; gap: 1.25rem; max-width: 60rem; }
.cab { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.cab h1 { margin: 0; font-size: 1.4rem; font-weight: 650; letter-spacing: -0.015em; }

.btn {
    font: inherit; font-size: 0.9rem; font-weight: 600; padding: 0.55rem 1.1rem; border-radius: 0.5rem;
    color: #fff; background: var(--plat); text-decoration: none; box-shadow: 0 6px 14px -6px rgb(79 70 229 / 0.6);
}
.btn:hover { filter: brightness(1.06); }

.tarjeta { background: var(--color-superficie); border: 1px solid var(--color-borde); border-radius: 0.75rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06); }
.tabla-envoltura { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
th, td { text-align: left; padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--color-borde); }
tr:last-child td { border-bottom: 0; }
th { font-size: 0.76rem; font-weight: 600; color: var(--color-suave); text-transform: uppercase; letter-spacing: 0.03em; }
.nombre { font-weight: 600; }
.min { color: var(--color-suave); white-space: nowrap; }

.badge { display: inline-flex; align-items: center; padding: 0.14rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
.badge--ok { background: var(--color-exito-tenue); color: var(--color-exito); }
.badge--warn { background: var(--color-aviso-tenue); color: var(--color-aviso); }
.badge--off { background: color-mix(in srgb, var(--color-suave) 15%, transparent); color: var(--color-suave); }

.vacio { color: var(--color-suave); }
</style>
