// =============================================================================
//  Builds the deployment package (for upload e.g. via FTP).
// -----------------------------------------------------------------------------
//  Run from the project ROOT (requires Node 18+ and installed frontend deps):
//
//      node tools/build-deploy.mjs            → deploy to the domain ROOT
//      node tools/build-deploy.mjs geodata    → deploy to the SUBDIRECTORY /geodata (WITHOUT a leading slash)
//
//  (a leading slash is accepted too; alternatively via a variable: BASE_PATH=/geodata node tools/build-deploy.mjs)
//
//  The result is the  deploy/  folder – upload its CONTENTS via FTP to the target folder
//  (web root or the subdirectory). Then on the server copy
//  config/config.example.php → config/config.php and fill in the DB credentials.
// =============================================================================

import { spawnSync } from 'node:child_process';
import { existsSync, rmSync, mkdirSync, cpSync, readFileSync, writeFileSync, readdirSync, statSync, mkdtempSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const frontend = join(root, 'frontend');
const out = join(root, 'deploy');

// Safety check: the cache rules in the root .htaccess are essential for deployment (without them
// browsers heuristically cache index.html and a returning user ends up on a stale
// version – the "can't sign in after a week" incident). We don't build a package without them.
const htaccessSrc = readFileSync(join(root, '.htaccess'), 'utf8');
if (!htaccessSrc.includes('Cache-Control "no-cache, must-revalidate"')) {
    console.error('✗ The root .htaccess is missing the cache rules (no-cache for index.html/version.json).');
    console.error('  Without them the known stale-cache bug would come back in production. Add them and run again.');
    process.exit(1);
}

// Normalize the (sub)directory: "" (root) or "/geodata". Same as svelte.config.js.
const arg = (process.argv[2] || process.env.BASE_PATH || '').trim().replace(/\/+$/, '');
const base = arg === '' ? '' : (arg.startsWith('/') ? arg : '/' + arg);
console.log(base ? `▶ Building for subdirectory: ${base}` : '▶ Building for domain root');

// 1) Frontend build (svelte.config.js reads BASE_PATH).
const res = spawnSync('npm', ['run', 'build'], {
    cwd: frontend,
    stdio: 'inherit',
    shell: true, // needed for npm.cmd on Windows
    env: { ...process.env, BASE_PATH: base }
});
if (res.status !== 0) {
    console.error('✗ Frontend build failed.');
    process.exit(1);
}
const buildDir = join(frontend, 'build');
if (!existsSync(buildDir)) {
    console.error('✗ frontend/build is missing – the build did not run.');
    process.exit(1);
}

// 2) Clean deploy/ folder. But stash the hashed chunks of the PREVIOUS build:
// a browser with a stale index.html (or an open tab that has yet to lazy-import
// the map/history) still needs them for a while after deployment – without them it would get
// a 404 and a blank page. Same name = same content (hash in the name), so merging is safe.
const immutableRel = join('_app', 'immutable');
let keep = null;
if (existsSync(join(out, immutableRel))) {
    keep = mkdtempSync(join(tmpdir(), 'zvk-immutable-'));
    cpSync(join(out, immutableRel), keep, { recursive: true, preserveTimestamps: true });
}
rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

// 3) Static frontend (index.html, _app/, logo.svg).
cpSync(buildDir, out, { recursive: true });

// Add back chunks from the previous build (only those the new build lacks). We keep them
// for at most 30 days (by file time) – no live client needs older ones.
if (keep) {
    const cutoff = Date.now() - 30 * 24 * 3600 * 1000;
    const mergeOld = (src, dst) => {
        for (const e of readdirSync(src, { withFileTypes: true })) {
            const s = join(src, e.name);
            const d = join(dst, e.name);
            if (e.isDirectory()) {
                mkdirSync(d, { recursive: true });
                mergeOld(s, d);
            } else if (!existsSync(d) && statSync(s).mtimeMs >= cutoff && e.name !== '.htaccess') {
                cpSync(s, d, { preserveTimestamps: true });
            }
        }
    };
    mergeOld(keep, join(out, immutableRel));
    rmSync(keep, { recursive: true, force: true });
}

// Immutable hashed files: cache forever. Deliberately a separate .htaccess DIRECTLY in
// the _app/immutable folder, so the long cache does not also freeze unhashed assets
// (assets/login.css, fonts/, logo.svg) – those are governed by the root .htaccess.
writeFileSync(join(out, immutableRel, '.htaccess'),
`<IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=31536000, immutable"
</IfModule>
`);

// 4) PHP backend and protected folders.
for (const f of ['api.php', 'login.php', 'logout.php', 'logo.svg']) {
    if (existsSync(join(root, f))) cpSync(join(root, f), join(out, f));
}
// Files with the (sub)directory substituted for __BASE__ (.htaccess ErrorDocument + error pages).
for (const f of ['.htaccess', '404.html', '500.html']) {
    const src = join(root, f);
    if (existsSync(src)) {
        writeFileSync(join(out, f), readFileSync(src, 'utf8').split('__BASE__').join(base));
    }
}
for (const d of ['app', 'tools']) {
    cpSync(join(root, d), join(out, d), { recursive: true });
}
// db/: only what actually gets imported (schema + seed) + the protective .htaccess. migrate_*.sql and
// future_extensions.sql are only for upgrading an existing DB / design drafts – they don't belong on a fresh site
// (the deployer might accidentally import them in phpMyAdmin → "table already exists").
mkdirSync(join(out, 'db'), { recursive: true });
for (const f of ['.htaccess', 'schema.sql', 'seed.sql', 'demo.sql']) {
    const src = join(root, 'db', f);
    if (existsSync(src)) cpSync(src, join(out, 'db', f));
}

// assets: only login.css (the rest of the styles lives in _app).
mkdirSync(join(out, 'assets'), { recursive: true });
cpSync(join(root, 'assets', 'login.css'), join(out, 'assets', 'login.css'));

// config/: only the template + layers + the protective .htaccess. NEVER config.php
// (it could contain local/foreign DB credentials).
mkdirSync(join(out, 'config'), { recursive: true });
for (const f of ['config.example.php', 'layers.php', '.htaccess']) {
    const src = join(root, 'config', f);
    if (existsSync(src)) cpSync(src, join(out, 'config', f));
}

// storage/: only the protective .htaccess (uploaded attachments on the server are never overwritten by a deployment).
mkdirSync(join(out, 'storage'), { recursive: true });
cpSync(join(root, 'storage', '.htaccess'), join(out, 'storage', '.htaccess'));

// The build script itself does not belong on the server.
rmSync(join(out, 'tools', 'build-deploy.mjs'), { force: true });
rmSync(join(out, 'tools', 'install.php'), { force: true });

console.log('\n✓ Done: the deploy/ folder is ready.');
console.log(`  1) Upload the CONTENTS of deploy/ via FTP to ${base ? '"' + base + '"' : 'the web root'}.`);
console.log('     NOTE: upload _app/immutable ADDITIVELY (without deleting files that are');
console.log('     already on the server) – open tabs and browsers with an older index.html');
console.log('     still need the old chunks for a while. The rest of the site can be mirrored.');
console.log('  2) On the server, copy config/config.example.php → config/config.php and fill in the DB settings.');
console.log('  3) In phpMyAdmin, import db/schema.sql (+ db/seed.sql).');
console.log('  4) After creating the accounts: set setup.setup_enabled => false and DELETE tools/create_admin.php from the server.');
