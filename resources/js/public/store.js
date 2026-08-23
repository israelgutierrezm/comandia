import { createApp } from 'vue';
import StoreApp from './StoreApp.vue';

/**
 * Entrada de la SPA pública de la tienda (Iteración 8, Tanda B). App aparte del admin: sin Inertia, sin layout de admin.
 * Los datos base (nombre, tema, sucursales, slug) vienen incrustados por el shell; el catálogo y el carrito los pide a la
 * API pública `/t/{slug}/...`.
 */
const el = document.getElementById('store-app');

if (el) {
    const store = window.__COMANDIA_STORE__ ?? { slug: '', name: '', theme: {}, branches: [] };
    createApp(StoreApp, { store }).mount(el);
}
