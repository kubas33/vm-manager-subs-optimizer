import {
    defineConfig,
    loadEnv,
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const configuredPort = Number.parseInt(env.VITE_PORT, 10);
    const port = Number.isNaN(configuredPort) ? 5173 : configuredPort;
    const runningInSail = env.LARAVEL_SAIL === '1';
    const configuredHost = env.VITE_HOST ?? (runningInSail ? '0.0.0.0' : '127.0.0.1');
    const hmrHost = env.VITE_HMR_HOST ?? (runningInSail ? 'localhost' : configuredHost);

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
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
