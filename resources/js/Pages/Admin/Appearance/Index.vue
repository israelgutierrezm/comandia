<script setup>
import { computed, ref } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

/**
 * Apariencia del negocio: el tema por OMISIÓN (rediseño estilo Acadion).
 *
 * Esta pantalla decide el tema que ve quien no ha elegido uno propio —una decisión de configuración del negocio, con su
 * permiso—. La elección PERSONAL y la personalización de colores viven en el panel de apariencia de la barra superior,
 * porque son de cada quien. El servidor persiste el default y el shell se recarga: la paleta vuelve resuelta y el
 * `AdminLayout` la inyecta al instante.
 */
const page = usePage();
const theme = computed(() => page.props.theme ?? {});
const disponibles = computed(() => theme.value.available ?? []);

const saving = ref('');
const error = ref(null);
const saved = ref(false);

async function fijarDefault(opcion) {
    if (opcion.is_default || saving.value !== '') {
        return;
    }

    saving.value = opcion.ulid;
    error.value = null;
    saved.value = false;

    try {
        await api.post(`/themes/${opcion.ulid}/default`);
        router.reload({ only: ['theme'] });
        saved.value = true;
    } catch (e) {
        if (e instanceof ApiError) {
            error.value = e.title;
        } else {
            throw e;
        }
    } finally {
        saving.value = '';
    }
}
</script>

<template>
    <Head title="Apariencia" />

    <div class="apariencia animar-entrada">
        <header class="apariencia__intro">
            <h1>Apariencia</h1>
            <p>
                El tema por omisión de tu negocio: lo verá quien no haya elegido uno propio. Cada persona puede cambiar
                el suyo y personalizar colores desde el botón de apariencia, arriba a la derecha.
            </p>
        </header>

        <p v-if="error" class="alert alert--notice" role="alert">{{ error }}</p>
        <p v-else-if="saved" class="alert alert--ok" role="status">Listo, el tema por omisión quedó guardado.</p>

        <div class="temas">
            <button
                v-for="opcion in disponibles"
                :key="opcion.ulid"
                type="button"
                class="tema tarjeta"
                :class="{ 'tema--activo': opcion.is_default }"
                :disabled="saving !== '' && saving !== opcion.ulid"
                :aria-pressed="opcion.is_default"
                @click="fijarDefault(opcion)"
            >
                <span class="tema__muestra" :style="{ background: opcion.sample.fondo }" aria-hidden="true">
                    <span class="tema__barra" :style="{ background: opcion.sample.barra_lateral }" />
                    <span class="tema__punto" :style="{ background: opcion.sample.acento }" />
                </span>

                <span class="tema__texto">
                    <span class="tema__nombre">{{ opcion.name }}</span>
                    <span v-if="opcion.is_default" class="tema__default">Predeterminado</span>
                </span>

                <svg
                    v-if="opcion.is_default"
                    class="tema__check"
                    viewBox="0 0 20 20"
                    width="18"
                    height="18"
                    fill="none"
                >
                    <path d="M4 10.5l4 4 8-8" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
        </div>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.apariencia {
    max-width: 44rem;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.apariencia__intro h1 {
    margin: 0 0 0.35rem;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-contenido);
}

.apariencia__intro p {
    margin: 0;
    color: var(--color-suave);
    font-size: 0.925rem;
}

.temas {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
    gap: 0.85rem;
}

.tema {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 0.85rem 1rem;
    cursor: pointer;
    text-align: left;
    font: inherit;
    color: var(--color-contenido);
    border: 1px solid var(--color-borde);
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.tema:hover:not(:disabled) { border-color: var(--color-acento); transform: translateY(-1px); }
.tema:disabled { opacity: 0.55; cursor: default; }
.tema--activo { border-color: var(--color-acento); box-shadow: 0 0 0 1px var(--color-acento); }

.tema__muestra {
    position: relative;
    display: flex;
    width: 3.25rem;
    height: 2.3rem;
    flex: none;
    border-radius: 0.45rem;
    overflow: hidden;
    box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.1);
}
.tema__barra { width: 33%; height: 100%; }
.tema__punto { position: absolute; right: 0.3rem; bottom: 0.3rem; width: 0.55rem; height: 0.55rem; border-radius: 50%; }

.tema__texto { flex: 1; display: flex; flex-direction: column; }
.tema__nombre { font-weight: 600; font-size: 0.95rem; }
.tema__default { font-size: 0.75rem; color: var(--color-suave); }
.tema__check { color: var(--color-acento); flex: none; }
</style>
