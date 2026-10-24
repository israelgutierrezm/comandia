<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

/**
 * «Mi cuenta» del super administrador (D382): sus datos y cambiar la contraseña con la sesión abierta.
 *
 * Cambiarla cierra las demás sesiones (otras computadoras, la cookie de «Mantener la sesión abierta») y le avisa por
 * correo. Ésta sigue abierta. No hay recuperación pública: quien la olvida la restablece por la consola del servidor.
 */
const props = defineProps({
    admin: { type: Object, required: true },
});

const form = useForm({ current_password: '', password: '', password_confirmation: '' });
const verClaves = ref(false);

const ultimoAcceso = computed(() => {
    if (!props.admin.last_login_at) return 'Todavía no hay registro';

    return new Intl.DateTimeFormat('es-MX', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(props.admin.last_login_at));
});

function submit() {
    form.put('/plataforma/mi-cuenta/contrasena', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('current_password'),
    });
}
</script>

<template>
    <Head title="Plataforma · Mi cuenta" />

    <div class="cuenta">
        <header class="cab">
            <h1>Mi cuenta</h1>
        </header>

        <dl class="tarjeta datos">
            <div><dt>Nombre</dt><dd>{{ admin.name }}</dd></div>
            <div><dt>Correo</dt><dd>{{ admin.email }}</dd></div>
            <div><dt>Último acceso</dt><dd>{{ ultimoAcceso }}</dd></div>
        </dl>

        <form class="tarjeta" @submit.prevent="submit">
            <div>
                <h2>Cambiar mi contraseña</h2>
                <p class="hint">
                    Al guardarla se cierran tus demás sesiones y te avisamos por correo. Esta sesión sigue abierta.
                </p>
            </div>

            <label class="campo">
                <span>Contraseña actual</span>
                <input v-model="form.current_password" :type="verClaves ? 'text' : 'password'" autocomplete="current-password" required />
                <span v-if="form.errors.current_password" class="err">{{ form.errors.current_password }}</span>
            </label>

            <div class="par">
                <label class="campo">
                    <span>Contraseña nueva</span>
                    <input v-model="form.password" :type="verClaves ? 'text' : 'password'" autocomplete="new-password" minlength="10" required />
                    <span v-if="form.errors.password" class="err">{{ form.errors.password }}</span>
                    <span v-else class="sub">Al menos 10 caracteres.</span>
                </label>
                <label class="campo">
                    <span>Repite la contraseña nueva</span>
                    <input v-model="form.password_confirmation" :type="verClaves ? 'text' : 'password'" autocomplete="new-password" required />
                </label>
            </div>

            <label class="ver">
                <input v-model="verClaves" type="checkbox" /> Mostrar contraseñas
            </label>

            <div class="acciones">
                <button type="submit" class="btn" :disabled="form.processing">
                    {{ form.processing ? 'Guardando…' : 'Guardar contraseña' }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.cuenta { --plat: #4f46e5; display: grid; gap: 1.25rem; max-width: 40rem; }
.cab h1 { margin: 0; font-size: 1.4rem; font-weight: 650; letter-spacing: -0.015em; }

.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
    padding: 1.35rem;
    display: grid;
    gap: 1rem;
}

.datos { margin: 0; gap: 0.75rem; }
.datos div { display: grid; grid-template-columns: 8.5rem 1fr; gap: 0.75rem; align-items: baseline; }
.datos dt { font-size: 0.82rem; color: var(--color-suave); }
.datos dd { margin: 0; font-size: 0.92rem; overflow-wrap: anywhere; }
@media (max-width: 30rem) { .datos div { grid-template-columns: 1fr; gap: 0.1rem; } }

h2 { margin: 0; font-size: 0.95rem; font-weight: 650; color: var(--color-contenido); }
.hint { margin: 0.3rem 0 0; font-size: 0.85rem; color: var(--color-suave); max-width: 34rem; }

.par { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
@media (max-width: 34rem) { .par { grid-template-columns: 1fr; } }

.campo { display: grid; gap: 0.3rem; font-size: 0.82rem; color: var(--color-contenido); align-content: start; }
.campo input {
    width: 100%; font: inherit; font-size: 0.9rem; padding: 0.55rem 0.65rem;
    border: 1px solid var(--color-borde); border-radius: 0.5rem; background: var(--color-superficie); color: var(--color-contenido);
}
.campo input:focus { outline: none; border-color: var(--plat); box-shadow: 0 0 0 3px rgb(79 70 229 / 0.15); }
.err { color: var(--color-peligro); font-size: 0.8rem; }
.sub { color: var(--color-suave); font-size: 0.78rem; }
.ver { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--color-suave); }

.acciones { display: flex; }
.btn {
    font: inherit; font-size: 0.95rem; font-weight: 600; padding: 0.7rem 1.35rem; border: 0; border-radius: 0.55rem;
    color: #fff; background: var(--plat); box-shadow: 0 8px 18px -8px rgb(79 70 229 / 0.6); cursor: pointer;
    transition: filter 0.15s ease, transform 0.15s ease;
}
.btn:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); }
.btn:disabled { opacity: 0.6; cursor: progress; }
.btn:focus-visible { outline: 2px solid var(--plat); outline-offset: 2px; }

@media (prefers-reduced-motion: reduce) {
    .btn:hover:not(:disabled) { transform: none; }
}
</style>
