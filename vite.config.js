import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    publicDir: false,
    plugins: [
        laravel({
            publicDirectory: 'public',
            buildDirectory: 'build',
            input: ['resources/js/addon.js'],
        }),
        ...statamic(),
    ],
});
