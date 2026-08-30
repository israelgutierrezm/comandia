<script setup>
import { ref } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

/**
 * Apariencia del negocio (rediseño, Fase B).
 *
 * El negocio elige el acento de marca de su panel entre una paleta curada. Al guardar, el servidor persiste el ajuste
 * y el shell se recarga: el acento vuelve resuelto por el backend y `AdminLayout` lo inyecta en `--color-acento`, así
 * que el cambio se ve al instante en toda la administración. El frontend sólo conoce los hex para la previsualización.
 */
const PRESETS = [
    { key: 'terracota', label: 'Terracota', hex: '#c2410c' },
    { key: 'esmeralda', label: 'Esmeralda', hex: '#047857' },
    { key: 'oceano', label: 'Océano', hex: '#0369a1' },
    { key: 'ciruela', label: 'Ciruela', hex: '#7c3aed' },
    { key: 'vino', label: 'Vino', hex: '#9f1239' },
    { key: 'pizarra', label: 'Pizarra', hex: '#334155' },
];

// Espejo de `SidebarPreset` (backend). Tonos oscuros a propósito: el texto del sidebar es claro, así que cualquiera
// queda legible sin tocar el resto del tema. El backend es la verdad; esto sólo previsualiza.
const SIDEBAR_PRESETS = [
    { key: 'piedra', label: 'Piedra', hex: '#292524' },
    { key: 'grafito', label: 'Grafito', hex: '#1f2937' },
    { key: 'noche', label: 'Noche', hex: '#0f172a' },
    { key: 'bosque', label: 'Bosque', hex: '#14342b' },
    { key: 'vino', label: 'Vino', hex: '#3f1020' },
    { key: 'indigo', label: 'Índigo', hex: '#1e1b4b' },
];

const page = usePage();
const current = ref(page.props.theme?.key ?? 'terracota');
const currentSidebar = ref(page.props.theme?.sidebar_key ?? 'piedra');
const saving = ref('');
const error = ref(null);
const saved = ref(false);

async function pick(preset) {
    if (preset.key === current.value || saving.value !== '') {
        return;
    }

    saving.value = `accent:${preset.key}`;
    error.value = null;
    saved.value = false;

    try {
        await api.put('/settings/appearance.accent', { value: preset.key });
        current.value = preset.key;
        // Previsualización inmediata: pintamos el acento antes de que llegue el shell recargado.
        document.documentElement.style.setProperty('--color-acento', preset.hex);
        // Y recargamos sólo el tema para que el ajuste persista en cada navegación posterior.
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

async function pickSidebar(preset) {
    if (preset.key === currentSidebar.value || saving.value !== '') {
        return;
    }

    saving.value = `sidebar:${preset.key}`;
    error.value = null;
    saved.value = false;

    try {
        await api.put('/settings/appearance.sidebar', { value: preset.key });
        currentSidebar.value = preset.key;
        // Previsualización inmediata: pintamos la barra antes de que llegue el shell recargado.
        document.documentElement.style.setProperty('--color-barra-lateral', preset.hex);
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
            <p>Los colores de tu panel. Se aplican al instante en toda la administración.</p>
        </header>

        <p v-if="error" class="alert alert--notice" role="alert">{{ error }}</p>
        <p v-else-if="saved" class="alert alert--ok" role="status">Listo, tu apariencia quedó guardada.</p>

        <section class="grupo">
            <h2 class="grupo__titulo">Color de acento</h2>
            <p class="grupo__nota">Botones, enlaces y resaltados de todo el panel.</p>

            <div class="swatches">
                <button
                    v-for="preset in PRESETS"
                    :key="preset.key"
                    type="button"
                    class="swatch tarjeta"
                    :class="{ 'swatch--activo': current === preset.key }"
                    :disabled="saving !== '' && saving !== `accent:${preset.key}`"
                    :aria-pressed="current === preset.key"
                    @click="pick(preset)"
                >
                    <span class="swatch__muestra" :style="{ background: preset.hex }" aria-hidden="true">
                        <svg v-if="current === preset.key" viewBox="0 0 20 20" fill="none" width="16" height="16">
                            <path d="M4 10.5l4 4 8-8" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span class="swatch__nombre">{{ preset.label }}</span>
                </button>
            </div>
        </section>

        <section class="grupo">
            <h2 class="grupo__titulo">Color de la barra lateral</h2>
            <p class="grupo__nota">El fondo del menú de navegación. Todos son oscuros para que el texto siga legible.</p>

            <div class="swatches">
                <button
                    v-for="preset in SIDEBAR_PRESETS"
                    :key="preset.key"
                    type="button"
                    class="swatch tarjeta"
                    :class="{ 'swatch--activo': currentSidebar === preset.key }"
                    :disabled="saving !== '' && saving !== `sidebar:${preset.key}`"
                    :aria-pressed="currentSidebar === preset.key"
                    @click="pickSidebar(preset)"
                >
                    <span class="swatch__muestra" :style="{ background: preset.hex }" aria-hidden="true">
                        <svg v-if="currentSidebar === preset.key" viewBox="0 0 20 20" fill="none" width="16" height="16">
                            <path d="M4 10.5l4 4 8-8" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span class="swatch__nombre">{{ preset.label }}</span>
                </button>
            </div>
        </section>
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

.grupo {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.grupo__titulo {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 650;
    color: var(--color-contenido);
}

.grupo__nota {
    margin: 0 0 0.65rem;
    color: var(--color-suave);
    font-size: 0.875rem;
}

.swatches {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
    gap: 0.85rem;
}

.swatch {
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

.swatch:hover:not(:disabled) {
    border-color: var(--color-acento);
    transform: translateY(-1px);
}

.swatch:disabled {
    opacity: 0.55;
    cursor: default;
}

.swatch--activo {
    border-color: var(--color-acento);
    box-shadow: 0 0 0 1px var(--color-acento);
}

.swatch__muestra {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.6rem;
    flex: none;
    display: grid;
    place-items: center;
    box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.08);
}

.swatch__nombre {
    font-weight: 600;
    font-size: 0.95rem;
}
</style>
