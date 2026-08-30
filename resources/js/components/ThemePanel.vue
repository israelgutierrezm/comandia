<script setup>
import { computed, ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { api, ApiError } from '../api/client';

/**
 * Panel de apariencia (estilo Acadion): se abre desde la barra superior y entra desde la derecha.
 *
 * Elegir un tema y personalizar colores son preferencias de la PERSONA, así que se guardan por su membresía vía
 * `/api/v1/preferences/theme*` y luego se recarga sólo el prop `theme` del shell —igual que la vieja pantalla de
 * Apariencia recargaba el acento—, así el `AdminLayout` re-inyecta la paleta al instante. El tamaño de letra lo lleva el
 * layout (es por navegador); aquí sólo se emiten los ajustes.
 */
const props = defineProps({
    abierto: { type: Boolean, default: false },
    escala: { type: Number, default: 100 },
    paso: { type: Number, default: 10 },
});

const emit = defineEmits(['cerrar', 'ajustar']);

const page = usePage();
const tema = computed(() => page.props.theme ?? {});

const guardando = ref(false);
const error = ref(null);

/** Los únicos tokens personalizables (espejo de la lista blanca del backend). El resto los fija el tema. */
const personalizables = [
    { token: 'acento', etiqueta: 'Color de acento' },
    { token: 'barra_lateral', etiqueta: 'Barra lateral' },
    { token: 'barra_lateral_activo', etiqueta: 'Resaltado activo' },
];

async function ejecutar(accion) {
    guardando.value = true;
    error.value = null;

    try {
        await accion();
        // Recargamos sólo el tema: el `AdminLayout` observa `theme.tokens` y re-pinta la paleta.
        router.reload({ only: ['theme'] });
    } catch (e) {
        if (e instanceof ApiError) {
            error.value = e.title;
        } else {
            throw e;
        }
    } finally {
        guardando.value = false;
    }
}

function elegirTema(ulid) {
    if (guardando.value) {
        return;
    }

    ejecutar(() => api.put('/preferences/theme', { theme_ulid: ulid }));
}

function personalizar(token, valor) {
    ejecutar(() => api.put('/preferences/theme/color', { token, value: valor }));
}

function restablecer() {
    ejecutar(() => api.delete('/preferences/theme/overrides'));
}
</script>

<template>
    <Transition name="velo">
        <div v-if="abierto" class="velo" @click="emit('cerrar')" />
    </Transition>

    <Transition name="panel">
        <aside v-if="abierto" class="panel" role="dialog" aria-label="Apariencia">
            <header class="panel__cabecera">
                <div>
                    <h2>Apariencia</h2>
                    <p class="panel__sub">Se guarda en tu cuenta</p>
                </div>
                <button type="button" class="panel__cerrar" aria-label="Cerrar" @click="emit('cerrar')">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="panel__cuerpo">
                <p v-if="error" class="panel__error" role="alert">{{ error }}</p>

                <!-- Temas -->
                <section>
                    <h3 class="panel__titulo">Tema</h3>

                    <div class="temas">
                        <button
                            v-for="opcion in (tema.available ?? [])"
                            :key="opcion.ulid"
                            type="button"
                            class="tema"
                            :class="{ 'tema--activo': opcion.key === tema.key }"
                            :disabled="guardando"
                            :aria-pressed="opcion.key === tema.key"
                            @click="elegirTema(opcion.ulid)"
                        >
                            <span class="tema__muestra" :style="{ background: opcion.sample.fondo }" aria-hidden="true">
                                <span class="tema__barra" :style="{ background: opcion.sample.barra_lateral }" />
                                <span class="tema__punto" :style="{ background: opcion.sample.acento }" />
                            </span>

                            <span class="tema__texto">
                                <span class="tema__nombre">{{ opcion.name }}</span>
                                <span v-if="opcion.is_default" class="tema__default">Predeterminado del negocio</span>
                            </span>

                            <svg
                                v-if="opcion.key === tema.key"
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
                </section>

                <!-- Tamaño de letra -->
                <section>
                    <h3 class="panel__titulo">Tamaño de letra</h3>
                    <p class="panel__nota">Sólo para ti, y sólo en este navegador.</p>

                    <div class="escala">
                        <button
                            type="button"
                            class="escala__boton"
                            :disabled="escala <= 80"
                            aria-label="Reducir tamaño de letra"
                            @click="emit('ajustar', -paso)"
                        >
                            A−
                        </button>
                        <span class="escala__valor">{{ escala }}%</span>
                        <button
                            type="button"
                            class="escala__boton"
                            :disabled="escala >= 140"
                            aria-label="Aumentar tamaño de letra"
                            @click="emit('ajustar', paso)"
                        >
                            A+
                        </button>
                    </div>
                </section>

                <!-- Personalización -->
                <section v-if="tema.allows_override">
                    <h3 class="panel__titulo">Ajustes propios</h3>
                    <p class="panel__nota">Sobrescriben el tema sólo para ti.</p>

                    <label v-for="campo in personalizables" :key="campo.token" class="color">
                        <span>{{ campo.etiqueta }}</span>
                        <input
                            type="color"
                            :value="(tema.tokens ?? {})[campo.token]"
                            :disabled="guardando"
                            @change="personalizar(campo.token, $event.target.value)"
                        />
                    </label>

                    <button type="button" class="panel__restablecer" :disabled="guardando" @click="restablecer">
                        Restablecer colores del tema
                    </button>
                </section>

                <p v-else class="panel__aviso">
                    Este tema no admite ajustes personales: sus colores están fijados para garantizar el contraste.
                </p>
            </div>
        </aside>
    </Transition>
</template>

<style scoped>
.velo {
    position: fixed;
    inset: 0;
    z-index: 40;
    background: rgb(15 23 42 / 0.4);
}
.velo-enter-active, .velo-leave-active { transition: opacity 0.25s ease; }
.velo-enter-from, .velo-leave-to { opacity: 0; }

.panel {
    position: fixed;
    top: 0;
    right: 0;
    z-index: 50;
    display: flex;
    flex-direction: column;
    width: 20rem;
    max-width: 92vw;
    height: 100%;
    background: var(--color-superficie);
    color: var(--color-contenido);
    box-shadow: -8px 0 30px -12px rgb(0 0 0 / 0.35);
}
.panel-enter-active, .panel-leave-active { transition: transform 0.28s ease; }
.panel-enter-from, .panel-leave-to { transform: translateX(100%); }

.panel__cabecera {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-borde);
}
.panel__cabecera h2 { margin: 0; font-size: 0.95rem; font-weight: 650; }
.panel__sub { margin: 0.1rem 0 0; font-size: 0.78rem; color: var(--color-suave); }
.panel__cerrar {
    display: grid;
    place-items: center;
    width: 2rem;
    height: 2rem;
    border: 0;
    border-radius: 0.5rem;
    background: transparent;
    color: var(--color-suave);
    cursor: pointer;
}
.panel__cerrar:hover { background: color-mix(in srgb, var(--color-contenido) 8%, transparent); }

.panel__cuerpo { flex: 1; overflow-y: auto; padding: 1.25rem; display: flex; flex-direction: column; gap: 1.5rem; }
.panel__titulo { margin: 0 0 0.65rem; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-suave); }
.panel__nota { margin: -0.35rem 0 0.65rem; font-size: 0.78rem; color: var(--color-suave); }
.panel__error { margin: 0; font-size: 0.82rem; color: var(--color-peligro); }
.panel__aviso { margin: 0; padding: 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; background: var(--color-fondo); color: var(--color-suave); }

.temas { display: flex; flex-direction: column; gap: 0.5rem; }
.tema {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.7rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.7rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    text-align: left;
    font: inherit;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.tema:hover:not(:disabled) { border-color: var(--color-acento); }
.tema:disabled { opacity: 0.6; cursor: default; }
.tema--activo { border-color: var(--color-acento); box-shadow: 0 0 0 1px var(--color-acento); }

.tema__muestra {
    position: relative;
    display: flex;
    width: 3rem;
    height: 2.1rem;
    flex: none;
    border-radius: 0.4rem;
    overflow: hidden;
    box-shadow: inset 0 0 0 1px rgb(0 0 0 / 0.1);
}
.tema__barra { width: 33%; height: 100%; }
.tema__punto { position: absolute; right: 0.25rem; bottom: 0.25rem; width: 0.5rem; height: 0.5rem; border-radius: 50%; }

.tema__texto { flex: 1; display: flex; flex-direction: column; }
.tema__nombre { font-size: 0.9rem; font-weight: 600; }
.tema__default { font-size: 0.72rem; color: var(--color-suave); }
.tema__check { color: var(--color-acento); flex: none; }

.escala { display: flex; align-items: center; gap: 0.6rem; }
.escala__boton {
    display: grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background: transparent;
    color: var(--color-contenido);
    cursor: pointer;
    font-weight: 700;
    font-size: 0.85rem;
}
.escala__boton:hover:not(:disabled) { border-color: var(--color-acento); color: var(--color-acento); }
.escala__boton:disabled { opacity: 0.4; cursor: default; }
.escala__valor { width: 3.5rem; text-align: center; font-size: 0.9rem; font-variant-numeric: tabular-nums; }

.color { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; font-size: 0.88rem; margin-bottom: 0.6rem; }
.color input[type='color'] { width: 3rem; height: 2rem; padding: 0; border: 1px solid var(--color-borde); border-radius: 0.4rem; background: transparent; cursor: pointer; }

.panel__restablecer {
    margin-top: 0.4rem;
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background: transparent;
    color: var(--color-suave);
    cursor: pointer;
    font-size: 0.8rem;
}
.panel__restablecer:hover:not(:disabled) { border-color: var(--color-acento); color: var(--color-acento); }
</style>
