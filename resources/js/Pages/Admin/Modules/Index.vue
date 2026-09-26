<script setup>
import { onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import ListHeader from '../../../components/ListHeader.vue';

/**
 * Módulos del negocio (Iteración 8, Tanda A).
 *
 * El propietario enciende o apaga los módulos activables (Tienda, Menús). Un módulo apagado no ejecuta una sola línea de su
 * código: su API y su superficie pública responden 404. Encenderlo surte efecto de inmediato (la cache de módulos se
 * invalida al guardar).
 */
const modules = ref([]);
const loading = ref(true);
const loadError = ref(null);
const error = ref(null);
const saving = ref('');

/**
 * Lo que deja de funcionar al apagar cada módulo, dicho en concreto para la confirmación. Apagar surte efecto en la
 * siguiente petición, así que un clic de más deja al público sin tienda o sin menú a media operación.
 */
const EFECTO_AL_APAGAR = {
    Ecommerce: 'La tienda en línea y los canales de marketplace dejan de recibir pedidos de inmediato, y la bandeja de pedidos deja de estar disponible.',
    DigitalMenus: 'Los menús digitales dejan de verse de inmediato: su dirección pública y sus códigos QR dejan de responder.',
};

onMounted(load);

async function load() {
    try {
        modules.value = (await api.get('/modules')).data;
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
}

async function toggle(m) {
    if (m.enabled) {
        const efecto = EFECTO_AL_APAGAR[m.module]
            ?? 'Su sección desaparece del sistema y su dirección pública deja de responder de inmediato.';

        if (!window.confirm(`¿Desactivar «${m.label}»? ${efecto} Su configuración se conserva y puedes volver a activarlo cuando quieras.`)) {
            return;
        }
    }

    saving.value = m.module;
    error.value = null;

    try {
        const { data } = await api.put(`/modules/${m.module}`, { enabled: !m.enabled });
        modules.value = data;
        // El menú lateral filtra por `active_modules` del shell: sin recargar ese prop, la sección recién encendida no
        // aparecería (o la apagada seguiría ahí) hasta la siguiente navegación.
        router.reload({ only: ['active_modules'] });
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        saving.value = '';
    }
}
</script>

<template>
    <Head title="Módulos" />

    <div class="modulos">
        <ListHeader
            title="Módulos"
            subtitle="Activa las capacidades opcionales de tu negocio. Un módulo apagado no aparece en el sistema ni atiende su dirección pública."
        />

        <p v-if="loadError" class="alert" role="alert">{{ loadError }}</p>
        <p v-if="error" class="alert" role="alert">{{ error }}</p>

        <p v-if="loading" class="nota">Cargando…</p>

        <ul v-else class="lista">
            <li v-for="m in modules" :key="m.module" class="fila">
                <div>
                    <span class="nombre">{{ m.label }}</span>
                    <span class="badge" :class="m.enabled ? 'badge--ok' : 'badge--off'">
                        {{ m.enabled ? 'Activo' : 'Inactivo' }}
                    </span>
                </div>
                <button
                    type="button"
                    class="link-button"
                    :class="{ 'link-button--danger': m.enabled }"
                    :disabled="saving === m.module"
                    @click="toggle(m)"
                >
                    {{ m.enabled ? 'Desactivar' : 'Activar' }}
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.modulos { display: grid; gap: 1rem; max-width: 40rem; }
/* En la rejilla el espacio lo pone el `gap`; el margen propio del aviso lo duplicaría. */
.modulos > .alert { margin: 0; }
.nota { color: var(--color-suave); font-size: 0.9rem; margin: 0; }
.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.fila {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    padding: 0.75rem 1rem;
}
.nombre { font-weight: 600; margin-right: 0.75rem; }
</style>
