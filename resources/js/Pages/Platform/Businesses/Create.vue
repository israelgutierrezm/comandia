<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

/**
 * Alta de un negocio desde la plataforma — «cargar una instancia nueva». El servidor crea el negocio, su dueño y su
 * primera sucursal en una transacción (ProvisionTenant) y lo deja pendiente de activación (ya puede operar).
 */
const form = useForm({
    business_name: '',
    owner_email: '',
    owner_first_name: '',
    owner_paternal_surname: '',
    owner_maternal_surname: '',
    plain_password: '',
    branch_name: '',
    branch_code: '',
});

function submit() {
    form.post('/plataforma/negocios', { onFinish: () => form.reset('plain_password') });
}
</script>

<template>
    <Head title="Plataforma · Nuevo negocio" />

    <div class="alta">
        <header class="cab">
            <div>
                <h1>Nuevo negocio</h1>
                <p class="hint">Se crea el negocio con su dueño y su primera sucursal. El dueño podrá entrar y configurar de inmediato.</p>
            </div>
            <Link href="/plataforma/negocios" class="volver">← Negocios</Link>
        </header>

        <form class="tarjeta" @submit.prevent="submit">
            <section>
                <h2>Negocio</h2>
                <label class="campo">
                    <span>Nombre del negocio</span>
                    <input v-model="form.business_name" type="text" maxlength="150" required />
                    <span v-if="form.errors.business_name" class="err">{{ form.errors.business_name }}</span>
                </label>
                <div class="par">
                    <label class="campo">
                        <span>Sucursal (opcional)</span>
                        <input v-model="form.branch_name" type="text" maxlength="120" placeholder="Matriz" />
                    </label>
                    <label class="campo">
                        <span>Código de sucursal</span>
                        <input v-model="form.branch_code" type="text" maxlength="20" placeholder="MTZ" />
                    </label>
                </div>
            </section>

            <section>
                <h2>Dueño</h2>
                <div class="par">
                    <label class="campo">
                        <span>Nombre</span>
                        <input v-model="form.owner_first_name" type="text" maxlength="80" required />
                        <span v-if="form.errors.owner_first_name" class="err">{{ form.errors.owner_first_name }}</span>
                    </label>
                    <label class="campo">
                        <span>Apellido paterno</span>
                        <input v-model="form.owner_paternal_surname" type="text" maxlength="80" required />
                        <span v-if="form.errors.owner_paternal_surname" class="err">{{ form.errors.owner_paternal_surname }}</span>
                    </label>
                </div>
                <label class="campo">
                    <span>Apellido materno (opcional)</span>
                    <input v-model="form.owner_maternal_surname" type="text" maxlength="80" />
                </label>
                <label class="campo">
                    <span>Correo del dueño</span>
                    <input v-model="form.owner_email" type="email" maxlength="150" required />
                    <span v-if="form.errors.owner_email" class="err">{{ form.errors.owner_email }}</span>
                </label>
                <label class="campo">
                    <span>Contraseña inicial</span>
                    <input v-model="form.plain_password" type="text" maxlength="100" required />
                    <span v-if="form.errors.plain_password" class="err">{{ form.errors.plain_password }}</span>
                    <span class="sub">Se la comunicas al dueño; él la cambia al entrar.</span>
                </label>
            </section>

            <div class="acciones">
                <button type="submit" class="btn" :disabled="form.processing">
                    {{ form.processing ? 'Creando…' : 'Dar de alta el negocio' }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.alta { --plat: #4f46e5; display: grid; gap: 1.25rem; max-width: 44rem; }
.cab { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.cab h1 { margin: 0; font-size: 1.4rem; font-weight: 650; letter-spacing: -0.015em; }
.hint { margin: 0.3rem 0 0; font-size: 0.85rem; color: var(--color-suave); max-width: 34rem; }

.volver {
    flex: none; font: inherit; font-size: 0.82rem; font-weight: 500; padding: 0.3rem 0.7rem;
    border: 1px solid color-mix(in srgb, var(--plat) 35%, transparent); border-radius: 0.5rem;
    color: var(--plat); text-decoration: none;
}
.volver:hover { background: color-mix(in srgb, var(--plat) 10%, transparent); }

.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
    padding: 1.35rem;
    display: grid;
    gap: 1.5rem;
}

section { display: grid; gap: 0.85rem; }
section h2 { margin: 0; font-size: 0.95rem; font-weight: 650; color: var(--color-contenido); }
.par { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
@media (max-width: 34rem) { .par { grid-template-columns: 1fr; } }

.campo { display: grid; gap: 0.3rem; font-size: 0.82rem; color: var(--color-contenido); }
.campo input {
    width: 100%; font: inherit; font-size: 0.9rem; padding: 0.55rem 0.65rem;
    border: 1px solid var(--color-borde); border-radius: 0.5rem; background: var(--color-superficie); color: var(--color-contenido);
}
.campo input:focus { outline: none; border-color: var(--plat); box-shadow: 0 0 0 3px rgb(79 70 229 / 0.15); }
.err { color: var(--color-peligro); font-size: 0.8rem; }
.sub { color: var(--color-suave); font-size: 0.78rem; }

.acciones { display: flex; }
.btn {
    font: inherit; font-size: 0.95rem; font-weight: 600; padding: 0.7rem 1.35rem; border: 0; border-radius: 0.55rem;
    color: #fff; background: var(--plat); box-shadow: 0 8px 18px -8px rgb(79 70 229 / 0.6); cursor: pointer;
    transition: filter 0.15s ease, transform 0.15s ease;
}
.btn:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); }
.btn:disabled { opacity: 0.6; cursor: progress; }

@media (prefers-reduced-motion: reduce) { .btn:hover:not(:disabled) { transform: none; } }
</style>
