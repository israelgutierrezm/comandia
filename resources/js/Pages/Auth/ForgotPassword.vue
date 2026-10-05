<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthWaves from '../../components/AuthWaves.vue';

/**
 * «¿Olvidaste tu contraseña?» (diseño de acceso, fase 2).
 *
 * La respuesta es SIEMPRE la misma —«si ese correo tiene cuenta, te enviamos un enlace»— y el correo sale por cola: ni
 * el texto ni el tiempo de respuesta dicen si la cuenta existe. Es la misma regla que el acceso, que no distingue un
 * correo inexistente de una contraseña equivocada.
 */
const form = useForm({ email: '' });

const page = usePage();
const enviado = computed(() => page.props.flash?.success ?? null);

function submit() {
    form.post('/olvide-contrasena');
}
</script>

<template>
    <Head title="Recuperar contraseña" />

    <AuthWaves>
        <template #subtitulo>
            <p class="lead">Te mandamos un enlace para crear una contraseña nueva.</p>
        </template>

        <form class="formulario" @submit.prevent="submit">
            <p v-if="enviado" class="aviso" role="status">{{ enviado }}</p>

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

            <button type="submit" class="entrar" :disabled="form.processing">
                <span>{{ form.processing ? 'Enviando…' : 'Enviar el enlace' }}</span>
            </button>

            <Link href="/login" class="enlace">Volver a entrar</Link>
        </form>
    </AuthWaves>
</template>

<style scoped>
@import '../../../css/auth-form.css';
</style>
