/**
 * Formatos de presentación que comparten las piezas de inventario: cantidades, fechas de calendario (caducidades,
 * recepciones) y la hora de la sucursal para capturar «cuándo ocurrió».
 *
 * ## Por qué viven aquí
 *
 * Los usan a la vez la pantalla de lotes, el panel de movimientos, el selector de lote y existencias. El sitio natural de
 * los de fecha sería `support/datetime.js`, junto a `formatInBranchTime`; se escribieron junto a los componentes de
 * inventario porque esta tanda no tocaba el soporte compartido. No tienen nada de inventario: si otra sección los
 * necesita, muévelos allá en lugar de copiarlos.
 *
 * ## Sólo presentan
 *
 * Aquí no se suma ni se redondea nada que el servidor vaya a usar. Las cantidades se reciben como cadena decimal
 * («12.5000», DECIMAL(12,4)) y sólo se pintan; la aritmética es del servidor (D134).
 */

const PARTES_DE_INSTANTE = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    // `h23` y no `hour12: false`: con el segundo, algunos motores escriben la medianoche como «24».
    hourCycle: 'h23',
};

/**
 * Las partes de pared de un instante en la zona dada.
 *
 * Si la zona falta o el navegador no la conoce, en la del navegador: una hora aproximada es mejor que un error que deje
 * el formulario inservible — el mismo criterio que `formatInBranchTime`.
 *
 * @param {Date} instante
 * @param {string|null|undefined} zona  Zona IANA de la sucursal.
 * @returns {Record<string, string>}
 */
function partesEnZona(instante, zona) {
    let formato;

    try {
        formato = new Intl.DateTimeFormat('en-US', { ...PARTES_DE_INSTANTE, ...(zona ? { timeZone: zona } : {}) });
    } catch (e) {
        if (!(e instanceof RangeError)) {
            throw e;
        }

        formato = new Intl.DateTimeFormat('en-US', PARTES_DE_INSTANTE);
    }

    return Object.fromEntries(
        formato.formatToParts(instante)
            .filter((parte) => parte.type !== 'literal')
            .map((parte) => [parte.type, parte.value]),
    );
}

/**
 * Una cantidad de inventario para presentar: «12.5» y no «12.5000». Hasta cuatro decimales, la escala de la columna.
 *
 * @param {string|number|null|undefined} valor  Como la manda la API (cadena decimal).
 * @returns {string} «—» si no hay cantidad, que no es lo mismo que «0».
 */
export function formatQuantity(valor) {
    if (valor === null || valor === undefined || valor === '') {
        return '—';
    }

    const numero = Number(valor);

    return Number.isFinite(numero) ? numero.toLocaleString('es-MX', { maximumFractionDigits: 4 }) : '—';
}

/**
 * Una fecha de CALENDARIO —columna DATE, «2026-10-01»— tal como es: el 1 de octubre, sin zona horaria.
 *
 * No se pasa por `formatInBranchTime` ni por `new Date('2026-10-01')`: los dos la leen como la medianoche UTC, y al
 * presentarla en México (UTC−6) se vuelve el 30 de septiembre. Una caducidad un día antes de la real es justo el dato
 * que no puede salir mal.
 *
 * @param {string|null|undefined} valor
 * @returns {string} Vacío si no hay fecha: el «no caduca» o el «—» lo decide quien pinta.
 */
export function formatCalendarDate(valor) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(valor ?? '');

    if (!partes) {
        return '';
    }

    return new Date(Number(partes[1]), Number(partes[2]) - 1, Number(partes[3]))
        .toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Hoy como fecha de calendario («2026-09-23») en la zona de la sucursal: el valor por omisión de una fecha de recepción
 * y el tope de un `<input type="date">` que no admite el futuro.
 *
 * @param {string|null|undefined} zona
 * @returns {string}
 */
export function todayInZone(zona) {
    const p = partesEnZona(new Date(), zona);

    return `${p.year}-${p.month}-${p.day}`;
}

/**
 * Ahora, con el formato de `<input type="datetime-local">` («2026-09-23T14:05»), en la hora de la sucursal. Es el tope
 * del campo «cuándo ocurrió»: el servidor no admite movimientos en el futuro.
 *
 * @param {string|null|undefined} zona
 * @returns {string}
 */
export function nowForDateTimeInput(zona) {
    const p = partesEnZona(new Date(), zona);

    return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
}

/**
 * Convierte una hora de PARED de la sucursal —lo que se teclea en un `datetime-local`— al instante en UTC, en ISO 8601.
 *
 * ## Por qué hace falta
 *
 * El campo entrega «2026-09-20T08:00» sin zona. Mandarlo así haría que el servidor lo leyera en SU zona (UTC), y un
 * movimiento de las 8 de la mañana en Monterrey quedaría registrado a las 2 de la madrugada. La hora que se teclea es la
 * de la sucursal, que es en la que se presentan todas las demás (§7), así que se convierte con esa zona.
 *
 * ## Cómo
 *
 * Se estima el instante tratando la hora de pared como si fuera UTC, se mide el desfase de la zona en ese instante y se
 * corrige. Dos pasadas y no una, porque la frontera norte sí cambia de horario: si entre la estimación y el resultado
 * hubo cambio, la segunda medición es la que vale.
 *
 * @param {string|null|undefined} valor  «AAAA-MM-DDTHH:MM» (los segundos son opcionales).
 * @param {string|null|undefined} zona  Zona IANA de la sucursal; sin ella, la del navegador.
 * @returns {string|null} El instante en ISO 8601 (UTC), o `null` si el valor no es una hora de pared.
 */
export function zonedWallTimeToIso(valor, zona) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(valor ?? '');

    if (!partes) {
        return null;
    }

    const [anio, mes, dia, hora, minuto] = partes.slice(1, 6).map(Number);
    const segundo = Number(partes[6] ?? 0);

    if (!zona) {
        return new Date(anio, mes - 1, dia, hora, minuto, segundo).toISOString();
    }

    const comoSiFueraUtc = Date.UTC(anio, mes - 1, dia, hora, minuto, segundo);

    const desfaseEn = (instante) => {
        const p = partesEnZona(new Date(instante), zona);

        return Date.UTC(
            Number(p.year), Number(p.month) - 1, Number(p.day), Number(p.hour), Number(p.minute), Number(p.second),
        ) - instante;
    };

    const estimado = comoSiFueraUtc - desfaseEn(comoSiFueraUtc);

    return new Date(comoSiFueraUtc - desfaseEn(estimado)).toISOString();
}
