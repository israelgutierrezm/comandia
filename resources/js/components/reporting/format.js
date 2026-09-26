import { formatMoney } from '../../support/money';

/**
 * Presentación de cifras y metas de reportes, compartida por la pantalla de Reportes, el tablero y sus indicadores.
 *
 * Sólo PRESENTA: el motor ya sumó y redondeó en el servidor (D134) y el estado del semáforo lo decide el servidor
 * (`EvaluateGoal`); aquí no se opera ninguna cifra. Vive en un solo lugar para que el mismo importe no se lea de dos
 * formas entre la tabla del reporte y el indicador del tablero.
 */

// Una sola instancia: construir un `Intl.NumberFormat` es caro y una tabla pinta cientos de celdas. Hasta 4 decimales
// porque las cantidades de inventario y las metas son DECIMAL(…, 4): «12.5000» se lee «12.5», «100.0000» se lee «100».
const NUMERO = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 4 });

/**
 * @param {string|number|null|undefined} value  La cifra como la manda la API (cadena decimal) o un número.
 * @param {string} [format]  El `format` de la medida en la definición del reporte: money, percent, quantity, integer…
 * @returns {string} «—» cuando no hay cifra, que no es lo mismo que cero.
 */
export function formatMeasure(value, format) {
    if (value === null || value === undefined || value === '') return '—';
    if (format === 'money') return formatMoney(value);

    // Un valor que no es número (un error aguas arriba) se pinta tal cual, no como «NaN».
    if (! Number.isFinite(Number(value))) return String(value);

    const numero = NUMERO.format(value);

    return format === 'percent' ? `${numero}%` : numero;
}

/**
 * Periodos de una meta, con el orden en que se listan. `adjective` concuerda con «meta» (femenino) y `progress` dice qué
 * compara el semáforo: lo que VA del periodo en curso, del inicio del periodo a hoy (`EvaluateGoal::periodRange`).
 */
export const GOAL_PERIODS = [
    { value: 'day', label: 'Día', adjective: 'diaria', progress: 'lo que va del día' },
    { value: 'week', label: 'Semana', adjective: 'semanal', progress: 'lo que va de la semana' },
    { value: 'month', label: 'Mes', adjective: 'mensual', progress: 'lo que va del mes' },
    { value: 'year', label: 'Año', adjective: 'anual', progress: 'lo que va del año' },
];

export function goalPeriod(value) {
    return GOAL_PERIODS.find((p) => p.value === value) ?? { value, label: value, adjective: value, progress: value };
}

/** Dirección de una meta: si la medida mejora al subir (ventas) o al bajar (mermas, descuentos). */
export const GOAL_DIRECTIONS = [
    { value: 'higher_better', label: 'Más es mejor', example: 'p. ej., ventas' },
    { value: 'lower_better', label: 'Menos es mejor', example: 'p. ej., mermas o descuentos' },
];

export function goalDirectionLabel(value) {
    return GOAL_DIRECTIONS.find((d) => d.value === value)?.label ?? value;
}
