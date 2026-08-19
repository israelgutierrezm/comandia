import { computed, reactive, ref, watch } from 'vue';
import { api, ApiError } from '../api/client';

/**
 * Listado con filtros, búsqueda, orden y paginación contra `/api/v1`.
 *
 * Existe para que las nueve pantallas de administración no repitan nueve veces la misma
 * coreografía —cargar, filtrar, paginar, manejar errores—, y para que el manejo del formato de
 * error de §8 esté en un solo sitio: si cada pantalla interpretara los 422 por su cuen, tarde o
 * temprano alguna dejaría de mostrar los errores por campo y nadie lo notaría hasta que un usuario
 * no pudiera guardar sin saber por qué.
 *
 * Los filtros se mandan tal como los declara el endpoint. Si se manda uno que la whitelist del
 * servidor no reconoce, la respuesta es 422 y se ve: es el comportamiento deseado, porque un filtro
 * ignorado devolvería la lista completa a alguien que cree estar viendo una filtrada.
 */
export function useResourceList(endpoint, { initialFilters = {}, debounceMs = 250 } = {}) {
    const items = ref([]);
    const meta = ref({});
    const loading = ref(false);
    /** @type {import('vue').Ref<ApiError|null>} */
    const error = ref(null);

    const filters = reactive({ search: '', sort: '', page: 1, ...initialFilters });

    let timer = null;

    async function load() {
        loading.value = true;
        error.value = null;

        try {
            const response = await api.get(endpoint, filters);

            items.value = response.data ?? [];
            meta.value = response.meta ?? {};
        } catch (e) {
            if (e instanceof ApiError) {
                error.value = e;
                items.value = [];
            } else {
                throw e;
            }
        } finally {
            loading.value = false;
        }
    }

    /**
     * Se espera antes de consultar mientras se escribe en el buscador.
     *
     * Sin esto, teclear "hamburguesa" dispara once consultas y las respuestas pueden llegar
     * desordenadas: la lista terminaría mostrando el resultado de "hambur" después del de la
     * palabra completa.
     */
    function reload() {
        clearTimeout(timer);
        timer = setTimeout(load, debounceMs);
    }

    // Cambiar cualquier filtro vuelve a la primera página: quedarse en la página 4 de un listado
    // recién filtrado suele mostrar una lista vacía que parece "no hay resultados".
    watch(
        () => ({ ...filters, page: undefined }),
        () => {
            filters.page = 1;
            reload();
        },
        { deep: true },
    );

    watch(() => filters.page, load);

    const isEmpty = computed(() => !loading.value && items.value.length === 0 && error.value === null);

    return { items, meta, loading, error, filters, load, reload, isEmpty };
}

/**
 * Envío de un formulario contra la API, con los errores por campo listos para pintar.
 *
 * Devuelve `true` si se guardó. Es lo que permite escribir en la pantalla
 * `if (await form.submit()) cerrar()` sin manejar excepciones en cada componente.
 */
export function useApiForm(submitFn) {
    const processing = ref(false);
    const fieldErrors = ref({});
    const generalError = ref(null);

    async function submit(...args) {
        processing.value = true;
        fieldErrors.value = {};
        generalError.value = null;

        try {
            const result = await submitFn(...args);

            // Devuelve LO QUE EL CALLBACK PRODUJO, o `true` cuando no produjo nada.
            //
            // La primera versión devolvía siempre `true` y descartaba el valor, y eso obligaba a que una pantalla que
            // necesita el recurso creado —para navegar a su detalle, por ejemplo— lo guardara en un `ref` aparte. Peor:
            // invitaba a escribir `const creado = await save.submit()` y usar `creado.ulid`, que es `undefined` sin que
            // nada avise. Pasó al construir la pantalla de recepciones: navegaba a `/recepciones/undefined` y la ruta
            // simplemente no coincidía, así que no pasaba nada.
            //
            // `undefined → true` mantiene el contrato de los llamadores que ya existen: todos comprueban
            // `if (await submit())`, y un callback sin `return` no debe leerse como fallo.
            return result === undefined ? true : result;
        } catch (e) {
            if (!(e instanceof ApiError)) {
                throw e;
            }

            if (e.isValidation) {
                fieldErrors.value = e.fieldErrors;
            } else {
                // 403, 409 y demás: son mensajes escritos para el usuario final —"no se puede dar
                // de baja este almacén porque un área consume de él"— y se muestran tal cual.
                generalError.value = e.message;
            }

            return false;
        } finally {
            processing.value = false;
        }
    }

    return { processing, fieldErrors, generalError, submit };
}
