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

        <!-- Panel de marca: SOLO escritorio. Azul marino PROFUNDO en degradado, con líneas de onda
             luminosas (cian→menta) como detalle — el lenguaje de la marca: oscuro dominante, brillos de
             detalle. Es SVG (sin WebGL): ligero y nítido, y el acceso no depende de que cargue una
             librería de 600 KB. -->
        <div class="panel-arte" aria-hidden="true">
            <svg class="ondas" viewBox="0 0 640 900" preserveAspectRatio="xMidYMax slice">
                <defs>
                    <linearGradient id="cx-trazo" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#1DCDFE" />
                        <stop offset="0.55" stop-color="#21D0B2" />
                        <stop offset="1" stop-color="#34F5C5" />
                    </linearGradient>
                    <radialGradient id="cx-brillo" cx="0.5" cy="0.5" r="0.5">
                        <stop offset="0" stop-color="#34F5C5" stop-opacity="0.5" />
                        <stop offset="0.6" stop-color="#1DCDFE" stop-opacity="0.14" />
                        <stop offset="1" stop-color="#1DCDFE" stop-opacity="0" />
                    </radialGradient>
                    <filter id="cx-glow" x="-20%" y="-20%" width="140%" height="140%">
                        <feGaussianBlur stdDeviation="3.2" result="b" />
                        <feMerge>
                            <feMergeNode in="b" />
                            <feMergeNode in="SourceGraphic" />
                        </feMerge>
                    </filter>
                </defs>

                <!-- Nodo luminoso al que convergen las líneas, abajo a la derecha. -->
                <ellipse cx="600" cy="720" rx="260" ry="210" fill="url(#cx-brillo)" />

                <!-- Líneas de onda: más densas y brillantes hacia la esquina inferior derecha. -->
                <g class="ondas__lineas" fill="none" stroke="url(#cx-trazo)" stroke-width="1.4" filter="url(#cx-glow)">
                    <path d="M-30 372 C 150 316 330 430 700 292" stroke-opacity="0.22" />
                    <path d="M-30 424 C 150 378 330 486 700 356" stroke-opacity="0.28" />
                    <path d="M-30 476 C 150 440 330 540 700 426" stroke-opacity="0.36" />
                    <path d="M-30 526 C 150 500 330 590 700 498" stroke-opacity="0.45" />
                    <path d="M-30 574 C 155 558 335 634 700 566" stroke-opacity="0.55" />
                    <path d="M-30 620 C 165 620 345 678 700 632" stroke-opacity="0.67" />
                    <path d="M-30 662 C 175 680 355 716 700 690" stroke-opacity="0.8" />
                    <path d="M-30 702 C 185 728 365 752 700 742" stroke-opacity="0.94" />
                </g>
            </svg>

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
    color: #13212e;
    background: #f1f5f8;
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
    border-radius: var(--radio-lg);
    color: #fff;
    /* La C del logo: cian → teal → menta. */
    background-image: linear-gradient(150deg, #1dcdfe, #21d0b2 60%, #34f5c5);
    box-shadow: 0 10px 24px -10px rgba(33, 208, 178, 0.6);
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
    color: #5c7482;
}

.pie {
    margin-top: 2.25rem;
    text-align: center;
    font-size: 0.75rem;
    color: #8ca0ad;
}

.pie a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
}

.pie a:hover {
    color: #0b8a99;
}

.pie__punto {
    margin: 0 0.5rem;
}

/* --- Panel de marca (escritorio) --- */
.panel-arte {
    position: relative;
    display: none;
    overflow: hidden;
    /* Azul marino PROFUNDO en degradado: lo alto-izquierda un poco iluminado, oscureciendo a los bordes.
       Es la base «oscuro dominante» sobre la que brillan las líneas. */
    background:
        radial-gradient(125% 95% at 32% 28%, #17436f 0%, #0c2949 42%, #081d34 72%, #05121f 100%);
}

.ondas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    animation: cx-deriva 14s ease-in-out infinite alternate;
}

@keyframes cx-deriva {
    from { transform: translate3d(0, 0, 0); }
    to { transform: translate3d(-2%, -1.5%, 0); }
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
    /* Detalle brillante sobre el azul: el cintillo en menta de marca. */
    color: #34f5c5;
}

.arte-texto h2 {
    margin: 0.85rem 0 0;
    font-size: 2.4rem;
    font-weight: 700;
    line-height: 1.08;
    letter-spacing: -0.02em;
    text-wrap: balance;
    text-shadow: 0 2px 16px rgba(0, 0, 0, 0.35);
}

.regla {
    display: block;
    width: 3.5rem;
    height: 0.28rem;
    border-radius: 999px;
    /* Detalle brillante: la regla en degradado cian→menta, la seña del logo. */
    background: linear-gradient(90deg, #1dcdfe, #34f5c5);
    margin: 1.5rem 0;
}

.arte-texto p {
    margin: 0;
    max-width: 26rem;
    font-size: 1.02rem;
    line-height: 1.6;
    color: rgba(213, 230, 240, 0.9);
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
    .entra,
    .ondas {
        animation: none;
    }
}
</style>
