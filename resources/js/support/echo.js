import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * El cliente de WebSockets, creado **una vez y sólo si hace falta**.
 *
 * ## Perezoso a propósito
 *
 * La mayoría de las pantallas del shell son listas y formularios que no necesitan tiempo real. Abrir una conexión al
 * cargar cualquiera de ellas gastaría un socket por pestaña para nada, y en un negocio con seis terminales abiertas en
 * la pantalla de artículos serían seis conexiones ociosas.
 *
 * ## Y tolerante a que no exista
 *
 * Si falta la configuración —una instalación que todavía no levantó Reverb, o un entorno donde no se quiere— esto
 * devuelve `null` en lugar de reventar. La pantalla que lo pide ya sabe funcionar sin socket: tiene su respaldo de
 * sondeo, que es obligatorio por §6.9. Un error aquí convertiría «no hay tiempo real» en «la pantalla no carga», que
 * es infinitamente peor.
 */
let instancia = null;
let intentado = false;

export function echo() {
    if (intentado) {
        return instancia;
    }

    intentado = true;

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (! key) {
        return null;
    }

    try {
        // `laravel-echo` espera Pusher en el ámbito global: es el protocolo que Reverb habla.
        window.Pusher = Pusher;

        instancia = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    } catch {
        instancia = null;
    }

    return instancia;
}

/**
 * Suscribirse a un canal privado, con aviso de conexión y de caída.
 *
 * Devuelve una función para darse de baja. Sin ella, cambiar de pantalla dejaría la suscripción viva y la siguiente
 * visita abriría otra encima: en una terminal que lleva días abierta, eso se acumula hasta que el servidor empieza a
 * rechazar suscripciones y nadie sabe por qué.
 *
 * `onConectado` y `onCaido` existen porque la pantalla tiene que **decir** en cuál de los dos está. «No se actualiza»
 * y «se actualiza cada diez segundos» son situaciones distintas, y quien opera un piso lleno merece saber cuál es.
 */
export function suscribir(canal, eventos, { onConectado, onCaido } = {}) {
    const cliente = echo();

    if (! cliente) {
        return () => {};
    }

    const suscripcion = cliente.private(canal);

    Object.entries(eventos).forEach(([nombre, manejador]) => {
        suscripcion.listen(`.${nombre}`, manejador);
    });

    const conector = cliente.connector?.pusher?.connection;

    if (conector) {
        conector.bind('connected', () => onConectado?.());
        conector.bind('unavailable', () => onCaido?.());
        conector.bind('failed', () => onCaido?.());
        conector.bind('disconnected', () => onCaido?.());

        // Ya conectado antes de suscribirse: el evento `connected` no volverá a dispararse, y la pantalla se quedaría
        // diciendo que sondea mientras el socket funciona.
        if (conector.state === 'connected') {
            onConectado?.();
        }
    }

    return () => cliente.leave(canal);
}
