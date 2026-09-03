<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

/**
 * Módulos del negocio (Iteración 8, Tanda A).
 *
 * El propietario enciende o apaga los módulos activables (Tienda, Menús). Un módulo apagado no ejecuta una sola línea de su
 * código: su API y su superficie pública responden 404. Encenderlo surte efecto de inmediato (la cache de módulos se
 * invalida al guardar).
 */
const modules = ref([]);
const error = ref(null);
const saving = ref('');

onMounted(load);

async function load() {
    const { data } = await api.get('/modules');
    modules.value = data;
}

async function toggle(m) {
    saving.value = m.module;
    error.value = null;

    try {
        const { data } = await api.put(`/modules/${m.module}`, { enabled: !m.enabled });
        modules.value = data;
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
        <h1>Módulos</h1>
        <p class="nota">
            Activa las capacidades opcionales de tu negocio. Un módulo apagado no aparece en el sistema ni atiende su
            dirección pública.
        </p>

        <p v-if="error" class="error">{{ error }}</p>

        <ul class="lista">
            <li v-for="m in modules" :key="m.module" class="fila">
                <div>
                    <span class="nombre">{{ m.label }}</span>
                    <span class="estado" :class="m.enabled ? 'estado--on' : 'estado--off'">
                        {{ m.enabled ? 'Activo' : 'Inactivo' }}
                    </span>
                </div>
                <button type="button" :disabled="saving === m.module" @click="toggle(m)">
                    {{ m.enabled ? 'Desactivar' : 'Activar' }}
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.modulos { display: grid; gap: 1rem; max-width: 40rem; }
.modulos h1 { margin: 0; }
.nota { color: #555; font-size: 0.9rem; margin: 0; }
.error { color: #a11; }
.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.fila { display: flex; align-items: center; justify-content: space-between; gap: 1rem; border: 1px solid #d6d6d6; border-radius: 6px; padding: 0.75rem 1rem; }
.nombre { font-weight: 600; margin-right: 0.75rem; }
.estado { font-size: 0.8rem; padding: 0.1rem 0.5rem; border-radius: 999px; }
.estado--on { background: #dcfce7; color: #166534; }
.estado--off { background: #f3f4f6; color: var(--color-suave); }
.fila button { padding: 0.35rem 0.85rem; }
</style>
