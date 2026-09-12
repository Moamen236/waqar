import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Two separate entry pairs — storefront (Tailwind) and admin (Bootstrap) —
// built from one Vite config but never sharing a bundle (Section 23).
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/storefront.css',
                'resources/js/storefront/app.tsx',
                'resources/css/admin.css',
                'resources/js/admin/app.tsx',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
