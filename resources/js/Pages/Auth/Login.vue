<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthWaves from '../../components/AuthWaves.vue';

/**
 * Inicio de sesión.
 *
 * La autenticación es global al SaaS: aquí no se pregunta el negocio. Pedirlo antes de saber si la
 * persona existe filtraría qué correos pertenecen a qué negocio a quien probara combinaciones (§4.1).
 *
 * Usa `useForm` de Inertia y no el cliente de la API: el inicio de sesión es lo que **crea** la
 * sesión con la que después se llama a `/api/v1`, así que no puede depender de ella.
 */
const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const verClave = ref(false);

function submit() {
    form.post('/login', {
        // La contraseña no se conserva en memoria tras un intento fallido.
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Entrar" />

    <AuthWaves>
        <form class="formulario" @submit.prevent="submit">
            <div class="campo" :class="{ 'campo--lleno': !!form.email }">
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    autofocus
                    required
                    class="entrada"
                />
                <label for="email" class="etiqueta">Correo</label>
                <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>
            </div>

            <div class="campo" :class="{ 'campo--lleno': !!form.password }">
                <input
                    id="password"
                    v-model="form.password"
                    :type="verClave ? 'text' : 'password'"
                    autocomplete="current-password"
                    required
                    class="entrada entrada--clave"
                />
                <label for="password" class="etiqueta">Contraseña</label>
                <button
                    type="button"
                    class="ojo"
                    :aria-label="verClave ? 'Ocultar contraseña' : 'Ver contraseña'"
                    @click="verClave = !verClave"
                >
                    <svg v-if="!verClave" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88" />
                    </svg>
                </button>
                <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
            </div>

            <label class="recordarme">
                <input v-model="form.remember" type="checkbox" />
                <span>Mantener la sesión abierta</span>
            </label>

            <button type="submit" class="entrar grupo" :disabled="form.processing">
                <span>{{ form.processing ? 'Entrando…' : 'Entrar' }}</span>
                <span v-if="!form.processing" class="flechas" aria-hidden="true">
                    <svg v-for="n in 3" :key="n" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
                    </svg>
                </span>
            </button>
        </form>
    </AuthWaves>
</template>

<style scoped>
.formulario {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

/* Campo con label flotante: la etiqueta vive dentro del input y sube al enfocarlo o al escribir.
   El estado «lleno» lo marca Vue con la clase `campo--lleno` (en vez de `:placeholder-shown` sobre un
   hermano adyacente): así no depende de cómo el compilador de estilos con alcance coloca el atributo
   de scope en un combinador `+`, y aguanta mejor el autocompletado del navegador. */
.campo {
    position: relative;
}

.entrada {
    width: 100%;
    font: inherit;
    border: 1px solid #d6d3d1;
    border-radius: 0.6rem;
    padding: 1.15rem 0.85rem 0.4rem;
    font-size: 0.95rem;
    color: #1c1917;
    background: #fff;
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.entrada--clave {
    padding-right: 2.75rem;
}

.entrada:focus {
    outline: none;
    border-color: #c2410c;
    box-shadow: 0 0 0 3px rgba(194, 65, 12, 0.15);
}

.etiqueta {
    position: absolute;
    left: 0.9rem;
    top: 0.85rem;
    color: #a8a29e;
    font-size: 0.95rem;
    pointer-events: none;
    transform-origin: left top;
    transition: all 0.18s ease;
}

.campo:focus-within .etiqueta,
.campo--lleno .etiqueta {
    top: 0.34rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: #c2410c;
}

.ojo {
    position: absolute;
    right: 0.5rem;
    top: 0.6rem;
    display: grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border: 0;
    background: none;
    color: #a8a29e;
    cursor: pointer;
    transition: color 0.2s ease;
}

.ojo:hover {
    color: #c2410c;
}

.ojo svg {
    width: 1.25rem;
    height: 1.25rem;
}

.error {
    margin: 0.35rem 0 0;
    font-size: 0.8rem;
    color: #b91c1c;
}

.recordarme {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: #57534e;
}

/* Botón de entrar: degradado de marca FIJO (terracota), no el acento del negocio. */
.entrar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    width: 100%;
    font: inherit;
    font-weight: 600;
    padding: 0.7rem 1rem;
    border: 0;
    border-radius: 0.6rem;
    color: #fff;
    cursor: pointer;
    background-image: linear-gradient(135deg, #ea580c, #c2410c);
    box-shadow: 0 10px 24px -10px rgba(194, 65, 12, 0.7);
    transition:
        filter 0.2s ease,
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.entrar:hover:not(:disabled) {
    filter: brightness(1.06);
    transform: translateY(-2px);
    box-shadow: 0 16px 30px -10px rgba(194, 65, 12, 0.85);
}

.entrar:active:not(:disabled) {
    transform: translateY(-1px);
}

.entrar:disabled {
    opacity: 0.65;
    cursor: progress;
}

/* Flechas del botón: en reposo se ve UNA; al pasar el cursor se van «creando» más, fluyendo hacia
   la derecha en cadena. */
.flechas {
    position: relative;
    display: inline-flex;
    width: 1.1rem;
    height: 1.1rem;
}

.chev {
    position: absolute;
    inset: 0;
    width: 1.1rem;
    height: 1.1rem;
    opacity: 0;
}

.chev:first-child {
    opacity: 1;
}

.grupo:hover:not(:disabled) .chev {
    animation: fluir 0.9s ease-in-out infinite;
}

.grupo:hover:not(:disabled) .chev:nth-child(2) {
    animation-delay: 0.2s;
}

.grupo:hover:not(:disabled) .chev:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes fluir {
    0% {
        opacity: 0;
        transform: translateX(-6px);
    }
    35% {
        opacity: 1;
    }
    100% {
        opacity: 0;
        transform: translateX(9px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .grupo:hover:not(:disabled) .chev {
        animation: none;
    }

    .entrar:hover:not(:disabled) {
        transform: none;
    }
}
</style>
