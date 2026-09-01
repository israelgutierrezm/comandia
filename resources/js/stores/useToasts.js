import { toast } from 'vue-sonner';

/**
 * Avisos de confirmación de acciones (toastr), sobre **vue-sonner** — el mismo que usa Acadion, montado como
 * `<Toaster position="bottom-right" rich-colors close-button />` en el layout.
 *
 * Este módulo es la fina capa por encima: mapea nuestros tonos a los de sonner y compone el mensaje según la acción,
 * para que TODAS las pantallas confirmen igual («Nueva sucursal creada exitosamente») sin repetir el texto ni el color.
 *
 * ## El color habla de la acción (a pedido)
 *
 * - **Crear → verde** (success): algo nuevo existe.
 * - **Editar → ámbar** (warning): algo que ya existía cambió; el ámbar avisa «ojo, se modificó».
 * - **Eliminar/dar de baja → rojo** (danger): algo dejó de estar.
 *
 * Los errores siguen sin toastearse desde aquí: la validación va por campo y los de negocio (403/409) donde el usuario
 * los mira. `toast.error` queda para el aviso puntual de una acción de un toque que no tiene formulario.
 */

/** Mapea un tono nuestro al método de sonner. `ok`/`success`→verde, `warning`→ámbar, `error`/`danger`→rojo. */
export function pushToast(text, tone = 'success') {
    if (tone === 'error' || tone === 'danger') {
        toast.error(text);
    } else if (tone === 'warning') {
        toast.warning(text);
    } else if (tone === 'info') {
        toast.info(text);
    } else {
        toast.success(text);
    }
}

export const toastOk = (text) => pushToast(text, 'success');
export const toastError = (text) => pushToast(text, 'error');

/**
 * Compone el mensaje y el tono de una acción CRUD, con el género del sustantivo.
 *
 * @param {{ kind: 'create'|'update'|'delete', entity: string, gender?: 'f'|'m' }} cfg
 *   `entity` es el sustantivo en singular con mayúscula inicial («Sucursal», «Almacén»); `gender` 'f' por omisión.
 * @returns {{ text: string, tone: 'success'|'warning'|'danger' }}
 */
export function mensajeAccion({ kind, entity, gender = 'f' }) {
    const o = gender === 'm' ? 'o' : 'a'; // terminación: creado/creada, editado/editada…
    const low = entity.charAt(0).toLowerCase() + entity.slice(1);

    if (kind === 'create') {
        return { text: `${gender === 'm' ? 'Nuevo' : 'Nueva'} ${low} cread${o} exitosamente.`, tone: 'success' };
    }

    if (kind === 'update') {
        return { text: `${entity} editad${o} exitosamente.`, tone: 'warning' };
    }

    if (kind === 'delete') {
        return { text: `${entity} eliminad${o} exitosamente.`, tone: 'danger' };
    }

    if (kind === 'archive') {
        return { text: `${entity} dad${o} de baja exitosamente.`, tone: 'danger' };
    }

    if (kind === 'restore') {
        return { text: `${entity} reactivad${o} exitosamente.`, tone: 'success' };
    }

    return { text: 'Listo.', tone: 'success' };
}

/** Muestra el toast de una acción CRUD ya compuesto. */
export function toastAccion(cfg) {
    const { text, tone } = mensajeAccion(cfg);
    pushToast(text, tone);
}
