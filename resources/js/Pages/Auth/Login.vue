<script setup>
import { Head, useForm } from '@inertiajs/vue3';

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

function submit() {
    form.post('/login', {
        // La contraseña no se conserva en memoria tras un intento fallido.
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Entrar" />

    <main class="page">
        <div class="card">
            <h1 class="brand">Comandia</h1>
            <p class="lead">Administración y punto de venta</p>

            <form @submit.prevent="submit">
                <label class="field">
                    <span class="field__label">Correo</span>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        autofocus
                        required
                        class="field__input"
                    />
                    <span v-if="form.errors.email" class="field__error">{{ form.errors.email }}</span>
                </label>

                <label class="field">
                    <span class="field__label">Contraseña</span>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="field__input"
                    />
                    <span v-if="form.errors.password" class="field__error">{{ form.errors.password }}</span>
                </label>

                <label class="checkbox">
                    <input v-model="form.remember" type="checkbox" />
                    <span>Mantener la sesión abierta</span>
                </label>

                <button type="submit" class="button" :disabled="form.processing">
                    {{ form.processing ? 'Entrando…' : 'Entrar' }}
                </button>
            </form>
        </div>
    </main>
</template>

<style scoped>
.page {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    background: #f8f7f5;
    font-family: ui-sans-serif, system-ui, sans-serif;
    color: #1c1917;
}

.card {
    width: 100%;
    max-width: 22rem;
    background: #fff;
    border: 1px solid #e7e5e4;
    border-radius: 0.75rem;
    padding: 2rem;
}

.brand {
    margin: 0;
    font-size: 1.6rem;
    font-weight: 600;
    letter-spacing: -0.02em;
}

.lead {
    margin: 0.25rem 0 1.75rem;
    font-size: 0.9rem;
    opacity: 0.6;
}

.field {
    display: block;
    margin-bottom: 1rem;
}

.field__label {
    display: block;
    font-size: 0.8rem;
    font-weight: 500;
    margin-bottom: 0.3rem;
}

.field__input {
    width: 100%;
    font: inherit;
    padding: 0.5rem 0.65rem;
    border: 1px solid #d6d3d1;
    border-radius: 0.375rem;
    background: #fff;
}

.field__input:focus {
    outline: 2px solid #c2410c;
    outline-offset: 1px;
}

.field__error {
    display: block;
    margin-top: 0.3rem;
    font-size: 0.8rem;
    color: #b91c1c;
}

.checkbox {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.85rem;
    margin-bottom: 1.25rem;
}

.button {
    width: 100%;
    font: inherit;
    font-weight: 600;
    padding: 0.55rem 1rem;
    border: 0;
    border-radius: 0.375rem;
    background: #c2410c;
    color: #fff;
    cursor: pointer;
}

.button:disabled {
    opacity: 0.6;
    cursor: progress;
}
</style>
