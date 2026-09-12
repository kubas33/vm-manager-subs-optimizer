import {
    defineConfig,
    loadEnv,
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const configuredPort = Number.parseInt(process.env.VITE_PORT ?? env.VITE_PORT, 10);
    const configuredHost = process.env.VITE_HOST ?? env.VITE_HOST ?? '0.0.0.0';
    const hmrHost = process.env.VITE_HMR_HOST ?? env.VITE_HMR_HOST ?? 'localhost';

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: configuredHost,
            port: Number.isNaN(configuredPort) ? 5173 : configuredPort,
            strictPort: true,
            cors: true,
            hmr: {
                host: hmrHost,
                clientPort: Number.isNaN(configuredPort) ? 5173 : configuredPort,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
