import { createApp } from 'vue';
import MenuApp from './MenuApp.vue';

/**
 * Entrada de la SPA pública del menú (Iteración 8, Tanda A).
 *
 * Es una app aparte del shell de administración: sin Inertia, sin sesión, sin el layout de admin. Es para el teléfono del
 * cliente que escanea el QR. Los datos vienen incrustados por el shell Blade (server-side, para el SEO y para no hacer una
 * segunda petición), en `window.__COMANDIA_MENU__`.
 */
const el = document.getElementById('menu-app');

if (el) {
    const data = window.__COMANDIA_MENU__ ?? { name: '', show_prices: true, theme: {}, sections: [] };
    createApp(MenuApp, { menu: data }).mount(el);
}
