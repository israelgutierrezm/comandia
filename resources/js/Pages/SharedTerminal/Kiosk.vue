<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../api/client';
import PinKeypad from '../../components/PinKeypad.vue';

/**
 * La antesala de una TERMINAL COMPARTIDA (ADR-012), a pantalla completa y sin usuario.
 *
 * Tres estados, resueltos del prop `shared_terminal` que arma el shell:
 *
 *  - **Sin dispositivo** → *bootstrap*: se pega el código de activación (el secreto que entregó el
 *    enrolamiento). Se guarda en este aparato y se canjea por una sesión de dispositivo. Si ya había uno
 *    guardado, se canjea solo al abrir, y la tablet queda "pegada" como terminal.
 *  - **Dispositivo sin operador** → *bloqueo*: código de empleado + PIN (teclado en pantalla). Al
 *    identificarse, entra al POS.
 *  - **Dispositivo con operador** → ya está dentro: se salta al POS.
 *
 * El destino tras el PIN es el PISO (la vista de conjunto del turno del mesero). El shell del POS corre
 * en modo kiosco: marco propio con el operador y "Salir", sin navegación de administración.
 */
const SECRET_KEY = 'comandia.shared_terminal_secret';
const POS_HOME = '/admin/pos/piso';

const page = usePage();
const st = computed(() => page.props.shared_terminal);

const modo = ref('cargando'); // 'cargando' | 'bootstrap' | 'bloqueo'
const secretInput = ref('');
const employeeCode = ref('');
const pin = ref('');
const procesando = ref(false);
const error = ref('');

function leerSecretoGuardado() {
    try {
        return localStorage.getItem(SECRET_KEY) || '';
    } catch {
        return '';
    }
}

function guardarSecreto(valor) {
    try {
        localStorage.setItem(SECRET_KEY, valor);
    } catch {
        // Sin persistencia (modo privado): la sesión de dispositivo dura lo que la cookie; no es un error.
    }
}

function olvidarSecreto() {
    try {
        localStorage.removeItem(SECRET_KEY);
    } catch {
        // Nada que hacer si el almacenamiento está bloqueado.
    }
}

/** Canjea el secreto por una sesión de dispositivo. Éxito → recarga a la pantalla de bloqueo. */
async function canjear(secret) {
    procesando.value = true;
    error.value = '';

    try {
        await api.post('/shared-terminal/session', { secret });
        guardarSecreto(secret);

        // Recarga la propia antesala: ahora HAY dispositivo, así que el shell la resolverá como bloqueo.
        router.visit('/terminal');
    } catch (e) {
        // El secreto no sirve (revocado, mal escrito, terminal dada de baja): se olvida y se vuelve a pedir.
        olvidarSecreto();
        error.value = e instanceof ApiError ? e.message : 'No se pudo activar la terminal.';
        modo.value = 'bootstrap';
        procesando.value = false;
    }
}

function activar() {
    const s = secretInput.value.trim();

    if (s === '') {
        error.value = 'Pega el código de activación de esta terminal.';

        return;
    }

    canjear(s);
}

/** Identifica al operador por código + PIN. Éxito → al POS. */
async function identificar() {
    if (procesando.value) {
        return;
    }

    if (employeeCode.value.trim() === '') {
        error.value = 'Captura tu código de empleado.';

        return;
    }

    if (pin.value.length < 4) {
        return;
    }

    procesando.value = true;
    error.value = '';

    try {
        await api.post('/shared-terminal/operator', {
            employee_code: employeeCode.value.trim(),
            pin: pin.value,
        });

        router.visit(POS_HOME);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo identificar. Intenta de nuevo.';
        pin.value = '';
        procesando.value = false;
    }
}

onMounted(() => {
    const estado = st.value;

    if (estado?.active) {
        // Ya hay dispositivo. Con operador, adentro; sin él, al bloqueo.
        if (estado.locked) {
            modo.value = 'bloqueo';
        } else {
            router.visit(POS_HOME);
        }

        return;
    }

    // Sin dispositivo: si esta tablet ya guardó un secreto, se canjea solo; si no, se pide.
    const guardado = leerSecretoGuardado();

    if (guardado !== '') {
        canjear(guardado);
    } else {
        modo.value = 'bootstrap';
    }
});
</script>

<template>
    <Head title="Terminal compartida" />

    <div class="kiosk-gate">
        <div class="kiosk-gate__card">
            <div class="kiosk-gate__brand">
                <span class="kiosk-gate__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 18h16.5M5.25 18a6.75 6.75 0 0 1 13.5 0M12 6.75V4.5m-2.25 0h4.5" />
                    </svg>
                </span>
                <span class="kiosk-gate__brand-name">Comandia</span>
            </div>

            <!-- Cargando / canjeando el secreto guardado -->
            <template v-if="modo === 'cargando'">
                <p class="kiosk-gate__hint">Activando la terminal…</p>
            </template>

            <!-- Bootstrap: pegar el secreto de activación -->
            <template v-else-if="modo === 'bootstrap'">
                <h1 class="kiosk-gate__title">Activar terminal compartida</h1>
                <p class="kiosk-gate__hint">
                    Pega el código de activación que se generó al enrolar esta caja desde
                    <strong>Terminales</strong>. Se guarda en este dispositivo.
                </p>

                <form class="kiosk-gate__form" @submit.prevent="activar">
                    <textarea
                        v-model="secretInput"
                        class="kiosk-gate__secret"
                        rows="2"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        placeholder="Código de activación"
                        :disabled="procesando"
                    />

                    <p v-if="error" class="kiosk-gate__error">{{ error }}</p>

                    <button type="submit" class="kiosk-gate__button" :disabled="procesando">
                        Activar terminal
                    </button>
                </form>
            </template>

            <!-- Bloqueo: código de empleado + PIN -->
            <template v-else>
                <h1 class="kiosk-gate__title">
                    {{ st?.terminal_name || 'Terminal compartida' }}
                </h1>
                <p class="kiosk-gate__hint">Identifícate para operar.</p>

                <form class="kiosk-gate__form" @submit.prevent="identificar">
                    <label class="kiosk-gate__field">
                        <span class="kiosk-gate__label">Código de empleado</span>
                        <input
                            v-model="employeeCode"
                            class="kiosk-gate__code"
                            type="text"
                            inputmode="text"
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            maxlength="20"
                            :disabled="procesando"
                        />
                    </label>

                    <PinKeypad
                        v-model="pin"
                        :procesando="procesando"
                        @submit="identificar"
                    />

                    <p v-if="error" class="kiosk-gate__error" role="alert">{{ error }}</p>
                </form>
            </template>
        </div>
    </div>
</template>

<style scoped>
.kiosk-gate {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    background:
        radial-gradient(1200px 600px at 50% -10%, color-mix(in srgb, var(--color-acento) 12%, transparent), transparent),
        var(--color-fondo);
    color: var(--color-contenido);
    font-family: ui-sans-serif, system-ui, sans-serif;
}

.kiosk-gate__card {
    width: 100%;
    max-width: 26rem;
    display: grid;
    gap: 1.25rem;
    justify-items: center;
    padding: 2rem 1.75rem 2.25rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-lg);
    text-align: center;
}

.kiosk-gate__brand {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    font-size: 1.05rem;
    font-weight: 650;
    letter-spacing: -0.01em;
}

.kiosk-gate__mark {
    display: grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border-radius: var(--radio);
    color: var(--color-acento-texto);
    background: var(--color-acento);
}
.kiosk-gate__mark svg { width: 1.25rem; height: 1.25rem; }

.kiosk-gate__title {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 700;
    text-wrap: balance;
}

.kiosk-gate__hint {
    margin: 0;
    color: var(--color-suave);
    font-size: 0.92rem;
    line-height: 1.5;
    max-width: 22rem;
}

.kiosk-gate__form {
    width: 100%;
    display: grid;
    gap: 1rem;
    justify-items: center;
}

.kiosk-gate__field {
    width: 100%;
    display: grid;
    gap: 0.35rem;
    text-align: left;
}
.kiosk-gate__label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--color-suave);
}

.kiosk-gate__code,
.kiosk-gate__secret {
    width: 100%;
    padding: 0.75rem 0.9rem;
    font: inherit;
    color: var(--color-contenido);
    background: var(--color-fondo);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    resize: none;
}
.kiosk-gate__code {
    font-size: 1.4rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-align: center;
    text-transform: uppercase;
}
.kiosk-gate__code:focus,
.kiosk-gate__secret:focus {
    outline: 2px solid color-mix(in srgb, var(--color-acento) 45%, transparent);
    outline-offset: 1px;
    border-color: var(--color-acento);
}

.kiosk-gate__button {
    width: 100%;
    padding: 0.8rem 1rem;
    font: inherit;
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-acento-texto);
    background: var(--color-acento);
    border: 1px solid var(--color-acento);
    border-radius: var(--radio);
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.kiosk-gate__button:hover:not(:disabled) {
    background: color-mix(in srgb, var(--color-acento) 88%, #000);
}
.kiosk-gate__button:disabled { opacity: 0.5; cursor: default; }

.kiosk-gate__error {
    margin: 0;
    width: 100%;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    color: var(--color-peligro);
    background: var(--color-peligro-tenue);
    border-radius: var(--radio-sm);
}
</style>
