import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * EncuestaLive — configuración de Vite.
 *
 * CAMBIO RESPECTO AL ESQUELETO DE LARAVEL:
 * se ha retirado el plugin `fonts` de laravel-vite-plugin con `bunny('Instrument Sans')`.
 *
 * Motivo: UX_UI_SPEC §5.3 fija **Inter** (SIL OFL) como familia del producto, no
 * Instrument Sans. Inter se sirve AUTOALOJADA desde `resources/css/fonts/*.woff2`
 * mediante `@font-face` propios en `resources/css/fonts.css`, de modo que el
 * navegador del participante no contacta con ninguna CDN externa en tiempo de
 * ejecución. Vite procesa esos `url()` y emite los binarios con hash a
 * `public/build/assets/`.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
