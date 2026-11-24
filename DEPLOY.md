# Deploying to shared PHP hosting

The backend (PHP + MySQL) runs directly on the hosting. The frontend is a **static SvelteKit SPA**
that is uploaded as static files via FTP next to the PHP backend. No Node runs on the server.

**Shortcut:** download a ready-made zip from [Releases](https://github.com/misavojte/GeodataCollector/releases)
(`*-root.zip` for a domain root, `*-subdir-geodata.zip` for `/geodata/`) and skip step 4.

## 0. Requirements

- **Hosting:** PHP 8.0+ (`pdo_mysql`, `mbstring`, `json`; `curl` for adding WMS/WMTS layers;
  `imagick` or `gd` for photo attachments), MySQL 8.0+ or MariaDB 10.2.7+,
  Apache with `mod_rewrite` and `mod_headers`.
- **Your computer (only if you build the package yourself):** Node.js 18+ and npm.

## 1. Database

Create a MySQL database and user in your hosting control panel. Write down the
**host** (DB server – on shared hosting often something other than `localhost`), **database name**, **user**, **password**.

## 2. Schema import

In phpMyAdmin (Import tab), upload:
1. `db/schema.sql` – tables,
2. `db/seed.sql` – the protected **Name** field and default basemaps (OpenStreetMap + two Czech national layers).
   All other attribute fields are defined after logging in (**Manage metadata** in the account menu).
3. *(optional, for trying things out)* `db/demo.sql` (in the package from v1.0.1 on, otherwise take it from the repository) –
   example fields and 19 sample features.
   Import it **after** creating your admin account (step 6); do not use it in production.

> **With SSH access** you can do steps 2 and 6 in one go: `php tools/install.php`
> (add `--demo` for the sample data). It is safe to run repeatedly.

> Do not import `db/future_extensions.sql` (a draft of future tables).

> **Upgrading an existing DB (not a new site):** `schema.sql` contains all current tables, so
> a new site needs nothing else. If you are upgrading an older installation, use
> `db/migrate_feature_revisions.sql` and `db/migrate_map_layers.sql` from the repository (not from the package)
> – they are idempotent (`IF NOT EXISTS`).

## 3. Backend configuration

1. Copy `config/config.example.php` → `config/config.php`.
2. Fill in `db` (host, name, user, pass).
3. `app.debug => false`, `security.cookie_secure => true`.
4. To create accounts, temporarily set `setup.setup_enabled => true` and a **mandatory** random
   `setup.setup_token`.

> **By hand, not via a wizard.** The DB credentials are filled in once, by hand, directly in
> `config/config.php`. A web installation wizard is deliberately not provided – it would need
> a writable config and a setup endpoint, which is an unnecessary security risk.
> If `config/config.php` is missing or has wrong credentials, the application shows a clear
> "Configuration error…" message (no silent blank page). You can fill in the config
> on your computer before uploading, or later on the server via an FTP editor.

## 4. Building the upload package (on your computer)

Everything is assembled by **a single command** – it builds the frontend and bundles the
PHP backend and the protective `.htaccess` files into the `deploy/` folder. Run it from the **project root**:

```bash
cd frontend && npm install && cd ..      # first time only (downloads dependencies)

node tools/build-deploy.mjs              # deploy to the domain ROOT
# – OR – to a subdirectory (e.g. /geodata):
node tools/build-deploy.mjs geodata      # give the subdirectory WITHOUT a leading slash
```

> **Subdirectory:** give just the name without `/` (e.g. `geodata`) – this works in PowerShell
> and Git Bash (Git Bash "mangles" a leading slash into a path). The script derives
> `base` `/geodata` from it, so the application loads correctly in the subdirectory. If you don't
> know the subdirectory name in advance, build the package once you do – a rebuild takes seconds.

This creates a `deploy/` folder with the complete content to upload:
```
deploy/
├── index.html  _app/  logo.svg        ← frontend (SvelteKit build)
├── 404.html  500.html                 ← error pages (ErrorDocument)
├── api.php  login.php  logout.php  .htaccess
└── app/  config/  db/  tools/  assets/login.css
```

> **Error pages:** `.htaccess` sets `ErrorDocument` to `404.html` / `500.html`
> (the script fills in paths for a (sub)directory). They are self-contained (inline styles) and work
> at any depth. The API (`/api/*`) has its own JSON errors – ErrorDocument does not affect them.

## 5. Uploading via FTP

Upload the **contents of the `deploy/` folder** (not the folder itself) to the target folder on the server:
- **domain root** → the web folder (e.g. `www/`), or
- **subdirectory** → the same one you specified in step 4 (e.g. `www/geodata/`).

`.htaccess` routes `/api/*` to `api.php` and serves other paths statically
(`index.html`, `_app/…`). The `app/`, `config/` and `db/` folders are protected by their own `.htaccess`
(the script bundles them automatically). The `config/config.php` file is **not** in the package –
you create it on the server (step 3).

> **Important when updating a running site:** upload the `_app/immutable/` folder
> **additively** – do not delete files that are already on the server. Open tabs
> and browsers with an older `index.html` still need them for a while; once deleted they would
> get a 404 and a blank page. You can mirror the rest of the site freely. (The build itself keeps
> chunks from previous builds up to 30 days old, so a plain "upload everything" is fine.)

> **Do not upload** `frontend/` (source), `node_modules/` or `router.php` to the server –
> they are only for development and building. The script does not include them in the package.

## 6. Creating accounts

1. Open `https://your-domain.example/tools/create_admin.php?token=YOUR_TOKEN`.
2. Create yourself as an **administrator**, then your team members as **collectors**.
3. **Security (mandatory):** set `setup.setup_enabled => false`, upload the config again,
   and **delete `tools/create_admin.php` from the server**. The token (`setup_token`) must be **at least
   16 characters** long – otherwise the tool refuses to run. While `setup_enabled => true`, this is open
   registration protected only by the token; so be sure to turn it off once onboarding is done.

## 7. Verification

1. `https://your-domain.example/` → login (`login.php`) → map.
2. Define your attribute fields under **Manage metadata** (account menu).
3. Switch base maps in the **Layers** tab, try drawing and saving a feature,
   click a feature to see its detail, edit its shape by dragging vertices.
4. Set `map.default_center` / `map.default_zoom` / `map.max_bounds` in `config/config.php` to your study area.

## 8. Private historical maps (WMS)

In `config/layers.php`, uncomment the template, fill in the URL, layer name and `proxy_auth`,
and set `proxy => true`. Tiles are then served through `api/wms-proxy`, and the credentials stay on the server.

## Troubleshooting

| Symptom | Solution |
|--------|--------|
| Blank page, 404 on `/_app/…` in the console | The package was built for a different (sub)directory. Run the build with the matching parameter: `node tools/build-deploy.mjs <subdirectory>` (root = no parameter). |
| Login succeeds, but then 404 | Old build with an `index.php` redirect – rebuild (the fix is in `login.php`). |
| 404 on `/api/...` | `mod_rewrite`/`.htaccess` is not working – check that `.htaccess` was uploaded. |
| 500 "Configuration error" | `config/config.php` is missing or the DB credentials are wrong. |
| Map without a basemap | A layer service URL changed or is unreachable – switch to another base map and fix it under **Manage layers**. |
| Geometry cannot be saved | The DB must support spatial functions (MariaDB 10.2.7+/MySQL 8). |

## Attachments (photos and documents)

- Uploaded files are stored in `storage/uploads/` on the server (protected by `storage/.htaccess`, served
  only by PHP after login). Deployment **neither overwrites nor deletes** this folder – when uploading
  manually via FTP, do not delete it; it is the only copy of the data. Back it up together with the database.
- Upgrading an existing DB: `db/migrate_attachments.sql` (new field types `photos`/`files` + `attachments` table).
- Photos are processed by Imagick (including HEIC from iPhones), with GD as a fallback: 400 px thumbnail,
  2048 px display version (JPEG); the original is kept unchanged. EXIF date and GPS are stored in `attachments`.
