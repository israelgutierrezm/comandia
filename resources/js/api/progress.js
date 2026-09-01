import { ref } from 'vue';

/**
 * Actividad de la API en curso, para la barra de carga superior.
 *
 * La barra de Inertia sólo aparece en navegaciones; los datos de dominio los trae el cliente `api` por `fetch`, y esas
 * cargas son las que dejaban un «Cargando…» de texto en cada pantalla. Aquí llevamos cuántas peticiones hay en vuelo:
 * la barra se muestra mientras haya al menos una. Un contador (no un booleano) para que dos peticiones simultáneas no
 * apaguen la barra cuando termina la primera.
 */
export const peticionesEnVuelo = ref(0);

export function marcarInicio() {
    peticionesEnVuelo.value += 1;
}

export function marcarFin() {
    peticionesEnVuelo.value = Math.max(0, peticionesEnVuelo.value - 1);
}
