import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { canDirective } from './directives/can';
import AdminLayout from './layouts/AdminLayout.vue';

/**
 * Punto de entrada del shell de Inertia (D59).
 *
 * Estructura de `resources/js` (espejo del backend, ARQUITECTURA_MAESTRA §9):
 *   Pages/       páginas resueltas por Inertia
 *   layouts/     administración, POS táctil, superficies públicas
 *   api/         cliente tipado de /api/v1 — de aquí salen TODOS los datos de dominio
 *   stores/      Pinia
 *   composables/ · components/ · directives/
 */

const appName = import.meta.env.VITE_APP_NAME || 'Comandia';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];

        // Layout por defecto para todo lo que vive bajo `Admin/`: así una pantalla nueva no puede
        // olvidarse de la navegación ni del selector de sucursal. Las páginas de autenticación no
        // lo llevan —todavía no hay contexto que mostrar— y por eso el prefijo decide.
        if (page && name.startsWith('Admin/') && page.default.layout === undefined) {
            page.default.layout = AdminLayout;
        }

        return page;
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .directive('can', canDirective)
            .mount(el);
    },

    progress: {
        color: '#c2410c',
    },
});
