import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * ¿Se captura el PIN con el teclado en pantalla?
 *
 * Dos niveles (D20). El negocio lo enciende por SUCURSAL (`pos.onscreen_pin_keypad`, resuelto en el shell), y cada
 * DISPOSITIVO puede forzarlo o apagarlo localmente — una misma sucursal tiene la caja táctil y el escritorio de la
 * trastienda, y el modo de captura es del aparato, no del negocio. Efectivo = override del dispositivo; si no hay,
 * el default de la sucursal.
 *
 * El override vive en `localStorage` y en un `ref` de MÓDULO (uno solo para toda la app): así, al cambiarlo desde
 * Apariencia, cualquier diálogo de PIN abierto se repinta al instante sin recargar.
 */
const STORAGE_KEY = 'comandia.pin_keypad_override'; // 'seguir' | 'on' | 'off'

function leerOverride() {
    try {
        const valor = localStorage.getItem(STORAGE_KEY);

        return valor === 'on' || valor === 'off' ? valor : 'seguir';
    } catch {
        // Modo privado o storage bloqueado: se sigue a la sucursal.
        return 'seguir';
    }
}

const override = ref(leerOverride());

export function usePinKeypad() {
    const page = usePage();

    /** Lo que decidió la sucursal (llega en el shell). */
    const defaultSucursal = computed(() => Boolean(page.props.onscreen_pin_keypad));

    /** El valor que realmente aplica en esta terminal. */
    const activo = computed(() => {
        if (override.value === 'on') {
            return true;
        }

        if (override.value === 'off') {
            return false;
        }

        return defaultSucursal.value;
    });

    /** Cambia la preferencia local del dispositivo. `'seguir'` borra el override y vuelve a heredar de la sucursal. */
    function fijarOverride(valor) {
        override.value = valor;

        try {
            if (valor === 'seguir') {
                localStorage.removeItem(STORAGE_KEY);
            } else {
                localStorage.setItem(STORAGE_KEY, valor);
            }
        } catch {
            // Sin persistencia: el cambio se queda sólo en memoria hasta recargar. No es un error que deba interrumpir.
        }
    }

    return { override, activo, defaultSucursal, fijarOverride };
}
