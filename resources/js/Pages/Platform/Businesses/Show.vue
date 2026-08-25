<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

/**
 * Detalle de un negocio en la plataforma: su estado, las acciones legales desde ahí (activar, suspender, sólo lectura,
 * reactivar) y su historial de transiciones. Los botones que se ven son sólo los que el servidor aceptaría.
 */
const props = defineProps({
    business: { type: Object, required: true },
    history: { type: Array, required: true },
    allowed: { type: Array, required: true },
});

const BADGE = {
    active: 'ok',
    pending_activation: 'warn',
    read_only: 'warn',
    suspended: 'off',
    pending_deletion: 'off',
    cancelled: 'off',
};

const form = useForm({ status: '', reason: '' });

function change(status) {
    form.status = status;
    form.post(`/plataforma/negocios/${props.business.ulid}/estado`, {
        preserveScroll: true,
        onSuccess: () => form.reset('reason', 'status'),
    });
}
</script>

<template>
    <Head :title="`Plataforma · ${business.name}`" />

    <div class="detalle">
        <header class="cab">
            <div>
                <h1>{{ business.name }}</h1>
                <p class="hint">{{ business.slug }} · alta {{ business.created_at }}</p>
            </div>
            <Link href="/plataforma/negocios" class="volver">← Negocios</Link>
        </header>

        <section class="tarjeta ficha">
            <div>
                <span class="etq">Estado</span>
                <span class="badge" :class="`badge--${BADGE[business.status] ?? 'off'}`">{{ business.status_label }}</span>
            </div>
            <div>
                <span class="etq">Contacto</span>
                <strong>{{ business.contact_email }}</strong>
            </div>
        </section>

        <section class="tarjeta">
            <h2>Cambiar estado</h2>
            <p v-if="!allowed.length" class="hint">No hay cambios disponibles desde el estado actual.</p>

            <template v-else>
                <label class="campo">
                    <span>Motivo (opcional; queda en el historial)</span>
                    <input v-model="form.reason" type="text" maxlength="255" placeholder="p. ej. impago del mes" />
                </label>

                <div class="acciones">
                    <button
                        v-for="a in allowed"
                        :key="a.value"
                        type="button"
                        class="accion"
                        :class="`accion--${BADGE[a.value] ?? 'off'}`"
                        :disabled="form.processing"
                        @click="change(a.value)"
                    >
                        {{ a.label }}
                    </button>
                </div>
            </template>
        </section>

        <section class="tarjeta">
            <h2>Historial</h2>
            <p v-if="!history.length" class="hint">Sin transiciones registradas.</p>
            <ol v-else class="historial">
                <li v-for="(h, i) in history" :key="i">
                    <span class="hist__mov">{{ h.from ?? '—' }} → <strong>{{ h.to }}</strong></span>
                    <span v-if="h.reason" class="hist__motivo">{{ h.reason }}</span>
                    <span class="hist__fecha">{{ h.at }}</span>
                </li>
            </ol>
        </section>
    </div>
</template>

<style scoped>
.detalle { --plat: #4f46e5; display: grid; gap: 1.25rem; max-width: 46rem; }
.cab { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.cab h1 { margin: 0; font-size: 1.4rem; font-weight: 650; letter-spacing: -0.015em; }
.hint { margin: 0.3rem 0 0; font-size: 0.85rem; color: var(--color-suave); }

.volver {
    flex: none; font: inherit; font-size: 0.82rem; font-weight: 500; padding: 0.3rem 0.7rem;
    border: 1px solid color-mix(in srgb, var(--plat) 35%, transparent); border-radius: 0.5rem; color: var(--plat); text-decoration: none;
}
.volver:hover { background: color-mix(in srgb, var(--plat) 10%, transparent); }

.tarjeta {
    background: var(--color-superficie); border: 1px solid var(--color-borde); border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06); padding: 1.2rem 1.35rem; display: grid; gap: 0.85rem;
}
.tarjeta h2 { margin: 0; font-size: 1.05rem; font-weight: 650; }

.ficha { grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); }
.ficha > div { display: grid; gap: 0.35rem; align-content: start; }
.etq { font-size: 0.78rem; color: var(--color-suave); text-transform: uppercase; letter-spacing: 0.03em; }

.badge { display: inline-flex; align-items: center; padding: 0.18rem 0.6rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; width: fit-content; }
.badge--ok { background: var(--color-exito-tenue); color: var(--color-exito); }
.badge--warn { background: var(--color-aviso-tenue); color: var(--color-aviso); }
.badge--off { background: color-mix(in srgb, var(--color-suave) 15%, transparent); color: var(--color-suave); }

.campo { display: grid; gap: 0.3rem; font-size: 0.82rem; color: var(--color-contenido); }
.campo input {
    width: 100%; font: inherit; font-size: 0.9rem; padding: 0.55rem 0.65rem; border: 1px solid var(--color-borde);
    border-radius: 0.5rem; background: var(--color-superficie); color: var(--color-contenido);
}
.campo input:focus { outline: none; border-color: var(--plat); box-shadow: 0 0 0 3px rgb(79 70 229 / 0.15); }

.acciones { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.accion {
    font: inherit; font-size: 0.9rem; font-weight: 600; padding: 0.55rem 1.1rem; border: 1px solid transparent;
    border-radius: 0.5rem; cursor: pointer; transition: filter 0.15s ease;
}
.accion:disabled { opacity: 0.6; cursor: progress; }
.accion--ok { background: var(--color-exito); color: #fff; }
.accion--warn { background: var(--color-aviso); color: #fff; }
.accion--off { background: var(--color-peligro); color: #fff; }
.accion:hover:not(:disabled) { filter: brightness(1.06); }

.historial { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.6rem; }
.historial li { display: grid; gap: 0.15rem; padding-bottom: 0.6rem; border-bottom: 1px solid var(--color-borde); font-size: 0.9rem; }
.historial li:last-child { border-bottom: 0; padding-bottom: 0; }
.hist__motivo { color: var(--color-suave); font-size: 0.85rem; }
.hist__fecha { color: var(--color-suave); font-size: 0.78rem; font-variant-numeric: tabular-nums; }
</style>
