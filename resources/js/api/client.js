/**
 * Cliente de /api/v1 para la SPA de administración (D59).
 *
 * Inertia entrega el shell; los datos vienen de la MISMA API que consume la app Flutter. Es lo que
 * hace que esa API esté ejercitada de verdad: si la web usara props del servidor, los endpoints de
 * Flutter serían los menos probados y los que fallarían en producción.
 *
 * Autenticación por cookie de sesión —el usuario ya inició sesión en el shell—, así que no hay
 * tokens que guardar en el navegador. Sanctum exige que la petición venga de un dominio declarado
 * *stateful*, y el navegador manda `Referer` y `Origin` solo.
 */

import { usePage } from '@inertiajs/vue3';
import { marcarInicio, marcarFin } from './progress';

const BASE = '/api/v1';

/**
 * Una lista OPCIONAL de la pantalla: si el rol activo no tiene permiso de verla (403), se trata como vacía en
 * lugar de tumbar el `Promise.all` entero —con la cuenta o el turno que sí cargaron bien—. Cualquier otro
 * error se relanza: un 500 o una caída de red siguen viéndose. Úsese sólo para lo que la pantalla puede
 * omitir (los métodos de pago para quien no cobra, p. ej.), nunca para el dato principal.
 *
 * @template T
 * @param {Promise<T>} request
 * @returns {Promise<T | { data: [] }>}
 */
export function orEmptyWhenForbidden(request) {
    return request.catch((e) => {
        if (e instanceof ApiError && e.status === 403) {
            return { data: [] };
        }

        throw e;
    });
}

/**
 * Error de la API con el formato uniforme de §8.
 *
 * `type` es el código estable que el código compara; `title` es texto para humanos y puede cambiar
 * sin romper nada. Guardar los dos por separado evita que la UI acabe comparando cadenas
 * traducibles para decidir qué hacer.
 */
export class ApiError extends Error {
    constructor(payload) {
        const { type, title, status, errors } = payload;

        super(title ?? 'No se pudo completar la operación.');

        this.name = 'ApiError';
        this.type = type ?? 'http_error';
        this.status = status ?? 0;
        /** @type {Record<string, string[]>} */
        this.errors = errors ?? {};

        // El cuerpo COMPLETO, no sólo los cuatro campos comunes.
        //
        // Antes se descartaba, y con él campos que el servidor publica a propósito para que el cliente no tenga que
        // deducirlos. El caso concreto: la respuesta `authorization_required` trae `required_permission` justamente para
        // que la pantalla pueda pedir el PIN sin llevar su propia tabla de «qué permiso pide cada operación» (D170) — y
        // llegaba aquí para perderse.
        this.payload = payload;
    }

    /**
     * El texto para humanos (`title` del §8). Vive en `message` —se lo pasa el constructor a `super()`—, pero
     * unas cuarenta pantallas lo leen como `e.title`: sin este acceso, ese campo era siempre `undefined` y los
     * errores se pintaban en blanco (una caja roja vacía, un aviso sin texto). Una sola fuente, dos nombres.
     */
    get title() {
        return this.message;
    }

    /**
     * El servidor dice que la operación es válida pero le falta la firma de otra persona (D170).
     *
     * Es un 409 con `type: 'authorization_required'`, y NO un error de los datos: la pantalla tiene que abrir el
     * diálogo del PIN y reintentar, no pintar un mensaje rojo.
     */
    get isAuthorizationRequired() {
        return this.type === 'authorization_required';
    }

    /** El permiso que el autorizador necesita tener, tal como lo dijo el servidor. */
    get requiredPermission() {
        return this.payload?.required_permission ?? null;
    }

    /** Errores de validación por campo, listos para pintar bajo cada control. */
    get fieldErrors() {
        return Object.fromEntries(
            Object.entries(this.errors).map(([field, messages]) => [field, messages[0]]),
        );
    }

    get isValidation() {
        return this.type === 'validation_error';
    }

    get isForbidden() {
        return this.type === 'forbidden';
    }

    get isConflict() {
        return this.type === 'conflict';
    }

    get isUnauthenticated() {
        return this.status === 401;
    }
}

const AUTH_BOUNCE_KEY = 'comandia:auth-bounce';
const AUTH_BOUNCE_WINDOW_MS = 10_000;

/**
 * A dónde mandar tras un 401: la pantalla de bloqueo de la terminal compartida si esta sesión es de
 * un dispositivo, y el login en cualquier otro caso.
 *
 * Un 401 en una terminal compartida no es "expiró la sesión de usuario" —no hay usuario—: es que el
 * operador caducó por inactividad o hizo Salir, y el servidor dejó de reconocerlo. Ahí el destino es
 * la pantalla de bloqueo (`/terminal`), para volver a teclear el PIN, no `/login`.
 */
function bounceTarget() {
    try {
        if (usePage().props.shared_terminal?.active) {
            return '/terminal';
        }
    } catch {
        // `usePage()` sólo existe dentro de un componente; fuera de él, el login es el destino seguro.
    }

    return '/login';
}

/**
 * Manda al shell de entrada cuando la API responde 401, UNA sola vez por episodio.
 *
 * La primera versión de esto llamaba a `window.location.reload()` sin freno, dando por hecho que
 * 401 siempre significa "la sesión expiró" y que recargar acabaría en el login. No siempre: si el
 * 401 viene de una configuración —por ejemplo, servir en un host ausente de `sanctum.stateful`—
 * la sesión está viva, recargar devuelve la MISMA pantalla autenticada, y ésa vuelve a llamar a la
 * API. El resultado era un bucle infinito de recargas martillando el servidor. Lo encontró el
 * navegador: la suite no puede verlo porque no monta Vue.
 *
 * Con un intento acotado, el caso legítimo se resuelve igual y el caso roto degrada a un error
 * visible, que es lo que un desarrollador necesita ver.
 *
 * @returns {boolean} si se inició la navegación
 */
function bounceToLogin() {
    let last = 0;

    try {
        last = Number(window.sessionStorage.getItem(AUTH_BOUNCE_KEY) ?? 0);
    } catch {
        // Almacenamiento bloqueado (modo privado, políticas del navegador): sin memoria del
        // intento anterior, no se navega. Preferible un error visible a un bucle.
        return false;
    }

    if (Number.isFinite(last) && Date.now() - last < AUTH_BOUNCE_WINDOW_MS) {
        return false;
    }

    window.sessionStorage.setItem(AUTH_BOUNCE_KEY, String(Date.now()));
    window.location.assign(bounceTarget());

    return true;
}

/**
 * Cabeceras de contexto operativo (§8).
 *
 * Viajan en cada petición y el servidor las valida contra el alcance de la membresía: el cliente
 * elige ENTRE sus opciones, no las inventa. El tenant NO va aquí a propósito — sale de la sesión
 * (ADR-002), y mandarlo produce 422.
 */
function contextHeaders() {
    const headers = {};

    try {
        const context = usePage().props.context;

        if (context?.branch_ulid) headers['X-Branch'] = context.branch_ulid;
        if (context?.role_ulid) headers['X-Role'] = context.role_ulid;
        if (context?.terminal_ulid) headers['X-Terminal'] = context.terminal_ulid;
    } catch {
        // `usePage()` sólo existe dentro de un componente. Fuera de él no hay contexto que enviar,
        // y el servidor usará los valores por defecto de la membresía.
    }

    return headers;
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function request(method, path, { body, query } = {}) {
    const url = new URL(`${BASE}${path}`, window.location.origin);

    if (query) {
        for (const [key, value] of Object.entries(query)) {
            // Se omiten los vacíos en lugar de mandarlos: un filtro con cadena vacía sería un
            // filtro por cadena vacía, no la ausencia de filtro.
            if (value !== undefined && value !== null && value !== '') {
                url.searchParams.set(key, String(value));
            }
        }
    }

    marcarInicio();

    try {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...contextHeaders(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 204) {
        return null;
    }

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        if (response.status === 401 && !bounceToLogin()) {
            // Ya se intentó volver al login y seguimos recibiendo 401: no es una sesión expirada.
            // El mensaje nombra la causa más probable en lugar de dejar un "no autorizado" opaco.
            throw new ApiError({
                type: 'unauthenticated',
                title:
                    'La API no reconoce la sesión. Si el servidor corre en un host o puerto que no ' +
                    'está en SANCTUM_STATEFUL_DOMAINS, Sanctum ignora la cookie: revisa .env.',
                status: 401,
            });
        }

        throw new ApiError({ ...payload, status: response.status });
    }

    return payload;
    } finally {
        marcarFin();
    }
}

export const api = {
    get: (path, query) => request('GET', path, { query }),
    post: (path, body) => request('POST', path, { body }),
    put: (path, body) => request('PUT', path, { body }),
    patch: (path, body) => request('PATCH', path, { body }),
    delete: (path) => request('DELETE', path),
};

/** Lo más que el servidor entrega por página (`ListQuery`): pedir más devuelve esto, sin avisar. */
export const MAX_PER_PAGE = 100;

/**
 * TODAS las filas de un listado paginado, para las pantallas que necesitan el conjunto completo (el catálogo del POS,
 * los artículos elegibles de una promoción). Pedir `per_page: 200` no sirve: el servidor corta en 100 y el artículo 101
 * simplemente no aparecía. Se recorren las páginas por `meta.last_page`, con un tope como seguro contra un servidor que
 * nunca dijera cuál es la última.
 *
 * @param {string} path
 * @param {Record<string, unknown>} [query]
 * @param {{ maxPages?: number }} [options]
 * @returns {Promise<Array<any>>}
 */
export async function getAllPages(path, query = {}, { maxPages = 50 } = {}) {
    const filas = [];

    for (let page = 1; page <= maxPages; page++) {
        const respuesta = await api.get(path, { ...query, per_page: MAX_PER_PAGE, page });

        filas.push(...(respuesta?.data ?? []));

        if (page >= Number(respuesta?.meta?.last_page ?? 1)) {
            break;
        }
    }

    return filas;
}
