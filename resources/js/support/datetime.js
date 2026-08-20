/**
 * Fechas y horas en la hora de la SUCURSAL, no la del navegador.
 *
 * ## Por qué no basta con `toLocaleString('es-MX')`
 *
 * Eso presenta en la zona horaria de la máquina que mira. Un gerente en Cancún revisando el corte de una sucursal de
 * Tijuana vería el turno abriéndose dos horas más tarde de lo que ocurrió — y en un corte, la hora es la que decide a
 * qué jornada pertenece el dinero. La regla del proyecto es explícita: los timestamps viajan en UTC y se **presentan**
 * en la zona de la sucursal (§7, y la convención de datos de la Arquitectura).
 *
 * ## El dato ya viajaba y nadie lo usaba
 *
 * `RequestContextResource` manda `active_branch.timezone` desde la Iteración 1, con un comentario que dice para qué es.
 * Al escribir la pantalla de caja resultó que **ninguna** pantalla lo consumía: todas las fechas del shell se pintaban
 * con la hora del navegador. Un dato servido y no consumido es una intención documentada sin implementar, y se ve igual
 * que si funcionara — hasta el día que dos zonas horarias no coinciden.
 *
 * Las pantallas viejas siguen con la hora del navegador; retrofitearlas es trabajo aparte y está anotado. Lo que no
 * volverá a pasar es que una pantalla nueva lo haga mal por no tener a mano cómo hacerlo bien.
 */

/**
 * Fecha y hora de un instante ISO, en la zona indicada.
 *
 * @param {string|null|undefined} iso  El instante en UTC, tal como lo manda la API.
 * @param {string|null|undefined} timezone  Zona IANA de la sucursal. Si falta, se cae a la del navegador: es mejor una
 *   hora aproximada que un guion, porque la alternativa es que quien opera no vea NADA. La ausencia es visible en el
 *   contexto, no aquí.
 * @returns {string} Vacío si no hay instante — un «—» lo decide quien pinta, no este ayudante.
 */
export function formatInBranchTime(iso, timezone) {
    if (! iso) {
        return '';
    }

    const instante = new Date(iso);

    if (Number.isNaN(instante.getTime())) {
        return '';
    }

    return instante.toLocaleString('es-MX', {
        dateStyle: 'short',
        timeStyle: 'short',
        ...(timezone ? { timeZone: timezone } : {}),
    });
}
