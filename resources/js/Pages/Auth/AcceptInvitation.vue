<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthWaves from '../../components/AuthWaves.vue';

/**
 * Aceptar una invitación para entrar a un negocio (diseño de acceso, fase 3).
 *
 * Lo que se pide depende de quién acepta, y lo decide el servidor (`mode`):
 *
 * - `new_account`: primera vez en Comandia → su nombre y la contraseña que va a usar.
 * - `existing_account`: ya usa Comandia en otro negocio → su contraseña de siempre.
 * - `signed_in`: ya tiene la sesión abierta con ese correo → sólo «Aceptar».
 * - `other_session`: la sesión abierta es de otra persona → salir primero.
 *
 * Una invitación vencida, usada, cancelada o inexistente (`state`) sólo dice que ya no vale y a quién pedir otra.
 */
const props = defineProps({
    token: { type: String, required: true },
    state: { type: String, required: true },
    mode: { type: String, default: null },
    business: { type: String, default: null },
    email: { type: String, default: null },
    roles: { type: Array, default: () => [] },
    suggested_name: { type: Object, default: () => ({}) },
    expires_at: { type: String, default: null },
    signed_in_as: { type: String, default: null },
});

const form = useForm({
    first_name: props.suggested_name?.first_name ?? '',
    paternal_surname: props.suggested_name?.paternal_surname ?? '',
    maternal_surname: props.suggested_name?.maternal_surname ?? '',
    password: '',
    password_confirmation: '',
});

const verClave = ref(false);

const motivo = computed(() => ({
    used: 'Esta invitación ya se usó.',
    revoked: 'Esta invitación se canceló.',
    expired: 'Esta invitación venció.',
    invalid: 'Este enlace no corresponde a ninguna invitación.',
}[props.state] ?? ''));

const comoQue = computed(() => (props.roles.length ? ` como ${props.roles.join(', ')}` : ''));

function aceptar() {
    form.post(`/invitacion/${props.token}`, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

function salir() {
    // Tras salir, el acceso manda de vuelta a esta misma invitación para aceptarla con la cuenta correcta.
    router.post('/logout', {}, { onSuccess: () => router.visit(`/invitacion/${props.token}`) });
}
</script>

<template>
    <Head title="Invitación" />

    <AuthWaves>
        <template #subtitulo>
            <p v-if="state === 'pending'" class="lead">
                Te invitaron a entrar a <strong>{{ business }}</strong>{{ comoQue }}.
            </p>
            <p v-else class="lead">Invitación a Comandia</p>
        </template>

        <!-- Ya no vale -->
        <div v-if="state !== 'pending'" class="formulario">
            <p class="aviso aviso--error" role="alert">
                {{ motivo }}
                <template v-if="business"> Pide otra a quien te invitó en {{ business }}.</template>
                <template v-else> Pide otra a quien te invitó.</template>
            </p>
            <Link href="/login" class="enlace">Ir a entrar</Link>
        </div>

        <!-- La sesión abierta es de otra persona -->
        <div v-else-if="mode === 'other_session'" class="formulario">
            <p class="aviso aviso--error" role="alert">
                Estás dentro como {{ signed_in_as }}, y esta invitación es para {{ email }}. Sal de esa cuenta para aceptarla.
            </p>
            <button type="button" class="entrar" @click="salir">Salir y aceptar</button>
        </div>

        <form v-else class="formulario" @submit.prevent="aceptar">
            <p v-if="form.errors.invitation" class="aviso aviso--error" role="alert">{{ form.errors.invitation }}</p>

            <p class="texto">
                La invitación es para <strong>{{ email }}</strong>.
                <template v-if="mode === 'existing_account'"> Ya usas Comandia: entra con tu contraseña de siempre.</template>
                <template v-else-if="mode === 'signed_in'"> Ya estás dentro con ese correo: sólo acepta.</template>
                <template v-else> Es tu primera vez en Comandia: crea tu contraseña.</template>
            </p>

            <template v-if="mode === 'new_account'">
                <div class="campo" :class="{ 'campo--lleno': !!form.first_name }">
                    <input id="first_name" v-model="form.first_name" type="text" autocomplete="given-name" required maxlength="60" class="entrada" />
                    <label for="first_name" class="etiqueta">Nombre</label>
                    <p v-if="form.errors.first_name" class="error">{{ form.errors.first_name }}</p>
                </div>

                <div class="campo" :class="{ 'campo--lleno': !!form.paternal_surname }">
                    <input id="paternal_surname" v-model="form.paternal_surname" type="text" autocomplete="family-name" required maxlength="60" class="entrada" />
                    <label for="paternal_surname" class="etiqueta">Primer apellido</label>
                    <p v-if="form.errors.paternal_surname" class="error">{{ form.errors.paternal_surname }}</p>
                </div>

                <div class="campo" :class="{ 'campo--lleno': !!form.maternal_surname }">
                    <input id="maternal_surname" v-model="form.maternal_surname" type="text" maxlength="60" class="entrada" />
                    <label for="maternal_surname" class="etiqueta">Segundo apellido (opcional)</label>
                </div>
            </template>

            <div v-if="mode !== 'signed_in'" class="campo" :class="{ 'campo--lleno': !!form.password }">
                <input
                    id="password"
                    v-model="form.password"
                    :type="verClave ? 'text' : 'password'"
                    :autocomplete="mode === 'new_account' ? 'new-password' : 'current-password'"
                    :minlength="mode === 'new_account' ? 10 : null"
                    required
                    class="entrada entrada--clave"
                />
                <label for="password" class="etiqueta">
                    {{ mode === 'new_account' ? 'Contraseña (10 caracteres o más)' : 'Tu contraseña' }}
                </label>
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

            <div v-if="mode === 'new_account'" class="campo" :class="{ 'campo--lleno': !!form.password_confirmation }">
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
                <span>{{ form.processing ? 'Aceptando…' : 'Aceptar y entrar' }}</span>
            </button>

            <Link v-if="mode === 'existing_account'" href="/olvide-contrasena" class="enlace">¿Olvidaste tu contraseña?</Link>
        </form>
    </AuthWaves>
</template>

<style scoped>
@import '../../../css/auth-form.css';
</style>
