<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthWaves from '../../components/AuthWaves.vue';

/**
 * Crear la contraseña nueva desde el enlace del correo (diseño de acceso, fase 2).
 *
 * Al guardarla, el servidor cierra TODAS las sesiones de la cuenta —navegadores y app, en todos sus negocios— y manda
 * a entrar con la nueva: no inicia sesión solo, para que un enlace robado no se convierta en una sesión abierta.
 */
const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const verClave = ref(false);

function submit() {
    form.post('/restablecer', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Contraseña nueva" />

    <AuthWaves>
        <template #subtitulo>
            <p class="lead">Crea tu contraseña nueva. Al guardarla se cerrarán tus sesiones en todos lados.</p>
        </template>

        <form class="formulario" @submit.prevent="submit">
            <p v-if="form.errors.token" class="aviso aviso--error" role="alert">
                {{ form.errors.token }} <Link href="/olvide-contrasena" class="enlace">Pedir otro enlace</Link>
            </p>

            <div class="campo" :class="{ 'campo--lleno': !!form.email }">
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
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
                    autocomplete="new-password"
                    minlength="10"
                    required
                    autofocus
                    class="entrada entrada--clave"
                />
                <label for="password" class="etiqueta">Contraseña nueva (10 caracteres o más)</label>
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

            <div class="campo" :class="{ 'campo--lleno': !!form.password_confirmation }">
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    :type="verClave ? 'text' : 'password'"
                    autocomplete="new-password"
                    required
                    class="entrada"
                />
                <label for="password_confirmation" class="etiqueta">Repítela</label>
            </div>

            <button type="submit" class="entrar" :disabled="form.processing">
                <span>{{ form.processing ? 'Guardando…' : 'Guardar la contraseña' }}</span>
            </button>
        </form>
    </AuthWaves>
</template>

<style scoped>
@import '../../../css/auth-form.css';
</style>
