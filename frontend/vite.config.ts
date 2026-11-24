import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

// For `vite dev` we proxy the PHP backend (runs separately, e.g. php -S 127.0.0.1:8088).
export default defineConfig({
    plugins: [sveltekit()],
    server: {
        proxy: {
            '/api': 'http://127.0.0.1:8088',
            '/login.php': 'http://127.0.0.1:8088',
            '/logout.php': 'http://127.0.0.1:8088'
        }
    }
});
