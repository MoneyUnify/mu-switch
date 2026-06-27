import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';
import { watchAndRun } from 'vite-plugin-watch-and-run';

export default defineConfig({
    plugins: [
        laravel({
            // `docs.css` powers the Blade-rendered /docs documentation site.
            input: ['resources/css/app.css', 'resources/css/docs.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        // Re-index the documentation catalogue whenever docs markdown changes.
        watchAndRun([
            {
                name: 'docs:index',
                watch: path.resolve('prezet/**/*.(md|jpg|png|webp)'),
                run: 'php artisan docs:index',
                delay: 1000,
            },
        ]),
    ],
});
