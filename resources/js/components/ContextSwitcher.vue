<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { api } from '../api/client';

/**
 * Selector de contexto operativo: negocio, sucursal y rol activo.
 *
 * Los tres son elecciones del operador **entre lo que ya tiene concedido** (§8): el servidor
 * revalida cada una contra el alcance de la membresía, así que esto es comodidad, no privilegio.
 *
 * El cambio de negocio pasa por el shell —es una operación de sesión y se audita—; los de sucursal y
 * rol viajan como cabeceras `X-Branch` y `X-Role` en cada llamada a la API. Por eso, al cambiarlos,
 * se recarga el shell: los permisos del rol activo y la sucursal alimentan la navegación, y dejarlos
 * desincronizados mostraría un menú que no corresponde al contexto real.
 */
const page = usePage();

const context = computed(() => page.props.context);

const branches = ref([]);
const roles = ref([]);
const loading = ref(false);

onMounted(async () => {
    loading.value = true;

    try {
        // Sólo lo que la membresía alcanza: el endpoint ya filtra por tenant, y las sucursales
        // fuera de alcance darían 403 al intentar usarlas.
        const [branchesResponse, contextResponse] = await Promise.all([
            api.get('/branches', { status: 'active', per_page: 100 }),
            api.get('/context'),
        ]);

        branches.value = branchesResponse.data;

        // Los roles ASIGNADOS, no el activo repetido. La primera versión ponía aquí `[active_role]`
        // porque no existía la lista: un selector de una sola opción, inútil justo en el producto
        // donde el rol activo decide todo. Listar los roles asignados no contradice D9 —listar no
        // es sumar—: los permisos siguen siendo los de UN rol y el servidor revalida la elección.
        roles.value = contextResponse.data.assigned_roles ?? [];
    } catch {
        // Un fallo aquí no debe romper el shell: el selector queda vacío y la persona sigue
        // operando con su contexto por defecto.
        branches.value = [];
    } finally {
        loading.value = false;
    }
});

function switchBranch(ulid) {
    if (!ulid || ulid === context.value?.branch_ulid) {
        return;
    }

    // Se recarga el shell para que la navegación y los permisos correspondan a la sucursal nueva.
    router.visit(window.location.pathname, {
        headers: { 'X-Branch': ulid },
        preserveScroll: true,
    });
}

/**
 * Cambiar de rol activo.
 *
 * Obliga a recargar el shell, y no es un detalle: con D9 los permisos son los del rol activo, así
 * que la navegación entera y todo `v-can` cambian. Dejar la pantalla como estaba mostraría un menú
 * que no corresponde a lo que la persona puede hacer.
 *
 * ## La cabecera ya no es la única portadora del rol (D234)
 *
 * Hasta la Iteración 4, `X-Role` valía para **una sola visita** y la navegación siguiente volvía al
 * rol por omisión sin avisar: este selector presentaba como estado algo que no lo era. Ahora el
 * servidor **recuerda** el rol elegido, así que la cabecera es la forma de *cambiarlo*, no de
 * sostenerlo. El selector dice la verdad durante la jornada, y al volver a iniciar sesión se vuelve
 * al rol por omisión.
 */
function switchRole(ulid) {
    if (!ulid || ulid === context.value?.role_ulid) {
        return;
    }

    router.visit(window.location.pathname, {
        headers: { 'X-Role': ulid },
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="switcher">
        <button class="switcher__tenant" type="button" @click="router.visit('/negocios')">
            <span class="switcher__label">Negocio</span>
            <span class="switcher__value">{{ context?.tenant?.name ?? '—' }}</span>
        </button>

        <label class="switcher__field">
            <span class="switcher__label">Sucursal</span>
            <select
                class="switcher__select"
                :value="context?.branch_ulid ?? ''"
                :disabled="loading || branches.length === 0"
                @change="switchBranch($event.target.value)"
            >
                <option v-if="!context?.branch_ulid" value="">Sin seleccionar</option>
                <option v-for="branch in branches" :key="branch.ulid" :value="branch.ulid">
                    {{ branch.name }}
                </option>
            </select>
        </label>

        <!-- Sólo si hay más de uno: con un rol asignado no hay nada que elegir, y un selector de una
             sola opción sugiere que existe una decisión que no existe. -->
        <label v-if="roles.length > 1" class="switcher__field">
            <span class="switcher__label">Rol activo</span>
            <select
                class="switcher__select"
                :value="context?.role_ulid ?? ''"
                :disabled="loading"
                @change="switchRole($event.target.value)"
            >
                <option v-for="role in roles" :key="role.ulid" :value="role.ulid">
                    {{ role.name }}
                </option>
            </select>
        </label>
    </div>
</template>

<style scoped>
.switcher {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.switcher__tenant,
.switcher__field {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.1rem;
    background: none;
    border: 0;
    padding: 0;
    cursor: pointer;
    font: inherit;
    text-align: left;
}

.switcher__label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.55;
}

.switcher__value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #c2410c;
}

.switcher__select {
    font: inherit;
    font-size: 0.9rem;
    border: 1px solid #d6d3d1;
    border-radius: 0.375rem;
    padding: 0.15rem 0.35rem;
    background: #fff;
}
</style>
