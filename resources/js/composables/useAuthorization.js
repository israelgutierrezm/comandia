import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Autorización en el frontend (ARQUITECTURA_MAESTRA §9, §4.2).
 *
 * ## Esto NO es seguridad
 *
 * Es presentación. La autorización de verdad la aplica el servidor en cada endpoint, y así tiene
 * que seguir siendo: cualquiera puede editar el estado del navegador. Lo que esto evita es ofrecer
 * un botón que al pulsarlo devuelve 403, porque una interfaz que promete lo que no cumple enseña al
 * usuario a desconfiar de ella.
 *
 * Los permisos son los del **rol activo** (D9), no la suma de roles: los comparte el shell y salen
 * del mismo servicio que decide en el servidor, así que las dos respuestas no pueden divergir.
 */
export function useAuthorization() {
    const page = usePage();

    const permissions = computed(() => page.props.permissions ?? []);
    const activeModules = computed(() => page.props.active_modules ?? []);
    const isReadOnly = computed(() => page.props.context?.is_read_only === true);

    /** ¿El rol activo tiene este permiso? */
    function can(permission) {
        return permissions.value.includes(permission);
    }

    /**
     * ¿Puede ESCRIBIR con este permiso?
     *
     * Un tenant en sólo lectura por impago conserva sus permisos —los datos son suyos y puede
     * consultarlos y exportarlos— y no puede operar. Separar las dos preguntas evita que la UI
     * oculte información que el usuario sí tiene derecho a ver.
     */
    function canWrite(permission) {
        return can(permission) && !isReadOnly.value;
    }

    /** ¿El tenant tiene contratado este módulo activable? */
    function hasModule(module) {
        return activeModules.value.includes(module);
    }

    return { permissions, can, canWrite, hasModule, isReadOnly };
}
