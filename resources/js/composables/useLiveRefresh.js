import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Mantener una pantalla al día, con o sin WebSocket.
 *
 * ## Por qué el respaldo de sondeo no es un adorno
 *
 * La Especificación lo exige —«Reverb … con **fallback de polling**» (§6.9)— y en este proyecto tiene además una razón
 * concreta: **en desarrollo no corre `queue:work`**, y la difusión va por cola. Sin respaldo, el piso se vería
 * congelado en cada máquina de desarrollo y parecería un defecto del sistema. Es exactamente la trampa que D229
 * documentó con el costeo, repetida en otra superficie.
 *
 * ## Uno o el otro, nunca los dos
 *
 * Con el socket vivo, sondear sería pedir cada diez segundos algo que ya llega solo. El sondeo se **apaga** al
 * conectar y se **enciende** al caer, y esa transición es el valor de este archivo: escrita en cada pantalla, alguna
 * se quedaría sondeando para siempre y nadie lo notaría hasta ver la gráfica de peticiones.
 *
 * ## Y la pantalla dice en cuál está
 *
 * `source` sale a la interfaz a propósito. «No se actualiza», «se actualiza al instante» y «se actualiza cada diez
 * segundos» son tres situaciones distintas, y quien opera un piso lleno merece saber en cuál está antes de fiarse de
 * lo que ve.
 */
export function useLiveRefresh(refrescar, { intervalMs = 10000 } = {}) {
    /** `'socket'`, `'polling'` o `'idle'` mientras arranca. */
    const source = ref('idle');
    const lastRefreshAt = ref(null);

    let temporizador = null;

    function marcar() {
        lastRefreshAt.value = new Date();
    }

    async function refrescarYMarcar() {
        await refrescar();
        marcar();
    }

    function empezarSondeo() {
        if (temporizador !== null) {
            return;
        }

        source.value = 'polling';
        temporizador = window.setInterval(refrescarYMarcar, intervalMs);
    }

    function pararSondeo() {
        if (temporizador === null) {
            return;
        }

        window.clearInterval(temporizador);
        temporizador = null;
    }

    /** El socket conectó: se apaga el sondeo y se refresca una vez, por si algo pasó mientras no había nadie oyendo. */
    function socketConectado() {
        pararSondeo();
        source.value = 'socket';
        refrescarYMarcar();
    }

    /** El socket cayó: vuelve el sondeo, y con él la única garantía de que la pantalla no miente. */
    function socketCaido() {
        empezarSondeo();
    }

    onMounted(() => {
        marcar();
        empezarSondeo();
    });

    // Sin esto, salir de la pantalla dejaría el temporizador vivo: la petición seguiría saliendo cada diez segundos
    // contra una pantalla que ya no existe, y en una sesión larga se acumularía uno por visita.
    onBeforeUnmount(pararSondeo);

    return { source, lastRefreshAt, socketConectado, socketCaido, refrescarYMarcar };
}
