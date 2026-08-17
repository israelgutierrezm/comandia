import { usePage } from '@inertiajs/vue3';

/**
 * Directiva `v-can` (ARQUITECTURA_MAESTRA §9).
 *
 *     <button v-can="'organization.branches.manage'">Nueva sucursal</button>
 *     <button v-can.write="'organization.branches.manage'">Guardar</button>
 *
 * El modificador `.write` añade la condición de que el tenant admita escrituras: un tenant en sólo
 * lectura por impago conserva sus permisos de lectura y no puede operar.
 *
 * ## Esto NO es seguridad
 *
 * Es presentación. La autorización real la aplica el servidor en cada endpoint. Lo que esto evita es
 * ofrecer un botón que al pulsarlo devuelve 403, porque una interfaz que promete lo que no cumple
 * enseña al usuario a desconfiar de ella.
 *
 * ## Por qué dos hooks y no uno
 *
 * En `beforeMount` el elemento **todavía no está en el DOM**, así que no tiene padre y no se puede
 * quitar; lo que sí se puede es marcarlo oculto antes de que se pinte. En `mounted` ya tiene padre y
 * se puede eliminar de verdad. Usar sólo `mounted` produciría un parpadeo del botón prohibido, y
 * usar sólo `beforeMount` dejaría el marcado en el documento —y con él pistas sobre qué existe
 * detrás de un permiso que la persona no tiene—.
 *
 * ## De dónde salen los permisos, y de dónde NO
 *
 * De `usePage()`, que en el adaptador de Vue 3 lee una referencia de ámbito de módulo y no necesita
 * instancia de componente.
 *
 * La primera versión los leía de `vnode.appContext.config.globalProperties.$page`, y eso está mal:
 * Vue sólo puebla `appContext` en el vnode RAÍZ de la aplicación. En cualquier otro componente es
 * `undefined`, así que la lista de permisos era siempre vacía y la directiva ocultaba TODAS las
 * acciones —con permiso o sin él—. El fallo era invisible en revisión de código, porque ocultar es
 * exactamente lo que la directiva debe hacer cuando no hay permiso; sólo se nota abriendo la
 * pantalla y viendo que al propietario no le queda ni un botón.
 */
function permissionsSnapshot() {
    const props = usePage()?.props ?? {};

    return {
        permissions: Array.isArray(props.permissions) ? props.permissions : [],
        isReadOnly: props.context?.is_read_only === true,
    };
}

export function isAllowed(binding, snapshot = permissionsSnapshot()) {
    const permitted = snapshot.permissions.includes(binding.value);

    return binding.modifiers?.write ? permitted && !snapshot.isReadOnly : permitted;
}

export const canDirective = {
    beforeMount(el, binding) {
        if (!isAllowed(binding)) {
            // Antes del primer pintado: sin esto habría un parpadeo.
            el.style.display = 'none';
            el.dataset.canDenied = 'true';
        }
    },

    mounted(el) {
        if (el.dataset.canDenied === 'true') {
            el.remove();
        }
    },
};
