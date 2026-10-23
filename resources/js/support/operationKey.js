/**
 * Una llave por INTENTO de registrar algo que no debe quedar dos veces —un abono, un ajuste— (D378).
 *
 * Se genera al abrir el formulario y viaja con el envío: si la red se cae y se reintenta, o alguien da doble clic, el
 * servidor reconoce la llave y devuelve lo que ya registró en vez de registrarlo otra vez. Un formulario nuevo trae una
 * llave nueva.
 *
 * `crypto.randomUUID` sólo existe en un contexto seguro (HTTPS o localhost); una tableta que entra por la IP del local
 * usa `getRandomValues`, que existe en cualquier contexto.
 *
 * @returns {string} de 32 a 36 caracteres: letras, números y guiones
 */
export function newOperationKey() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}
