<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Marco de las pantallas de acceso (rediseño, Fase C).
 *
 * Escritorio: el FORMULARIO a la izquierda; a la derecha un panel a pantalla completa con un fondo
 * animado de ondas (vanta.js sobre WebGL) en los colores de marca de Comandia —FIJOS, no el acento
 * del negocio: aquí todavía no hay negocio elegido, y el acceso debe verse igual para todos—.
 * Móvil: solo el formulario; el panel animado no se monta (sin WebGL, sin lienzo, sin batería
 * malgastada en una pantalla que se cruza en segundos).
 *
 * ## Por qué three/vanta se cargan con `import()` diferido
 *
 * three.js + vanta pesan ~600 KB. Las páginas de Inertia se empaquetan de forma ANSIOSA (`eager` en
 * el glob de `app.js`), así que importarlos arriba los metería en el bundle del POS y la
 * administración —que deben abrir rápido en una tablet de caja—. Por eso se cargan con `import()`
 * dinámico DENTRO de `onMounted`: quedan en su propio trozo asíncrono que solo se baja en la
 * pantalla de acceso y solo en escritorio. Si WebGL falla o las librerías no cargan, queda el fondo
 * cálido estático y el acceso no se rompe.
 */
const fondo = ref(null);
let efecto = null;
let observador = null;

onMounted(async () => {
    if (fondo.value === null) {
        return;
    }

    // En móvil no se monta el efecto: la pantalla es solo el formulario.
    if (!window.matchMedia('(min-width: 1024px)').matches) {
        return;
    }

    // Respeta a quien pide menos movimiento: sin animación, queda el fondo cálido estático.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    let THREE;
    let WAVES;
    try {
        THREE = await import('three');
        WAVES = (await import('vanta/dist/vanta.waves.min')).default;
    } catch {
        return; // sin las librerías, queda el fondo cálido
    }

    try {
        efecto = WAVES({
            el: fondo.value,
            THREE,
            mouseControls: true,
            touchControls: false,
            gyroControls: false,
            minHeight: 200,
            minWidth: 200,
            scale: 1,
            scaleMobile: 1,
            // Colores de marca FIJOS (terracota profunda), independientes del acento del negocio.
            color: 0x7c2d12,
            shininess: 32,
            waveHeight: 14,
            waveSpeed: 0.85,
            zoom: 0.92,
        });
    } catch {
        return; // sin WebGL queda el fondo cálido
    }

    // Reajuste tras el layout: el panel toma su alto por flex y vanta podía montar con el tamaño
    // equivocado —el bug clásico de «en escritorio no se ve nada»—.
    const reajustar = () => {
        try {
            efecto?.resize();
        } catch {
            // nada
        }
    };
    requestAnimationFrame(reajustar);
    setTimeout(reajustar, 300);

    if (typeof ResizeObserver !== 'undefined') {
        observador = new ResizeObserver(reajustar);
        observador.observe(fondo.value);
    }
});

onBeforeUnmount(() => {
    observador?.disconnect();
    try {
        efecto?.destroy();
    } catch {
        // nada
    }
});
</script>

<template>
    <div class="marco">
        <!-- Panel del formulario -->
        <div class="panel-form">
            <div class="entra caja">
                <div class="cabecera">
                    <span class="logo" aria-hidden="true">
                        <!-- Campana de servicio: la seña de «la orden está lista» en un restaurante. -->
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 18h16.5M5.25 18a6.75 6.75 0 0 1 13.5 0M12 6.75V4.5m-2.25 0h4.5" />
                        </svg>
                    </span>
                    <h1 class="marca">Comandia</h1>
                    <slot name="subtitulo">
                        <p class="lead">Administración y punto de venta</p>
                    </slot>
                </div>

                <slot />

                <div class="pie">
                    <span>Comandia</span>
                    <span class="pie__punto">•</span>
                    <a href="mailto:soporte@comandia.mx">Soporte</a>
                </div>
            </div>
        </div>

        <!-- Panel animado: SOLO escritorio. -->
        <div class="panel-arte" aria-hidden="true">
            <div ref="fondo" class="lienzo"></div>
            <div class="velo"></div>
            <div class="arte-texto">
                <span class="arte-marca">Comandia</span>
                <h2>Del pedido<br />al corte de caja.</h2>
                <span class="regla"></span>
                <p>Punto de venta, inventario y caja en una sola plataforma para tu negocio de alimentos y bebidas.</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.marco {
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    color: #1c1917;
    background: #f7f5f3;
}

/* --- Panel del formulario --- */
.panel-form {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    padding: 2.5rem 1.5rem;
}

.caja {
    width: 100%;
    max-width: 22rem;
}

.cabecera {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    margin-bottom: 2rem;
}

.logo {
    display: grid;
    place-items: center;
    width: 3.75rem;
    height: 3.75rem;
    border-radius: 1rem;
    color: #fff;
    background-image: linear-gradient(135deg, #3b82f6, #2563eb);
    box-shadow: 0 10px 24px -10px rgba(194, 65, 12, 0.7);
}

.logo svg {
    width: 2rem;
    height: 2rem;
}

.marca {
    margin: 1rem 0 0;
    font-size: 1.65rem;
    font-weight: 700;
    letter-spacing: -0.02em;
}

.lead {
    margin: 0.35rem 0 0;
    font-size: 0.9rem;
    color: #78716c;
}

.pie {
    margin-top: 2.25rem;
    text-align: center;
    font-size: 0.75rem;
    color: #a8a29e;
}

.pie a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
}

.pie a:hover {
    color: #2563eb;
}

.pie__punto {
    margin: 0 0.5rem;
}

/* --- Panel animado (escritorio) --- */
.panel-arte {
    position: relative;
    display: none;
    overflow: hidden;
    /* Fondo cálido mientras vanta pinta (o si WebGL falla): no deja un hueco negro. */
    background: #2a1206;
}

.lienzo {
    position: absolute;
    inset: 0;
}

.velo {
    position: absolute;
    inset: 0;
    background: linear-gradient(115deg, rgba(30, 12, 4, 0.62), rgba(30, 12, 4, 0.2) 55%, transparent);
}

.arte-texto {
    position: relative;
    z-index: 1;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 3.5rem;
    color: #fff;
}

.arte-marca {
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.68);
}

.arte-texto h2 {
    margin: 0.85rem 0 0;
    font-size: 2.4rem;
    font-weight: 700;
    line-height: 1.08;
    letter-spacing: -0.02em;
    text-wrap: balance;
    text-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
}

.regla {
    display: block;
    width: 3.5rem;
    height: 0.28rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.75);
    margin: 1.5rem 0;
}

.arte-texto p {
    margin: 0;
    max-width: 26rem;
    font-size: 1.02rem;
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.88);
}

@media (min-width: 1024px) {
    .marco {
        flex-direction: row;
    }

    .panel-form {
        order: 1;
    }

    .panel-arte {
        order: 2;
        display: block;
        flex: 0 0 52%;
    }
}

@keyframes entrar {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.entra {
    animation: entrar 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@media (prefers-reduced-motion: reduce) {
    .entra {
        animation: none;
    }
}
</style>
