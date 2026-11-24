import adapter from '@sveltejs/adapter-static';
import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

// Deployment subdirectory. Passed via BASE_PATH at build time (or as an argument
// to tools/build-deploy.mjs). Empty = domain root.
//   node tools/build-deploy.mjs /collector  → base "/collector"
const raw = (process.env.BASE_PATH || '').trim().replace(/\/+$/, '');
const base = raw === '' ? '' : (raw.startsWith('/') ? raw : '/' + raw);

/** @type {import('@sveltejs/kit').Config} */
const config = {
    preprocess: vitePreprocess(),
    kit: {
        // Static build (SPA) – uploaded to the server next to the PHP API.
        adapter: adapter({ fallback: 'index.html', precompress: false }),
        // base: deployment subdirectory (from BASE_PATH); relative for robust asset loading.
        paths: { base, relative: true },
        // An open app checks _app/version.json every 5 minutes – after a new version is deployed,
        // collectors in a long session are offered a reload (otherwise the old code would keep running
        // and lazily loaded parts, such as the map or history, would fail once the chunks on the server are replaced).
        version: { pollInterval: 300_000 },
        // CSP with automatic hashes for SvelteKit's own inline scripts/styles.
        csp: {
            directives: {
                'default-src': ['self'],
                'script-src': ['self'],
                'style-src': ['self', 'unsafe-inline'],
                'img-src': ['self', 'data:', 'https:'],
                'connect-src': ['self'],
                'font-src': ['self', 'data:'],
                'object-src': ['none'],
                'base-uri': ['self']
            }
        }
    }
};

export default config;
