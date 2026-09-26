/**
 * Dinero para PRESENTAR, con el formato de México: «$1,500.00».
 *
 * ## Uno solo, y por qué
 *
 * Cada pantalla del POS lo formateaba a su manera: la cuenta pintaba «$1500.00» (sin separador de miles), la lista de
 * cuentas usaba `Intl` y la caja insertaba las comas a mano. En la misma operación —marcar, cobrar, cortar— el mismo
 * importe se leía de tres formas, y eso es justo lo que hace dudar de una cifra. Aquí se define una vez.
 *
 * ## Sólo presenta; no opera
 *
 * El dinero lo calcula y lo redondea el servidor (D134): aquí no se suma ni se redondea nada. Por eso la cadena decimal
 * que manda la API («1500.00») se le pasa TAL CUAL al formateador: los navegadores actuales la leen como decimal exacto,
 * sin pasar por un `float`. Uno viejo la convierte a número, y un DECIMAL(12,2) cabe en él sin pérdida.
 */

// Una sola instancia: construir un `Intl.NumberFormat` es caro, y la pantalla de la cuenta pinta decenas de importes en
// cada render.
const FORMATO = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

/**
 * @param {string|number|null|undefined} value  El importe como lo manda la API (cadena decimal) o un número.
 * @returns {string} «$1,500.00» / «-$50.00»; «—» si no hay importe, que no es lo mismo que «$0.00». Un valor que no es
 *   número (un error aguas arriba) también se pinta como ausente, no como «$NaN».
 */
export function formatMoney(value) {
    const crudo = typeof value === 'string' ? value.trim() : value;

    if (crudo === null || crudo === undefined || crudo === '' || ! Number.isFinite(Number(crudo))) {
        return '—';
    }

    return FORMATO.format(crudo);
}
