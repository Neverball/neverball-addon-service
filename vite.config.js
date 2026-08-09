import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss()],
    publicDir: false,
    build: {
        rollupOptions: {
            input: 'resources/js/addon-tool.js',
            output: {
                entryFileNames: '[name].[hash].js',
                assetFileNames: '[name].[hash][extname]',
            },
        },
        manifest:    true,
        outDir:      'public/dist',
    },
    define: { global: 'globalThis' },
});
