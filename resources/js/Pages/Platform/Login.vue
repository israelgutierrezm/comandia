<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

/**
 * Acceso a la super administración de la plataforma. Superficie APARTE del acceso de negocios: otra tabla, otro guard,
 * otra cookie. Colores de plataforma (índigo), no la terracota de los negocios.
 */
const form = useForm({ email: '', password: '', remember: false });
const verClave = ref(false);

function submit() {
    form.post('/plataforma/acceso', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Plataforma · Acceso" />

    <div class="acceso">
        <div class="caja">
            <div class="marca">
                <span class="marca__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    </svg>
                </span>
                <span class="marca__txt">Comandia<small>Plataforma</small></span>
            </div>

            <h1>Super administración</h1>
            <p class="lead">Acceso exclusivo del operador de la plataforma.</p>

            <form @submit.prevent="submit">
                <label class="campo">
                    <span>Correo</span>
                    <input v-model="form.email" type="email" autocomplete="username" autofocus required />
                    <span v-if="form.errors.email" class="err">{{ form.errors.email }}</span>
                </label>

                <label class="campo">
                    <span>Contraseña</span>
                    <span class="clave">
                        <input v-model="form.password" :type="verClave ? 'text' : 'password'" autocomplete="current-password" required />
                        <button type="button" @click="verClave = !verClave">{{ verClave ? 'Ocultar' : 'Ver' }}</button>
                    </span>
                    <span v-if="form.errors.password" class="err">{{ form.errors.password }}</span>
                </label>

                <label class="recordar">
                    <input v-model="form.remember" type="checkbox" /> Mantener la sesión abierta
                </label>

                <button type="submit" class="entrar" :disabled="form.processing">
                    {{ form.processing ? 'Entrando…' : 'Entrar' }}
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.acceso {
    --plat: #4f46e5;
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    background: #0f172a;
    font-family: ui-sans-serif, system-ui, sans-serif;
}

.caja {
    width: 100%;
    max-width: 23rem;
    background: #fff;
    border-radius: 0.9rem;
    padding: 2rem;
    box-shadow: 0 20px 50px -20px rgb(0 0 0 / 0.5);
}

.marca { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1.5rem; }
.marca__mark { display: grid; place-items: center; width: 2.1rem; height: 2.1rem; border-radius: 0.6rem; color: #fff; background: var(--plat); }
.marca__mark svg { width: 1.3rem; height: 1.3rem; }
.marca__txt { display: flex; flex-direction: column; font-weight: 700; font-size: 1.15rem; letter-spacing: -0.01em; line-height: 1.1; color: #1e293b; }
.marca__txt small { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #64748b; }

h1 { margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; }
.lead { margin: 0.25rem 0 1.5rem; font-size: 0.85rem; color: #64748b; }

form { display: grid; gap: 1rem; }
.campo { display: grid; gap: 0.3rem; font-size: 0.85rem; color: #334155; }
.campo input { width: 100%; font: inherit; font-size: 0.95rem; padding: 0.6rem 0.7rem; border: 1px solid #cbd5e1; border-radius: 0.55rem; color: #0f172a; }
.campo input:focus { outline: none; border-color: var(--plat); box-shadow: 0 0 0 3px rgb(79 70 229 / 0.15); }
.clave { position: relative; display: block; }
.clave input { padding-right: 4rem; }
.clave button { position: absolute; right: 0.4rem; top: 50%; transform: translateY(-50%); border: 0; background: none; color: var(--plat); font: inherit; font-size: 0.8rem; cursor: pointer; }
.err { color: #dc2626; font-size: 0.8rem; }
.recordar { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #475569; }

.entrar {
    font: inherit;
    font-weight: 600;
    padding: 0.7rem 1rem;
    border: 0;
    border-radius: 0.55rem;
    color: #fff;
    background-image: linear-gradient(135deg, #6366f1, #4f46e5);
    box-shadow: 0 10px 24px -10px rgb(79 70 229 / 0.7);
    cursor: pointer;
    transition: filter 0.2s ease, transform 0.2s ease;
}
.entrar:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); }
.entrar:disabled { opacity: 0.65; cursor: progress; }

@media (prefers-reduced-motion: reduce) {
    .entrar:hover:not(:disabled) { transform: none; }
}
</style>
