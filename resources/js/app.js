import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

/**
 * Punto de entrada del shell de Inertia.
 *
 * Estructura de resources/js (espejo del backend, ARQUITECTURA_MAESTRA §9):
 *   Pages/            páginas resueltas por Inertia (shell)
 *   modules/{modulo}/  views, components, composables, stores (Pinia), services
 *   layouts/          administración, POS táctil, superficies públicas
 */

const appName = import.meta.env.VITE_APP_NAME || 'Comandia';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        color: '#c2410c',
    },
});
