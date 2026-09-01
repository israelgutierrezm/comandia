import { reactive } from 'vue';

/**
 * Avisos efímeros de confirmación de acciones (toastr), compartidos por todo el panel.
 *
 * ## Por qué a nivel de módulo, y no un componente con props
 *
 * El estado vive fuera de los componentes para que cualquier pantalla —o cualquier helper como `useApiForm`— avise sin
 * pasar props ni inyectar nada: se importa `pushToast` y ya. El host (`ToastHost`) lo pinta UNA sola vez desde el
 * layout, así no hay un toast por pantalla que se solape ni se pierda al navegar.
 *
 * ## No sustituye a los errores en su sitio
 *
 * El toastr es para CONFIRMAR (se guardó, se envió la comanda, se canceló). Los errores de validación siguen junto a su
 * campo y los de negocio (403/409) donde el usuario los está mirando; un toast que se va solo no es lugar para un error
 * que hay que leer y corregir.
 */
const toasts = reactive([]);
let seq = 0;

/**
 * Encola un aviso y devuelve su id (por si hay que cerrarlo a mano).
 *
 * @param {string} text  El mensaje.
 * @param {'ok'|'error'|'info'} type  El tono (confirmación, error, informativo).
 * @param {{ duration?: number }} opts  `duration` en ms; 0 lo deja hasta que se cierre a mano.
 */
export function pushToast(text, type = 'ok', { duration = 3200 } = {}) {
    const id = ++seq;
    toasts.push({ id, text, type });

    if (duration > 0) {
        setTimeout(() => dismissToast(id), duration);
    }

    return id;
}

export function dismissToast(id) {
    const i = toasts.findIndex((t) => t.id === id);

    if (i !== -1) {
        toasts.splice(i, 1);
    }
}

export const toastOk = (text, opts) => pushToast(text, 'ok', opts);
export const toastError = (text, opts) => pushToast(text, 'error', opts);

/** Para componentes que además necesitan leer la lista (el host) o cerrar a mano. */
export function useToasts() {
    return { toasts, pushToast, dismissToast };
}
