import {
    defineConfig,
    loadEnv,
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const configuredPort = Number.parseInt(process.env.VITE_PORT ?? env.VITE_PORT, 10);
    const port = Number.isNaN(configuredPort) ? 5173 : configuredPort;
    const runningInSail = (process.env.LARAVEL_SAIL ?? env.LARAVEL_SAIL) === '1';
    const configuredHost = process.env.VITE_HOST ?? env.VITE_HOST ?? (runningInSail ? '0.0.0.0' : '127.0.0.1');
    const hmrHost = process.env.VITE_HMR_HOST ?? env.VITE_HMR_HOST ?? (runningInSail ? 'localhost' : configuredHost);

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
            port,
            strictPort: true,
            cors: true,
            hmr: {
                host: hmrHost,
                clientPort: port,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
