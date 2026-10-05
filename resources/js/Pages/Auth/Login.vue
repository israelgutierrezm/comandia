<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
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

// «Contraseña actualizada: entra con la nueva» al volver de restablecerla (diseño de acceso, fase 2).
const page = usePage();
const aviso = computed(() => page.props.flash?.success ?? null);

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
            <p v-if="aviso" class="aviso" role="status">{{ aviso }}</p>

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

            <Link href="/olvide-contrasena" class="enlace">¿Olvidaste tu contraseña?</Link>

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
@import '../../../css/auth-form.css';
</style>
