import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Dos entradas: el shell de administración (Inertia) y la SPA pública (menú QR / tienda, Iteración 8).
            // La pública NO carga Inertia ni el layout de admin: es para el teléfono del cliente, sin sesión.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/public/menu.js', 'resources/js/public/store.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
